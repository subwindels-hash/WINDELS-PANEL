<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Vtu_product_model extends MY_Model {
    protected $table = 'vtu_products';

    /** Products a customer may buy, for one network and type. */
    public function active_for($network_id, $service_type){
        return $this->db->where('network_id',$network_id)
                        ->where('service_type',$service_type)
                        ->where('is_active',1)
                        ->order_by('sorting','ASC')
                        ->get($this->table)->result();
    }

    public function find_active($id){
        return $this->db->where('id',$id)->where('is_active',1)->get($this->table)->row();
    }

    public function find_by_code($network_id, $service_type, $code){
        return $this->db->where('network_id',$network_id)
                        ->where('service_type',$service_type)
                        ->where('code',$code)->get($this->table)->row();
    }

    /** Catalogue rows sourced from one provider, for the admin detail page. */
    public function paginated_for_provider($provider_id, $limit, $offset){
        return $this->db->where('provider_id',$provider_id)
                        ->order_by('service_type','ASC')->order_by('sorting','ASC')
                        ->limit($limit,$offset)->get($this->table)->result();
    }

    public function count_for_provider($provider_id){
        return (int)$this->db->where('provider_id',$provider_id)->count_all_results($this->table);
    }

    /**
     * Upsert one product from a vendor's price list.
     * Returns 'inserted'|'updated'|'unchanged'.
     *
     * The vendor owns what a product *is* (its code, name and what it charges
     * us). It does not own what we sell it for: `price` is written only when
     * the row has none, so a catalogue sync can never quietly move an existing
     * product onto a losing margin, and never re-prices a product an admin has
     * set by hand. Deactivated rows stay deactivated for the same reason.
     *
     * @param array $v variation_code, name, amount (vendor price), fixed_price
     */
    public function upsert_from_provider($provider_id, $network_id, $service_type, array $v, $sorting = 0){
        $variation = (string)($v['variation_code'] ?? '');
        if ($variation === '') return 'unchanged';

        $code = strtoupper(preg_replace('/[^A-Za-z0-9._-]/', '-', $variation));
        $cost = isset($v['amount']) && $v['amount'] !== null
            ? number_format((float)$v['amount'], 8, '.', '') : null;

        $existing = $this->find_by_code($network_id, $service_type, $code);
        $now = $this->now_utc();

        if (!$existing) {
            $this->db->insert($this->table, array(
                'public_id'     => windels_public_id(),
                'network_id'    => $network_id,
                'provider_id'   => $provider_id,
                'service_type'  => $service_type,
                'code'          => $code,
                'provider_code' => $variation,
                'name'          => mb_substr((string)($v['name'] ?? $variation), 0, 128),
                'face_value'    => $cost,
                // A brand-new product sells at the vendor's price until an
                // admin prices it; that is visible and zero-margin, not a loss.
                'price'         => $cost,
                'provider_cost' => $cost,
                // New rows arrive switched off: a sync must not put an
                // unpriced, unreviewed product in front of customers.
                'is_active'     => 0,
                'sorting'       => (int)$sorting,
                'created_at'    => $now,
                'updated_at'    => $now,
            ));
            return 'inserted';
        }

        $fields = array(
            'provider_id'   => $provider_id,
            'provider_code' => $variation,
            'name'          => mb_substr((string)($v['name'] ?? $variation), 0, 128),
            'provider_cost' => $cost,
            'updated_at'    => $now,
        );
        if ($existing->price === null) $fields['price'] = $cost;
        if ($existing->face_value === null) $fields['face_value'] = $cost;

        $this->db->where('id', $existing->id)->update($this->table, $fields);
        return 'updated';
    }
}
