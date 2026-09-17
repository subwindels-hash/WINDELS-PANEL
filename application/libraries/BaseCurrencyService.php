<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * BaseCurrencyService — redenominate the panel's accounting currency.
 *
 * ## What this does, and why it is not just a setting
 *
 * The base currency is the denomination of every stored money column: a
 * wallet row holding `100.00000000` means ₦100 only because the panel says
 * the base is NGN. Changing that label alone would silently multiply every
 * balance in the system by the exchange rate — a ₦100 wallet would become a
 * $100 wallet. So this service does the only honest thing: it **converts the
 * amounts** at the current rate in the same transaction that moves the label.
 * ₦100 becomes $0.0753, not $100.
 *
 * That is why `base_currency` is not an ordinary key in SettingsService's
 * schema. The generic save path writes a value and stops; this needs to
 * rewrite ~60 money columns atomically, rebase the `currencies` table, and
 * leave an audit trail. Admin → Settings hands the value here instead.
 *
 * ## What is deliberately NOT converted
 *
 *   - **Provider- and customer-charge amounts** (`providers.balance`,
 *     `provider_services.rate`, `provider_transactions.cost`, every
 *     `provider_cost` / `provider_charge`, plus the charge leg on
 *     `payment_transactions` and `fundsvera_checkouts`). A provider row
 *     records what an upstream vendor bills in *their* currency; likewise, a
 *     customer who paid NGN 10,000 still paid NGN 10,000 when our books later
 *     move from USD to EUR. Only the deposit's explicit settlement/base leg
 *     is redenominated.
 *   - **Percentages, multipliers and physical dimensions**
 *     (`commission_percent`, `fee_percent`, `rate_multiplier`, `markup`,
 *     `discount_percent`, `length_cm`...). A percentage is not money.
 *   - **Gift-card face values** (`giftcard_products.face_value`,
 *     `giftcard_orders.face_value`), which are denominated in
 *     `recipient_currency` — a $50 Amazon card is $50 regardless of our books.
 *   - **Crypto quotes** (`blockonomics_addresses.rate_used`, the BTC columns
 *     and `fiat_amount`). Both sides record what the provider quoted at the
 *     time; neither is accounting money to rewrite later.
 *   - **`currencies.exchange_rate`**, which is rebased rather than scaled —
 *     see rebase_currency_table().
 *
 * ## Rows with their own currency column
 *
 * Tables that carry a `currency` column are converted **only where that
 * column equals the old base**. A customer who deliberately holds a USD
 * wallet keeps holding dollars; their balance is not touched, because it was
 * never denominated in the base currency in the first place.
 */
class BaseCurrencyService {

