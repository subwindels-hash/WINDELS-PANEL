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
            'provider_id'        => $provider_id,
            'provider_service_id'=> (string)$svc['provider_service_id'],
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

        // Single atomic statement against uq_provider_svc (provider_id,
        // provider_service_id). A read-then-write pair would race two
        // concurrent syncs of the same provider into a duplicate-key error.
        $cols = array_keys($data);
        $sql = 'INSERT INTO '.$this->table.' ('.implode(', ', $cols).') VALUES ('
             . implode(', ', array_fill(0, count($cols), '?')).') '
             . 'ON DUPLICATE KEY UPDATE '
             . implode(', ', array_map(function($c){ return $c.' = VALUES('.$c.')'; },
                 array_diff($cols, array('provider_id','provider_service_id'))));
        $this->db->query($sql, array_values($data));

        return $existing ? 'updated' : 'inserted';
    }
}
