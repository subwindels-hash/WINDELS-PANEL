<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Notification_model extends MY_Model {
    protected $table = 'notifications';

    public function unread_for_user($user_id, $limit=20){
        return $this->db->where(array('user_id'=>$user_id,'is_read'=>0))->order_by('created_at','DESC')->limit($limit)->get($this->table)->result();
    }
    public function mark_read($user_id, $public_id=NULL){
        $this->db->where('user_id',$user_id);
        if ($public_id) $this->db->where('public_id',$public_id);
        return $this->db->update($this->table, array('is_read'=>1,'read_at'=>$this->now_utc()));
    }
}
