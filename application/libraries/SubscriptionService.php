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
        ));
        $this->ci->load->library(array('PricingService'));
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
