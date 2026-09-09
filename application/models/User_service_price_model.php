<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class User_service_price_model extends MY_Model {
    protected $table = 'user_service_prices';

    public function for_user($user_id, $service_id){
        return $this->db->where(array('user_id'=>$user_id,'service_id'=>$service_id))->get($this->table)->row();
    }

    /** Recent per-customer overrides, bounded for the service editor. */
    public function for_service_with_users($service_id, $limit=50){
        return $this->db->select('user_service_prices.id, user_service_prices.rate,
                users.public_id AS user_public_id, users.username, users.email', false)
            ->from($this->table)
            ->join('users', 'users.id = user_service_prices.user_id', 'inner')
            ->where('user_service_prices.service_id', (int)$service_id)
            ->order_by('users.username','ASC')
            ->limit(max(1, min(100, (int)$limit)))->get()->result();
    }

    public function put($user_id, $service_id, $rate){
        $existing = $this->for_user($user_id, $service_id);
        $data = array('rate'=>$rate, 'updated_at'=>gmdate('Y-m-d H:i:s'));
        if ($existing) {
            $this->db->where('id',$existing->id)->update($this->table,$data);
            return $this->find_by_id($existing->id);
        }
        $data['user_id'] = (int)$user_id;
        $data['service_id'] = (int)$service_id;
        $data['created_at'] = gmdate('Y-m-d H:i:s');
        $this->db->insert($this->table,$data);
        return $this->find_by_id($this->db->insert_id());
    }

    public function remove($user_id, $service_id){
        return $this->db->where(array('user_id'=>$user_id,'service_id'=>$service_id))
            ->delete($this->table);
    }
}
