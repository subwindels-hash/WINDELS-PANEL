<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * OrderService — the order placement and state-transition engine (Session 09).
 *
 * Create flow (§23):
 *   1. load + validate the service (active, quantity bounds, link, blacklist)
 *   2. resolve the price via PricingService (user > group > default)
 *   3. charge the wallet through LedgerService (double-entry, idempotent)
 *   4. insert the order with PENDING status + frozen provider cost
 *   5. record PENDING in order_status_history
 *   6. submit to the provider adapter (with retry/backoff); on success store
 *      provider_order_id and move PENDING → PROCESSING, else → FAILED and refund
 *
 * Every money value is DECIMAL-as-string, processed with bcmath. The service is
 * idempotent on an explicit idempotency_key so a double-submit never charges
 * twice. No controller in the app may INSERT/UPDATE orders or wallets directly.
 */
class OrderService {

    /** Terminal states in which the customer must get their charge back. */
    private static $refunding_states = array('CANCELED', 'CANCELLED', 'REFUNDED', 'FAILED', 'EXPIRED');

    const IDEM_SCOPE = 'order:create';

    private $ci;

    public function __construct() {
        $this->ci =& get_instance();
        // OrderStateMachine is a static utility, not a CI library, so the
        // loader will not bring it in — and every transition below calls it.
        // Without this an order placement fatals on the first status change.
        require_once __DIR__.'/OrderStateMachine.php';
        $this->ci->load->model(array(
            'Service_model', 'Order_model', 'Order_status_history_model',
            'Provider_model', 'Wallet_model', 'Blacklist_model',
        ));
        $this->ci->load->library(array(
            'PricingService', 'LedgerService', 'ProviderSyncService', 'EncryptionService',
        ));
    }

    /**
     * Place an order.
     *
     * @param int|object $user       authenticated user (id or row)
     * @param array      $input      service (public_id or id), link, quantity,
     *                                fields?, idempotency_key?, note?
     * @return array{ok:bool,order?:object,error?:string,code?:string}
     */
    /**
     * Place an order whose charge was already taken elsewhere.
     *
     * Drip-feed and subscription schedules reserve the whole charge up front,
     * so their child orders must not hit the wallet again. Everything else —
     * validation, provider submission, history — is the normal path.
     *
     * @param array $context source (DRIPFEED|SUBSCRIPTION) plus the parent ids
     */
    public function place_prepaid($user, array $input, array $context = array()) {
        $input['__prepaid'] = true;
        $input['source'] = $context['source'] ?? 'DRIPFEED';
        foreach (array('dripfeed_order_id', 'dripfeed_run_number', 'subscription_id') as $k) {
            if (isset($context[$k])) $input[$k] = $context[$k];
        }
        return $this->place($user, $input);
    }

