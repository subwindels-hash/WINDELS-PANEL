<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Payment_webhook_model extends MY_Model {
    protected $table = 'payment_webhooks';

    /**
     * Returns FALSE when this gateway event was already stored AND fully
     * processed (§64 idempotency). An existing row that is still unprocessed
     * is a previous transient failure: its id is returned again so the
     * retried delivery re-runs processing (confirm() is itself idempotent).
     */
    public function record_once($gateway_type, $event_id, $payload, $signature_valid=NULL, $event_type=NULL){
        if ($event_id) {
            $existing = $this->db->where(array('gateway_type'=>$gateway_type,'event_id'=>$event_id))->get($this->table)->row();
            if ($existing) {
                return (int)$existing->processed === 0 ? (int)$existing->id : FALSE;
            }
        }
        $this->db->insert($this->table, array(
            'gateway_type'=>$gateway_type, 'event_id'=>$event_id, 'event_type'=>$event_type,
            'payload'=> is_string($payload) ? $payload : json_encode($payload),
            'signature_valid'=> $signature_valid === NULL ? NULL : (int)$signature_valid,
            'created_at'=>$this->now_utc(),
        ));
        return $this->db->insert_id();
    }
    public function unprocessed($limit=100){
        return $this->db->where('processed',0)->order_by('created_at','ASC')->limit($limit)->get($this->table)->result();
    }
}
