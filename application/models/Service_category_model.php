<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Service_category_model extends MY_Model {
    protected $table = 'service_categories';

    public function active(){
        return $this->db->where('is_active',1)->order_by('sorting','ASC')->get($this->table)->result();
    }
    public function find_by_slug($slug){ return $this->db->where('slug',$slug)->get($this->table)->row(); }

    /** Active and inactive categories for bounded admin form pickers. */
    public function for_picker($limit=200){
        return $this->picker_projection()->order_by('sorting','ASC')->order_by('name','ASC')
            ->limit(max(1, min(500, (int)$limit)))->get($this->table)->result();
    }

    /** Preserve a selected value even when it falls outside the bounded picker. */
    public function picker_by_id($id){
        return $this->picker_projection()->where('id', (int)$id)->get($this->table)->row();
    }

    private function picker_projection(){
        return $this->db->select('id, public_id, name, slug, is_active, sorting', false);
    }
}
