<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Admin/Catalogue — pricing and shelf control for all four product domains.
 *
 * Every catalogue in this panel imports the same way on purpose: a vendor sync
 * writes `is_active = 0, price = NULL`, because the vendor knows its own cost
 * and nothing about our margin. That rule protects the business and, until
 * this screen, left it with no way out — a fresh install has 18 VTU networks,
 * 7 number countries, 11 number services, 8 gift card brands and 3 identity
 * checks, and not one sellable product. Putting a data bundle on sale meant
 * hand-written SQL against production.
 *
 * This is that missing screen. One controller over four domains rather than
 * four near-identical ones, because the operator's job is the same in each:
 * find the unpriced rows, price them, switch them on. The rules that differ
 * (one variable-amount product per network, a gift card's own currency, no
 * BVN-by-phone) live in CatalogueService, so a rule cannot hold on one screen
 * and not another.
 *
 * Reading requires `services.view` — STAFF may look at what is on sale.
 * Changing a price or a switch requires `pricing.manage`, which STAFF does not
 * have: a price is money. Every mutation is POST-only, CSRF-protected and
 * audit-logged with the full before/after row, because "who dropped the price
 * on Steam cards" must be answerable months later.
 */
class Catalogue extends Admin_Controller {

    const PER_PAGE = 25;

    public function __construct() {
        parent::__construct();
        $this->require_perm('services.view');
        $this->load->library(array('CatalogueService', 'DashboardStats'));
        $this->load->model(array(
            'Vtu_product_model', 'Vtu_network_model',
            'Number_product_model', 'Number_country_model', 'Number_service_model',
            'Identity_product_model',
            'Giftcard_product_model', 'Giftcard_brand_model',
            'Provider_model', 'Audit_log_model',
        ));
    }

    /** The catalogue opens on VTU, the domain with the most rows. */
    public function index() {
        redirect('admin/catalogue/vtu');
    }

    /** GET /admin/catalogue/:domain — the grid for one domain. */
    public function domain($domain) {
        if (!CatalogueService::is_domain($domain)) show_404();

        $filters = $this->filters($domain);
        $page    = max(1, (int)$this->input->get('page'));
        $limit   = self::PER_PAGE;

        $grid = $this->catalogueservice->grid($domain, $filters, $limit, ($page - 1) * $limit);
        $total = (int)$grid['total'];

        $this->render($domain, 'admin/catalogue/index', CatalogueService::label($domain), array(
            'rows'        => $grid['rows'],
            'filters'     => $filters,
            'options'     => $this->catalogueservice->options($domain),
            'page'        => $page,
            'total'       => $total,
            'total_pages' => max(1, (int)ceil($total / $limit)),
        ));
    }

    /** GET /admin/catalogue/:domain/:id — one product's edit form. */
    public function edit($domain, $public_id) {
        if (!CatalogueService::is_domain($domain)) show_404();
        $product = $this->catalogueservice->find($domain, $public_id);
        if (!$product) show_404();

        $this->render($domain, 'admin/catalogue/edit', $product->name, array(
            'product' => $product,
            'options' => $this->catalogueservice->options($domain),
        ));
    }

    /** POST /admin/catalogue/:domain/create — add a product by hand. */
    public function create($domain) {
        $this->guard($domain);

        $res = $this->catalogueservice->save($domain, null, $this->input->post(null, true));
        if (empty($res['ok'])) {
            return $this->fail($domain, $res['error']);
        }

        $product = $res['product'];
        $this->audit('catalogue.created', $domain, $product, null, $this->row($product));
        $this->flash($res, 'Product "'.$product->name.'" created.');
        redirect('admin/catalogue/'.$domain.'/'.$product->public_id);
    }

    /** POST /admin/catalogue/:domain/:id/update — price and details. */
    public function update($domain, $public_id) {
        $product = $this->guard($domain, $public_id);

        $res = $this->catalogueservice->save($domain, $product, $this->input->post(null, true));
        if (empty($res['ok'])) {
            return $this->fail($domain, $res['error'], $product);
        }

        $this->audit('catalogue.updated', $domain, $res['product'], $res['before'],
            $this->row($res['product']));
        $this->flash($res, 'Saved.');
        redirect('admin/catalogue/'.$domain.'/'.$res['product']->public_id);
    }

