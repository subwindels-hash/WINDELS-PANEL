<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Service_price_model extends MY_Model {
    protected $table = 'service_prices';

    public function for_group($service_id, $price_group_id){
        return $this->db->where(array('service_id'=>$service_id,'price_group_id'=>$price_group_id))->get($this->table)->row();
    }

    /** Every price group plus this service's optional override. */
    public function for_service_with_groups($service_id, $limit=100){
        return $this->db->select('price_groups.id, price_groups.name, price_groups.description,
                price_groups.is_default, service_prices.rate AS service_rate', false)
            ->from('price_groups')
            ->join('service_prices', 'service_prices.price_group_id = price_groups.id AND service_prices.service_id = '.(int)$service_id, 'left')
            ->order_by('price_groups.name','ASC')
            ->limit(max(1, min(200, (int)$limit)))->get()->result();
    }

    public function put($service_id, $price_group_id, $rate){
        $existing = $this->for_group($service_id, $price_group_id);
        $data = array('rate'=>$rate, 'updated_at'=>gmdate('Y-m-d H:i:s'));
        if ($existing) {
            $this->db->where('id',$existing->id)->update($this->table,$data);
            return $this->find_by_id($existing->id);
        }
        $data['service_id'] = (int)$service_id;
        $data['price_group_id'] = (int)$price_group_id;
        $data['created_at'] = gmdate('Y-m-d H:i:s');
        $this->db->insert($this->table,$data);
        return $this->find_by_id($this->db->insert_id());
    }

    public function remove($service_id, $price_group_id){
        return $this->db->where(array('service_id'=>$service_id,'price_group_id'=>$price_group_id))
            ->delete($this->table);
    }
}
