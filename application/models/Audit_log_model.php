<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Audit_log_model extends MY_Model {
    protected $table = 'audit_logs';

    /** Append-only audit trail for sensitive actions (§61). */
    public function record($actor_id, $action, $resource, $resource_id=NULL, $before=NULL, $after=NULL, $ip=NULL, $user_agent=NULL, $request_id=NULL){
        return $this->db->insert($this->table, array(
            'actor_id'=>$actor_id, 'action'=>$action, 'resource'=>$resource, 'resource_id'=>$resource_id,
            'before_json'=> $before === NULL ? NULL : json_encode($before),
            'after_json'=> $after === NULL ? NULL : json_encode($after),
            'ip'=>$ip, 'user_agent'=>$user_agent, 'request_id'=>$request_id,
            'created_at'=>$this->now_utc(),
        ));
    }
}
