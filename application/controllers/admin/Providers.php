<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Admin/Providers — list, create, test and sync upstream providers.
 *
 * Covers both families: SMM panels and VTU vendors (VTpass). API keys are
 * encrypted at rest via EncryptionService and never returned to the UI
 * (Session 08, 24).
 *
 * Permissions are granular as the role matrix always intended: reading the
 * list needs `providers.view`, running a connection test or a catalogue sync
 * needs `providers.sync`, and creating or editing a provider needs
 * `providers.manage`. Until Session 30 every action gated on `providers.manage`
 * alone, so the other two keys were seeded, granted to STAFF, and enforced
 * nowhere — support could be told they could see the provider list and then
 * meet a 403.
 */
class Providers extends Admin_Controller {

    const PER_PAGE = 20;

    public function __construct() {
        parent::__construct();
        $this->require_perm('providers.view');
        $this->load->model(array('Provider_model', 'Provider_service_model'));
        $this->load->library(array('ProviderSyncService', 'Provider_manager', 'form_validation'));
    }

    public function index() {
        $status = $this->input->get('status', true);
        $search = $this->input->get('q', true);
        $page   = max(1, (int)$this->input->get('page'));
        $limit  = self::PER_PAGE;

        $providers = $this->Provider_model->paginated($limit, ($page-1)*$limit, $status ?: null, $search ?: null);
        $total     = $this->Provider_model->count_all($status ?: null, $search ?: null);

        // Attach service counts without an N+1 query.
        $counts = array();
        if ($providers) {
            $rows = $this->db
                ->select('provider_id, COUNT(*) AS c', false)
                ->where_in('provider_id', array_map(function($p){ return $p->id; }, $providers))
                ->group_by('provider_id')->get('provider_services')->result();
            foreach ($rows as $r) $counts[(int)$r->provider_id] = (int)$r->c;
        }

        $this->load->view('layouts/app', array(
            'title'        => 'Providers',
            'nav_active'   => 'admin/providers',
            'content_view' => 'admin/providers/index',
            'current_user' => $this->current_user,
            'permissions'  => $this->auth->permissions(),
            'unread'       => 0,
            'providers'    => $providers,
            'counts'       => $counts,
            'api_types'    => $this->api_types(),
            'status'       => $status,
            'search'       => $search,
            'page'         => $page,
            'total_pages'  => max(1, (int)ceil($total / $limit)),
            'total'        => $total,
        ));
    }

    public function create() {
        $this->require_perm('providers.manage');
        if ($this->input->method(true) !== 'POST') show_404();

        $result = $this->providersyncservice->create_provider($this->input->post());
        if (!$result['ok']) {
            $this->session->set_flashdata('error', implode(' ', $result['errors']));
            return redirect('admin/providers');
        }
        $this->session->set_flashdata('success', 'Provider "'.$result['provider']->name.'" created. Test the connection before enabling sync.');
        redirect('admin/providers/detail/'.$result['provider']->public_id);
    }

    public function detail($public_id) {
        $provider = $this->Provider_model->find_by_public_id($public_id);
        if (!$provider) show_404();

        $page = max(1, (int)$this->input->get('page'));
        $limit = self::PER_PAGE;
        $family = ProviderSyncService::family($provider);

        // A VTU vendor has no SMM service list; its catalogue is vtu_products.
        if ($family === Provider_manager::FAMILY_VTU) {
            $this->load->model('Vtu_product_model');
            $services = $this->Vtu_product_model->paginated_for_provider($provider->id, $limit, ($page-1)*$limit);
            $total    = $this->Vtu_product_model->count_for_provider($provider->id);
        } else {
            $services = $this->Provider_service_model->paginated_for_provider($provider->id, $limit, ($page-1)*$limit);
            $total    = $this->Provider_service_model->count_for_provider($provider->id);
        }

        $this->load->view('layouts/app', array(
            'title'        => $provider->name,
            'nav_active'   => 'admin/providers',
            'content_view' => 'admin/providers/detail',
            'current_user' => $this->current_user,
            'permissions'  => $this->auth->permissions(),
            'unread'       => 0,
            'provider'     => $provider,
            'family'       => $family,
            'services'     => $services,
            'sync_logs'    => $this->Provider_model->recent_sync_logs($provider->id, 10),
            'health_logs'  => $this->Provider_model->recent_health_logs($provider->id, 10),
            'page'         => $page,
            'total_pages'  => max(1, (int)ceil($total / $limit)),
            'total'        => $total,
        ));
    }

    public function test($public_id) {
        $this->guard_post();
        $provider = $this->Provider_model->find_by_public_id($public_id);
        if (!$provider) show_404();

        $res = $this->providersyncservice->test_connection($provider);
        if ($res['ok']) {
            $this->session->set_flashdata('success',
                'Connection OK — balance '.($res['balance'] !== null ? $res['balance'].' '.$res['currency'] : 'unknown').' ('.$res['latency_ms'].' ms).');
        } else {
            $this->session->set_flashdata('error', 'Connection failed: '.($res['error'] ?? 'unknown error').' ('.$res['latency_ms'].' ms).');
        }
        redirect('admin/providers/detail/'.$public_id);
    }

    public function sync($public_id) {
        $this->guard_post();
        $provider = $this->Provider_model->find_by_public_id($public_id);
        if (!$provider) show_404();

        $res = $this->providersyncservice->sync_services($provider);
        if ($res['ok']) {
            $msg = "Sync complete — {$res['inserted']} new, {$res['updated']} updated ({$res['latency_ms']} ms).";
            if (!empty($res['inserted'])) {
                // New VTU products land inactive and unpriced on purpose.
                $msg .= ' New products are inactive until you set a price.';
            }
            $this->session->set_flashdata('success', $msg);
        } else {
            $this->session->set_flashdata('error', 'Sync failed: '.($res['error'] ?? 'unknown error'));
        }
        redirect('admin/providers/detail/'.$public_id);
    }

    public function sync_balance($public_id) {
        $this->guard_post();
        $provider = $this->Provider_model->find_by_public_id($public_id);
        if (!$provider) show_404();

        $res = $this->providersyncservice->sync_balance($provider);
        if ($res['ok']) {
            $this->session->set_flashdata('success',
                'Balance synced: '.$res['balance'].' '.$res['currency'].' ('.$res['latency_ms'].' ms).');
        } else {
            $this->session->set_flashdata('error', 'Balance sync failed: '.($res['error'] ?? 'unknown error'));
        }
        redirect('admin/providers/detail/'.$public_id);
    }

    /**
     * POST-only, plus the permission the action needs.
     *
     * Defaults to `providers.sync`: test/sync/sync-balance all reach out to a
     * vendor but change no stored credential, which is the distinction the
     * role matrix draws between STAFF and ADMIN here.
     */
    private function guard_post($perm = 'providers.sync') {
        if ($this->input->method(true) !== 'POST') show_404();
        $this->require_perm($perm);
    }

    /**
     * api_types offered by the create form, grouped by family.
     *
     * Read from the registry rather than hardcoded in the view, so a build
     * without an adapter cannot offer it and then fail at the first call.
     */
    private function api_types() {
        $out = array();
        foreach (Provider_manager::families() as $family) {
            foreach (Provider_manager::supported_types($family) as $type) {
                if ($type === 'MOCK' && isset($out['MOCK'])) continue;
                $out[$type] = $family;
            }
        }
        return $out;
    }
}