    /**
     * Base-currency money columns, by table.
     *
     * `cols`     — columns holding an amount in the base currency.
     * `currency` — the row's own currency column, when it has one. Rows are
     *              converted only where it matches the old base; the column
     *              is then relabelled to the new base.
     *
     * Every money column in the schema is either listed here or excluded on
     * purpose (see the class docblock) — there is no third category, and a
     * new money column must be classified in one of the two.
     */
    private static function money_map() {
        return array(
            'wallets'               => array('cols' => array('balance', 'total_deposited', 'total_spent'), 'currency' => 'currency'),
            'wallet_transactions'   => array('cols' => array('amount', 'balance_before', 'balance_after'), 'currency' => 'currency'),
            'ledger_entries'        => array('cols' => array('amount'), 'currency' => 'currency'),
            'services'              => array('cols' => array('rate')),
            'service_prices'        => array('cols' => array('rate')),
            'user_service_prices'   => array('cols' => array('rate')),
            'orders'                => array('cols' => array('charge', 'rate_at_order', 'refunded_amount'), 'currency' => 'currency'),
            'cancellation_requests' => array('cols' => array('refund_amount')),
            'dripfeed_orders'       => array('cols' => array('charge'), 'currency' => 'currency'),
            'payment_methods'       => array('cols' => array('min_amount', 'max_amount', 'fee_fixed')),
            // `payment_transactions.amount/currency` is deliberately absent:
            // that is the immutable CUSTOMER CHARGE in the panel default
            // currency. Only its explicit settlement/base leg is converted in
            // convert_money() below.
            'referral_accounts'     => array('cols' => array('total_earned', 'total_paid')),
            'referral_commissions'  => array('cols' => array('amount'), 'currency' => 'currency'),
            'referral_campaigns'    => array('cols' => array('reward_amount', 'budget', 'spent', 'cost')),
            'service_transactions'  => array('cols' => array('amount', 'refunded_amount'), 'currency' => 'currency'),
            'vtu_products'          => array('cols' => array('face_value', 'price', 'min_amount', 'max_amount')),
            'vtu_transactions'      => array('cols' => array('face_value')),
            'number_products'       => array('cols' => array('price')),
            'identity_products'     => array('cols' => array('price')),
            'giftcard_products'     => array('cols' => array('price')),
            'marketplace_listings'  => array('cols' => array('price', 'promo_price'), 'currency' => 'currency'),
            'marketplace_orders'    => array('cols' => array('unit_price', 'gross_amount', 'shipping_cost'), 'currency' => 'currency'),
            // Fundsvera checkout amounts are what the NGN bank rail expected,
            // received and charged. They belong to the immutable charge leg,
            // not to the accounting currency, so they must survive a base
            // currency change byte-for-byte for webhook reconciliation.
            // Blockonomics' fiat_amount/fiat_currency is another immutable
            // provider quote and is excluded for the same reason.
            'earnings'              => array('cols' => array('amount'), 'currency' => 'currency'),
            'payout_requests'       => array('cols' => array('amount'), 'currency' => 'currency'),
            'cart_items'            => array('cols' => array('quoted_unit_price')),
            'coupons'               => array('cols' => array('min_order_amount', 'max_discount_amount'), 'currency' => 'currency'),
            'coupon_redemptions'    => array('cols' => array('discount_amount')),
            'shipping_methods'      => array('cols' => array('price'), 'currency' => 'currency'),
            'shop_order_shipments'  => array('cols' => array('shipping_cost')),
        );
    }

    /** Settings whose value is a base-currency amount. */
    private static function money_settings() {
        return array('min_deposit', 'max_deposit', 'referral_min_payout');
    }

    private $ci;

    public function __construct() {
        $this->ci =& get_instance();
        $this->ci->load->model(array('Currency_model', 'Setting_model'));
    }

    /**
     * The currency the books are currently kept in.
     *
     * Reads the `currencies` table (the `is_base` row is the authority once a
     * switch has happened) and falls back to the helper, which falls back to
     * config. Never throws — a broken read must not make money un-renderable.
     */
    public function current() {
        try {
            $row = $this->ci->Currency_model->base();
            if ($row && !empty($row->code)) return strtoupper($row->code);
        } catch (Throwable $e) {
            log_message('error', 'base currency read failed: '.$e->getMessage());
        }
        return marvy_base_currency();
    }

