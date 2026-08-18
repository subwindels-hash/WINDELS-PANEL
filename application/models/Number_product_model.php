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
                'public_id'         => windels_public_id(),
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
