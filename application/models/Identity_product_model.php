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
}
