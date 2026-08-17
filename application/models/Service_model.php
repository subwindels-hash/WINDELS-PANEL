<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Service_model extends MY_Model {
    protected $table = 'services';

    /**
     * Columns the order/drip-feed/subscription pickers actually render.
     *
     * `description` is a TEXT column that also backs the FULLTEXT index; none
     * of the picker views read it, and pulling it for every row on a catalogue
     * of a few thousand synced services is the bulk of the payload (Session 18).
     */
    const PICKER_COLUMNS = 'id, public_id, name, slug, category_id, rate, min_quantity,
        max_quantity, increment_step, service_type, average_time, dripfeed_supported,
        subscription_supported, refill_supported, cancel_supported, featured, sorting';

    public function active($category_id=NULL){
        $this->db->where('status','ACTIVE');
        if ($category_id) $this->db->where('category_id',$category_id);
        return $this->db->order_by('sorting','ASC')->get($this->table)->result();
    }

    /**
     * Active services with only the picker columns selected.
     *
     * Same rows as active(), materially smaller result set. Used by the pages
     * that render a service dropdown rather than service detail.
     */
    public function active_for_picker($category_id=NULL){
        $this->db->select(self::PICKER_COLUMNS, false)->where('status','ACTIVE');
        if ($category_id) $this->db->where('category_id',$category_id);
        return $this->db->order_by('sorting','ASC')->get($this->table)->result();
    }

    /** Active services in a category, counted for pagination. */
    public function count_active($category_id=NULL){
        $this->db->where('status','ACTIVE');
        if ($category_id) $this->db->where('category_id',$category_id);
        return (int)$this->db->count_all_results($this->table);
    }
    public function find_by_slug($slug){ return $this->db->where('slug',$slug)->get($this->table)->row(); }
    /** FULLTEXT search over name + description (ft_svc_search). */
    public function search($term, $limit=50){
        return $this->db->where("MATCH(name, description) AGAINST (".$this->db->escape($term)." IN NATURAL LANGUAGE MODE)", NULL, FALSE)
                        ->where('status','ACTIVE')->limit($limit)->get($this->table)->result();
    }
}