    /**
     * POST /admin/catalogue/:domain/:id/status — put a product on or off sale.
     *
     * Separate from update() because it is the one action an operator takes in
     * a hurry, and because switching *off* must never be blocked by a
     * validation rule — see CatalogueService::set_status().
     */
    public function status($domain, $public_id) {
        $product = $this->guard($domain, $public_id);
        $active  = $this->input->post('is_active') === '1';

        $res = $this->catalogueservice->set_status($domain, $product, $active);
        if (empty($res['ok'])) {
            return $this->fail($domain, $res['error'], $product);
        }

        $this->audit('catalogue.'.($active ? 'activated' : 'deactivated'), $domain,
            $product, array('is_active' => (int)$product->is_active),
            array('is_active' => $active ? 1 : 0));
        $this->flash($res, $active
            ? '"'.$product->name.'" is now on sale.'
            : '"'.$product->name.'" has been taken off sale.');
        redirect('admin/catalogue/'.$domain.'/'.$product->public_id);
    }

    /* ----------------------------- helpers ----------------------------- */

    private function filters($domain) {
        $f = array(
            'status'  => $this->input->get('status', true),
            'pricing' => $this->input->get('pricing', true),
            'search'  => $this->input->get('q', true),
        );
        if ($domain === 'vtu') {
            $f['network_id']   = (int)$this->input->get('network');
            $f['service_type'] = $this->input->get('type', true);
        } elseif ($domain === 'numbers') {
            $f['country_id'] = (int)$this->input->get('country');
            $f['service_id'] = (int)$this->input->get('service');
        } elseif ($domain === 'identity') {
            $f['id_type'] = $this->input->get('type', true);
        } elseif ($domain === 'giftcards') {
            $f['brand_id']          = (int)$this->input->get('brand');
            $f['denomination_type'] = $this->input->get('type', true);
        }
        return $f;
    }

    private function render($domain, $view, $title, array $data) {
        $this->load->view('layouts/app', array_merge(array(
            'title'        => $title,
            'nav_active'   => 'admin/catalogue',
            'content_view' => $view,
            'current_user' => $this->current_user,
            'permissions'  => $this->auth->permissions(),
            'unread'       => $this->dashboardstats->unread_count($this->current_user->id),
            'domain'       => $domain,
            'domains'      => CatalogueService::domains(),
        ), $data));
    }

    /** POST-only + permission + existence, shared by every mutation. */
    private function guard($domain, $public_id = null) {
        if ($this->input->method(true) !== 'POST') show_404();
        if (!CatalogueService::is_domain($domain)) show_404();
        $this->require_perm('pricing.manage');
        if ($public_id === null) return null;

        $product = $this->catalogueservice->find($domain, $public_id);
        if (!$product) show_404();
        return $product;
    }

    private function fail($domain, $message, $product = null) {
        $this->session->set_flashdata('error', $message);
        redirect('admin/catalogue/'.$domain.($product ? '/'.$product->public_id : ''));
    }

    /**
     * One flash for the outcome, one for anything the operator should know but
     * that is not a reason to refuse the change (selling below cost, an
     * inactive brand, no amount limits).
     */
    private function flash(array $res, $message) {
        $this->session->set_flashdata('success', $message);
        if (!empty($res['warnings'])) {
            $this->session->set_flashdata('warning', implode(' ', $res['warnings']));
        }
    }

    /** The stored row as an array, for the audit trail. */
    private function row($product) {
        return $product ? get_object_vars($product) : null;
    }

    /** The table an audit entry should name, per domain. */
    private function table($domain) {
        $map = array(
            'vtu'       => 'vtu_products',
            'numbers'   => 'number_products',
            'identity'  => 'identity_products',
            'giftcards' => 'giftcard_products',
        );
        return isset($map[$domain]) ? $map[$domain] : $domain;
    }

    private function audit($action, $domain, $product, $before, $after) {
        $this->Audit_log_model->record(
            $this->current_user->id, $action, $this->table($domain),
            $product ? (string)$product->id : null,
            $before, $after,
            $this->input->ip_address(), $this->input->user_agent(), $this->request_id
        );
    }
}