    public function place($user, array $input) {
        $prepaid = !empty($input['__prepaid']);
        $user = is_object($user) ? $user : $this->resolve_user((int)$user);
        if (!$user) {
            return array('ok' => false, 'error' => 'User not found', 'code' => 'NO_USER');
        }

        // Idempotency: a repeated request returns the original order.
        $idem = $this->normalise_idem($input['idempotency_key'] ?? null, $user);
        if ($idem) {
            $existing = $this->ci->Order_model->find_by_idempotency_key($idem);
            if ($existing) {
                // Idempotency keys are never an authorization mechanism. A
                // caller must not be able to discover another customer's order
                // by deliberately colliding with its key.
                if ((int)$existing->user_id !== (int)$user->id) {
                    return array(
                        'ok' => false,
                        'code' => 'IDEMPOTENCY_CONFLICT',
                        'error' => 'That idempotency key is already in use.',
                    );
                }
                return array('ok' => true, 'order' => $existing, 'duplicate' => true);
            }
        }

        // 1. Resolve the service.
        $service = $this->resolve_service($input);
        if (!$service) {
            return array('ok' => false, 'error' => 'Service not found', 'code' => 'NO_SERVICE');
        }
        if ($service->status !== 'ACTIVE') {
            return array('ok' => false, 'error' => 'Service is unavailable', 'code' => 'SERVICE_INACTIVE');
        }

        // 2. Validate quantity against the frozen bounds/step.
        $q = (int)($input['quantity'] ?? 0);
        $qerr = $this->validate_quantity($q, $service);
        if ($qerr) {
            return array('ok' => false, 'error' => $qerr, 'code' => 'BAD_QUANTITY');
        }

        // 3. Validate the target link.
        $link = trim((string)($input['link'] ?? ''));
        if (!$this->valid_link($link)) {
            return array('ok' => false, 'error' => 'A valid http(s) link is required', 'code' => 'BAD_LINK');
        }
        if ($this->ci->Blacklist_model->text_contains_blacklisted_link($link)) {
            return array('ok' => false, 'error' => 'That link is not permitted', 'code' => 'BLACKLISTED');
        }

        // 4. Price.
        $rate = $this->ci->pricingservice->price_for($service, $user);
        $charge = $this->ci->pricingservice->charge_for_quantity($rate, $q);

        // Provider cost is frozen at order time (§56) so margin is auditable.
        $provider_charge = $service->provider_rate !== null
            ? $this->ci->pricingservice->charge_for_quantity($service->provider_rate, $q)
            : null;

        // 5. Charge the wallet (idempotent). The ledger is the only writer.
        // A prepaid child order (drip-feed / subscription run) skips this: its
        // parent already reserved the full charge.
        $wallet = $this->ci->Wallet_model->for_user($user->id);
        $charge_idem = $idem ?: ('order:charge:'.$user->id.':'.marvy_public_id());
        if (!$prepaid) {
            $charged = $this->ci->ledgerservice->charge(
                $wallet->id, $charge, 'ORDER', null, $charge_idem
            );
            if (empty($charged['ok'])) {
                $code = ($charged['error'] ?? '') === 'INSUFFICIENT_BALANCE' ? 'INSUFFICIENT_BALANCE' : 'CHARGE_FAILED';
                return array('ok' => false, 'error' => $charged['error'] ?? 'Could not charge wallet', 'code' => $code);
            }
        }

        // 6. Persist the order + initial history inside a transaction.
        $order = $this->persist_order($user, $service, compact('link','q','rate','charge','provider_charge','idem','input'));
        if (!$order) {
            // Roll back the wallet charge if we could not create the row. A
            // prepaid order never charged here, so there is nothing to undo —
            // the parent schedule owns that money.
            if (!$prepaid) {
                $this->ci->ledgerservice->refund($wallet->id, $charge, 'ORDER', null, 'order:rollback:'.$charge_idem);
            }
            return array('ok' => false, 'error' => 'Could not create order', 'code' => 'PERSIST_FAILED');
        }

        // 7. Submit to the provider (inline; a queue/worker can take over later).
        //    When the operator disables auto-submit, a regular order is held in
        //    PENDING for staff review instead of being submitted here. Prepaid
        //    child orders (drip-feed/subscription runs) keep auto-submitting so
        //    scheduled money flow is never left stranded in a review queue.
        if (!$prepaid && !$this->auto_submit_enabled()) {
            $this->ci->db->where('id', $order->id)->update('orders', array(
                'note' => 'Awaiting manual review (auto-submit disabled)',
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ));
            $order = $this->ci->Order_model->find_by_id($order->id);
            $this->notify($user, $order);
            return array('ok' => true, 'order' => $order, 'held' => true);
        }
        $submit = $this->submit_to_provider($order, $service, $link, $q, $input);
        if (!empty($submit['ok']) && !empty($submit['submitted'])) {
            $this->transition($order->id, 'PENDING', 'PROCESSING', 'SYSTEM', 'Submitted to provider');
            $this->ci->db->where('id', $order->id)->update('orders', array(
                'provider_id'        => $service->provider_id,
                'provider_service_id'=> $service->provider_service_id,
                'provider_order_id'  => $submit['provider_order_id'],
                'submitted_at'       => gmdate('Y-m-d H:i:s'),
            ));
            $order = $this->ci->Order_model->find_by_id($order->id);
        } elseif (empty($submit['ok'])) {
            // Submission failed: mark FAILED and refund the charge immediately.
            $this->transition($order->id, 'PENDING', 'FAILED', 'SYSTEM', $submit['error'] ?? 'Provider submission failed');
            if (!$prepaid) {
                $this->ci->ledgerservice->refund($wallet->id, $charge, 'ORDER', $order->public_id, 'order:refund:'.$order->public_id);
            }
            $this->ci->db->where('id', $order->id)->update('orders', array(
                'note' => $submit['error'] ?? 'Provider submission failed',
            ));
            $order = $this->ci->Order_model->find_by_id($order->id);
            return array('ok' => false, 'order' => $order, 'error' => $submit['error'] ?? 'Submission failed', 'code' => 'SUBMIT_FAILED');
        }

        $this->notify($user, $order);
        return array('ok' => true, 'order' => $order);
    }

