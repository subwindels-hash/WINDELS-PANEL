<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Admin/Currencies — Admin → Settings → Currency.
 *
 * Controls which currencies customers may browse the catalogue in, which one
 * is the default, its display format (inherited from the existing
 * `currency_display` setting) and each currency's exchange rate against the
 * base/accounting currency. The base currency itself is never editable here
 * — see CurrencyService's class comment for why.
 *
 * Gated on `settings.manage`, same as the rest of Admin → Settings: this is
 * platform configuration, not a per-order financial action.
 */
class Currencies extends Admin_Controller {

    public function __construct() {
        parent::__construct();
        $this->require_perm('settings.manage');
        $this->load->library(array('CurrencyService', 'SettingsService', 'DashboardStats'));
    }

    /** GET /admin/currencies */
    public function index() {
        $this->load->view('layouts/app', array(
            'title'        => 'Currencies',
            'nav_active'   => 'admin/currencies',
            'content_view' => 'admin/currencies/index',
            'current_user' => $this->current_user,
            'permissions'  => $this->auth->permissions(),
            'unread'       => $this->dashboardstats->unread_count($this->current_user->id),
            'currencies'   => $this->currencyservice->all(),
            'base_currency'=> $this->currencyservice->base_code(),
            'display_currency' => $this->currencyservice->display_code(),
            'currency_display_format' => $this->settingsservice->current()['currency_display'] ?? 'symbol',
            'page_description' => 'The accounting currency is fixed by design (see below); enable other '
                .'currencies for browsing, choose the default, and keep exchange rates current.',
        ));
    }

    /** POST /admin/currencies/active — enable or disable a currency for display. */
    public function set_active() {
        $this->guard();
        $code = $this->input->post('code', true);
        $active = $this->input->post('active') ? true : false;

        $res = $this->currencyservice->set_active($code, $active, $this->current_user->id);
        $this->finish($res, $active ? 'Currency enabled.' : 'Currency disabled.');
    }

    /** POST /admin/currencies/default — set the default display currency. */
    public function set_default() {
        $this->guard();
        $res = $this->currencyservice->set_display_default($this->input->post('code', true), $this->current_user->id);
        $this->finish($res, 'Default display currency updated.');
    }

    /** POST /admin/currencies/rate — manually record an exchange rate. */
    public function set_rate() {
        $this->guard();
        $res = $this->currencyservice->set_rate(
            $this->input->post('code', true),
            $this->input->post('rate', true),
            $this->current_user->id,
            'MANUAL'
        );
        $this->finish($res, 'Exchange rate updated.');
    }

    /* ------------------------------ helpers ----------------------------- */

    private function guard() {
        if ($this->input->method(true) !== 'POST') show_404();
        $this->require_perm('settings.manage');
    }

    private function finish($res, $success_message) {
        $this->session->set_flashdata(empty($res['ok']) ? 'error' : 'success',
            empty($res['ok']) ? $res['error'] : $success_message);
        redirect('admin/currencies');
    }
}
