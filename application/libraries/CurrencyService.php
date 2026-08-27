<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * CurrencyService — display-currency configuration and conversion.
 *
 * ## What this is
 *
 * The panel's accounting/settlement currency (`marvy_base_currency()`, NGN by
 * default) stays exactly what it has always been: every wallet, order,
 * payment, earning and payout is charged, refunded and paid out in it, and
 * nothing here changes that. This service only controls what a *browsing*
 * customer sees the catalogue priced in — a display conversion, not a second
 * settlement currency. Checkout still charges the wallet in the base
 * currency; a converted price shown on a product card is informational.
 *
 * ## Why this separation instead of "just let people pay in USD"
 *
 * Accepting a different settlement currency means every order, refund and
 * commission calculation would need to carry an exchange rate snapshot at the
 * moment of charge, and every domain service (OrderService, TransactionEngine,
 * MarketplaceService, GiftcardService, PayoutService) would need rewiring to
 * agree on it. That is a large, high-risk change to core money-movement code.
 * This service is the safe, additive slice: real admin control over which
 * currencies are enabled, what customers see by default, and a fully audited
 * exchange rate with provenance — without touching a single charge path.
 *
 * ## Where the rate comes from
 *
 * `currencies.exchange_rate` is "units of this currency per 1 unit of the
 * base currency" — the same convention migration 011 established. Rates are
 * manual today (`rate_source = 'MANUAL'` or `'SEED'`); `set_rate()` accepts an
 * arbitrary source string so a future automatic provider integration is a
 * pure addition, not a rewrite of this class or the schema.
 */
class CurrencyService {

    /** Currencies this build ships symbols/support for out of the box. */
    const KNOWN = array('NGN', 'USD', 'EUR', 'GBP', 'INR', 'BRL');

    private $ci;

    public function __construct() {
        $this->ci =& get_instance();
        $this->ci->load->model(array('Currency_model', 'Setting_model'));
    }

    /** Every configured currency (admin view). */
    public function all() {
        return $this->ci->Currency_model->all_rows();
    }

    /** Currencies a customer may currently choose to browse in. */
    public function active() {
        return $this->ci->Currency_model->active();
    }

    /** The immutable accounting/settlement currency. Never changes here. */
    public function base_code() {
        return marvy_base_currency();
    }

    /**
     * The currency browsing customers see prices converted into.
     *
     * Falls back to the base currency when unset, disabled, or the setting
     * points at a currency that no longer exists — a stale/invalid setting
     * must never make prices disappear or throw.
     */
    public function display_code() {
        $code = strtoupper((string)$this->ci->Setting_model->get('default_display_currency', $this->base_code()));
        $row = $this->ci->Currency_model->find($code);
        if ($row && (int)$row->is_active === 1) return $code;
        return $this->base_code();
    }

    /**
     * Convert an amount denominated in the base currency into `$to` (defaults
     * to the configured display currency). Returns the amount unchanged if
     * `$to` is the base currency or is not a known, active currency.
     */
    public function convert($amount, $to = null) {
        $to = strtoupper((string)($to ?: $this->display_code()));
        if ($to === $this->base_code()) return (string)$amount;
        $row = $this->ci->Currency_model->find($to);
        if (!$row || (int)$row->is_active !== 1) return (string)$amount;
        return bcmul((string)$amount, (string)$row->exchange_rate, 8);
    }

    /**
     * Format an amount as the given (or configured display) currency, honouring
     * the currency's own decimal precision and the panel's symbol/code display
     * setting — the same formatting rules marvy_money() applies for the base
     * currency, extended to any configured currency.
     */
    public function format($amount, $code = null) {
        $code = strtoupper((string)($code ?: $this->display_code()));
        $row = $this->ci->Currency_model->find($code);
        $decimals = $row ? (int)$row->decimal_precision : 2;
        $formatted = number_format((float)$amount, $decimals, '.', ',');

        $display = strtolower((string)$this->ci->Setting_model->get('currency_display', 'symbol'));
        if ($display === 'code') return $code.' '.$formatted;

        $symbol = $row ? $row->symbol : ($code.' ');
        return $symbol.$formatted;
    }

    /** Convert-then-format in one call: the base-currency amount shown in the display currency. */
    public function display($amount, $to = null) {
        $to = $to ?: $this->display_code();
        return $this->format($this->convert($amount, $to), $to);
    }

    /* ------------------------------------------------------------------ */
    /* Admin mutations                                                     */
    /* ------------------------------------------------------------------ */

