<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Provider_service_model extends MY_Model {
    protected $table = 'provider_services';

    public function for_provider($provider_id){
        return $this->db->where('provider_id',$provider_id)
            ->order_by('name','ASC')->get($this->table)->result();
    }

    public function count_for_provider($provider_id){
        return (int)$this->db->where('provider_id',$provider_id)->count_all_results($this->table);
    }

    public function paginated_for_provider($provider_id, $limit, $offset){
        return $this->db->where('provider_id',$provider_id)
            ->order_by('name','ASC')->limit($limit,$offset)->get($this->table)->result();
    }

    /** Find a provider service by its provider-side id. */
    public function find_provider_service($provider_id, $provider_service_id){
        return $this->db->where(array(
            'provider_id' => $provider_id,
            'provider_service_id' => $provider_service_id,
        ))->get($this->table)->row();
    }

    /**
     * Upsert a synced service. Returns 'inserted'|'updated'|'unchanged'.
     * Raw payload is always refreshed; rate/limits update only when changed.
     */
    public function upsert_service($provider_id, array $svc){
        $existing = $this->find_provider_service($provider_id, (string)$svc['provider_service_id']);
        $data = array(
            'name'               => $svc['name'],
            'category'           => $svc['category'] ?? null,
            'rate'               => $svc['rate'],
            'min_quantity'       => (int)$svc['min_quantity'],
            'max_quantity'       => (int)$svc['max_quantity'],
            'service_type'       => $svc['service_type'] ?? 'DEFAULT',
            'cancel_supported'   => !empty($svc['cancel']) ? 1 : 0,
            'refill_supported'   => !empty($svc['refill']) ? 1 : 0,
            'dripfeed_supported' => !empty($svc['dripfeed']) ? 1 : 0,
            'raw_payload'        => isset($svc['raw']) ? json_encode($svc['raw']) : null,
            'last_synced_at'     => gmdate('Y-m-d H:i:s'),
        );
        if ($existing) {
            unset($data['last_synced_at']);
            $this->db->where('id', $existing->id)->update($this->table, $data);
            return 'updated';
        }
        $data['provider_id'] = $provider_id;
        $data['provider_service_id'] = (string)$svc['provider_service_id'];
        $this->db->insert($this->table, $data);
        return 'inserted';
    }
}
