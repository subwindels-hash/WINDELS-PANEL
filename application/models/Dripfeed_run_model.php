<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Dripfeed_run_model extends MY_Model {
    protected $table = 'dripfeed_runs';

    public function for_dripfeed($dripfeed_id) {
        return $this->db->where('dripfeed_order_id', $dripfeed_id)
            ->order_by('run_number', 'ASC')->get($this->table)->result();
    }

    public function next_pending($dripfeed_id) {
        return $this->db->where('dripfeed_order_id', $dripfeed_id)
            ->where('status', 'PENDING')->order_by('run_number', 'ASC')->limit(1)->get($this->table)->row();
    }
}
