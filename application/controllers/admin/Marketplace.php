<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Admin marketplace — the platform IS the seller, and there are no vendors.
 *
 * Staff with marketplace.manage post, price, promote, feature, categorise,
 * publish/unpublish and fulfil the storefront's listings from here.
 * Escrow reveal and resolution remain separate, sharper permissions.
 * Every mutation in this controller is permission-gated and POST-only.
 */
class Marketplace extends Admin_Controller {
    const PER_PAGE = 25;

    public function __construct() {
        parent::__construct();
        $this->require_perm('marketplace.view');
        $this->load->library(array('MarketplaceService', 'DashboardStats', 'MediaService'));
        $this->load->model(array(
            'Marketplace_listing_model',
            'Marketplace_order_model', 'Marketplace_category_model', 'Audit_log_model'
        ));
    }

    public function index() {
        $tab = strtolower((string)$this->input->get('tab', true));
        if (!in_array($tab, array('orders', 'listings', 'analytics'), true)) $tab = 'orders';
        $page = max(1, (int)$this->input->get('page'));
        $filters = array(
            'status' => strtoupper((string)$this->input->get('status', true)),
            'search' => $this->input->get('q', true),
        );
        $offset = ($page - 1) * self::PER_PAGE;
        $rows = array(); $total = 0; $analytics = null;
        if ($tab === 'listings') {
            $rows = $this->Marketplace_listing_model->admin_search($filters, self::PER_PAGE, $offset);
            $total = $this->Marketplace_listing_model->admin_count($filters);
        } elseif ($tab === 'analytics') {
            $analytics = $this->analytics();
        } else {
            $rows = $this->Marketplace_order_model->admin_search($filters, self::PER_PAGE, $offset);
            $total = $this->Marketplace_order_model->admin_count($filters);
        }
        $this->view('index', 'Marketplace operations', array(
            'tab' => $tab, 'rows' => $rows, 'total' => $total,
            'analytics' => $analytics,
            'can_manage' => $this->auth->can('marketplace.manage'),
            'page' => $page, 'per_page' => self::PER_PAGE, 'filters' => $filters,
        ));
    }

    /* ------------------------- listings (sell) -------------------------- */

    /** GET create/edit form. */
    public function listing_form($public_id = null) {
        $this->require_perm('marketplace.manage');
        $listing = null;
        if ($public_id !== null) {
            $listing = $this->Marketplace_listing_model->find_public($public_id, false);
            if (!$listing) show_404();
        }
        $this->view('listing_form', $listing ? 'Edit listing' : 'New listing', array(
            'listing' => $listing,
            'categories' => $this->Marketplace_category_model->active(),
        ));
    }

    /** POST create/update. Price and ownership are server-side throughout. */
    public function save_listing($public_id = null) {
        $this->post_only();
        $this->require_perm('marketplace.manage');

        // Optional shelf image — sniffed, re-named and polyglot-proofed by
        // MediaService; the uploader's filename is never trusted.
        $image = null;
        if (!empty($_FILES['image']['name'])) {
            $up = $this->mediaservice->store($_FILES['image'], 'marketplace', $this->current_user->id);
            if (empty($up['ok'])) {
                $this->session->set_flashdata('error', $up['error']);
                return redirect($public_id
                    ? 'admin/marketplace/listings/'.$public_id.'/edit'
                    : 'admin/marketplace/listings/new');
            }
            $image = $up['media']->storage_key;
        }

        $res = $this->marketplaceservice->save_listing($this->current_user, array(
            'title' => $this->input->post('title', true),
            'category' => $this->input->post('category', true),
            // XSS filtering is intentionally enabled; listings are rendered as
            // text, never trusted HTML.
            'description' => $this->input->post('description', true),
            'price' => $this->input->post('price', true),
            'promo_price' => $this->input->post('promo_price', true),
            'stock' => $this->input->post('stock', true),
            'delivery_days' => $this->input->post('delivery_days', true),
            'product_type' => $this->input->post('product_type', true),
            'is_featured' => (bool)$this->input->post('is_featured'),
            'image' => $image,
        ), $public_id);
        $this->flash_result($res, 'Listing saved to the storefront.');
        redirect('admin/marketplace?tab=listings');
    }

