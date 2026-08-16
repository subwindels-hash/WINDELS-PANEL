<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * PricingService — user > group > default (§17), single source of truth.
 */
class PricingService {
    private $ci;
    public function __construct(){ $this->ci =& get_instance(); }
    public function price_for($service, $user){
        if (!$service) return null;
        // 1. user-specific
        if ($user) {
            $row = $this->ci->db->where(array('user_id'=>$user->id,'service_id'=>$service->id))->get('user_service_prices')->row();
            if ($row) return $row->rate;
            // 2. group
            if (!empty($user->price_group_id)) {
                $g = $this->ci->db->where(array('service_id'=>$service->id,'price_group_id'=>$user->price_group_id))->get('service_prices')->row();
                if ($g) return $g->rate;
            }
        }
        return $service->rate;
    }
    public function charge_for_quantity($rate, $quantity){
        // rate is per 1000 typically; use bcmath
        $per_unit = bcdiv((string)$rate, '1000', 8);
        return bcmul($per_unit, (string)$quantity, 8);
    }
}
