<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Order_status_history_model extends MY_Model {
    protected $table = 'order_status_history';

    public function for_order($order_id){
        return $this->db->where('order_id',$order_id)->order_by('created_at','ASC')->get($this->table)->result();
    }
    /** Append-only: every status change must be recorded with its source (§26/29). */
    public function record($order_id, $previous, $new, $source, $reason=NULL, $actor_id=NULL, $provider_status=NULL){
        return $this->db->insert($this->table, array(
            'order_id'=>$order_id, 'previous_status'=>$previous, 'new_status'=>$new,
            'source'=>$source, 'reason'=>$reason, 'actor_id'=>$actor_id,
            'provider_status'=>$provider_status, 'created_at'=>$this->now_utc(),
        ));
    }
}
