<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/** A gift card brand — Amazon, Steam, Google Play (§23, §26). */
class Giftcard_brand_model extends MY_Model {
    protected $table = 'giftcard_brands';

    /**
     * Brands with at least one buyable product.
     *
     * An active brand whose every denomination is unpriced is not something a
     * customer can do anything with, and showing it produces the worst
     * storefront outcome there is: a category that opens onto nothing. The
     * join is against the same conditions Giftcard_product_model::active()
     * uses, so the two can never disagree about what "buyable" means.
     */
    public function sellable(){
        return $this->db
            ->select('giftcard_brands.*, COUNT(giftcard_products.id) AS product_count', false)
            ->from($this->table)
            ->join('giftcard_products',
                   'giftcard_products.brand_id = giftcard_brands.id AND giftcard_products.is_active = 1',
                   'inner')
            ->where('giftcard_brands.is_active', 1)
            ->where('giftcard_products.price IS NOT NULL', null, false)
            ->group_by('giftcard_brands.id')
            ->order_by('giftcard_brands.sorting', 'ASC')
            ->order_by('giftcard_brands.name', 'ASC')
            ->get()->result();
    }

    public function active(){
        return $this->db->where('is_active',1)
                        ->order_by('sorting','ASC')->order_by('name','ASC')
                        ->get($this->table)->result();
    }

    public function all_brands(){
        return $this->db->order_by('sorting','ASC')->order_by('name','ASC')
                        ->get($this->table)->result();
    }

    public function find_by_code($code){
        return $this->db->where('code',strtoupper($code))->get($this->table)->row();
    }

    public function find_active_by_code($code){
        return $this->db->where('code',strtoupper($code))->where('is_active',1)
                        ->get($this->table)->row();
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

    /**
     * Create or refresh a brand from a vendor catalogue row.
     *
     * The vendor owns the name, the logo and the redeem instructions — they
     * are its product, and a brand renaming itself should follow through. It
     * does not own is_active: whether the panel sells Steam cards is an
     * operator decision, so a sync never re-activates something switched off.
     */
    public function upsert_from_provider(array $v, $sorting = 0){
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '-', trim((string)($v['brand_name'] ?? ''))));
        if ($code === '' || $code === '-') return null;

        $existing = $this->find_by_code($code);
        $fields = array(
            'name'                => mb_substr((string)($v['brand_name'] ?? $code), 0, 128),
            'provider_brand_id'   => isset($v['brand_id']) ? (string)$v['brand_id'] : null,
            'logo_url'            => isset($v['logo_url']) ? mb_substr((string)$v['logo_url'], 0, 512) : null,
            'redeem_instructions' => isset($v['redeem_instructions']) ? (string)$v['redeem_instructions'] : null,
        );

        if ($existing) {
            $this->update_fields($existing->id, $fields);
            return (int)$existing->id;
        }
        return $this->create($fields + array(
            'code'      => $code,
            // New brands arrive switched on — unlike products, a brand carries
            // no price, so an active brand with no priced products is still
            // invisible to customers via sellable().
            'is_active' => 1,
            'sorting'   => (int)$sorting,
        ));
    }
}