    /* -------------------------------------------------------------- */

    public function cancel($order, $user) {
        $order = is_object($order) ? $order : $this->ci->Order_model->find_public_for_user($order, $user->id);
        if (!$order) return array('ok'=>false,'error'=>'Order not found','code'=>'NO_ORDER');
        if (!OrderStateMachine::can($order->status, 'CANCELED')) {
            return array('ok'=>false,'error'=>'Order cannot be canceled in its current state','code'=>'NOT_CANCELLABLE');
        }
        if (!(int)$order->service_id || !$this->service_supports($order->service_id, 'cancel_supported')) {
            return array('ok'=>false,'error'=>'This service does not support cancellation','code'=>'CANCEL_UNSUPPORTED');
        }
        // Try provider cancel if it has been submitted, then move to CANCELED.
        if ($order->provider_order_id) {
            $provider = $order->provider_id ? $this->ci->Provider_model->find_by_id($order->provider_id) : null;
            if ($provider) {
                try {
                    $adapter = $this->ci->providersyncservice->adapter($provider);
                    $adapter->requestCancel($order->provider_order_id);
                } catch (Exception $e) {
                    log_message('error', 'provider cancel failed: '.$e->getMessage());
                }
            }
        }
        $this->transition($order->id, $order->status, 'CANCELED', 'CUSTOMER', 'Canceled by customer');
        $order = $this->ci->Order_model->find_by_id($order->id);
        $refunded = $this->refund_charge($order, 'CUSTOMER');
        $order = $this->ci->Order_model->find_by_id($order->id);
        $this->sync_affiliate($order);
        return array('ok'=>true, 'order'=>$order, 'refunded'=>$refunded);
    }

    /**
     * Apply an external status update (used by the cron status poller / webhooks).
     * Refunds the wallet for PARTIAL orders according to `remains`.
     */
    public function apply_status($order, $new_status, $source, $reason = null, array $extra = array()) {
        $order = is_object($order) ? $order : $this->ci->Order_model->find_by_id((int)$order);
        if (!$order) return array('ok'=>false,'error'=>'Order not found');
        if (!OrderStateMachine::can($order->status, $new_status)) {
            return array('ok'=>false,'error'=>"Illegal transition {$order->status} -> {$new_status}");
        }
        // A partial delivery is its own path: it records `remains` and refunds
        // the undelivered share. Callers reach it either by asking for PARTIAL
        // directly (admin/provider sync) or by reporting COMPLETED with a
        // non-zero remainder.
        if (!empty($extra['remains']) && (int)$extra['remains'] > 0
            && in_array($new_status, array('PARTIAL', 'COMPLETED'), true)) {
            return $this->apply_partial($order, (int)$extra['remains'], $source, $reason);
        }
        $data = array();
        if ($new_status === 'COMPLETED') {
            $data['completed_at'] = gmdate('Y-m-d H:i:s');
        }
        $this->transition($order->id, $order->status, $new_status, $source, $reason);
        if ($data) $this->ci->db->where('id',$order->id)->update('orders',$data);
        $order = $this->ci->Order_model->find_by_id($order->id);
        // Reaching a terminal non-delivery state must return the customer's
        // money; refund_charge() is idempotent so repeated calls are safe.
        if (in_array($new_status, self::$refunding_states, true)) {
            $this->refund_charge($order, $source);
            $order = $this->ci->Order_model->find_by_id($order->id);
        }
        $this->sync_affiliate($order);
        $this->notify_reseller_webhook($order, $new_status);
        $this->notify_customer($order, $new_status);
        return array('ok'=>true, 'order'=>$order);
    }

