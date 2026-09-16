<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Admin/Settings — the panel's configuration.
 *
 * `admin/settings` has been the last entry in the admin sidebar since Session
 * 15 and 404'd for every operator, so `settings.manage` gated nothing and
 * every value seeded in Session 02 could only be changed with SQL.
 *
 * The schema, validation and the list of deliberately-omitted keys live in
 * SettingsService. Two things are worth knowing before reading further:
 *
 *   - Settings that no code reads are **not** rendered as controls. The screen
 *     lists them instead, with what each would need to work. A switch that
 *     saves and does nothing is worse than no switch.
 *   - `base_currency` is handled by BaseCurrencyService, which redenominates
 *     every stored money column at the current rate in a single transaction.
 */
class Settings extends Admin_Controller {

    public function __construct() {
        parent::__construct();
        $this->require_perm('settings.manage');
        $this->load->library(array('SettingsService', 'DashboardStats'));
        $this->load->model(array('Setting_model', 'Audit_log_model'));
    }

    public function index() {
        $this->load->view('layouts/app_theme', array(
            'title'        => 'Settings',
            'nav_active'   => 'admin/settings',
            'content_view' => 'admin/settings/index',
            'current_user' => $this->current_user,
            'permissions'  => $this->auth->permissions(),
            'unread'       => $this->dashboardstats->unread_count($this->current_user->id),
            'grouped'      => $this->settingsservice->grouped(),
            'values'       => $this->settingsservice->current(),
            'unwired'      => SettingsService::unwired(),
            'readonly'     => SettingsService::readonly_settings(),
            'base_currency'=> marvy_base_currency(),
            // Real rows, not a hardcoded list: a currency can only become the
            // base if it exists and has a rate to convert the books with.
            'base_currency_choices' => $this->base_currency_choices(),
            'page_description' => 'Panel-wide configuration. Every change is recorded in the audit log.',
        ));
    }

    /** POST /admin/settings/save — validate and persist. */
    /** GET|POST /admin/settings/flags */
    public function flags() {
        if ($this->input->method(true) === 'POST') {
            return $this->save_flags();
        }
        $this->load->model('Feature_flag_model');
        $this->load->view('layouts/app', array(
            'title'        => 'Feature flags',
            'nav_active'   => 'admin/settings',
            'content_view' => 'admin/settings/flags',
            'current_user' => $this->current_user,
            'permissions'  => $this->auth->permissions(),
            'unread'       => $this->dashboardstats->unread_count($this->current_user->id),
            'flags'        => $this->Feature_flag_model->all_rows(),
            'page_description' => 'Turn product modules on or off without a deploy.',
        ));
    }

    /** POST /admin/settings/flags */
    public function save_flags() {
        $this->guard();
        $this->load->model('Feature_flag_model');
        $posted = $this->input->post('flags');
        $posted = is_array($posted) ? $posted : array();
        foreach ($this->Feature_flag_model->all_rows() as $row) {
            $on = !empty($posted[$row->flag_key]);
            $this->Feature_flag_model->set_enabled($row->flag_key, $on);
        }
        $this->audit('settings.feature_flags', array('flags' => array('before' => null, 'after' => $posted)));
        $this->session->set_flashdata('success', 'Feature flags saved.');
        redirect('admin/settings/flags');
    }

