<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * SubscriptionService — recurring auto-order plans (Session 10).
 *
 * Creates a subscription row that the cron worker executes on its interval.
 * Payment is collected per run at execution time (no up-front reservation),
 * matching the "new subscribers per interval" model used by most SMM panels.
 */
class SubscriptionService {

    const INTERVALS = array('daily' => 1440, 'weekly' => 10080, 'monthly' => 43200);

    private $ci;

    public function __construct() {
        $this->ci =& get_instance();
        $this->ci->load->model(array(
            'Service_model', 'Subscription_model', 'Subscription_event_model', 'Blacklist_model',
            'User_model',
        ));
        $this->ci->load->library(array('PricingService', 'OrderService'));
    }

    public function create($user, array $input) {
        $service = $this->resolve($input);
        if (!$service) return array('ok'=>false,'error'=>'Service not found','code'=>'NO_SERVICE');
        if ($service->status !== 'ACTIVE') return array('ok'=>false,'error'=>'Service unavailable','code'=>'SERVICE_INACTIVE');
        if (!(int)$service->subscription_supported) return array('ok'=>false,'error'=>'This service does not support subscriptions','code'=>'UNSUPPORTED');

        $quantity = (int)($input['quantity'] ?? 0);
        if ($quantity < (int)$service->min_quantity || $quantity > (int)$service->max_quantity)
            return array('ok'=>false,'error'=>"Quantity must be between {$service->min_quantity} and {$service->max_quantity}",'code'=>'BAD_QUANTITY');

        $target = trim((string)($input['target'] ?? ''));
        if ($target === '' || strlen($target) > 500) return array('ok'=>false,'error'=>'A target profile or link is required','code'=>'BAD_TARGET');
        if (filter_var($target, FILTER_VALIDATE_URL) && !in_array(strtolower(parse_url($target, PHP_URL_SCHEME)), array('http','https')))
            return array('ok'=>false,'error'=>'Target must be http(s)','code'=>'BAD_TARGET');

        $interval_type = $input['interval_type'] ?? 'daily';
        if (!isset(self::INTERVALS[$interval_type])) return array('ok'=>false,'error'=>'Unsupported interval','code'=>'BAD_INTERVAL');
        $interval = (int)($input['interval_minutes'] ?? self::INTERVALS[$interval_type]);
        if ($interval < 60) return array('ok'=>false,'error'=>'Interval must be at least 60 minutes','code'=>'BAD_INTERVAL');

        $posts = isset($input['posts']) ? (int)$input['posts'] : null;
        $runs  = isset($input['runs']) ? (int)$input['runs'] : null;

        $start = !empty($input['start_at']) ? date('Y-m-d H:i:s', strtotime($input['start_at'])) : gmdate('Y-m-d H:i:s');
        $next  = date('Y-m-d H:i:s', strtotime($start.' +'.$interval.' minutes'));
        $expires = ($posts || $runs) ? date('Y-m-d H:i:s', strtotime($start.' +'.($interval*max(1,(int)($runs?:$posts))).' minutes')) : null;

        $this->ci->db->trans_start();
        $public_id = windels_public_id();
        $this->ci->db->insert('subscriptions', array(
            'public_id' => $public_id,
            'user_id' => $user->id,
            'service_id' => $service->id,
            'provider_id' => $service->provider_id,
            'target' => $target,
            'quantity' => $quantity,
            'posts' => $posts,
            'delay_minutes' => (int)($input['delay_minutes'] ?? 0),
            'interval_type' => $interval_type,
            'runs' => $runs,
            'runs_completed' => 0,
            'status' => 'ACTIVE',
            'start_at' => $start,
            'next_execution_at' => $next,
            'expires_at' => $expires,
            'metadata' => !empty($input['fields']) ? json_encode($input['fields']) : null,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ));
        $id = $this->ci->db->insert_id();
        $this->ci->db->insert('subscription_events', array(
            'subscription_id' => $id,
            'type' => 'created',
            'payload' => json_encode(array('interval'=>$interval,'quantity'=>$quantity)),
            'created_at' => gmdate('Y-m-d H:i:s'),
        ));
        $this->ci->db->trans_complete();
        if ($this->ci->db->trans_status() === false) return array('ok'=>false,'error'=>'Could not create subscription','code'=>'PERSIST_FAILED');

        return array('ok'=>true,'subscription'=>$this->ci->Subscription_model->find_by_id($id));
    }