    /**
     * Tell the customer what happened to their order.
     *
     * Last step on purpose, and never fatal: the order has already reached its
     * new state, and a mail or inbox problem must not roll that back or make
     * the caller think the transition failed.
     */
    private function notify_customer($order, $status) {
        $types = array(
            'COMPLETED' => 'order.completed',
            'PARTIAL'   => 'order.partial',
            'CANCELED'  => 'order.canceled',
            'REFUNDED'  => 'order.refunded',
        );
        if (!isset($types[$status])) return;

        try {
            $this->ci->load->library('NotificationService');
            if (!isset($this->ci->notificationservice)) return;
            $service = $this->ci->db->select('name')->where('id', $order->service_id)->get('services')->row();
            $name = $service->name ?? 'your service';

            $bodies = array(
                'COMPLETED' => 'Order '.$order->public_id.' for '.$name.' is complete.',
                'PARTIAL'   => 'Order '.$order->public_id.' was partially delivered. '
                               .marvy_money($order->refunded_amount ?? '0', $order->currency ?? null).' has been refunded to your wallet.',
                'CANCELED'  => 'Order '.$order->public_id.' was cancelled and the charge returned to your wallet.',
                'REFUNDED'  => 'Order '.$order->public_id.' was refunded to your wallet.',
            );

            $this->ci->notificationservice->notify(
                $order->user_id, $types[$status], $bodies[$status],
                array('order_id' => $order->public_id, 'url' => 'dashboard/orders/'.$order->public_id),
                array(
                    'order_id'      => $order->public_id,
                    'service_name'  => $name,
                    'quantity'      => number_format((int)$order->quantity),
                    'charge'        => marvy_money($order->charge ?? '0', $order->currency ?? null),
                    'remains'       => (string)($order->remains ?? '0'),
                    'refund_amount' => marvy_money($order->refunded_amount ?? '0', $order->currency ?? null),
                )
            );
        } catch (Throwable $e) {
            log_message('error', 'order notification failed for '.$order->public_id.': '.$e->getMessage());
        }
    }

    private function notify_reseller_webhook($order, $status) {
        try {
            $this->ci->load->model('Setting_model');
            if (!isset($this->ci->Setting_model)) return;
            $url = trim((string)$this->ci->Setting_model->get('reseller_webhook_url', ''));
            if ($url === '' || stripos($url, 'https://') !== 0) return;
            $secret = (string)$this->ci->Setting_model->get('reseller_webhook_secret', '');
            $payload = json_encode(array(
                'event'    => 'order.status',
                'status'   => $status,
                'order_id' => $order->public_id,
                'user_id'  => (int)$order->user_id,
                'at'       => gmdate('c'),
            ));
            $headers = array('Content-Type: application/json', 'X-Marvy-Event: order.status');
            if ($secret !== '') {
                $headers[] = 'X-Marvy-Signature: sha256='.hash_hmac('sha256', $payload, $secret);
            }
            if (!class_exists('SecureHttpClient', false)) {
                require_once APPPATH.'libraries/SecureHttpClient.php';
            }
            $http = new SecureHttpClient();
            $http->post($url, $payload, $headers);
        } catch (Throwable $e) {
            log_message('error', 'reseller webhook failed: '.$e->getMessage());
        }
    }

