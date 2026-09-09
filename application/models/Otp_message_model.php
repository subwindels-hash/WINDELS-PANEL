<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Codes delivered to a rented number (§11). Append-only.
 *
 * A vendor returns its whole inbox on every poll, so recording is idempotent
 * on the vendor's own message id: the second poll of a number that has one
 * SMS must still leave one row, or the customer sees their code six times and
 * cannot tell which is current.
 */
class Otp_message_model extends MY_Model {
    protected $table = 'otp_messages';

    /**
     * Store one SMS if it is new.
     *
     * @return bool TRUE when a row was written, FALSE when it was a repeat.
     */
    public function record($virtual_number_id, array $sms){
        $provider_message_id = isset($sms['id']) && $sms['id'] !== '' ? (string)$sms['id'] : null;

        if ($provider_message_id !== null && $this->exists($virtual_number_id, $provider_message_id)) {
            return false;
        }
        // Without a vendor id there is nothing to be idempotent on, so fall
        // back to the body: a repeated poll of the same SMS must not duplicate.
        if ($provider_message_id === null && $this->body_seen($virtual_number_id, $sms['text'] ?? '')) {
            return false;
        }

        $this->db->insert($this->table, array(
            'virtual_number_id'   => $virtual_number_id,
            'provider_message_id' => $provider_message_id,
            'sender'              => isset($sms['sender']) ? mb_substr((string)$sms['sender'], 0, 64) : null,
            'body'                => isset($sms['text']) ? (string)$sms['text'] : null,
            'code'                => isset($sms['code']) && $sms['code'] !== ''
                                        ? mb_substr((string)$sms['code'], 0, 32) : null,
            'received_at'         => $sms['received_at'] ?? $this->now_utc(),
            'created_at'          => $this->now_utc(),
        ));
        return true;
    }

    public function exists($virtual_number_id, $provider_message_id){
        return (bool)$this->db->where('virtual_number_id',$virtual_number_id)
                              ->where('provider_message_id',$provider_message_id)
                              ->get($this->table)->row();
    }

    public function for_number($virtual_number_id){
        return $this->db->where('virtual_number_id',$virtual_number_id)
                        ->order_by('created_at','ASC')->get($this->table)->result();
    }

    public function count_for_number($virtual_number_id){
        return (int)$this->db->where('virtual_number_id',$virtual_number_id)
                             ->count_all_results($this->table);
    }

    private function body_seen($virtual_number_id, $body){
        if ($body === '') return false;
        foreach ($this->for_number($virtual_number_id) as $row) {
            if ((string)$row->body === (string)$body) return true;
        }
        return false;
    }
}