    /**
     * Execute one due subscription run (called by the cron worker).
     *
     * Unlike drip-feed, a subscription is **not** prepaid: each run charges the
     * wallet at execution time, so an insufficient balance pauses the plan
     * rather than failing it permanently.
     *
     * Concurrency: the row is claimed by advancing next_execution_at with a
     * compare-and-set before any order is placed, so overlapping workers cannot
     * both run the same cycle. The order also carries a deterministic
     * idempotency key per (subscription, run number).
     *
     * @return array{ok:bool, skipped?:bool, order?:object, error?:string, code?:string}
     */
    public function execute_due($sub) {
        $sub = is_object($sub) ? $sub : $this->ci->Subscription_model->find_by_id((int)$sub);
        if (!$sub) return array('ok'=>false,'error'=>'Subscription not found','code'=>'NOT_FOUND');
        if ($sub->status !== 'ACTIVE') return array('ok'=>true,'skipped'=>true,'reason'=>'not active');

        $now = gmdate('Y-m-d H:i:s');
        if ($sub->next_execution_at !== null && $sub->next_execution_at > $now) {
            return array('ok'=>true,'skipped'=>true,'reason'=>'not due');
        }
        // Expired or out of runs: close it out instead of ordering again.
        if ($sub->expires_at !== null && $sub->expires_at <= $now) {
            $this->close($sub, 'EXPIRED', 'Subscription reached its end date');
            return array('ok'=>true,'skipped'=>true,'reason'=>'expired');
        }
        if ($sub->runs !== null && (int)$sub->runs_completed >= (int)$sub->runs) {
            $this->close($sub, 'COMPLETED', 'All runs completed');
            return array('ok'=>true,'skipped'=>true,'reason'=>'all runs completed');
        }

        $interval = (int)(self::INTERVALS[$sub->interval_type] ?? 1440);
        $next     = gmdate('Y-m-d H:i:s', time() + ($interval * 60));

        // Claim this cycle by moving the clock forward first.
        $this->ci->db->where('id', $sub->id)
            ->where('next_execution_at', $sub->next_execution_at)
            ->update('subscriptions', array('next_execution_at' => $next));
        if ((int)$this->ci->db->affected_rows() !== 1) {
            return array('ok'=>true,'skipped'=>true,'reason'=>'claimed by another worker');
        }

        $run_number = (int)$sub->runs_completed + 1;
        $user = $this->ci->User_model->find_by_id($sub->user_id);
        if (!$user) {
            $this->event($sub->id, 'failed', array('error'=>'user not found'));
            return array('ok'=>false,'error'=>'User not found','code'=>'NO_USER');
        }

        $result = $this->ci->orderservice->place($user, array(
            'service'         => $sub->service_id,
            'link'            => $sub->target,
            'quantity'        => (int)$sub->quantity,
            'source'          => 'SUBSCRIPTION',
            'subscription_id' => $sub->id,
            'fields'          => $sub->metadata ? json_decode($sub->metadata, true) : null,
            'idempotency_key' => 'subscription:'.$sub->public_id.':run:'.$run_number,
        ));

        if (empty($result['ok'])) {
            $code = $result['code'] ?? 'RUN_FAILED';
            // No funds is a recoverable condition: pause so the customer can
            // top up and resume, rather than burning the remaining runs.
            if ($code === 'INSUFFICIENT_BALANCE') {
                $this->ci->db->where('id', $sub->id)->update('subscriptions', array('status'=>'PAUSED'));
                $this->event($sub->id, 'paused', array('reason'=>'insufficient balance'));
                return array('ok'=>false,'error'=>'Insufficient balance','code'=>$code,'paused'=>true);
            }
            $this->event($sub->id, 'failed', array('error'=>$result['error'] ?? 'order failed'));
            return array('ok'=>false,'error'=>$result['error'] ?? 'Order failed','code'=>$code);
        }

        $order     = $result['order'];
        $completed = $run_number;
        $finished  = $sub->runs !== null && $completed >= (int)$sub->runs;

        $this->ci->db->where('id', $sub->id)->update('subscriptions', array(
            'runs_completed'    => $completed,
            'status'            => $finished ? 'COMPLETED' : 'ACTIVE',
            'next_execution_at' => $finished ? null : $next,
            'updated_at'        => gmdate('Y-m-d H:i:s'),
        ));
        $this->event($sub->id, 'executed', array('order'=>$order->public_id,'run'=>$completed));

        return array('ok'=>true,'order'=>$order,'run'=>$completed,'finished'=>$finished);
    }

