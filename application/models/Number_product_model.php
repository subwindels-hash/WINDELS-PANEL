<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/** One buyable (country, service) pair from one vendor (§10). */
class Number_product_model extends MY_Model {
    protected $table = 'number_products';

    /** Products a customer may rent in one country, with their service names. */
    public function active_for_country($country_id){
        return $this->db
            ->select('number_products.*, number_services.code AS service_code,
                      number_services.name AS service_name, number_services.logo_url', false)
            ->from($this->table)
            ->join('number_services', 'number_services.id = number_products.service_id', 'left')
            ->where('number_products.country_id', $country_id)
            ->where('number_products.is_active', 1)
            ->where('number_services.is_active', 1)
            ->order_by('number_products.sorting', 'ASC')
            ->get()->result();
    }

    public function find_active($id){
        return $this->db->where('id',$id)->where('is_active',1)->get($this->table)->row();
    }

    public function find_by_code($country_id, $service_id, $code){
        return $this->db->where('country_id',$country_id)
                        ->where('service_id',$service_id)
                        ->where('code',$code)->get($this->table)->row();
    }

    /** The buyable row for a (country, service) pair, whichever code it has. */
    public function find_for_pair($country_id, $service_id){
        return $this->db->where('country_id',$country_id)
                        ->where('service_id',$service_id)
                        ->where('is_active',1)
                        ->order_by('sorting','ASC')
                        ->get($this->table)->row();
    }

    /**
     * The admin catalogue grid: every row, priced or not, active or not.
     *
     * Unscoped by is_active on purpose — a sync imports rows inactive and
     * unpriced, and those are exactly the rows this screen exists to work on.
     * Reached only behind `services.view`.
     *
     * @param array $filters country_id|service_id|status|pricing|search
     */
    public function admin_search(array $filters, $limit = 25, $offset = 0){
        $this->admin_filters($filters);
        return $this->db
            ->select('number_products.*, number_countries.name AS country_name,
                      number_countries.code AS country_code,
                      number_services.name AS service_name,
                      number_services.code AS service_code', false)
            ->join('number_countries', 'number_countries.id = number_products.country_id', 'left')
            ->join('number_services', 'number_services.id = number_products.service_id', 'left')
            ->order_by('number_products.sorting','ASC')
            ->order_by('number_products.code','ASC')
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
        if (!empty($f['country_id'])) $this->db->where('number_products.country_id', (int)$f['country_id']);
        if (!empty($f['service_id'])) $this->db->where('number_products.service_id', (int)$f['service_id']);
        if (isset($f['status']) && $f['status'] !== '' && $f['status'] !== null) {
            $this->db->where('number_products.is_active', $f['status'] === 'active' ? 1 : 0);
        }
        if (!empty($f['pricing']) && $f['pricing'] === 'unpriced') {
            $this->db->where('number_products.price IS NULL', null, false);
        }
        if (!empty($f['pricing']) && $f['pricing'] === 'priced') {
            $this->db->where('number_products.price IS NOT NULL', null, false);
        }
        if (!empty($f['search'])) {
            $term = trim((string)$f['search']);
            $this->db->group_start()
                ->like('number_products.code', $term)
                ->or_like('number_products.provider_product', $term)
                ->group_end();
        }
    }

    /**
     * Other active rows for the same (country, service), excluding one id.
     *
     * NumberService::reserve() resolves through find_for_pair(), which takes
     * the first active row by sort order. A second active row for the same
     * pair therefore decides the price silently; the catalogue screen refuses
     * to create one.
     */
    public function other_active($country_id, $service_id, $exclude_id = null){
        $this->db->where('country_id',$country_id)
                 ->where('service_id',$service_id)
                 ->where('is_active',1);
        if ($exclude_id) $this->db->where('id !=', (int)$exclude_id);
        return $this->db->order_by('sorting','ASC')->get($this->table)->result();
    }

    public function create(array $data){
        if (empty($data['public_id'])) $data['public_id'] = marvy_public_id();
        $now = $this->now_utc();
        $data += array('created_at'=>$now, 'updated_at'=>$now);
        $this->db->insert($this->table, $data);
        return (int)$this->db->insert_id();
    }

    public function update_fields($id, array $fields){
        $fields['updated_at'] = $this->now_utc();
        return $this->db->where('id',$id)->update($this->table, $fields);
    }

    /** Catalogue rows sourced from one vendor, for the admin detail page. */
    public function paginated_for_provider($provider_id, $limit, $offset){
        return $this->db->where('provider_id',$provider_id)
                        ->order_by('sorting','ASC')
                        ->limit($limit,$offset)->get($this->table)->result();
    }

    public function count_for_provider($provider_id){
        return (int)$this->db->where('provider_id',$provider_id)->count_all_results($this->table);
    }

    /**
     * Upsert one row from a vendor's price list.
     * Returns 'inserted'|'updated'|'unchanged'.
     *
     * The vendor owns availability and what it charges us. It does not own our
     * selling price: `price` is written only when the row has none, so a sync
     * can never move an existing product onto a losing margin or undo a price
     * an admin set by hand. New rows land INACTIVE and unpriced for the same
     * reason — a product with no price would otherwise be buyable for nothing.
     *
     * @param array $v service, provider_product, operator, cost, stock
     */
    public function upsert_from_provider($provider_id, $country_id, $service_id, array $v, $sorting = 0){
        $product = trim((string)($v['provider_product'] ?? ''));
        if ($product === '') return 'unchanged';

        $code = strtoupper(preg_replace('/[^A-Za-z0-9._-]/', '-', $product));
        $cost = isset($v['cost']) && $v['cost'] !== null && is_numeric($v['cost'])
            ? number_format((float)$v['cost'], 8, '.', '') : null;
        $stock = isset($v['stock']) && $v['stock'] !== null ? (int)$v['stock'] : null;

        $existing = $this->find_by_code($country_id, $service_id, $code);
        $now = $this->now_utc();

        if (!$existing) {
            $this->db->insert($this->table, array(
                'public_id'         => marvy_public_id(),
                'country_id'        => $country_id,
                'service_id'        => $service_id,
                'provider_id'       => $provider_id,
                'code'              => $code,
                'provider_country'  => isset($v['provider_country']) ? (string)$v['provider_country'] : null,
                'provider_operator' => isset($v['operator']) ? (string)$v['operator'] : 'any',
                'provider_product'  => $product,
                'price'             => null,
                'provider_cost'     => $cost,
                'stock'             => $stock,
                'is_active'         => 0,
                'sorting'           => (int)$sorting,
                'created_at'        => $now,
                'updated_at'        => $now,
            ));
            return 'inserted';
        }

        $fields = array(
            'provider_id'       => $provider_id,
            'provider_product'  => $product,
            'provider_operator' => isset($v['operator']) ? (string)$v['operator'] : $existing->provider_operator,
            'provider_cost'     => $cost,
            'stock'             => $stock,
            'updated_at'        => $now,
        );
        $this->db->where('id', $existing->id)->update($this->table, $fields);
        return 'updated';
    }
}
