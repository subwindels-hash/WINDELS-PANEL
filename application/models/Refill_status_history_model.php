<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Refill_status_history_model extends MY_Model {
    protected $table = 'refill_status_history';

    public function for_refill($refill_id) {
        return $this->db->where('refill_id', $refill_id)
            ->order_by('created_at', 'ASC')->get()->result();
    }

    public function record($refill_id, $previous, $new, $source) {
        return $this->db->insert($this->table, array(
            'refill_id' => $refill_id,
            'previous_status' => $previous,
            'new_status' => $new,
            'source' => $source,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ));
    }
}