    private function close($sub, $status, $reason) {
        $this->ci->db->where('id', $sub->id)->update('subscriptions', array(
            'status' => $status, 'next_execution_at' => null,
        ));
        $this->event($sub->id, strtolower($status), array('reason'=>$reason));
    }

    private function event($subscription_id, $type, array $payload = array()) {
        $this->ci->db->insert('subscription_events', array(
            'subscription_id' => $subscription_id,
            'type'            => $type,
            'payload'         => json_encode($payload),
            'created_at'      => gmdate('Y-m-d H:i:s'),
        ));
    }

    public function pause($public_id, $user) {
        $sub = $this->ci->Subscription_model->find_public_for_user($public_id, $user->id);
        if (!$sub) return array('ok'=>false,'error'=>'Subscription not found','code'=>'NOT_FOUND');
        if ($sub->status !== 'ACTIVE') return array('ok'=>false,'error'=>'Only active subscriptions can be paused','code'=>'BAD_STATE');
        $this->ci->db->where('id',$sub->id)->update('subscriptions', array('status'=>'PAUSED'));
        return array('ok'=>true,'subscription'=>$this->ci->Subscription_model->find_by_id($sub->id));
    }

    public function resume($public_id, $user) {
        $sub = $this->ci->Subscription_model->find_public_for_user($public_id, $user->id);
        if (!$sub) return array('ok'=>false,'error'=>'Subscription not found','code'=>'NOT_FOUND');
        if ($sub->status !== 'PAUSED') return array('ok'=>false,'error'=>'Only paused subscriptions can be resumed','code'=>'BAD_STATE');
        $this->ci->db->where('id',$sub->id)->update('subscriptions', array('status'=>'ACTIVE'));
        return array('ok'=>true,'subscription'=>$this->ci->Subscription_model->find_by_id($sub->id));
    }

    public function cancel($public_id, $user) {
        $sub = $this->ci->Subscription_model->find_public_for_user($public_id, $user->id);
        if (!$sub) return array('ok'=>false,'error'=>'Subscription not found','code'=>'NOT_FOUND');
        if (in_array($sub->status, array('CANCELED','EXPIRED'), true)) return array('ok'=>true,'subscription'=>$sub);
        $this->ci->db->where('id',$sub->id)->update('subscriptions', array('status'=>'CANCELED'));
        return array('ok'=>true,'subscription'=>$this->ci->Subscription_model->find_by_id($sub->id));
    }

    private function resolve($input) {
        if (!empty($input['service_public_id'])) return $this->ci->Service_model->find_by_public_id($input['service_public_id']);
        if (!empty($input['service'])) {
            $v = $input['service'];
            return ctype_digit((string)$v) ? $this->ci->Service_model->find_by_id((int)$v) : $this->ci->Service_model->find_by_slug((string)$v);
        }
        return null;
    }
}