    /**
     * Return whatever of the charge has not already been refunded.
     *
     * Idempotent twice over: the amount is computed as charge minus
     * refunded_amount (so a PARTIAL that already refunded its undelivered share
     * only gives back the rest), and the ledger movement carries a
     * deterministic key, so a retry credits nothing a second time.
     *
     * @return string the amount refunded ('0.00000000' when there was nothing to give back)
     */
    private function refund_charge($order, $source = 'SYSTEM') {
        if (!$order) return '0.00000000';
        $already = (string)($order->refunded_amount ?? '0');
        $outstanding = bcsub((string)$order->charge, $already, 8);
        if (bccomp($outstanding, '0', 8) <= 0) return '0.00000000';

        $wallet = $this->ci->Wallet_model->for_user($order->user_id);
        if (!$wallet) {
            log_message('error', 'refund skipped: no wallet for user '.$order->user_id);
            return '0.00000000';
        }
        $result = $this->ci->ledgerservice->refund(
            $wallet->id, $outstanding, 'ORDER', $order->public_id,
            'order:refund:'.$order->public_id
        );
        if (empty($result['ok'])) {
            log_message('error', 'order refund failed for '.$order->public_id.': '.($result['error'] ?? 'unknown'));
            return '0.00000000';
        }
        // A duplicate means an earlier run already credited this order; leave
        // refunded_amount as it stands rather than double-counting it.
        if (empty($result['duplicate'])) {
            $this->ci->db->where('id', $order->id)->update('orders', array(
                'refunded_amount' => bcadd($already, $outstanding, 8),
                'updated_at'      => gmdate('Y-m-d H:i:s'),
            ));
        }
        return $outstanding;
    }

    private function apply_partial($order, $remains, $source, $reason) {
        if (!OrderStateMachine::can($order->status, 'PARTIAL')) {
            return array('ok'=>false,'error'=>'Cannot mark PARTIAL from '.$order->status);
        }
        $this->transition($order->id, $order->status, 'PARTIAL', $source, $reason);
        $update = array('remains'=>(int)$remains);
        // Refund the undelivered share proportionally, unless the operator has
        // switched automatic partial refunds off (settings `partial_refund_enabled`);
        // in that case the amount stays charged until staff refund it manually.
        if ($this->partial_refund_enabled()) {
            if ($order->quantity > 0 && $remains > 0 && $remains < (int)$order->quantity) {
                $refund = bcmul($order->charge, bcdiv((string)$remains, (string)$order->quantity, 8), 8);
                if (bccomp($refund, '0', 8) > 0) {
                    $wallet = $this->ci->Wallet_model->for_user($order->user_id);
                    $this->ci->ledgerservice->refund($wallet->id, $refund, 'ORDER', $order->public_id,
                        'order:partial:'.$order->public_id);
                    $update['refunded_amount'] = $refund;
                }
            }
        } else {
            $update['note'] = 'Partial delivery recorded; refund pending manual review';
        }
        $this->ci->db->where('id',$order->id)->update('orders', $update);
        $order = $this->ci->Order_model->find_by_id($order->id);
        $this->sync_affiliate($order);
        $this->notify_customer($order, 'PARTIAL');
        return array('ok'=>true, 'order'=>$order);
    }

    /**
     * Whether partial deliveries auto-refund their undelivered share
     * (settings `partial_refund_enabled`, default on).
     */
    private function partial_refund_enabled() {
        return $this->setting_flag('partial_refund_enabled');
    }

    /**
     * Keep referral commissions in step with the order's final state
     * (Session 14). Accrues on COMPLETED/PARTIAL, reverses unpaid commissions
     * when the order ends up canceled/refunded/failed. Never fatal: an
     * affiliate bookkeeping error must not fail an order status update.
     */
    private function sync_affiliate($order) {
        if (!$order) return;
        try {
            $this->ci->load->library('AffiliateService');
            if (!isset($this->ci->affiliateservice)
                || !method_exists($this->ci->affiliateservice, 'record_for_order')) {
                return;
            }
            if (in_array($order->status, array('COMPLETED','PARTIAL'), true)) {
                $this->ci->affiliateservice->record_for_order($order);
            } elseif (in_array($order->status, array('CANCELED','CANCELLED','REFUNDED','FAILED'), true)) {
                $this->ci->affiliateservice->reverse_for_order($order);
            }
        } catch (Exception $e) {
            log_message('error', 'affiliate sync failed: '.$e->getMessage());
        }
    }

