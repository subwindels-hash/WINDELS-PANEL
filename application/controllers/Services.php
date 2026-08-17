<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Services — public service catalog (Session 07).
 *
 * Server-side search (FULLTEXT), category/platform/type filters, sorting and
 * pagination. The detail page shows the guest price and, for authenticated
 * visitors, their resolved price via PricingService (user > group > default).
 */
class Services extends Public_Controller {

    const PER_PAGE = 12;

    public function __construct() {
        parent::__construct();
        $this->load->model(array('Service_model', 'Service_category_model', 'Setting_model'));
        $this->load->library(array('PricingService', 'form_validation'));
    }

    public function index() {
        $q      = trim((string)$this->input->get('q'));
        $cat    = $this->input->get('category', true);
        $platform = $this->input->get('platform', true);
        $type   = $this->input->get('type', true);
        $sort   = $this->input->get('sort', true);
        $page   = max(1, (int)$this->input->get('page'));

        $category = $cat ? $this->Service_category_model->find_by_slug($cat) : null;

        // Build the result set. FULLTEXT is used only when there is a searchable
        // term; otherwise we fall back to a LIKE scan so short tokens still work.
        $result = $this->query_services($q, $category, $platform, $type, $sort, $page, self::PER_PAGE);

        $categories = $this->Service_category_model->active();
        $platforms  = array_unique(array_filter(array_map(function ($c) { return $c->platform; }, $categories)));
        $types      = array('DEFAULT','CUSTOM_COMMENTS','PACKAGE','SUBSCRIPTION','MENTIONS_USER_FOLLOWERS');

        $this->render_public('public/services/index', array(
            'title'       => 'Services',
            'services'    => $result['rows'],
            'categories'  => $categories,
            'platforms'   => $platforms,
            'types'       => $types,
            'total'       => $result['total'],
            'page'        => $page,
            'total_pages' => max(1, (int)ceil($result['total'] / self::PER_PAGE)),
            'filters'     => array(
                'q'        => $q,
                'category' => $cat,
                'platform' => $platform,
                'type'     => $type,
                'sort'     => $sort ?: 'popular',
            ),
        ));
    }

    public function detail($slug) {
        $service = $this->Service_model->find_by_slug($slug);
        if (!$service || $service->status !== 'ACTIVE') show_404();

        $category = $service->category_id
            ? $this->db->where('id', $service->category_id)->get('service_categories')->row()
            : null;

        // Authenticated visitors see their resolved (user/group) price.
        $user_price = null;
        if ($this->auth && $this->auth->check()) {
            $user = $this->auth->user();
            $user_price = $this->pricingservice->price_for($service, $user);
        }

        // Related services from the same category (exclude self), cheapest first.
        $related = array();
        if ($category) {
            $related = $this->db->where('category_id', $category->id)
                ->where('id !=', $service->id)
                ->where('status', 'ACTIVE')
                ->order_by('rate', 'ASC')
                ->limit(4)->get('services')->result();
        }

        // Is this service favorited by the current user?
        $is_favorite = false;
        if ($this->auth && $this->auth->check()) {
            $is_favorite = (bool)$this->db->where(array(
                'user_id' => $this->auth->id(),
                'service_id' => $service->id,
            ))->count_all_results('service_favorites');
        }

        $this->render_public('public/services/detail', array(
            'title'        => $service->name,
            'meta_description' => trim(strip_tags((string)$service->description)) ?: 'Order '.$service->name.' on WINDELS PANEL.',
            'service'      => $service,
            'category'     => $category,
            'user_price'   => $user_price,
            'related'      => $related,
            'is_favorite'  => $is_favorite,
        ));
    }

    /* -------------------------------------------------------------- */

    private function query_services($q, $category, $platform, $type, $sort, $page, $per_page) {
        $filters = function () use ($q, $category, $platform, $type) {
            $this->db->where('services.status', 'ACTIVE');
            if ($category) $this->db->where('services.category_id', $category->id);
            if ($platform) $this->db->where('service_categories.platform', $platform);
            if ($type)     $this->db->where('services.service_type', $type);
            if ($q !== '') {
                if (preg_match('/^[0-9a-zA-Z\s\-_.,!@#$%^&*()+]{3,}$/', $q)) {
                    $this->db->group_start()
                        ->where('MATCH(services.name, services.description) AGAINST ('.$this->db->escape($q).' IN NATURAL LANGUAGE MODE)', null, false)
                        ->or_like('services.name', $q)
                        ->group_end();
                } else {
                    $this->db->like('services.name', $q);
                }
            }
        };

        // Count
        $this->db->from('services')->join('service_categories', 'service_categories.id = services.category_id', 'left');
        $filters();
        $total = (int)$this->db->count_all_results();

        // Rows
        $this->db->select('services.*, service_categories.name AS category_name, service_categories.slug AS category_slug, service_categories.platform AS platform')
            ->from('services')
            ->join('service_categories', 'service_categories.id = services.category_id', 'left');
        $filters();

        switch ($sort) {
            case 'price_asc':  $this->db->order_by('services.rate', 'ASC'); break;
            case 'price_desc': $this->db->order_by('services.rate', 'DESC'); break;
            case 'name':       $this->db->order_by('services.name', 'ASC'); break;
            case 'newest':     $this->db->order_by('services.id', 'DESC'); break;
            case 'popular':
            default:           $this->db->order_by('services.trending', 'DESC')->order_by('services.sorting', 'ASC')->order_by('services.id', 'ASC');
        }
        $rows = $this->db->limit($per_page, ($page - 1) * $per_page)->get()->result();

        return array('rows' => $rows, 'total' => $total);
    }
}