    /**
     * Redenominate the panel into `$to`, converting every stored amount.
     *
     * Returns ok/error plus the rate used and a per-table row count, so the
     * admin screen can say exactly what moved rather than "saved".
     */
    public function change($to, $actor_id) {
        $to   = strtoupper(trim((string)$to));
        $from = $this->current();

        if ($to === '') return $this->err('NO_CODE', 'Choose a currency.');
        if ($to === $from) {
            return $this->err('UNCHANGED', $from.' is already the base currency.');
        }

        $target = $this->ci->Currency_model->find($to);
        if (!$target) {
            return $this->err('NOT_FOUND', 'Unknown currency '.$to.'. Add it under Admin → Currencies first.');
        }

        // The conversion factor is the target's current rate: units of $to per
        // 1 unit of the old base, which is exactly what `exchange_rate` means
        // while $from is the base. Refuse a missing or zero rate outright —
        // multiplying every balance by 0 is not a recoverable mistake.
        $rate = (string)$target->exchange_rate;
        if (!is_numeric($rate) || bccomp($rate, '0', 8) <= 0) {
            return $this->err('NO_RATE',
                'No usable '.$to.' exchange rate is set. Set the rate under Admin → Currencies first, '
                .'then change the base currency.');
        }

        // A half-applied redenomination is the worst possible state: some
        // balances converted, some not, and no way to tell which. Everything
        // below is one transaction.
        $this->ci->db->trans_begin();
        try {
            $converted = $this->convert_money($from, $to, $rate);
            $this->convert_settings($rate);
            $this->rebase_currency_table($from, $to, $rate);
            $this->ci->Setting_model->set('base_currency', $to, 'general');
        } catch (Throwable $e) {
            $this->ci->db->trans_rollback();
            log_message('error', 'base currency change failed: '.$e->getMessage());
            return $this->err('FAILED', 'Could not change the base currency: '.$e->getMessage()
                .' Nothing was changed.');
        }

        if ($this->ci->db->trans_status() === false) {
            $this->ci->db->trans_rollback();
            return $this->err('FAILED', 'Could not change the base currency. Nothing was changed.');
        }
        $this->ci->db->trans_commit();

        // Drop every memo that could still be holding the old base.
        Currency_model::forget();
        if (function_exists('marvy_forget_base_currency')) marvy_forget_base_currency();
        // The pay currency is derived from the base one, so it is stale too.
        if (function_exists('marvy_forget_display_currency')) marvy_forget_display_currency();
        if (class_exists('Setting_model') && method_exists('Setting_model', 'flush_cache')) {
            Setting_model::flush_cache();
        }

        $this->audit($actor_id, $from, $to, $rate, $converted);

        return array('ok' => true, 'from' => $from, 'to' => $to,
                     'rate' => $rate, 'converted' => $converted);
    }

    /* ------------------------------------------------------------------ */

    /**
     * Scale every base-currency money column by the rate, and relabel the
     * rows that carried the old code.
     *
     * Done in SQL rather than row-by-row in PHP: a wallet_transactions table
     * on a live panel is millions of rows, and pulling them through PHP would
     * hold the transaction open long enough to lock out the whole site.
     */
    private function convert_money($from, $to, $rate) {
        $counts = array();
        foreach (self::money_map() as $table => $spec) {
            if (!$this->ci->db->table_exists($table)) continue;

            $sets = array();
            foreach ($spec['cols'] as $col) {
                if (!$this->ci->db->field_exists($col, $table)) continue;
                // NULL stays NULL — an absent amount is not zero.
                $sets[] = "`{$col}` = ROUND(`{$col}` * ?, 8)";
            }
            if (!$sets) continue;

            $params = array_fill(0, count($sets), $rate);
            $sql    = "UPDATE `{$table}` SET ".implode(', ', $sets);

            $cur_col = isset($spec['currency']) ? $spec['currency'] : null;
            if ($cur_col && $this->ci->db->field_exists($cur_col, $table)) {
                // Only rows actually denominated in the old base, and relabel
                // them in the same statement so a row can never end up
                // converted but still claiming the old currency.
                $sql .= ", `{$cur_col}` = ? WHERE `{$cur_col}` = ?";
                $params[] = $to;
                $params[] = $from;
            }

            $this->ci->db->query($sql, $params);
            $counts[$table] = (int)$this->ci->db->affected_rows();
        }

        // The settlement leg of a deposit (migration 042). It is keyed on its
        // own `base_currency` column rather than `currency`, so it cannot ride
        // along in the generic map: on a converted deposit `currency` is the
        // charge currency (NGN) while the amounts being redenominated here are
        // the base ones (USD).
        //
        // `fx_rate` moves the opposite way. It is "units of the charge
        // currency per 1 unit of base", so re-expressing it against the new
        // base DIVIDES by the rate — the same rebasing the currencies table
        // gets, for the same reason. Scaling it like an amount would rewrite
        // what each historical customer was actually quoted.
        if ($this->ci->db->table_exists('payment_transactions')
                && $this->ci->db->field_exists('base_currency', 'payment_transactions')) {
            $this->ci->db->query(
                "UPDATE `payment_transactions`
                    SET `base_amount`          = ROUND(`base_amount` * ?, 8),
                        `credited_base_amount` = ROUND(`credited_base_amount` * ?, 8),
                        `fx_rate`              = ROUND(`fx_rate` / ?, 8),
                        `base_currency`        = ?
                  WHERE `base_currency` = ?",
                array($rate, $rate, $rate, $to, $from)
            );
            $counts['payment_transactions_base_leg'] = (int)$this->ci->db->affected_rows();
        }

        // Fixed-amount coupons only: a percentage coupon's "value" is a
        // percentage and must not be scaled.
        if ($this->ci->db->table_exists('coupons') && $this->ci->db->field_exists('discount_type', 'coupons')) {
            $this->ci->db->query(
                "UPDATE `coupons` SET `discount_value` = ROUND(`discount_value` * ?, 8) WHERE `discount_type` = 'FIXED'",
                array($rate)
            );
            $counts['coupons_fixed_value'] = (int)$this->ci->db->affected_rows();
        }

        return $counts;
    }

