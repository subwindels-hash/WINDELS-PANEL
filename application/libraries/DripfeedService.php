<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * DripfeedService — splits a large order into scheduled child orders (Session 10).
 *
 * The total charge is reserved up-front and held in a dripfeed_orders row; each
 * child order is placed via OrderService by the cron worker and the runs are
 * tracked in dripfeed_runs. This library validates the request, computes the
 * schedule, reserves the wallet charge, and creates the parent + run rows.
 */
class DripfeedService {

    const MIN_INTERVAL_MINUTES = 5;
    const MAX_RUNS = 100;

    private $ci;

    public function __construct() {
        $this->ci =& get_instance();
        $this->ci->load->model(array(
            'Service_model', 'Dripfeed_order_model', 'Dripfeed_run_model',
            'Wallet_model', 'Blacklist_model',
        ));
        $this->ci->load->library(array('PricingService', 'LedgerService', 'OrderService'));
    }

    /**
     * Create a dripfeed schedule.
     *
     * @param object $user
     * @param array $input service, link, total_quantity, quantity_per_run, runs, interval_minutes, start_at?
     * @return array{ok:bool,dripfeed?:object,error?:string,code?:string}
     */
    public function create($user, array $input) {
        $service = $this->resolve_service($input);
        if (!$service) return array('ok'=>false,'error'=>'Service not found','code'=>'NO_SERVICE');
        if ($service->status !== 'ACTIVE') return array('ok'=>false,'error'=>'Service unavailable','code'=>'SERVICE_INACTIVE');
        if (!(int)$service->dripfeed_supported) return array('ok'=>false,'error'=>'This service does not support drip-feed','code'=>'UNSUPPORTED');

        $total = (int)($input['total_quantity'] ?? 0);
        $per_run = (int)($input['quantity_per_run'] ?? 0);
        $runs = (int)($input['runs'] ?? 0);
        $interval = (int)($input['interval_minutes'] ?? 0);
        $link = trim((string)($input['link'] ?? ''));

        if ($per_run <= 0 || $runs <= 1 || $runs > self::MAX_RUNS)
            return array('ok'=>false,'error'=>'Runs must be between 2 and '.self::MAX_RUNS,'code'=>'BAD_RUNS');
        if ($total !== $per_run * $runs)
            return array('ok'=>false,'error'=>'Total must equal quantity per run × runs','code'=>'BAD_TOTAL');
        if ($total < (int)$service->min_quantity || $total > (int)$service->max_quantity)
            return array('ok'=>false,'error'=>'Total must be between '.$service->min_quantity.' and '.$service->max_quantity,'code'=>'BAD_QUANTITY');
        if ($per_run < 1) return array('ok'=>false,'error'=>'Quantity per run is too small','code'=>'BAD_QUANTITY');
        if ($interval < self::MIN_INTERVAL_MINUTES)
            return array('ok'=>false,'error'=>'Interval must be at least '.self::MIN_INTERVAL_MINUTES.' minutes','code'=>'BAD_INTERVAL');
        if (!filter_var($link, FILTER_VALIDATE_URL) || !in_array(strtolower(parse_url($link, PHP_URL_SCHEME)), array('http','https')))
            return array('ok'=>false,'error'=>'A valid http(s) link is required','code'=>'BAD_LINK');
        if ($this->ci->Blacklist_model->text_contains_blacklisted_link($link))
            return array('ok'=>false,'error'=>'That link is not permitted','code'=>'BLACKLISTED');

        $rate = $this->ci->pricingservice->price_for($service, $user);
        $charge = $this->ci->pricingservice->charge_for_quantity($rate, $total);

        $wallet = $this->ci->Wallet_model->for_user($user->id);
        $idem = 'dripfeed:'.$user->id.':'.windels_public_id();
        $charged = $this->ci->ledgerservice->charge($wallet->id, $charge, 'DRIPFEED', null, $idem);
        if (empty($charged['ok'])) {
            $code = ($charged['error'] ?? '') === 'INSUFFICIENT_BALANCE' ? 'INSUFFICIENT_BALANCE' : 'CHARGE_FAILED';
            return array('ok'=>false,'error'=>$charged['error'] ?? 'Could not reserve charge','code'=>$code);
        }

        $start_at = !empty($input['start_at']) ? date('Y-m-d H:i:s', strtotime($input['start_at'])) : gmdate('Y-m-d H:i:s');
        $next_run_at = date('Y-m-d H:i:s', strtotime($start_at.' +'.$interval.' minutes'));

        $this->ci->db->trans_start();
        $public_id = windels_public_id();
        $this->ci->db->insert('dripfeed_orders', array(
            'public_id' => $public_id,
            'user_id' => $user->id,
            'service_id' => $service->id,
            'link' => $link,
            'total_quantity' => $total,
            'quantity_per_run' => $per_run,
            'runs' => $runs,
            'runs_completed' => 0,
            'interval_minutes' => $interval,
            'charge' => $charge,
            'currency' => 'USD',
            'fields' => !empty($input['fields']) ? json_encode($input['fields']) : null,
            'start_at' => $start_at,
            'next_run_at' => $next_run_at,
            'status' => 'ACTIVE',
            'created_at' => gmdate('Y-m-d H:i:s'),
        ));
        $drip_id = $this->ci->db->insert_id();
        for ($i = 1; $i <= $runs; $i++) {
            $this->ci->db->insert('dripfeed_runs', array(
                'dripfeed_order_id' => $drip_id,
                'run_number' => $i,
                'status' => 'PENDING',
                'created_at' => gmdate('Y-m-d H:i:s'),
            ));
        }
        $this->ci->db->trans_complete();
        if ($this->ci->db->trans_status() === false) {
            $this->ci->ledgerservice->refund($wallet->id, $charge, 'DRIPFEED', null, 'dripfeed:rollback:'.$idem);
            return array('ok'=>false,'error'=>'Could not create schedule','code'=>'PERSIST_FAILED');
        }

        $drip = $this->ci->Dripfeed_order_model->find_by_id($drip_id);
        return array('ok'=>true,'dripfeed'=>$drip);
    }

