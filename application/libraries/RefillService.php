<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * RefillService — the "the likes dropped, top it back up" path.
 *
 * A refill is the only remedy a customer has after an order is COMPLETED, and
 * it is the one operation in the panel that costs the operator money without
 * charging anybody: the provider re-delivers under its own guarantee. That
 * makes honesty about what actually happened the whole job of this class.
 *
 * ## What was wrong before
 *
 * The previous version called the provider, and then returned
 * `array('ok' => true)` **whatever the provider said**:
 *
 *   - a refusal ("Incorrect order ID", "Refill not available for this
 *     service", "Order is too old") was stored in `refills.error`, the row was
 *     left in PENDING, and the customer was shown "Refill requested". Nothing
 *     had been requested. Nobody was ever told.
 *   - a timeout was treated identically, so a refill the provider never
 *     received was also reported as accepted.
 *   - either way the row had no `provider_refill_id`, and the status poller
 *     (`Refill_model::pending_provider_sync()`) only looks at rows that have
 *     one — so the refill sat in PENDING **for ever**. The admin Refills queue
 *     grew a permanent tail of "waiting more than 24 hours" rows that no
 *     worker would ever touch again.
 *
 * ## The rules now
 *
 * 1. A provider **refusal** is final: the refill is closed as FAILED with the
 *    provider's own words, and the caller is told `ok => false` so the
 *    customer sees the refusal instead of a false promise.
 * 2. A **transport failure** (timeout, 502, HTML maintenance page) is not an
 *    answer. The refill stays PENDING and the cron worker re-sends it, up to
 *    MAX_SUBMIT_ATTEMPTS, after which it is closed as FAILED so it cannot
 *    become an immortal queue item.
 * 3. An order with **no usable provider reference** (no provider, provider
 *    disabled, never submitted) cannot be refilled automatically. The row is
 *    kept PENDING and flagged `manual` so staff can act on it, and the worker
 *    does not burn attempts on it.
 * 4. Every terminal outcome writes history, stamps `completed_at` and
 *    notifies the customer — a refill that quietly failed is worse than one
 *    that was refused out loud.
 */
class RefillService {

    /** Re-sends of an unanswered refill before it is written off. */
    const MAX_SUBMIT_ATTEMPTS = 5;

    /** Statuses that mean "still in flight". Mirrors Refill_model. */
    const OPEN_STATUSES = array('PENDING', 'PROCESSING', 'IN_PROGRESS');

    private $ci;

    public function __construct() {
        $this->ci =& get_instance();
        $this->ci->load->model(array('Order_model','Refill_model','Refill_status_history_model','Provider_model'));
        $this->ci->load->library(array('ProviderSyncService'));
    }

    /**
     * Request a refill for a customer's order.
     *
     * @return array{ok:bool,refill?:object,error?:string,code?:string,message?:string}
     */
    public function request($order_public_id, $user) {
        $order = $this->ci->Order_model->find_public_for_user($order_public_id, $user->id);
        if (!$order) return array('ok'=>false,'error'=>'Order not found','code'=>'NO_ORDER');

        if (!(int)$order->service_id || !$this->service_flag($order->service_id, 'refill_supported')) {
            return array('ok'=>false,'error'=>'This service does not support refills','code'=>'UNSUPPORTED');
        }
        if (!in_array($order->status, array('COMPLETED','PARTIAL'), true)) {
            return array('ok'=>false,'error'=>'Refills are only available for completed orders','code'=>'NOT_REFILLABLE');
        }
        $active = $this->ci->Refill_model->active_for_order($order->id);
        if ($active) {
            return array('ok'=>false,'error'=>'A refill is already pending for this order','code'=>'DUPLICATE');
        }
        // Providers honour refills for a limited window and charge back
        // nothing outside it. Asking anyway wastes an API call and, worse,
        // tells the customer a top-up is coming when the provider will refuse.
        $window = $this->refill_window_days();
        if ($window > 0) {
            $completed = ($order->completed_at ?? null) ?: ($order->updated_at ?? null);
            if ($completed && strtotime($completed.' UTC') < strtotime('-'.$window.' days')) {
                return array('ok'=>false,'code'=>'WINDOW_CLOSED',
                    'error'=>'The refill guarantee for this order ran out after '.$window.' days.');
            }
        }

        $outcome = $this->submit($order);
        $refill  = $this->persist($order, $user->id, $outcome);

        if ($outcome['status'] === 'FAILED') {
            // The provider answered, and the answer was no. Say so.
            return array('ok'=>false,'code'=>'PROVIDER_REFUSED',
                         'error'=>$outcome['error'] ?: 'The provider refused the refill.',
                         'refill'=>$refill);
        }
        return array('ok'=>true, 'refill'=>$refill, 'message'=>$this->message_for($outcome));
    }

    /**
     * Send (or re-send) a refill to the provider.
     *
     * Returns the row state the attempt earned, never throws.
     *
     * @return array{status:string,provider_refill_id:?string,error:?string,manual:bool}
     */
    public function submit($order) {
        if (!$order || !$order->provider_id) {
            return array('status'=>'PENDING','provider_refill_id'=>null,'manual'=>true,
                'error'=>'This order was never sent to a provider; a member of staff has to refill it by hand.');
        }
        $provider = $this->ci->Provider_model->find_by_id($order->provider_id);
        if (!$provider || $provider->status !== 'ACTIVE') {
            return array('status'=>'PENDING','provider_refill_id'=>null,'manual'=>true,
                'error'=>'The provider for this order is not active; a member of staff has to refill it by hand.');
        }
        if (!$order->provider_order_id) {
            return array('status'=>'PENDING','provider_refill_id'=>null,'manual'=>true,
                'error'=>'The provider never returned an order id, so there is nothing to refill against.');
        }

        try {
            $res = $this->ci->providersyncservice->adapter($provider)->requestRefill($order->provider_order_id);
        } catch (Exception $e) {
            // An exception is a transport problem, not a refusal: keep it open.
            return array('status'=>'PENDING','provider_refill_id'=>null,'manual'=>false,
                         'error'=>$e->getMessage());
        } catch (Throwable $e) {
            return array('status'=>'PENDING','provider_refill_id'=>null,'manual'=>false,
                         'error'=>$e->getMessage());
        }

        if (!empty($res['ok']) && !empty($res['provider_refill_id'])) {
            return array('status'=>'PROCESSING','provider_refill_id'=>(string)$res['provider_refill_id'],
                         'error'=>null,'manual'=>false);
        }
        // No refill id and no explicit retry hint: treat the provider as having
        // answered. Guessing "retryable" here would re-send refills a panel has
        // already refused, which is how a provider account gets rate-limited.
        $retryable = !empty($res['retryable']);
        return array(
            'status'             => $retryable ? 'PENDING' : 'FAILED',
            'provider_refill_id' => null,
            'manual'             => false,
            'error'              => $res['error'] ?? 'The provider refused the refill.',
        );
    }

    /**
     * Cron entry point: re-send a refill that never reached the provider.
     *
     * Attempts are counted in `refills.metadata` so a provider that is down
     * for a week cannot keep one row cycling for ever; after
     * MAX_SUBMIT_ATTEMPTS the refill is closed and the customer told.
     *
     * @return string the status the refill now has
     */
    public function retry($refill) {
        $order = $this->ci->Order_model->find_by_id($refill->order_id);
        $meta  = $this->metadata($refill);
        $attempts = (int)($meta['submit_attempts'] ?? 1);

        $outcome = $this->submit($order);
        $meta['submit_attempts'] = ++$attempts;
        $meta['manual'] = !empty($outcome['manual']);

        if ($outcome['status'] === 'PROCESSING') {
            $this->apply($refill, 'PROCESSING', 'SYSTEM', array(
                'provider_refill_id' => $outcome['provider_refill_id'],
                'error'              => null,
                'metadata'           => $meta,
            ));
            return 'PROCESSING';
        }
        if ($outcome['status'] === 'FAILED') {
            $this->apply($refill, 'FAILED', 'PROVIDER', array('error'=>$outcome['error'], 'metadata'=>$meta));
            return 'FAILED';
        }
        if (empty($outcome['manual']) && $attempts >= self::MAX_SUBMIT_ATTEMPTS) {
            $this->apply($refill, 'FAILED', 'SYSTEM', array(
                'error'    => 'Given up after '.$attempts.' attempts: '.($outcome['error'] ?: 'the provider never answered.'),
                'metadata' => $meta,
            ));
            return 'FAILED';
        }
        // Still waiting. Bump last_checked_at so the queue rotates instead of
        // re-trying the same unlucky row on every run.
        $this->touch($refill, array('error'=>$outcome['error'], 'metadata'=>$meta));
        return 'PENDING';
    }

    /**
     * Move a refill to a new status, writing history and — when the new status
     * is terminal — stamping completed_at and telling the customer.
     */
    public function apply($refill, $new_status, $source, array $extra = array()) {
        $row = array_merge(array(
            'status'          => $new_status,
            'last_checked_at' => gmdate('Y-m-d H:i:s'),
        ), $this->encode_extra($extra));

        if (in_array($new_status, array('COMPLETED','FAILED'), true) && empty($refill->completed_at)) {
            $row['completed_at'] = gmdate('Y-m-d H:i:s');
        }
        if ($new_status !== $refill->status) {
            $this->ci->Refill_status_history_model->record($refill->id, $refill->status, $new_status, $source);
        }
        $this->ci->db->where('id', $refill->id)->update('refills', $row);

        if ($new_status !== $refill->status) {
            $this->notify($refill, $new_status, $extra['error'] ?? null);
        }
        return $this->ci->Refill_model->find_by_id($refill->id);
    }

    /** Record that we looked, without pretending anything changed. */
    public function touch($refill, array $extra = array()) {
        $this->ci->db->where('id', $refill->id)->update('refills',
            array_merge(array('last_checked_at' => gmdate('Y-m-d H:i:s')), $this->encode_extra($extra)));
    }

    /* ------------------------------------------------------------------ */

    /**
     * Tell the customer how their refill ended.
     *
     * A refill that silently failed is the complaint this whole module exists
     * to stop: the customer asked for their drop to be topped up, saw
     * "requested", and never heard another word.
     */
    private function notify($refill, $status, $error = null) {
        $types = array('COMPLETED' => 'refill.completed', 'FAILED' => 'refill.failed');
        if (!isset($types[$status])) return;
        try {
            $order = $this->ci->Order_model->find_by_id($refill->order_id);
            if (!$order) return;
            $this->ci->load->library('NotificationService');
            if (!isset($this->ci->notificationservice)) return;

            $body = $status === 'COMPLETED'
                ? 'The refill for order '.$order->public_id.' is complete.'
                : 'The refill for order '.$order->public_id.' could not be completed'
                  .($error ? ': '.$error : '.').' Contact support if the drop is still there.';

            $this->ci->notificationservice->notify(
                $order->user_id, $types[$status], $body,
                array('order_id' => $order->public_id, 'url' => 'dashboard/orders/'.$order->public_id),
                array('order_id' => $order->public_id, 'reason' => (string)$error)
            );
        } catch (Throwable $e) {
            // Never fatal: the refill has already reached its state.
            log_message('error', 'refill notify failed: '.$e->getMessage());
        }
    }

    private function persist($order, $user_id, array $outcome) {
        $this->ci->db->trans_start();
        $public_id = marvy_public_id();
        $now = gmdate('Y-m-d H:i:s');
        $this->ci->db->insert('refills', array(
            'public_id'          => $public_id,
            'order_id'           => $order->id,
            'provider_id'        => $order->provider_id,
            'provider_refill_id' => $outcome['provider_refill_id'],
            'status'             => $outcome['status'],
            'requested_by_id'    => $user_id,
            'error'              => $outcome['error'],
            'requested_at'       => $now,
            'last_checked_at'    => $now,
            'completed_at'       => $outcome['status'] === 'FAILED' ? $now : null,
            'metadata'           => json_encode(array(
                'submit_attempts' => 1,
                'manual'          => !empty($outcome['manual']),
            )),
        ));
        $id = $this->ci->db->insert_id();
        $this->ci->Refill_status_history_model->record($id, null, $outcome['status'], 'CUSTOMER');
        $this->ci->db->trans_complete();
        $refill = $this->ci->Refill_model->find_by_id($id);
        // A refill refused on the spot never passes through apply(), so this
        // is its only chance to reach the customer's inbox. Without it the
        // refusal lives in a staff queue nobody reads.
        if ($outcome['status'] === 'FAILED') $this->notify($refill, 'FAILED', $outcome['error']);
        return $refill;
    }

    private function encode_extra(array $extra) {
        $row = array();
        foreach (array('provider_refill_id','error') as $k) {
            if (array_key_exists($k, $extra)) $row[$k] = $extra[$k];
        }
        if (isset($extra['metadata'])) {
            $row['metadata'] = is_string($extra['metadata'])
                ? $extra['metadata'] : json_encode($extra['metadata']);
        }
        if (isset($extra['completed_at'])) $row['completed_at'] = $extra['completed_at'];
        return $row;
    }

    private function metadata($refill) {
        if (empty($refill->metadata)) return array();
        $meta = is_array($refill->metadata) ? $refill->metadata : json_decode((string)$refill->metadata, true);
        return is_array($meta) ? $meta : array();
    }

    private function message_for(array $outcome) {
        if (!empty($outcome['manual'])) {
            return 'Refill logged for a member of staff to handle: '.$outcome['error'];
        }
        if ($outcome['status'] === 'PENDING') {
            return 'The provider could not be reached; the refill is queued and will be re-sent automatically.';
        }
        return 'The provider accepted the refill.';
    }

    /** Settings `refill_window_days` — 0 means "no time limit". */
    private function refill_window_days() {
        try {
            $this->ci->load->model('Setting_model');
            if (!isset($this->ci->Setting_model)) return 30;
            $value = $this->ci->Setting_model->get('refill_window_days', 30);
        } catch (Throwable $e) {
            return 30;
        }
        if (!is_numeric($value)) return 30;
        return max(0, min(3650, (int)$value));
    }

    private function service_flag($service_id, $flag) {
        $row = $this->ci->db->select($flag)->where('id', $service_id)->get('services')->row();
        return $row ? (int)$row->$flag === 1 : false;
    }
}
