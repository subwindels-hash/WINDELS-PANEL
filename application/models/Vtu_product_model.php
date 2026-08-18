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

    /**
     * Other active rows for the same (network, service_type), excluding one id.
     *
     * VtuService::variable_product() takes active_for(...)[0] for airtime and
     * electricity, so a second active row for the same network silently
     * decides the discount and the bounds by sort order. The catalogue screen
     * refuses to create that situation; this is the query that detects it.
     */
    public function other_active($network_id, $service_type, $exclude_id = null){
        $this->db->where('network_id',$network_id)
                 ->where('service_type',$service_type)
                 ->where('is_active',1);
        if ($exclude_id) $this->db->where('id !=', (int)$exclude_id);
        return $this->db->order_by('sorting','ASC')->get($this->table)->result();
    }

    public function find_by_code($network_id, $service_type, $code){
        return $this->db->where('network_id',$network_id)
                        ->where('service_type',$service_type)
                        ->where('code',$code)->get($this->table)->row();
    }

    /**
     * The admin catalogue grid: every row, priced or not, active or not.
     *
     * Deliberately unscoped by is_active — the whole point of the screen is
     * the rows customers cannot see yet. Only ever reached behind
     * `services.view`, the same contract as the other admin queues.
     *
     * @param array $filters network_id|service_type|status|pricing|search
     */
    public function admin_search(array $filters, $limit = 25, $offset = 0){
        $this->admin_filters($filters);
        return $this->db
            ->select('vtu_products.*, vtu_networks.name AS network_name,
                      vtu_networks.code AS network_code', false)
            ->join('vtu_networks', 'vtu_networks.id = vtu_products.network_id', 'left')
            ->order_by('vtu_products.service_type','ASC')
            ->order_by('vtu_products.sorting','ASC')
            ->order_by('vtu_products.name','ASC')
            ->limit($limit, $offset)
            ->get()->result();
    }

    public function admin_count(array $filters){
        $this->admin_filters($filters);
        return (int)$this->db->count_all_results();
    }

    /** Shared WHERE builder so the grid and its count can never disagree. */
    private function admin_filters(array $f){
        $this->db->from($this->table);
        if (!empty($f['network_id']))   $this->db->where('vtu_products.network_id', (int)$f['network_id']);
        if (!empty($f['service_type'])) $this->db->where('vtu_products.service_type', $f['service_type']);
        if (isset($f['status']) && $f['status'] !== '' && $f['status'] !== null) {
            $this->db->where('vtu_products.is_active', $f['status'] === 'active' ? 1 : 0);
        }
        // "Needs a price" is the working queue of this screen: a sync imports
        // rows unpriced, and an unpriced row is the one thing that cannot be
        // sold at all.
        if (!empty($f['pricing']) && $f['pricing'] === 'unpriced') {
            $this->db->where('vtu_products.price IS NULL', null, false);
        }
        if (!empty($f['pricing']) && $f['pricing'] === 'priced') {
            $this->db->where('vtu_products.price IS NOT NULL', null, false);
        }
        if (!empty($f['search'])) {
            $term = trim((string)$f['search']);
            $this->db->group_start()
                ->like('vtu_products.code', $term)
                ->or_like('vtu_products.name', $term)
                ->group_end();
        }
    }

    public function create(array $data){
        if (empty($data['public_id'])) $data['public_id'] = windels_public_id();
        $now = $this->now_utc();
        $data += array('created_at'=>$now, 'updated_at'=>$now);
        $this->db->insert($this->table, $data);
        return (int)$this->db->insert_id();
    }

    public function update_fields($id, array $fields){
        $fields['updated_at'] = $this->now_utc();
        return $this->db->where('id',$id)->update($this->table, $fields);
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