    /* -------------------------------------------------------------- */
    /* internals                                                      */
    /* -------------------------------------------------------------- */

    private function resolve_user($id) {
        return $this->ci->db->where('id', $id)->where('status', 'ACTIVE')->get('users')->row();
    }

    private function resolve_service($input) {
        if (!empty($input['service_public_id'])) {
            return $this->ci->Service_model->find_by_public_id($input['service_public_id']);
        }
        if (!empty($input['service'])) {
            $v = $input['service'];
            if (ctype_digit((string)$v)) return $this->ci->Service_model->find_by_id((int)$v);
            $service = $this->ci->Service_model->find_by_slug((string)$v);
            return $service ?: $this->ci->Service_model->find_by_public_id((string)$v);
        }
        return null;
    }

    private function validate_quantity($q, $service) {
        if ($q <= 0) return 'Quantity must be greater than zero';
        if ($q < (int)$service->min_quantity) return 'Minimum quantity is '.number_format($service->min_quantity);
        if ($q > (int)$service->max_quantity) return 'Maximum quantity is '.number_format($service->max_quantity);
        $step = (int)($service->increment_step ?: 1);
        if ($step > 1 && ($q % $step) !== 0) return 'Quantity must be a multiple of '.$step;
        return null;
    }

    private function valid_link($link) {
        if ($link === '' || strlen($link) > 2048) return false;
        if (!filter_var($link, FILTER_VALIDATE_URL)) return false;
        $scheme = strtolower((string)parse_url($link, PHP_URL_SCHEME));
        if (!in_array($scheme, array('http','https'), true)) return false;
        $host = parse_url($link, PHP_URL_HOST);
        if (!$host || $host === 'localhost' || $host === '127.0.0.1') return false;
        return true;
    }

    private function normalise_idem($key, $user) {
        if (!$key) return null;
        $key = substr(preg_replace('/[^a-zA-Z0-9._\-]/', '', (string)$key), 0, 100);
        return $key !== '' ? $key : null;
    }

    private function persist_order($user, $service, $ctx) {
        // Persist inside a transaction; the wallet charge already committed in
        // the ledger, but we still want the order + history atomically.
        $this->ci->db->trans_start();
        $public_id = marvy_public_id();
        $this->ci->db->insert('orders', array(
            'public_id'         => $public_id,
            'user_id'           => $user->id,
            'service_id'        => $service->id,
            'provider_id'       => $service->provider_id,
            'provider_service_id' => $service->provider_service_id,
            'status'            => 'PENDING',
            'link'              => $ctx['link'],
            'quantity'          => $ctx['q'],
            'charge'            => $ctx['charge'],
            'rate_at_order'     => $ctx['rate'],
            'provider_charge'   => $ctx['provider_charge'],
            'currency'          => marvy_base_currency(),
            'fields'            => !empty($ctx['input']['fields']) ? json_encode($ctx['input']['fields']) : null,
            'source'            => $ctx['input']['source'] ?? 'WEB',
            'note'              => $ctx['input']['note'] ?? null,
            // Link a child order back to the schedule that produced it, so a
            // drip-feed/subscription run is traceable from the order row.
            'dripfeed_order_id'   => $ctx['input']['dripfeed_order_id'] ?? null,
            'dripfeed_run_number' => $ctx['input']['dripfeed_run_number'] ?? null,
            'subscription_id'     => $ctx['input']['subscription_id'] ?? null,
            'idempotency_key'   => $ctx['idem'],
            'created_at'        => gmdate('Y-m-d H:i:s'),
        ));
        $order_id = $this->ci->db->insert_id();
        $this->ci->Order_status_history_model->record(
            $order_id, null, 'PENDING', 'SYSTEM', 'Order created', null
        );
        $this->ci->db->trans_complete();
        if ($this->ci->db->trans_status() === false) return null;
        return $this->ci->Order_model->find_by_id($order_id);
    }

