<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Subscription_event_model extends MY_Model {
    protected $table = 'subscription_events';

    public function for_subscription($subscription_id) {
        return $this->db->where('subscription_id', $subscription_id)
            ->order_by('created_at', 'DESC')->get($this->table)->result();
    }
}
