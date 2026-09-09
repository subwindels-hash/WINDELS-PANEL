<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Provider_service_model extends MY_Model {
    protected $table = 'provider_services';
    private $pricing_by_provider = array();

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

        // Keep linked panel services aware of the newest vendor cost without
        // overwriting admin-authored fields. Only rows that explicitly opted
        // into auto_price_sync receive a new selling rate.
        $this->propagate_service_source($provider_id, $data);

        return $existing ? 'updated' : 'inserted';
    }

    private function propagate_service_source($provider_id, array $source){
        $snapshot = json_encode(array(
            'provider_service_id' => (string)$source['provider_service_id'],
            'name' => (string)$source['name'],
            'category' => $source['category'],
            'rate' => (string)$source['rate'],
            'min_quantity' => (int)$source['min_quantity'],
            'max_quantity' => (int)$source['max_quantity'],
            'service_type' => (string)$source['service_type'],
            'cancel_supported' => (int)$source['cancel_supported'],
            'refill_supported' => (int)$source['refill_supported'],
            'dripfeed_supported' => (int)$source['dripfeed_supported'],
            'last_synced_at' => $source['last_synced_at'],
        ), JSON_UNESCAPED_SLASHES);

        $scope = array(
            'provider_id' => (int)$provider_id,
            'provider_service_id' => (string)$source['provider_service_id'],
        );
        $this->db->where($scope)->update('services', array(
            'provider_rate' => (string)$source['rate'],
            'provider_source_snapshot' => $snapshot,
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ));

        // A provider catalogue may contain thousands of rows. Cache its two
        // pricing values for this request instead of issuing one provider query
        // for every mirrored service.
        if (!array_key_exists((int)$provider_id, $this->pricing_by_provider)) {
            $provider = $this->db->select('rate_multiplier, markup', false)
                ->where('id', (int)$provider_id)->get('providers')->row();
            $this->pricing_by_provider[(int)$provider_id] = $provider ?: false;
        }
        $provider = $this->pricing_by_provider[(int)$provider_id];
        if (!$provider) return;
        $multiplier = isset($provider->rate_multiplier) ? (string)$provider->rate_multiplier : '1';
        $markup = isset($provider->markup) ? (string)$provider->markup : '0';
        $rate = bcadd(
            bcmul((string)$source['rate'], $multiplier, 8),
            $markup,
            8
        );
        // Do not let an extreme provider rule overflow services.rate or turn
        // an existing positive selling rate into zero. Trusted cost evidence
        // above is still refreshed so an admin can correct the pricing rule.
        if (!preg_match('/^(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{8})$/', $rate)
                || bccomp($rate, '0', 8) <= 0) return;
        $scope['auto_price_sync'] = 1;
        $this->db->where($scope)->update('services', array(
            'rate' => $rate,
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ));
    }
}