    /** Publish / unpublish / archive any platform listing. */
    public function listing_status($public_id) {
        $this->post_only();
        $this->require_perm('marketplace.manage');
        $res = $this->marketplaceservice->moderate_listing(
            $public_id, $this->input->post('status', true), $this->current_user->id,
            $this->input->post('note', true)
        );
        $this->flash_result($res, 'Listing updated.');
        redirect('admin/marketplace?tab=listings');
    }

    /* -------------------------- categories ------------------------------ */

    public function categories() {
        $this->require_perm('marketplace.manage');
        $this->view('categories', 'Marketplace categories', array(
            'categories' => $this->Marketplace_category_model->all(),
        ));
    }

    public function save_category($public_id = null) {
        $this->post_only();
        $this->require_perm('marketplace.manage');
        $name = trim((string)$this->input->post('name', true));
        $slug = strtoupper(trim((string)$this->input->post('slug', true)));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 80
            || !Marketplace_category_model::valid_slug($slug)) {
            $this->session->set_flashdata('error', 'Give a 2–80 character name and an A–Z/0–9 slug.');
            return redirect('admin/marketplace/categories');
        }
        if ($public_id !== null) {
            $cat = $this->Marketplace_category_model->find_public($public_id);
            if (!$cat) show_404();
            $dupe = $this->Marketplace_category_model->find_active($slug);
            if ($dupe && (int)$dupe->id !== (int)$cat->id) {
                $this->session->set_flashdata('error', 'That slug is already in use.');
                return redirect('admin/marketplace/categories');
            }
            $this->Marketplace_category_model->update_fields($cat->id, array(
                'name' => $name, 'slug' => $slug,
            ));
            $this->audit('marketplace.category.update', $cat->id, null, array('name' => $name, 'slug' => $slug));
        } else {
            if ($this->Marketplace_category_model->find_active($slug)) {
                $this->session->set_flashdata('error', 'That slug is already in use.');
                return redirect('admin/marketplace/categories');
            }
            $id = $this->Marketplace_category_model->create(array(
                'public_id' => marvy_public_id(),
                'name' => $name, 'slug' => $slug, 'status' => 'ACTIVE',
                'sort_order' => (int)$this->input->post('sort_order', true),
                'created_at' => gmdate('Y-m-d H:i:s'),
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ));
            $this->audit('marketplace.category.create', $id, null, array('name' => $name, 'slug' => $slug));
        }
        $this->session->set_flashdata('success', 'Category saved.');
        redirect('admin/marketplace/categories');
    }

    public function category_status($public_id) {
        $this->post_only();
        $this->require_perm('marketplace.manage');
        $cat = $this->Marketplace_category_model->find_public($public_id);
        if (!$cat) show_404();
        $status = strtoupper((string)$this->input->post('status', true));
        if (!in_array($status, array('ACTIVE', 'ARCHIVED'), true)) show_404();
        $this->Marketplace_category_model->update_fields($cat->id, array('status' => $status));
        $this->audit('marketplace.category.status', $cat->id, array('status' => $cat->status), array('status' => $status));
        $this->session->set_flashdata('success', 'Category updated.');
        redirect('admin/marketplace/categories');
    }

    /* ------------------------- orders / escrow --------------------------- */

    public function order($public_id) {
        $order = $this->Marketplace_order_model->detail_public($public_id);
        if (!$order) show_404();
        $this->render_order($order, null);
    }

    /** Fulfil an order on the platform's behalf (any operator with manage). */
    public function deliver($public_id) {
        $this->post_only();
        $this->require_perm('marketplace.manage');
        $res = $this->marketplaceservice->deliver(
            $this->current_user, $public_id, $this->input->post('delivery', false), true
        );
        $this->flash_result($res, 'Delivery submitted securely.');
        redirect('admin/marketplace/orders/'.$public_id);
    }

    public function reveal($public_id) {
        $this->post_only();
        $this->require_perm('marketplace.reveal');
        $res = $this->marketplaceservice->reveal($this->current_user, $public_id, true);
        if (empty($res['ok'])) {
            $this->session->set_flashdata('error', $res['error']);
            return redirect('admin/marketplace/orders/'.$public_id);
        }
        $this->render_order($this->Marketplace_order_model->detail_public($public_id), $res['delivery']);
    }

    public function resolve($public_id) {
        $this->post_only();
        $this->require_perm('marketplace.resolve');
        $order = $this->Marketplace_order_model->find_public($public_id);
        if (!$order) show_404();
        $resolution = strtoupper((string)$this->input->post('resolution', true));
        $reason = $this->input->post('reason', true);
        if ($resolution === 'RELEASE') {
            $res = $this->marketplaceservice->release($order, 'ADMIN', $this->current_user->id);
            $success = 'Sale completed; escrow closed in the platform favour.';
        } elseif ($resolution === 'REFUND') {
            $res = $this->marketplaceservice->refund($order, $this->current_user->id, $reason);
            $success = 'Buyer refunded from escrow.';
        } else {
            $res = array('ok' => false, 'error' => 'Choose a valid resolution.');
            $success = '';
        }
        $this->flash_result($res, $success);
        redirect('admin/marketplace/orders/'.$public_id);
    }

    public function moderate_listing($public_id) {
        $this->post_only();
        $this->require_perm('marketplace.moderate_listings');
        $res = $this->marketplaceservice->moderate_listing(
            $public_id, $this->input->post('status', true), $this->current_user->id,
            $this->input->post('note', true)
        );
        $this->flash_result($res, 'Listing moderation saved.');
        redirect('admin/marketplace?tab=listings');
    }

    /* ------------------------------ helpers ----------------------------- */

    private function audit($action, $resource_id, $before, $after) {
        $this->Audit_log_model->record(
            $this->current_user->id, $action, 'marketplace_category', (string)$resource_id,
            $before, $after,
            $this->input->ip_address(), $this->input->user_agent(),
            function_exists('marvy_request_id') ? marvy_request_id() : null
        );
    }

    private function analytics() {
        $db = $this->db;
        $by_status = $db->select('status, COUNT(*) AS n')->group_by('status')
            ->get('marketplace_orders')->result();
        $gmv = $db->select("COALESCE(SUM(gross_amount),0) AS gmv")->get('marketplace_orders')->row();
        $released = $db->select("COALESCE(SUM(gross_amount),0) AS v")
            ->where('status', 'COMPLETED')->get('marketplace_orders')->row();
        $top = $db->select('marketplace_listings.title, COUNT(*) AS n')
            ->from('marketplace_orders')
            ->join('marketplace_listings', 'marketplace_listings.id = marketplace_orders.listing_id', 'inner')
            ->group_by('marketplace_orders.listing_id')
            ->order_by('n', 'DESC')->limit(5)->get()->result();
        $listings = $db->select('status, COUNT(*) AS n')->group_by('status')
            ->get('marketplace_listings')->result();
        return array('by_status' => $by_status, 'gmv' => $gmv->gmv,
            'released' => $released->v, 'top_listings' => $top, 'listings' => $listings);
    }

    private function render_order($order, $plain) {
        $this->view('order', 'Marketplace order', array(
            'order' => $order,
            'events' => $this->Marketplace_order_model->events($order->id),
            'plain' => $plain,
            'can_manage' => $this->auth->can('marketplace.manage'),
        ));
    }

    private function post_only() {
        if ($this->input->method() !== 'post') show_404();
    }

    private function flash_result(array $res, $success) {
        $this->session->set_flashdata(empty($res['ok']) ? 'error' : 'success',
            empty($res['ok']) ? $res['error'] : $success);
    }

    private function view($view, $title, array $data) {
        $this->load->view('layouts/app', array_merge(array(
            'title' => $title,
            'nav_active' => 'admin/marketplace',
            'unread' => $this->dashboardstats->unread_count($this->current_user->id),
            'content_view' => 'admin/marketplace/'.$view,
            'current_user' => $this->current_user,
            'permissions' => $this->auth->permissions(),
        ), $data));
    }
}
