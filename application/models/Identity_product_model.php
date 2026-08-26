<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/** What identity checks are on sale and what they cost (§22, §26). */
class Identity_product_model extends MY_Model {
    protected $table = 'identity_products';

    /**
     * The catalogue a customer may buy from.
     *
     * Unpriced rows are excluded rather than shown as "price on request": a
     * product with a NULL price cannot be charged for, so offering it would
     * produce a free lookup that still costs us a vendor call. Catalogue sync
     * lands new rows unpriced and inactive for exactly this reason.
     */
    public function active(){
        return $this->db->where('is_active',1)
                        ->where('price IS NOT NULL', null, false)
                        ->order_by('sorting','ASC')->order_by('name','ASC')
                        ->get($this->table)->result();
    }

    public function all_products(){
        return $this->db->order_by('sorting','ASC')->order_by('name','ASC')
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

    /**
     * The admin catalogue grid: every row, priced or not, active or not.
     *
     * The three seeded checks ship unpriced and switched off, so an operator's
     * first visit here is the only thing standing between a fresh install and
     * an identity catalogue nobody can buy from.
     *
     * @param array $filters id_type|status|pricing|search
     */
    public function admin_search(array $filters, $limit = 25, $offset = 0){
        $this->admin_filters($filters);
        return $this->db->order_by('sorting','ASC')->order_by('name','ASC')
                        ->limit($limit, $offset)->get()->result();
    }

    public function admin_count(array $filters){
        $this->admin_filters($filters);
        return (int)$this->db->count_all_results();
    }

    /** Shared WHERE builder so the grid and its count can never disagree. */
    private function admin_filters(array $f){
        $this->db->from($this->table);
        if (!empty($f['id_type'])) $this->db->where('identity_products.id_type', $f['id_type']);
        if (isset($f['status']) && $f['status'] !== '' && $f['status'] !== null) {
            $this->db->where('identity_products.is_active', $f['status'] === 'active' ? 1 : 0);
        }
        if (!empty($f['pricing']) && $f['pricing'] === 'unpriced') {
            $this->db->where('identity_products.price IS NULL', null, false);
        }
        if (!empty($f['pricing']) && $f['pricing'] === 'priced') {
            $this->db->where('identity_products.price IS NOT NULL', null, false);
        }
        if (!empty($f['search'])) {
            $term = trim((string)$f['search']);
            $this->db->group_start()
                ->like('identity_products.code', $term)
                ->or_like('identity_products.name', $term)
                ->group_end();
        }
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
}