    public function pause($drip_public_id, $user) {
        $drip = $this->ci->Dripfeed_order_model->find_public_for_user($drip_public_id, $user->id);
        if (!$drip) return array('ok'=>false,'error'=>'Drip-feed not found','code'=>'NOT_FOUND');
        if (!in_array($drip->status, array('ACTIVE'), true)) return array('ok'=>false,'error'=>'Only active schedules can be paused','code'=>'BAD_STATE');
        $this->ci->db->where('id', $drip->id)->update('dripfeed_orders', array('status'=>'PAUSED'));
        return array('ok'=>true,'dripfeed'=>$this->ci->Dripfeed_order_model->find_by_id($drip->id));
    }

    public function resume($drip_public_id, $user) {
        $drip = $this->ci->Dripfeed_order_model->find_public_for_user($drip_public_id, $user->id);
        if (!$drip) return array('ok'=>false,'error'=>'Drip-feed not found','code'=>'NOT_FOUND');
        if ($drip->status !== 'PAUSED') return array('ok'=>false,'error'=>'Only paused schedules can be resumed','code'=>'BAD_STATE');
        $this->ci->db->where('id', $drip->id)->update('dripfeed_orders', array('status'=>'ACTIVE'));
        return array('ok'=>true,'dripfeed'=>$this->ci->Dripfeed_order_model->find_by_id($drip->id));
    }

    public function cancel($drip_public_id, $user) {
        $drip = $this->ci->Dripfeed_order_model->find_public_for_user($drip_public_id, $user->id);
        if (!$drip) return array('ok'=>false,'error'=>'Drip-feed not found','code'=>'NOT_FOUND');
        if (in_array($drip->status, array('CANCELED','COMPLETED'), true)) return array('ok'=>true,'dripfeed'=>$drip);

        // Refund the unspent reserve.
        $spent = $this->ci->db->select('COALESCE(SUM(o.charge),0) AS s', false)
            ->from('dripfeed_runs dr')->join('orders o', 'o.id = dr.order_id', 'left')
            ->where('dr.dripfeed_order_id', $drip->id)
            ->where('dr.status', 'COMPLETED')->get()->row();
        $spent_amt = $spent ? (string)$spent->s : '0.00000000';
        $refund = bcsub($drip->charge, $spent_amt, 8);
        if (bccomp($refund, '0', 8) > 0) {
            $wallet = $this->ci->Wallet_model->for_user($user->id);
            $this->ci->ledgerservice->refund($wallet->id, $refund, 'DRIPFEED', $drip->public_id, 'dripfeed:cancel:'.$drip->public_id);
        }
        $this->ci->db->where('id', $drip->id)->update('dripfeed_orders', array('status'=>'CANCELED'));
        $this->ci->db->where('dripfeed_order_id', $drip->id)->where('status','PENDING')
            ->update('dripfeed_runs', array('status'=>'CANCELED'));
        return array('ok'=>true,'dripfeed'=>$this->ci->Dripfeed_order_model->find_by_id($drip->id),'refund'=>$refund);
    }

    private function resolve_service($input) {
        if (!empty($input['service_public_id'])) return $this->ci->Service_model->find_by_public_id($input['service_public_id']);
        if (!empty($input['service'])) {
            $v = $input['service'];
            return ctype_digit((string)$v) ? $this->ci->Service_model->find_by_id((int)$v) : $this->ci->Service_model->find_by_slug((string)$v);
        }
        return null;
    }
}
