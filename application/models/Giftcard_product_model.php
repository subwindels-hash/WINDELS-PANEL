<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/** One buyable gift card denomination (§23, §26). */
class Giftcard_product_model extends MY_Model {
    protected $table = 'giftcard_products';

    /**
     * What a customer may actually buy.
     *
     * Three conditions, and each one exists because dropping it sells
     * something the panel cannot deliver:
     *   - is_active, the operator's switch;
     *   - price IS NOT NULL, because an unpriced row would be charged as
     *     nothing (catalogue sync deliberately imports without a price);
     *   - denomination_type = FIXED, because a RANGE product has no
     *     denomination until the customer names one, and this phase has no
     *     form for that.
     */
    public function active(){
        return $this->db
            ->select('giftcard_products.*, giftcard_brands.name AS brand_name,
                      giftcard_brands.code AS brand_code, giftcard_brands.logo_url', false)
            ->from($this->table)
            ->join('giftcard_brands', 'giftcard_brands.id = giftcard_products.brand_id', 'inner')
            ->where('giftcard_products.is_active', 1)
            ->where('giftcard_products.price IS NOT NULL', null, false)
            ->where('giftcard_products.denomination_type', 'FIXED')
            ->where('giftcard_brands.is_active', 1)
            ->order_by('giftcard_brands.sorting', 'ASC')
            ->order_by('giftcard_products.face_value', 'ASC')
            ->get()->result();
    }

    /** The denominations on sale for one brand. */
    public function active_for_brand($brand_id){
        return $this->db->where('brand_id', $brand_id)
                        ->where('is_active', 1)
                        ->where('price IS NOT NULL', null, false)
                        ->where('denomination_type', 'FIXED')
                        ->order_by('face_value', 'ASC')
                        ->get($this->table)->result();
    }

    public function find_active($id){
        return $this->db->where('id',$id)->where('is_active',1)->get($this->table)->row();
    }

    public function find_active_by_code($code){
        return $this->db->where('code',strtoupper($code))->where('is_active',1)
                        ->get($this->table)->row();
    }

    public function find_by_code($code){
        return $this->db->where('code',strtoupper($code))->get($this->table)->row();
    }

    public function all_products(){
        return $this->db->order_by('sorting','ASC')->order_by('name','ASC')
                        ->get($this->table)->result();
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

    /**
     * Upsert one denomination from a vendor's catalogue.
     * Returns 'inserted'|'updated'|'unchanged'.
     *
     * The same two rules as the VTU and number syncs, for the same reasons:
     *
     *   - the vendor owns its cost and its metadata; it never owns `price`.
     *     Our selling price is written only when the row has none, so a sync
     *     cannot move an existing product onto a losing margin or quietly undo
     *     a price an operator set by hand. That matters more here than
     *     anywhere else in the panel, because a gift card's vendor cost moves
     *     with the FX rate — a nightly sync that repriced the shop would have
     *     the shop chasing the naira.
     *   - new rows land INACTIVE and unpriced, so nothing becomes buyable
     *     without somebody deciding it should be.
     */
    public function upsert_from_provider($provider_id, $brand_id, array $v, $sorting = 0){
        $vendor_id = trim((string)($v['provider_product_id'] ?? ''));
        if ($vendor_id === '') return 'unchanged';

        // The card's own denomination currency, never defaulted: a row whose
        // currency the vendor did not state would otherwise be imported as a
        // dollar card and sold as one. See migration 014.
        $currency = strtoupper(substr(trim((string)($v['recipient_currency'] ?? '')), 0, 3));
        if (strlen($currency) !== 3) return 'unchanged';

        $type = strtoupper((string)($v['denomination_type'] ?? 'FIXED')) === 'RANGE' ? 'RANGE' : 'FIXED';
        $face = $this->money_or_null($v['face_value'] ?? null);
        if ($type === 'FIXED' && $face === null) return 'unchanged';

        $country = strtoupper(substr((string)($v['country_code'] ?? 'US'), 0, 2));
        // The code has to distinguish denominations of the same vendor product,
        // because Reloadly gives a $25 and a $50 Amazon card the same productId.
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '-',
            ($v['brand_name'] ?? 'CARD').'-'.$country.'-'
            .($type === 'RANGE' ? 'RANGE' : rtrim(rtrim((string)$face, '0'), '.'))));

        $existing = $this->find_by_code($code);
        $now = $this->now_utc();

        $vendor_fields = array(
            'provider_id'         => $provider_id,
            'brand_id'            => $brand_id,
            'name'                => mb_substr((string)($v['name'] ?? $code), 0, 160),
            'country_code'        => $country,
            'provider_product_id' => $vendor_id,
            'denomination_type'   => $type,
            'recipient_currency'  => $currency,
            'face_value'          => $face,
            'min_face_value'      => $this->money_or_null($v['min_face_value'] ?? null),
            'max_face_value'      => $this->money_or_null($v['max_face_value'] ?? null),
            'provider_cost'       => $this->money_or_null($v['cost'] ?? null),
            'updated_at'          => $now,
        );

        if (!$existing) {
            $this->db->insert($this->table, $vendor_fields + array(
                'public_id' => windels_public_id(),
                'code'      => $code,
                'price'     => null,
                'is_active' => 0,
                'sorting'   => (int)$sorting,
                'created_at'=> $now,
            ));
            return 'inserted';
        }

        $this->db->where('id', $existing->id)->update($this->table, $vendor_fields);
        return 'updated';
    }

    private function money_or_null($v){
        return ($v === null || $v === '' || !is_numeric($v))
            ? null : number_format((float)$v, 8, '.', '');
    }
}
