<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Payment_webhook_model extends MY_Model {
    protected $table = 'payment_webhooks';

    /**
     * Returns FALSE when this gateway event was already stored AND fully
     * processed (§64 idempotency). An existing row that is still unprocessed
     * is a previous transient failure: its id is returned again so the
     * retried delivery re-runs processing (confirm() is itself idempotent).
     *
     * One exception matters for money. A stored row that was closed as
     * "invalid signature" (or as unverifiable) is NOT proof the event was
     * handled — it is proof it was refused. Gateway event ids are guessable
     * (Paystack's are sequential integers), so treating such a row as a
     * duplicate would let anyone pre-register an id with a junk signature and
     * make the genuine, correctly signed callback for that id be silently
     * dropped: the customer pays and is never credited. When a later delivery
     * of the same id arrives with a VALID signature, the refusal is reopened
     * and processed.
     */
    public function record_once($gateway_type, $event_id, $payload, $signature_valid=NULL, $event_type=NULL){
        if ($event_id) {
            $existing = $this->db->where(array('gateway_type'=>$gateway_type,'event_id'=>$event_id))->get($this->table)->row();
            if ($existing) {
                if ((int)$existing->processed === 0) return (int)$existing->id;
                if ($signature_valid === TRUE && (int)$existing->signature_valid !== 1) {
                    $this->db->where('id', $existing->id)->update($this->table, array(
                        'payload'         => is_string($payload) ? $payload : json_encode($payload),
                        'signature_valid' => 1,
                        'processed'       => 0,
                        'processed_at'    => NULL,
                        'error'           => NULL,
                        'event_type'      => $event_type,
                    ));
                    log_message('error', 'payments: reopened previously refused webhook '
                        .$gateway_type.'/'.$event_id.' — this delivery is correctly signed');
                    return (int)$existing->id;
                }
                return FALSE;
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
