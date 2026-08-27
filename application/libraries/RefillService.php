<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * RefillService — customer-initiated refills (Session 10).
 *
 * A refill is allowed when the order's service supports it and the order is in
 * a refillable state (COMPLETED/PARTIAL). It creates a `refills` row, calls the
 * provider adapter's requestRefill(), records provider_refill_id and writes
 * history. Duplicate active refills for the same order are rejected.
 */
class RefillService {

    private $ci;

    public function __construct() {
        $this->ci =& get_instance();
        $this->ci->load->model(array('Order_model','Refill_model','Refill_status_history_model','Provider_model'));
        $this->ci->load->library(array('ProviderSyncService'));
    }

    /**
     * Request a refill for a customer's order.
     *
     * @return array{ok:bool,refill?:object,error?:string,code?:string}
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

        // Call the provider when one is configured; otherwise create the row in
        // PENDING so a worker can submit it.
        $provider_refill_id = null;
        $status = 'PENDING';
        $error = null;
        if ($order->provider_id) {
            $provider = $this->ci->Provider_model->find_by_id($order->provider_id);
            if ($provider && $provider->status === 'ACTIVE' && $order->provider_order_id) {
                try {
                    $adapter = $this->ci->providersyncservice->adapter($provider);
                    $res = $adapter->requestRefill($order->provider_order_id);
                    if (!empty($res['ok']) && !empty($res['provider_refill_id'])) {
                        $provider_refill_id = (string)$res['provider_refill_id'];
                        $status = 'PROCESSING';
                    } else {
                        $error = $res['error'] ?? 'Provider rejected the refill';
                    }
                } catch (Exception $e) {
                    $error = $e->getMessage();
                }
            }
        }

        $refill = $this->persist($order, $user->id, $provider_refill_id, $status, $error);
        return array('ok'=>true,'refill'=>$refill);
    }

    private function persist($order, $user_id, $provider_refill_id, $status, $error) {
        $this->ci->db->trans_start();
        $public_id = marvy_public_id();
        $this->ci->db->insert('refills', array(
            'public_id'          => $public_id,
            'order_id'           => $order->id,
            'provider_id'        => $order->provider_id,
            'provider_refill_id' => $provider_refill_id,
            'status'             => $status,
            'requested_by_id'    => $user_id,
            'error'              => $error,
            'requested_at'       => gmdate('Y-m-d H:i:s'),
            'last_checked_at'    => gmdate('Y-m-d H:i:s'),
        ));
        $id = $this->ci->db->insert_id();
        $this->ci->Refill_status_history_model->record($id, null, $status, 'CUSTOMER');
        $this->ci->db->trans_complete();
        return $this->ci->Refill_model->find_by_id($id);
    }

    private function service_flag($service_id, $flag) {
        $row = $this->ci->db->select($flag)->where('id', $service_id)->get('services')->row();
        return $row ? (int)$row->$flag === 1 : false;
    }
}