    /**
     * Whether orders auto-submit to the provider (settings `order_auto_submit`,
     * default on). Fail open so an unreadable setting never strands orders.
     */
    private function auto_submit_enabled() {
        return $this->setting_flag('order_auto_submit');
    }

    /**
     * Read a boolean setting, defaulting to on.
     *
     * load->model() succeeding does not guarantee the property exists — under
     * a test double, or a loader that resolved the model onto a different
     * name, reading $this->ci->Setting_model raises "Undefined property"
     * rather than throwing, so a try/catch never fires and the warning leaks
     * into output. Check the property before touching it.
     *
     * Fails open: an unreadable setting must never strand orders.
     */
    private function setting_flag($key) {
        try {
            $this->ci->load->model('Setting_model');
            if (!isset($this->ci->Setting_model) || !is_object($this->ci->Setting_model)) {
                return true;
            }
            $v = $this->ci->Setting_model->get($key, true);
        } catch (Throwable $e) {
            return true;
        }
        if ($v === null || $v === '') return true;
        if (is_bool($v)) return $v;
        return in_array(strtolower(trim((string)$v)), array('1','true','yes','on'), true);
    }

    /**
     * Manually submit a held (PENDING) order to its provider — the staff action
     * behind the `order_auto_submit` review queue. Mirrors the inline path in
     * place(): on success the order moves PENDING → PROCESSING with provider
     * fields set; on a provider rejection it fails and refunds the charge.
     *
     * @param object|string $order the order object or its public id
     */
    public function manually_submit($order, $staff_user = null) {
        $order = is_object($order) ? $order : $this->ci->Order_model->find_by_public_id((string)$order);
        if (!$order) return array('ok' => false, 'error' => 'Order not found', 'code' => 'NO_ORDER');
        if ($order->status !== 'PENDING') {
            return array('ok' => false, 'error' => 'Only PENDING orders can be submitted', 'code' => 'NOT_PENDING');
        }

        $service = $this->ci->Service_model->find_by_id((int)$order->service_id);
        if (!$service) return array('ok' => false, 'error' => 'Service not found', 'code' => 'NO_SERVICE');

        $fields = array();
        if (!empty($order->fields)) {
            $decoded = json_decode($order->fields, true);
            if (is_array($decoded)) $fields = $decoded;
        }
        $input = array('fields' => $fields, 'source' => $order->source ?? 'WEB');

        $submit = $this->submit_to_provider($order, $service, $order->link, (int)$order->quantity, $input);
        if (!empty($submit['ok']) && !empty($submit['submitted'])) {
            $this->transition($order->id, 'PENDING', 'PROCESSING', $staff_user ? 'ADMIN' : 'SYSTEM', 'Submitted to provider');
            $this->ci->db->where('id', $order->id)->update('orders', array(
                'provider_id'        => $service->provider_id,
                'provider_service_id'=> $service->provider_service_id,
                'provider_order_id'  => $submit['provider_order_id'],
                'submitted_at'       => gmdate('Y-m-d H:i:s'),
                'note'               => null,
            ));
            return array('ok' => true, 'order' => $this->ci->Order_model->find_by_id($order->id));
        }

        if (!empty($submit['ok'])) {
            // No provider configured/active: the order legitimately stays
            // PENDING so a worker/admin can route it later.
            return array('ok' => false, 'error' => 'No active provider is configured for this service', 'code' => 'NO_PROVIDER');
        }

        // Provider rejected the order: fail it and refund the charge, exactly
        // as the inline path would have.
        $this->transition($order->id, 'PENDING', 'FAILED', $staff_user ? 'ADMIN' : 'SYSTEM', $submit['error'] ?? 'Provider submission failed');
        $this->refund_charge($order, $staff_user ? 'ADMIN' : 'SYSTEM');
        $this->ci->db->where('id', $order->id)->update('orders', array(
            'note' => $submit['error'] ?? 'Provider submission failed',
        ));
        return array('ok' => false, 'order' => $this->ci->Order_model->find_by_id($order->id),
                     'error' => $submit['error'] ?? 'Submission failed', 'code' => 'SUBMIT_FAILED');
    }