    /** Enable or disable a currency for display. The base currency can never be disabled. */
    public function set_active($code, $active, $actor_id) {
        $code = strtoupper(trim((string)$code));
        $row = $this->ci->Currency_model->find($code);
        if (!$row) return $this->err('NOT_FOUND', 'Unknown currency.');
        if ((int)$row->is_base === 1 && !$active) {
            return $this->err('BASE_CURRENCY', 'The base currency cannot be disabled.');
        }
        $before = (int)$row->is_active;
        if (!$this->ci->Currency_model->set_active($code, $active)) {
            return $this->err('UPDATE_FAILED', 'Could not update that currency.');
        }
        $this->audit($actor_id, 'currency.active_changed', $code,
            array('is_active' => $before), array('is_active' => $active ? 1 : 0));

        // If the currency being disabled was the configured display default,
        // fall back to the base currency rather than leaving a dangling
        // setting that quietly stops converting anything.
        if (!$active && strtoupper((string)$this->ci->Setting_model->get('default_display_currency', '')) === $code) {
            $this->ci->Setting_model->set('default_display_currency', $this->base_code(), 'currency');
        }
        return array('ok' => true);
    }

    /**
     * Set the default display currency. Must be active (or the base currency,
     * which is always active); refuses silently-wrong configuration rather
     * than accepting a code nothing can convert into.
     */
    public function set_display_default($code, $actor_id) {
        $code = strtoupper(trim((string)$code));
        $row = $this->ci->Currency_model->find($code);
        if (!$row) return $this->err('NOT_FOUND', 'Unknown currency.');
        if ((int)$row->is_active !== 1) {
            return $this->err('INACTIVE', 'Enable that currency before making it the default.');
        }
        $before = (string)$this->ci->Setting_model->get('default_display_currency', $this->base_code());
        $this->ci->Setting_model->set('default_display_currency', $code, 'currency');
        if ($before !== $code) {
            $this->audit($actor_id, 'currency.default_display_changed', $code,
                array('default_display_currency' => $before), array('default_display_currency' => $code));
        }
        return array('ok' => true);
    }

    /**
     * Manually record an exchange rate. Rejects a non-positive or absurd rate
     * outright — a fat-fingered "0" or a rate with a misplaced decimal is not
     * a policy decision, it is silent data corruption for every price shown
     * to a customer in that currency, and this is exactly the failure mode
     * the platform explicitly must not allow to pass silently.
     */
    public function set_rate($code, $rate, $actor_id, $source = 'MANUAL') {
        $code = strtoupper(trim((string)$code));
        $row = $this->ci->Currency_model->find($code);
        if (!$row) return $this->err('NOT_FOUND', 'Unknown currency.');
        if ((int)$row->is_base === 1) {
            return $this->err('BASE_CURRENCY', 'The base currency is always exactly 1.0 and cannot be changed.');
        }
        if (!is_numeric($rate) || bccomp((string)$rate, '0', 8) <= 0) {
            return $this->err('BAD_RATE', 'The exchange rate must be a positive number.');
        }
        // Sanity bound: catches an obvious data-entry mistake (e.g. typing the
        // inverse rate) without hard-coding what a "normal" rate looks like
        // for every possible currency pair.
        if (bccomp((string)$rate, '1000000', 8) > 0) {
            return $this->err('BAD_RATE', 'That rate looks like a mistake — double-check the direction '
                .'(units of '.$code.' per 1 unit of '.$this->base_code().').');
        }

        $before = (string)$row->exchange_rate;
        if (!$this->ci->Currency_model->set_rate($code, $rate, $actor_id, $source)) {
            return $this->err('UPDATE_FAILED', 'Could not update that exchange rate.');
        }
        $this->audit($actor_id, 'currency.rate_changed', $code,
            array('exchange_rate' => $before), array('exchange_rate' => number_format((float)$rate, 8, '.', ''), 'source' => $source));
        return array('ok' => true);
    }

    /* ------------------------------------------------------------------ */

    private function err($code, $message) {
        return array('ok' => false, 'code' => $code, 'error' => $message);
    }

    private function audit($actor_id, $action, $entity, $before = null, $after = null) {
        try {
            $this->ci->load->model('Audit_log_model');
            $this->ci->Audit_log_model->record(
                $actor_id ?: null, $action, 'currencies', (string)$entity, $before, $after,
                isset($this->ci->input) ? $this->ci->input->ip_address() : null,
                isset($this->ci->input) ? $this->ci->input->user_agent() : null,
                method_exists($this->ci, 'request_id') ? $this->ci->request_id() : null
            );
        } catch (Throwable $e) {
            log_message('error', 'currency audit failed: '.$e->getMessage());
        }
    }
}