    /** Scale the settings that hold a base-currency amount. */
    private function convert_settings($rate) {
        foreach (self::money_settings() as $key) {
            $current = $this->ci->Setting_model->get($key, null);
            if ($current === null || $current === '' || !is_numeric($current)) continue;
            $this->ci->Setting_model->set($key, bcmul((string)$current, $rate, 8), 'payments');
        }
    }

    /**
     * Rebase `currencies` so the new base is 1.0 and every other rate is
     * re-expressed against it.
     *
     * Rates are "units of this currency per 1 unit of base". Moving the base
     * from $from to $to divides every rate by the $to rate — the same
     * arithmetic migration 011 did by hand for USD → NGN, done generically.
     * The old base, which had no row value of its own beyond 1.0, becomes
     * 1/rate.
     */
    private function rebase_currency_table($from, $to, $rate) {
        $this->ci->db->query("UPDATE `currencies` SET `is_base` = 0");
        $this->ci->db->query(
            "UPDATE `currencies` SET `exchange_rate` = ROUND(`exchange_rate` / ?, 8) WHERE `code` <> ?",
            array($rate, $from)
        );
        // The old base did not describe itself with a rate (it was the 1.0
        // row); against the new base it is worth 1/rate.
        $this->ci->db->query(
            "UPDATE `currencies` SET `exchange_rate` = ROUND(1 / ?, 8), `is_active` = 1 WHERE `code` = ?",
            array($rate, $from)
        );
        $this->ci->db->query(
            "UPDATE `currencies` SET `is_base` = 1, `is_active` = 1, `exchange_rate` = '1.00000000',
                    `rate_source` = 'REBASE', `rate_updated_at` = ?, `updated_at` = ? WHERE `code` = ?",
            array(gmdate('Y-m-d H:i:s'), gmdate('Y-m-d H:i:s'), $to)
        );
    }

    private function audit($actor_id, $from, $to, $rate, array $converted) {
        try {
            $this->ci->load->model('Audit_log_model');
            $this->ci->Audit_log_model->record(
                $actor_id ?: null, 'settings.base_currency_changed', 'currencies', $to,
                array('base_currency' => $from),
                array('base_currency' => $to, 'rate' => $rate, 'rows_converted' => $converted),
                isset($this->ci->input) ? $this->ci->input->ip_address() : null,
                isset($this->ci->input) ? $this->ci->input->user_agent() : null,
                method_exists($this->ci, 'request_id') ? $this->ci->request_id() : null
            );
        } catch (Throwable $e) {
            log_message('error', 'base currency audit failed: '.$e->getMessage());
        }
    }

    private function err($code, $message) {
        return array('ok' => false, 'code' => $code, 'error' => $message);
    }
}
