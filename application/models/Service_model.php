<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Service_model extends MY_Model {
    protected $table = 'services';

    public function active($category_id=NULL){
        $this->db->where('status','ACTIVE');
        if ($category_id) $this->db->where('category_id',$category_id);
        return $this->db->order_by('sorting','ASC')->get($this->table)->result();
    }
    public function find_by_slug($slug){ return $this->db->where('slug',$slug)->get($this->table)->row(); }
    /** FULLTEXT search over name + description (ft_svc_search). */
    public function search($term, $limit=50){
        return $this->db->where("MATCH(name, description) AGAINST (".$this->db->escape($term)." IN NATURAL LANGUAGE MODE)", NULL, FALSE)
                        ->where('status','ACTIVE')->limit($limit)->get($this->table)->result();
    }
}
