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

    /**
     * Services to showcase on the marketing homepage, with their category.
     *
     * Prefers rows an operator has explicitly marked `featured`, then falls
     * back to the normal catalogue ordering, so the homepage shows real
     * services at real prices even before anyone has curated a shortlist.
     * Returns an empty array on a catalogue with nothing active — the caller
     * renders a "catalogue is being prepared" state rather than invented
     * placeholder cards.
     */
    public function homepage_showcase($limit = 6) {
        $limit = max(1, min(24, (int)$limit));
        return $this->db
            ->select('s.public_id, s.name, s.slug, s.rate, s.min_quantity, s.max_quantity,
                      s.average_time, s.refill_supported, s.cancel_supported, s.dripfeed_supported,
                      c.name AS category_name, c.slug AS category_slug', false)
            ->from($this->table.' s')
            ->join('service_categories c', 'c.id = s.category_id', 'left')
            ->where('s.status', 'ACTIVE')
            ->order_by('s.featured', 'DESC')
            ->order_by('s.sorting', 'ASC')
            ->limit($limit)
            ->get()->result();
    }

    /**
     * Active categories that actually contain something buyable, with counts.
     *
     * A category with no active services is a dead end for a visitor, so the
     * homepage and the catalogue nav only offer categories that can be
     * ordered from.
     */
    public function categories_with_counts($limit = 12) {
        $limit = max(1, min(50, (int)$limit));
        return $this->db
            ->select('c.name, c.slug, c.icon, COUNT(s.id) AS service_count, MIN(s.rate) AS from_rate', false)
            ->from('service_categories c')
            ->join($this->table.' s', "s.category_id = c.id AND s.status = 'ACTIVE'", 'inner')
            ->where('c.is_active', 1)
            ->group_by('c.id, c.name, c.slug, c.icon, c.sorting')
            ->order_by('c.sorting', 'ASC')
            ->limit($limit)
            ->get()->result();
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

    /** Bounded, joined service grid for the admin editor. */
    public function admin_search(array $filters, $limit=25, $offset=0){
        $this->db->select('services.*, service_categories.name AS category_name,
                service_categories.public_id AS category_public_id,
                providers.name AS provider_name, providers.public_id AS provider_public_id', false)
            ->from($this->table)
            ->join('service_categories', 'service_categories.id = services.category_id', 'left')
            ->join('providers', 'providers.id = services.provider_id', 'left');
        $this->admin_filters($filters);
        return $this->db->order_by('services.sorting','ASC')
            ->order_by('services.name','ASC')
            ->limit(max(1, min(100, (int)$limit)), max(0, (int)$offset))
            ->get()->result();
    }

    public function admin_count(array $filters){
        $this->db->from($this->table)
            ->join('service_categories', 'service_categories.id = services.category_id', 'left')
            ->join('providers', 'providers.id = services.provider_id', 'left');
        $this->admin_filters($filters);
        return (int)$this->db->count_all_results();
    }

    private function admin_filters(array $filters){
        if (!empty($filters['status']) && in_array($filters['status'], array('ACTIVE','INACTIVE','ARCHIVED'), true)) {
            $this->db->where('services.status', $filters['status']);
        }
        if (!empty($filters['category_public_id'])) {
            $this->db->where('service_categories.public_id', (string)$filters['category_public_id']);
        }
        if (!empty($filters['provider_public_id'])) {
            $this->db->where('providers.public_id', (string)$filters['provider_public_id']);
        }
        if (!empty($filters['service_type'])) $this->db->where('services.service_type', $filters['service_type']);
        if (!empty($filters['search'])) {
            $term = trim((string)$filters['search']);
            $this->db->group_start()
                ->like('services.name', $term)
                ->or_like('services.slug', $term)
                ->or_like('services.provider_service_id', $term)
                ->group_end();
        }
    }
}