    public function save() {
        $this->guard();

        $post = $this->input->post(null, true);

        // The base currency is not an ordinary setting: switching it has to
        // convert every stored amount, so it is handled before the generic
        // save and reported separately. Previously the form posted this key,
        // SettingsService silently dropped it (not in its schema), and the
        // operator was told "Nothing changed" — the value never moved.
        $base_result = $this->maybe_change_base_currency($post);
        if ($base_result !== null && empty($base_result['ok'])) {
            $this->session->set_flashdata('error', $base_result['error']);
            redirect('admin/settings');
        }

        $res = $this->settingsservice->save($post);
        if (empty($res['ok'])) {
            $this->session->set_flashdata('error', $res['error']);
            redirect('admin/settings');
        }

        if (empty($res['changed']) && $base_result === null) {
            $this->session->set_flashdata('warning', 'Nothing changed.');
            redirect('admin/settings');
        }

        if ($base_result !== null) {
            $rows = array_sum($base_result['converted']);
            $this->session->set_flashdata('success',
                'Base currency changed from '.$base_result['from'].' to '.$base_result['to']
                .' at a rate of '.rtrim(rtrim(number_format((float)$base_result['rate'], 8, '.', ''), '0'), '.')
                .'. '.number_format($rows).' stored amount'.($rows === 1 ? '' : 's')
                .' were converted.'
                .(empty($res['changed']) ? '' : ' '.count($res['changed']).' other setting'
                    .(count($res['changed']) === 1 ? '' : 's').' updated.'));
            if (!empty($res['changed'])) $this->audit('settings.updated', $res['changed']);
            redirect('admin/settings');
        }

        // One audit entry carrying every before/after pair: settings change
        // rarely and matter a lot, so "who raised the commission" must stay
        // answerable months later.
        $this->audit('settings.updated', $res['changed']);
        $this->session->set_flashdata('success',
            count($res['changed']).' setting'.(count($res['changed']) === 1 ? '' : 's').' updated.');
        redirect('admin/settings');
    }

    /* ----------------------------- helpers ----------------------------- */

    /**
     * Apply a base-currency change if the form asked for one.
     *
     * Returns null when the field was absent or already matches (so the rest
     * of the save behaves exactly as before), the service result otherwise.
     */
    private function maybe_change_base_currency(array &$post) {
        if (!array_key_exists('base_currency', $post)) return null;

        $requested = strtoupper(trim((string)$post['base_currency']));
        // Never let it fall through to the generic saver, which would write a
        // settings row that disagrees with the ledger.
        unset($post['base_currency']);

        $this->load->library('BaseCurrencyService');
        if ($requested === '' || $requested === $this->basecurrencyservice->current()) {
            return null;
        }
        $result = $this->basecurrencyservice->change($requested, $this->current_user->id);
        if (!empty($result['ok'])) {
            // This request already rendered prices in the old currency; drop
            // the memo so the redirect target is truthful.
            if (function_exists('marvy_forget_base_currency')) marvy_forget_base_currency();
        }
        return $result;
    }

    /**
     * Currencies that can legally become the base, labelled with the rate the
     * conversion would use. A currency with no usable rate is not offered —
     * selecting it could only fail.
     */
    private function base_currency_choices() {
        $this->load->library('CurrencyService');
        $current = marvy_base_currency();
        $out = array();
        try {
            foreach ($this->currencyservice->all() as $row) {
                $code = strtoupper($row->code);
                if ($code === $current) {
                    $out[$code] = $code.' — '.$row->name.' (current)';
                    continue;
                }
                if (bccomp((string)$row->exchange_rate, '0', 8) <= 0) continue;
                $out[$code] = $code.' — '.$row->name
                    .' (1 '.$current.' = '.rtrim(rtrim(number_format((float)$row->exchange_rate, 8, '.', ''), '0'), '.').' '.$code.')';
            }
        } catch (Throwable $e) {
            log_message('error', 'base currency choices unavailable: '.$e->getMessage());
        }
        if (!$out) $out = array($current => $current);
        return $out;
    }

    private function guard() {
        if ($this->input->method(true) !== 'POST') show_404();
        $this->require_perm('settings.manage');
    }

    private function audit($action, array $changed) {
        $before = array();
        $after  = array();
        foreach ($changed as $key => $pair) {
            $before[$key] = $pair['before'];
            $after[$key]  = $pair['after'];
        }
        $this->Audit_log_model->record(
            $this->current_user->id, $action, 'settings', null,
            $before, $after,
            $this->input->ip_address(), $this->input->user_agent(), $this->request_id
        );
    }
}