    private function submit_to_provider($order, $service, $link, $quantity, $input) {
        // Without a configured provider we keep the order in PENDING so a
        // worker/admin can route it later. This keeps the flow usable in demo.
        if (empty($service->provider_id)) {
            return array('ok' => true, 'submitted' => false, 'provider_order_id' => null);
        }
        try {
            $provider = $this->ci->Provider_model->find_by_id($service->provider_id);
            if (!$provider || $provider->status !== 'ACTIVE') {
                return array('ok' => true, 'submitted' => false, 'provider_order_id' => null);
            }
            $adapter = $this->ci->providersyncservice->adapter($provider);
            $payload = array(
                'service'  => $service->provider_service_id,
                'link'     => $link,
                'quantity' => $quantity,
            );
            if (!empty($input['fields'])) $payload = array_merge($payload, (array)$input['fields']);
            $res = $adapter->createOrder($payload);
            if (empty($res['ok'])) {
                return array('ok' => false, 'error' => $res['error'] ?? 'Provider rejected the order');
            }
            return array('ok' => true, 'submitted' => true, 'provider_order_id' => $res['provider_order_id'] ?? null);
        } catch (Exception $e) {
            return array('ok' => false, 'error' => $e->getMessage());
        }
    }

    /**
     * Move an order to a new state: validate the transition, write the order
     * row and append the history entry — the two must always happen together,
     * or `orders.status` and `order_status_history` drift apart (§26/29).
     */
    private function transition($order_id, $from, $to, $source, $reason = null) {
        OrderStateMachine::assert($from, $to);
        $this->ci->db->where('id', $order_id)->update('orders', array(
            'status'     => $to,
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ));
        $this->ci->Order_status_history_model->record($order_id, $from, $to, $source, $reason);
    }

    private function service_supports($service_id, $flag) {
        $row = $this->ci->db->select($flag)->where('id', $service_id)->get('services')->row();
        return $row ? (int)$row->$flag === 1 : false;
    }

    /**
     * Tell the referral system this customer placed an order.
     *
     * FIRST_ORDER is the default qualifying event, so this is the hook that
     * turns most referrals into earnings. Never fatal: the order is already
     * placed and paid for.
     */
    private function referral_first_order($user) {
        try {
            $this->ci->load->library('ReferralService');
            // load->library() succeeding does not guarantee the property
            // exists under a test double; reading it blind raises a warning
            // that a try/catch cannot see. This method returns nothing, so an
            // early return here is safe.
            if (!isset($this->ci->referralservice)) return;
            $this->ci->referralservice->record_event($user->id, 'FIRST_ORDER');
        } catch (Throwable $e) {
            log_message('error', 'referral FIRST_ORDER hook failed: '.$e->getMessage());
        }
    }

    private function notify($user, $order) {
        $this->referral_first_order($user);
        try {
            $this->ci->load->model('Notification_model');
            $this->ci->db->insert('notifications', array(
                'public_id' => marvy_public_id(),
                'user_id'   => $user->id,
                'type'      => 'order.created',
                'channel'   => 'IN_APP',
                'title'     => 'Order placed',
                'body'      => 'Your order #'.$order->public_id.' was placed successfully.',
                'data'      => json_encode(array('order_id' => $order->public_id)),
                'created_at'=> gmdate('Y-m-d H:i:s'),
            ));
        } catch (Exception $e) {
            log_message('error', 'order notify failed: '.$e->getMessage());
        }
    }
}
