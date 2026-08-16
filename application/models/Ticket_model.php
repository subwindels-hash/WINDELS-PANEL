<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Ticket_model extends MY_Model {
    protected $table = 'tickets';

    public function for_user($user_id){
        return $this->db->where('user_id',$user_id)->order_by('updated_at','DESC')->get($this->table)->result();
    }
    public function open_queue(){
        return $this->db->where_in('status', array('OPEN','PENDING'))->order_by('priority','DESC')->order_by('created_at','ASC')->get($this->table)->result();
    }
}
