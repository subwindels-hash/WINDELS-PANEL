<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * PaymentService — wallet top-ups and webhook reconciliation (Session 11).
 *
 * Responsibilities:
 *   - create a PaymentTransaction (CREATED/PENDING) with idempotency
 *   - delegate to a GatewayInterface for initiation
 *   - on confirmed payment (manual approval or webhook) credit the wallet
 *     exactly once through LedgerService, recording the wallet_transaction_id
 *
 * Reconciliation is idempotent: a given (gateway, event_id) is stored once,
 * and a transaction can only move to SUCCESS once. No controller credits a
 * wallet directly.
 */
class PaymentService {

    const IDEM_SCOPE = 'payment:deposit';
    const STATUS_CREATED = 'CREATED';
    const STATUS_PENDING = 'PENDING';
    const STATUS_SUCCESS = 'SUCCESS';
    const STATUS_FAILED  = 'FAILED';

    /** Hosted card gateways the panel can route a card payment through. */
    const CARD_GATEWAY_CODES = array('paystack', 'flutterwave', 'razorpay', 'stripe');

    /**
     * Deposit methods whose charge currency is always the panel default.
     *
     * These are the two bank-transfer choices shown on Add Funds. A caller may
     * not force either one back to the accounting/base currency by posting a
     * different `currency`: the amount field is labelled in the default
     * currency, so accepting that override would make the instructions and
     * the transaction disagree about what the customer owes.
     */
    const DEFAULT_CURRENCY_METHODS = array('manual', 'fundsvera');

    private $ci;

    public function __construct() {
        $this->ci =& get_instance();
        $this->ci->load->model(array(
            'Payment_transaction_model','Payment_webhook_model','Payment_event_model',
            'Wallet_model','Setting_model',
        ));
        $this->ci->load->library(array('LedgerService','EncryptionService'));
        // Gateway classes are plain (unnamespaced) library files: CI3 does not
        // autoload them and composer's PSR-4 prefix does not cover them, so
        // they must be required explicitly — `new ManualGateway` in deposit()/
        // record_webhook() fataled ("Class not found") on every live deposit.
        if (!interface_exists('GatewayInterface', false)) {
            require_once APPPATH.'libraries/GatewayInterface.php';
        }
        // Every hosted adapter extends HostedGateway, so the base class has to
        // be present before any of them is required.
        if (!class_exists('HostedGateway', false)) {
            require_once APPPATH.'libraries/HostedGateway.php';
        }
        if (!class_exists('ManualGateway', false)) {
            require_once APPPATH.'libraries/ManualGateway.php';
        }
        if (!class_exists('BlockonomicsGateway', false)) {
            require_once APPPATH.'libraries/BlockonomicsGateway.php';
        }
        if (!class_exists('FundsveraGateway', false)) {
            require_once APPPATH.'libraries/FundsveraGateway.php';
        }
        foreach (array('StripeGateway','PaypalGateway','FlutterwaveGateway','RazorpayGateway','PaystackGateway','CoinpaymentsGateway') as $gw) {
            if (!class_exists($gw, false) && is_file(APPPATH.'libraries/'.$gw.'.php')) {
                require_once APPPATH.'libraries/'.$gw.'.php';
            }
        }
    }

    /**
     * Initialise a deposit.
     *
     * ## Which currency the customer is charged in
     *
     * The amount the customer typed is in the PAY currency — the panel's
     * default display currency, the one every price on the site is quoted in
     * (`marvy_pay_currency()`). That is what the gateway is handed, so a
     * Nigerian customer reading ₦ prices is charged ₦ rather than being
     * bounced to a dollar checkout because the books happen to be kept in USD.
     *
     * The wallet is still credited in the BASE currency. The conversion
     * happens once, here, at a rate PINNED on the deposit row (`fx_rate`), and
     * `confirm()` credits `credited_base_amount` — never a figure re-derived
     * from the rate of the day. The customer pays at the rate they were shown
     * on Add Funds, whatever the market does between "Continue" and the
     * webhook.
     *
     * Deposit bounds (`payment_methods.min_amount` / `max_amount`) are stored
     * in the base currency like every other money column, so they are
     * compared against the base-currency leg, not the typed amount.
     *
     * @param object $user
     * @param array $input  payment_method (code), amount, currency, idempotency_key?
     * @return array{ok:bool, transaction?:object, redirect_url?:string, checkout?:array, error?:string, code?:string}
     */
    public function deposit($user, array $input) {
        $method = $this->resolve_method($input['payment_method'] ?? null);
        if (!$method) return array('ok'=>false,'error'=>'Unknown payment method','code'=>'NO_METHOD');
        if (!(int)$method->is_active) return array('ok'=>false,'error'=>'That payment method is unavailable','code'=>'METHOD_INACTIVE');

        // Do not let a code-first cPanel update fall through to an INSERT that
        // references migration-042 columns the live database does not have.
        // The admin queue remains readable through its legacy-total fallback,
        // but opening a new deposit before the settlement fields exist would
        // either 500 or lose the pinned accounting leg.
        if (isset($this->ci->Payment_transaction_model)
                && method_exists($this->ci->Payment_transaction_model, 'deposit_currency_schema_ready')
                && !$this->ci->Payment_transaction_model->deposit_currency_schema_ready()) {
            return array(
                'ok' => false,
                'code' => 'SCHEMA_UPGRADE_REQUIRED',
                'error' => 'Payments are temporarily unavailable while database upgrade 042 is pending. '
                    .'An administrator must import database/upgrade-042-deposit-currency.sql.',
            );
        }

        $amount = $this->normalise_amount($input['amount'] ?? null);
        if ($amount === null) return array('ok'=>false,'error'=>'Invalid amount','code'=>'BAD_AMOUNT');

        // Manual / Bank Transfer and Fundsvera are bound to the panel default
        // here, inside the money service — not merely in the controller. That
        // makes the rule hold for dashboard forms, JSON API calls, retries and
        // any future caller, even if one posts the base currency explicitly.
        $currency = $this->charge_currency_for($method, $input['currency'] ?? null);
        if (!preg_match('/^[A-Z]{3}$/', $currency)) return array('ok'=>false,'error'=>'Bad currency','code'=>'BAD_CURRENCY');
        if (!$this->method_supports_currency($method, $currency)) {
            return $this->unsupported_currency($method, $currency);
        }

        $quote = $this->quote($amount, $currency);
        if (empty($quote['ok'])) return $quote;

        // Limits are base-currency amounts (BaseCurrencyService converts them
        // with every other money column), so they are checked on the base leg.
        if ($method->min_amount !== null && bccomp($quote['base_amount'], (string)$method->min_amount, 8) < 0)
            return array('ok'=>false,'code'=>'AMOUNT_TOO_LOW',
                'error'=>'Minimum is '.marvy_money($this->in_pay_currency($method->min_amount, $quote['fx_rate']), $currency));
        if ($method->max_amount !== null && bccomp($quote['base_amount'], (string)$method->max_amount, 8) > 0)
            return array('ok'=>false,'code'=>'AMOUNT_TOO_HIGH',
                'error'=>'Maximum is '.marvy_money($this->in_pay_currency($method->max_amount, $quote['fx_rate']), $currency));

        $idem = $this->normalise_idem($input['idempotency_key'] ?? null, $user);
        if ($idem) {
            $existing = $this->ci->Payment_transaction_model->find_by_idempotency_key($idem);
            if ($existing) return array('ok'=>true,'transaction'=>$existing,'duplicate'=>true);
        }

        // Fees and bonuses are percentages plus a base-currency fixed part, so
        // they are computed on the base leg and then expressed in the charge
        // currency — otherwise a ₦100 fixed fee would become a $100 one.
        $fee_base   = $this->calculate_fee($method, $quote['base_amount']);
        $bonus_base = $this->calculate_bonus($method, $quote['base_amount']);
        $credited_base = bcadd(bcsub($quote['base_amount'], $fee_base, 8), $bonus_base, 8);

        $fee      = $this->in_pay_currency($fee_base, $quote['fx_rate']);
        $bonus    = $this->in_pay_currency($bonus_base, $quote['fx_rate']);
        $credited = $this->in_pay_currency($credited_base, $quote['fx_rate']);

        $public_id = marvy_public_id();
        $tx = $this->persist_transaction(array(
            'base_currency'        => $quote['base_currency'],
            'base_amount'          => $quote['base_amount'],
            'credited_base_amount' => $credited_base,
            'fx_rate'              => $quote['fx_rate'],
            'public_id'          => $public_id,
            // Fundsvera requires a >= 20-character reference that is unique per
            // business; every provider gets the same stable value so support
            // can search one column regardless of gateway.
            'internal_reference' => 'MVS-'.strtoupper($public_id),
            'provider'           => $method->code,
            'payment_method'     => $method->type ? strtolower((string)$method->type) : null,
            'initiated_at'       => gmdate('Y-m-d H:i:s'),
            'user_id'            => $user->id,
            'payment_method_id'  => $method->id,
            'amount'             => $amount,
            'fee'                => $fee,
            'bonus'              => $bonus,
            'credited_amount'    => $credited,
            'currency'           => $currency,
            'status'             => self::STATUS_CREATED,
            'idempotency_key'    => $idem,
            'metadata'           => !empty($input['note']) ? json_encode(array('note'=>$input['note'])) : null,
            'created_at'         => gmdate('Y-m-d H:i:s'),
        ));

        $this->transition($tx->id, null, self::STATUS_CREATED, 'SYSTEM', 'Initialised');

        $gateway = $this->gateway_for($method);
        $init = $gateway->initiate($tx, $user);
        if (empty($init['ok'])) {
            $this->mark_failed($tx->id, $init['error'] ?? 'Gateway error');
            return array(
                'ok'    => false,
                'error' => $init['error'] ?? 'Could not initiate payment',
                // Keep a specific adapter refusal (notably
                // CURRENCY_UNSUPPORTED) instead of flattening every failure
                // into GATEWAY_ERROR. The form can then explain the actual
                // configuration problem to the customer/operator.
                'code'  => $init['code'] ?? 'GATEWAY_ERROR',
            );
        }
        // Fundsvera's own checkout URL is a bank-transfer instructions page.
        // Always land the customer on our deposit page instead: it offers a
        // hosted card gateway when one is configured, shows the account
        // details we parsed, and keeps the Fundsvera checkout link as the
        // "open secure checkout" option. Jumping straight to the Fundsvera
        // link is how a customer who asked for card payment ends up staring at
        // a transfer page with no card option.
        if (strtolower((string)$method->code) === 'fundsvera') {
            $init['redirect_url'] = null;
        }
        $status = $init['status'] ?? self::STATUS_PENDING;
        // Hosted gateways echo the reference we gave them on their callback,
        // so store it now: without it the webhook has nothing to match on and
        // a paid deposit sits unreconciled.
        $post_init = array();
        if (!empty($init['provider_tx_id'])) {
            $post_init['provider_tx_id'] = substr((string)$init['provider_tx_id'], 0, 128);
        }
        // Keep the provider's own checkout payload (link, crypto address, coin
        // amount, expiry). A customer who closes the tab mid-payment can then
        // resume from Deposits instead of starting a second deposit — and
        // support can see exactly what the customer was shown.
        if (!empty($init['checkout']) || !empty($init['redirect_url'])) {
            $meta = json_decode((string)$tx->metadata, true);
            $meta = is_array($meta) ? $meta : array();
            $meta['checkout'] = array_merge(
                is_array($init['checkout'] ?? null) ? $init['checkout'] : array(),
                array('redirect_url' => $init['redirect_url'] ?? null)
            );
            $post_init['metadata'] = json_encode($meta, JSON_UNESCAPED_SLASHES);
        }
        if ($post_init) {
            $this->ci->Payment_transaction_model->update_status($tx->id, $post_init);
        }
        $this->transition($tx->id, self::STATUS_CREATED, $status, 'GATEWAY', 'Initiated');
        $tx = $this->ci->Payment_transaction_model->find_by_id($tx->id);

        return array(
            'ok' => true,
            'transaction' => $tx,
            'redirect_url' => $init['redirect_url'] ?? null,
            'checkout' => $init['checkout'] ?? null,
        );
    }

    /* ------------------------------------------------------------------ */
    /* The charge / settlement currency split                              */
    /* ------------------------------------------------------------------ */

    /**
     * The currency a deposit is charged in — the default display currency.
     *
     * Falls back to the base currency whenever the display currency cannot be
     * resolved: a deposit must always be quotable in *something*, and the
     * accounting currency is the one figure that always exists.
     */
    public function pay_currency() {
        try {
            if (function_exists('marvy_pay_currency')) {
                $code = strtoupper((string)marvy_pay_currency());
                if (preg_match('/^[A-Z]{3}$/', $code)) return $code;
            }
        } catch (Throwable $e) {
            log_message('error', 'pay currency unavailable: '.$e->getMessage());
        }
        return marvy_base_currency();
    }

    /**
     * Resolve the charge currency for one payment method.
     *
     * Manual / Bank Transfer and Fundsvera always use the default currency,
     * regardless of a caller-supplied value. Other adapters retain the
     * existing extension point: an internal caller may request a particular
     * supported currency, while an omitted value still defaults to the panel
     * default.
     *
     * @param object|string $method payment-method row or code
     * @param string|null $requested caller-supplied charge currency
     */
    public function charge_currency_for($method, $requested = null) {
        $code = strtolower(trim((string)(is_object($method) ? ($method->code ?? '') : $method)));
        if (in_array($code, self::DEFAULT_CURRENCY_METHODS, true)) {
            return $this->pay_currency();
        }

        $currency = strtoupper(trim((string)($requested ?: $this->pay_currency())));
        return $currency;
    }

    /** Whether this adapter can actually collect the selected charge currency. */
    public function method_supports_currency($method, $currency = null) {
        if (!$method) return false;
        $currency = strtoupper(trim((string)($currency ?: $this->charge_currency_for($method))));
        if (!preg_match('/^[A-Z]{3}$/', $currency)) return false;

        $gateway = $this->gateway_for_code(
            is_object($method) ? ($method->code ?? '') : $method,
            is_object($method) ? $method : null
        );
        if (!method_exists($gateway, 'supports_currency')) return true;

        try {
            return (bool)$gateway->supports_currency($currency);
        } catch (Throwable $e) {
            log_message('error', 'could not check payment currency support: '.$e->getMessage());
            return false;
        }
    }

    /** A customer-facing refusal for a default currency the provider cannot collect. */
    private function unsupported_currency($method, $currency) {
        $code = strtolower((string)(is_object($method) ? ($method->code ?? '') : $method));
        if ($code === 'fundsvera') {
            return array(
                'ok'    => false,
                'code'  => 'CURRENCY_UNSUPPORTED',
                'error' => 'Fundsvera bank transfers collect NGN only. The panel default currency is '
                    .$currency.'. Set the default payment currency to NGN under Admin → Currencies, '
                    .'or use Manual / Bank Transfer.',
            );
        }

        $name = is_object($method) && !empty($method->name) ? (string)$method->name : ucfirst($code);
        return array(
            'ok'    => false,
            'code'  => 'CURRENCY_UNSUPPORTED',
            'error' => $name.' cannot collect '.$currency.'. Choose another payment method.',
        );
    }

    /**
     * Price a deposit: what the customer pays, what the wallet gets, and the
     * rate that ties the two together.
     *
     * The rate is read once and returned so the caller can pin it on the row.
     * A charge currency with no usable rate is refused outright rather than
     * quietly treated as 1:1 — quoting "₦1,328" and crediting a ₦1,328 wallet
     * balance when the books are in dollars would hand the customer ~1,300x
     * the money they paid for.
     *
     * @return array{ok:bool, base_currency?:string, base_amount?:string,
     *               fx_rate?:string, error?:string, code?:string}
     */
    public function quote($pay_amount, $pay_currency = null) {
        $base = marvy_base_currency();
        $pay  = strtoupper((string)($pay_currency ?: $this->pay_currency()));

        if ($pay === $base) {
            return array('ok'=>true, 'base_currency'=>$base,
                'base_amount'=>number_format((float)$pay_amount, 8, '.', ''),
                'fx_rate'=>'1.00000000');
        }

        $rate = $this->fx_rate($pay);
        if ($rate === null) {
            return array('ok'=>false, 'code'=>'NO_RATE',
                'error'=>'No usable '.$pay.' exchange rate is configured, so this deposit cannot be '
                    .'priced right now. Please contact support.');
        }

        return array(
            'ok'            => true,
            'base_currency' => $base,
            // pay ÷ rate: the rate is units of the charge currency per 1 base.
            'base_amount'   => bcdiv((string)$pay_amount, $rate, 8),
            'fx_rate'       => $rate,
        );
    }

    /** A base-currency amount expressed in the charge currency at a pinned rate. */
    private function in_pay_currency($base_amount, $fx_rate) {
        return bcmul((string)$base_amount, (string)$fx_rate, 8);
    }

    /**
     * Units of `$code` per 1 unit of the base currency, or NULL when no
     * usable, active rate exists. Never invents one.
     */
    private function fx_rate($code) {
        try {
            $this->ci->load->model('Currency_model');
            $row = $this->ci->Currency_model->find($code);
            if ($row && (int)$row->is_active === 1 && is_numeric($row->exchange_rate)
                    && bccomp((string)$row->exchange_rate, '0', 8) > 0) {
                return (string)$row->exchange_rate;
            }
        } catch (Throwable $e) {
            log_message('error', 'fx rate lookup failed for '.$code.': '.$e->getMessage());
        }
        return null;
    }

    /**
     * What a deposit credits the wallet with, in the BASE currency.
     *
     * Prefers the figure pinned when the deposit was opened. A row from before
     * migration 042 has no pinned leg and was charged in the base currency by
     * construction, so its charge-currency figure is already the base figure —
     * which is exactly what the old code credited.
     */
    private function settlement_amount($tx) {
        if (isset($tx->credited_base_amount) && $tx->credited_base_amount !== null
                && $tx->credited_base_amount !== '') {
            return (string)$tx->credited_base_amount;
        }
        if (isset($tx->base_amount) && $tx->base_amount !== null && $tx->base_amount !== '') {
            return (string)$tx->base_amount;
        }
        return $tx->credited_amount !== null ? (string)$tx->credited_amount : (string)$tx->amount;
    }

    /**
     * Confirm a transaction and credit the wallet once.
     *
     * The wallet is credited in the BASE currency using the amount pinned at
     * initiation — not the charge amount, and not a figure re-derived from
     * today's rate. A customer who was quoted "$1 = ₦1,328" and paid ₦1,328
     * gets $1, even if the rate moved to 1,400 while the webhook was in
     * flight. LedgerService then does its own conversion if the wallet itself
     * holds a foreign currency; that boundary is unchanged.
     *
     * @param object $tx        the payment transaction
     * @param string $source    SYSTEM|ADMIN|WEBHOOK
     * @param string|null $provider_tx_id
     */
    public function confirm($tx, $source = 'SYSTEM', $provider_tx_id = null) {
        if (!$tx || $tx->status === self::STATUS_SUCCESS) return array('ok'=>true,'duplicate'=>true,'transaction'=>$tx);
        if (!in_array($tx->status, array(self::STATUS_CREATED, self::STATUS_PENDING), true)) {
            return array('ok'=>false,'error'=>'Transaction cannot be confirmed in '.$tx->status,'code'=>'BAD_STATE');
        }
        $wallet = $this->ci->Wallet_model->for_user($tx->user_id);
        // Base currency: LedgerService::credit() speaks base, always.
        $credited = $this->settlement_amount($tx);
        $idem = 'payment:credit:'.($tx->idempotency_key ?: $tx->public_id);

        $this->ci->db->trans_start();
        $credit = $this->ci->ledgerservice->credit(
            $wallet->id, $credited, 'DEPOSIT', 'PaymentTransaction', $tx->public_id, $idem,
            array('fee'=>(string)$tx->fee, 'bonus'=>(string)$tx->bonus, 'tx_id'=>$tx->public_id)
        );
        if (empty($credit['ok'])) {
            $this->ci->db->trans_complete();
            return array('ok'=>false,'error'=>$credit['error'] ?? 'Could not credit wallet','code'=>'CREDIT_FAILED');
        }
        // Find the wallet transaction we just created.
        $wt = $this->ci->db->where('idempotency_key', $idem)->get('wallet_transactions')->row();
        $update = array(
            'status' => self::STATUS_SUCCESS,
            'wallet_transaction_id' => $wt ? $wt->id : null,
            'verified_at' => gmdate('Y-m-d H:i:s'),
            'paid_at' => gmdate('Y-m-d H:i:s'),
        );
        if ($provider_tx_id) $update['provider_tx_id'] = substr((string)$provider_tx_id, 0, 128);
        $this->ci->Payment_transaction_model->update_status($tx->id, $update);
        $this->transition($tx->id, $tx->status, self::STATUS_SUCCESS, $source, 'Confirmed');
        $this->ci->db->trans_complete();

        // The customer asked for this money to arrive; tell them it has.
        // Outside the transaction and never fatal — the credit is already
        // committed and a mail problem must not undo it.
        try {
            $this->ci->load->library('NotificationService');
            // load->library() succeeding does not guarantee the property is
            // there (a test double, or a loader that resolved it under another
            // name); reading it blind raises a warning try/catch cannot see.
            if (isset($this->ci->notificationservice)) {
                $wallet_now = $this->ci->Wallet_model->for_user($tx->user_id);
                // $credited is a BASE-currency figure; label it as such rather
                // than with the charge currency, or a ₦1,328 payment would be
                // announced as "₦1.00 added to your wallet".
                $credited_code = (string)(($tx->base_currency ?? '') ?: marvy_base_currency());
                // What they actually paid, when that is a different currency.
                $paid = marvy_money($tx->amount, $tx->currency);
                $line = marvy_money($credited, $credited_code).' has been added to your wallet';
                if (strtoupper((string)$tx->currency) !== strtoupper($credited_code)) {
                    $line .= ' ('.$paid.' paid)';
                }
                $this->ci->notificationservice->notify(
                    $tx->user_id, 'payment.credited',
                    $line.'.',
                    array('reference' => $tx->public_id, 'url' => 'dashboard/wallet/deposits/'.$tx->public_id),
                    array(
                        'amount'  => marvy_money($credited, $credited_code),
                        // The wallet may hold a different currency than the
                        // deposit; show the balance in what it actually holds.
                        'balance' => marvy_money($wallet_now->balance ?? '0',
                            $wallet_now->currency ?? $credited_code),
                    )
                );
            }
        } catch (Throwable $e) {
            log_message('error', 'deposit notification failed for '.$tx->public_id.': '.$e->getMessage());
        }

        // A confirmed deposit may be the event that qualifies a referral.
        // Outside the transaction and never fatal: the deposit has already
        // succeeded, and a referral bookkeeping problem must not roll it back.
        $this->referral_event($tx->user_id, 'FIRST_DEPOSIT');

        return array('ok'=>true,'transaction'=>$this->ci->Payment_transaction_model->find_by_id($tx->id));
    }

    /**
     * Notify the referral system, without ever affecting the caller.
     *
     * Its own method so an early return here cannot skip the calling method's
     * return value. load->library() succeeding does not guarantee the property
     * exists — under a test double, or a loader that resolved it under another
     * name, reading it blind raises a warning a try/catch cannot see.
     */
    private function referral_event($user_id, $event) {
        try {
            $this->ci->load->library('ReferralService');
            if (!isset($this->ci->referralservice)) return;
            $this->ci->referralservice->record_event($user_id, $event);
        } catch (Throwable $e) {
            log_message('error', 'referral '.$event.' hook failed: '.$e->getMessage());
        }
    }

    /**
     * Settle a deposit the moment the customer comes back from the provider.
     *
     * Hosted gateways land the customer on their deposit page after paying
     * (HostedGateway::return_url). Until now that page only re-read the row:
     * the wallet moved when the webhook arrived, or when reconciliation asked
     * the provider twenty minutes later, or when an admin clicked approve —
     * never at the moment the paying customer was actually looking at it.
     *
     * What happens here depends on how the gateway finishes a charge:
     *
     *   - A CAPTURE-step gateway (PayPal) has not taken the money at all when
     *     the customer returns: approval is only their signature. The return
     *     is the one moment the approved order can be captured, so we capture
     *     it and credit through confirm() — the webhook cannot do this for us
     *     because a webhook that arrives before capture would credit money
     *     PayPal never took (the approval event deliberately parses as
     *     PENDING for exactly that reason).
     *
     *   - A gateway that finishes the charge itself (Paystack, Stripe, …) is
     *     asked once, server-side, whether the payment completed. The webhook
     *     remains the primary truth; this single question closes the gap when
     *     it is late or was never configured. Nothing the customer's browser
     *     sent is trusted — only the provider's own answer.
     *
     *   - A gateway with no status call (manual bank transfer, crypto
     *     callbacks) is a quiet no-op: there is nothing to ask, and pretending
     *     otherwise would move money on a guess.
     *
     * Confirmation goes through confirm() in every case, which is idempotent
     * on the ledger — a webhook landing mid-settle cannot double-credit.
     *
     * @param object $tx     the payment transaction being viewed
     * @param string $source audit source recorded on the transition
     * @return array{ok:bool, transaction?:object, noop?:bool, captured?:bool,
     *               verified?:bool, error?:string, code?:string}
     */
    public function settle_hosted_return($tx, $source = 'RETURN') {
        if (!$tx) return array('ok'=>false,'error'=>'No deposit to settle','code'=>'NO_TRANSACTION');
        if ($tx->status === self::STATUS_SUCCESS) {
            return array('ok'=>true,'noop'=>true,'duplicate'=>true,'transaction'=>$tx);
        }
        if (!in_array($tx->status, array(self::STATUS_CREATED, self::STATUS_PENDING), true)) {
            return array('ok'=>false,'error'=>'This deposit cannot be settled in '.$tx->status,
                'code'=>'BAD_STATE');
        }

        $verifier = $this->return_verifier($tx);
        $gateway  = $verifier['gateway'];
        if (!$gateway) return array('ok'=>true,'noop'=>true,'transaction'=>$tx);
        if (method_exists($gateway, 'is_configured') && !$gateway->is_configured()) {
            return array('ok'=>true,'noop'=>true,'transaction'=>$tx);
        }
        $reference = $verifier['reference'];

        // 1) Capture-step gateway: the money only moves when we capture the
        //    approved order, and the customer's return is that moment.
        if (method_exists($gateway, 'capture')) {
            $capture = $gateway->capture($reference);
            if (!empty($capture['ok'])) {
                $coverage = $this->provider_payment_covers_deposit($tx, $capture);
                if (empty($coverage['ok'])) {
                    $this->record_shortfall($tx, $capture, $coverage);
                    return array('ok'=>false,'code'=>$coverage['code'],
                        'error'=>$coverage['message'].' Support will reconcile it.');
                }
                $res = $this->confirm($tx, $source, $capture['provider_tx_id'] ?? null);
                if (!empty($res['ok'])) return array_merge($res, array('captured'=>true));
                return $res;
            }
            // The capture can fail because the webhook won the race and
            // PayPal already captured the order — fall through and ask the
            // order's status before giving up. Anything else stays pending for
            // the reconciliation sweep, which retries through verify().
            log_message('error', 'settle_hosted_return: capture failed for '
                .$tx->public_id.': '.($capture['error'] ?? 'unknown error'));
        }

        // 2) Self-settling gateway: one server-side question, and the answer
        //    is the only thing that can credit anyone.
        if (method_exists($gateway, 'verify')) {
            $verdict = $gateway->verify($reference);
            if (!empty($verdict['ok']) && strtoupper((string)($verdict['status'] ?? '')) === 'SUCCESS') {
                $coverage = $this->provider_payment_covers_deposit($tx, $verdict);
                if (empty($coverage['ok'])) {
                    $this->record_shortfall($tx, $verdict, $coverage);
                    return array('ok'=>false,'code'=>$coverage['code'],
                        'error'=>$coverage['message'].' Support will reconcile it.');
                }
                $res = $this->confirm($tx, $source, $verdict['provider_tx_id'] ?? null);
                if (!empty($res['ok'])) return array_merge($res, array('verified'=>true));
                return $res;
            }
            // No answer, or not finished: quiet. The webhook may still land,
            // and reconciliation re-asks on its next sweep.
            return array('ok'=>true,'noop'=>true,'transaction'=>$tx);
        }

        // 3) Nothing to ask — a quiet no-op.
        return array('ok'=>true,'noop'=>true,'transaction'=>$tx);
    }

    /**
     * The adapter (and the reference it should be asked) for a deposit being
     * viewed on return.
     *
     * Resolution mirrors CronWorkers::verify_with_gateway() on purpose: a
     * Fundsvera deposit paid by hosted card must be asked at the CARD gateway
     * (Fundsvera never saw that charge), and PayPal is only addressable by its
     * own order id. The two copies are kept separate because each is pinned by
     * its own test suite (CronWorkersTest drives the worker against a service
     * double; PaymentsTest drives the service against a CI double) — change
     * one, change the other.
     *
     * @return array{gateway:?object, reference:?string}
     */
    private function return_verifier($tx) {
        $meta = json_decode((string)($tx->metadata ?? ''), true);
        $meta = is_array($meta) ? $meta : array();

        // A card checkout opened on this deposit (see card_checkout()): the
        // charge lives at the card gateway, so that is who gets asked, by the
        // session/link id the provider returned.
        if (!empty($meta['card_checkout']['provider'])) {
            $card_code = strtolower((string)$meta['card_checkout']['provider']);
            if (in_array($card_code, self::CARD_GATEWAY_CODES, true)) {
                $card_method = $this->resolve_method($card_code);
                if ($card_method) {
                    $card_checkout = is_array($meta['card_checkout']) ? $meta['card_checkout'] : array();
                    $key = $card_checkout['session_id'] ?? $card_checkout['link_id'] ?? null;
                    return array(
                        'gateway'   => $this->gateway_for_code($card_code, $card_method),
                        'reference' => ($key !== null && $key !== '') ? (string)$key
                            : (string)$tx->internal_reference,
                    );
                }
            }
        }

        $code = strtolower(trim((string)$tx->provider));
        if ($code === '' || !in_array($code, $this->implemented_gateways(), true)) {
            return array('gateway'=>null,'reference'=>null);
        }

        $reference = null;
        if ($code === 'paypal') {
            // PayPal knows its own order id; our reference 404s at their end.
            if (!empty($meta['checkout']['order_id'])) $reference = (string)$meta['checkout']['order_id'];
        }
        if ($reference === null) {
            $reference = (string)(($tx->provider_tx_id ?? '') ?: ($tx->internal_reference ?? ''));
        }
        if ($reference === '') return array('gateway'=>null,'reference'=>null);

        return array(
            'gateway'   => $this->gateway_for_code($code, $this->resolve_method($code)),
            'reference' => $reference,
        );
    }

    /**
     * Whether what the provider says arrived covers what we expected.
     *
     * A gateway that reports no amount is trusted on the deposit row, because
     * some providers only sign the status/reference and the amount is already
     * the row we created. Once a provider *does* report amount or currency,
     * both must agree with the transaction before a webhook can credit the
     * wallet: a USD success callback must never satisfy an NGN deposit, and a
     * partial payment must never become full wallet balance. Crypto adapters
     * that validate a coin amount against their stored quote mark the metadata
     * with `provider_amount_validated`, because their callback currency (BTC,
     * USDT) is intentionally different from the fiat deposit row.
     */
    private function provider_payment_covers_deposit($tx, array $res) {
        $metadata = isset($res['metadata']) && is_array($res['metadata']) ? $res['metadata'] : array();
        if (!empty($metadata['provider_amount_validated'])) {
            return array('ok' => true);
        }

        $expected_currency = strtoupper(trim((string)($tx->currency ?? (function_exists('marvy_base_currency') ? marvy_base_currency() : 'NGN'))));
        $reported_currency = isset($res['currency']) ? strtoupper(trim((string)$res['currency'])) : '';
        if ($reported_currency !== '' && $expected_currency !== '' && $reported_currency !== $expected_currency) {
            return array(
                'ok' => false,
                'code' => 'CURRENCY_MISMATCH',
                'message' => 'The provider reported '.$reported_currency.' for a '.$expected_currency.' deposit.',
            );
        }

        if (array_key_exists('amount', $res) && $res['amount'] !== null && $res['amount'] !== '') {
            if (!is_numeric($res['amount'])) {
                return array(
                    'ok' => false,
                    'code' => 'BAD_PROVIDER_AMOUNT',
                    'message' => 'The provider reported an invalid payment amount.',
                );
            }
            if (bccomp((string)$res['amount'], (string)$tx->amount, 8) < 0) {
                return array(
                    'ok' => false,
                    'code' => 'UNDERPAID',
                    'message' => 'The provider reports less money than this deposit was for.',
                );
            }
        }

        return array('ok' => true);
    }

    /** Backwards-compatible boolean for older source-level tests and comments. */
    private function amount_covers_deposit($tx, array $res) {
        $coverage = $this->provider_payment_covers_deposit($tx, $res);
        return !empty($coverage['ok']);
    }

    /** Record a provider-reported shortfall/currency mismatch on the transaction for staff. */
    private function record_shortfall($tx, array $res, array $coverage = array()) {
        $meta = json_decode((string)($tx->metadata ?? ''), true);
        $meta = is_array($meta) ? $meta : array();
        $code = (string)($coverage['code'] ?? 'UNDERPAID');
        $meta['reconciliation'] = array(
            'underpaid'         => $code === 'UNDERPAID',
            'currency_mismatch'=> $code === 'CURRENCY_MISMATCH',
            'invalid_amount'    => $code === 'BAD_PROVIDER_AMOUNT',
            'expected'          => (string)$tx->amount,
            'expected_currency' => (string)($tx->currency ?? (function_exists('marvy_base_currency') ? marvy_base_currency() : 'NGN')),
            'provider_amount'   => (string)($res['amount'] ?? ''),
            'provider_currency' => (string)($res['currency'] ?? ''),
            'checked_at'        => gmdate('Y-m-d H:i:s'),
        );
        $this->ci->Payment_transaction_model->update_status($tx->id,
            array('metadata' => json_encode($meta, JSON_UNESCAPED_SLASHES)));
        log_message('error', 'payment confirmation refused for '.$tx->public_id.' — '
            .($coverage['message'] ?? 'provider amount did not cover the deposit').' left for staff');
    }

    /** Mark a transaction failed (terminal). */
    public function mark_failed($tx_id, $reason = null) {
        $tx = $this->ci->Payment_transaction_model->find_by_id($tx_id);
        if (!$tx || in_array($tx->status, array(self::STATUS_SUCCESS, self::STATUS_FAILED), true)) return;
        $this->ci->Payment_transaction_model->update_status($tx->id,
            array('failed_at' => gmdate('Y-m-d H:i:s')));
        $this->transition($tx->id, $tx->status, self::STATUS_FAILED, 'SYSTEM', $reason);
    }

    /**
     * Record and process an incoming webhook (idempotent on gateway+event_id).
     *
     * @return array{ok:bool, already_seen?:bool, transaction?:object, error?:string}
     */
    public function record_webhook($gateway_type, $raw_body, array $headers) {
        // Gateways with a real adapter verify and parse their own callbacks.
        // Anything else goes through the generic HMAC envelope, which is
        // fail-closed: no configured secret means the event is stored but no
        // money moves.
        $gateway = in_array($gateway_type, $this->implemented_gateways(), true)
            ? $this->gateway_for_code($gateway_type)
            : null;

        $sig_ok = $gateway
            ? $gateway->verify_webhook($raw_body, $headers)
            : $this->verify_generic_signature($gateway_type, $raw_body, $headers);

        if ($gateway instanceof BlockonomicsGateway) {
            // Blockonomics reports progress in the query string, so its parser
            // needs the headers too.
            $event = $gateway->parse_event($raw_body, $headers);
        } elseif ($gateway) {
            $event = $gateway->parse_event($raw_body);
        } else {
            $event = $this->parse_generic_event($raw_body);
        }

        $id = $this->ci->Payment_webhook_model->record_once(
            $gateway_type,
            $event['event_id'] ?? null,
            $raw_body,
            $sig_ok,
            $event['type'] ?? null
        );
        if ($id === false) {
            return array('ok'=>true,'already_seen'=>true); // duplicate, do not reprocess
        }

        // Only process when signature is valid AND the event indicates success.
        if ($sig_ok === false) {
            $this->ci->db->where('id', $id)->update('payment_webhooks',
                array('processed'=>1,'processed_at'=>gmdate('Y-m-d H:i:s'),'error'=>'invalid signature'));
            return array('ok'=>false,'error'=>'Invalid signature');
        }
        // null means "no secret configured, cannot verify": store the event for
        // the operator to inspect but never move money on it.
        if ($sig_ok === null) {
            $this->ci->db->where('id', $id)->update('payment_webhooks', array(
                'processed' => 1,
                'processed_at' => gmdate('Y-m-d H:i:s'),
                'error' => 'unverified: no webhook secret configured for '.$gateway_type,
            ));
            return array('ok'=>true,'unverified'=>true);
        }

        return $this->process_event($id, $event);
    }

    /**
     * Re-run a callback that was stored, verified, but never finished.
     *
     * Replaying the raw body through record_webhook() cannot work: webhook
     * signatures live in HTTP headers we did not keep, so re-verification
     * would fail and a genuine, already-verified event would be closed as
     * "invalid signature". This picks up from the point verification had
     * already reached — which is exactly what a retry needs, and why both the
     * reconciliation sweep and the admin "reprocess" button use it.
     *
     * @param object $row a payment_webhooks row with signature_valid = 1
     */
    public function reprocess_stored_webhook($row) {
        if (!$row || (int)$row->signature_valid !== 1) {
            return array('ok' => false, 'error' => 'That event never passed signature verification.');
        }

        $gateway_type = strtolower((string)$row->gateway_type);
        $gateway = in_array($gateway_type, $this->implemented_gateways(), true)
            ? $this->gateway_for_code($gateway_type)
            : null;

        $event = $gateway
            ? ($gateway instanceof BlockonomicsGateway
                ? $gateway->parse_event((string)$row->payload, array())
                : $gateway->parse_event((string)$row->payload))
            : $this->parse_generic_event((string)$row->payload);

        // Reopen the row so the outcome of this attempt is what gets stored.
        $this->ci->db->where('id', $row->id)->update('payment_webhooks',
            array('processed' => 0, 'error' => null));

        return $this->process_event((int)$row->id, $event);
    }

    /**
     * Act on a verified, parsed gateway event: match the deposit and credit it
     * exactly once, recording the outcome on the webhook row.
     */
    private function process_event($id, array $event) {
        $terminal = strtolower($event['status'] ?? '');

        // A confirmed-but-short payment is a real event that must be visible to
        // staff, and must never credit as though it were complete.
        if ($terminal === 'underpaid') {
            $this->ci->db->where('id', $id)->update('payment_webhooks', array(
                'processed' => 1,
                'processed_at' => gmdate('Y-m-d H:i:s'),
                'error' => 'underpaid: amount received is less than the amount quoted',
            ));
            return array('ok'=>true,'underpaid'=>true);
        }

        if (!in_array($terminal, array('success','succeeded','completed','paid','approved'), true)) {
            $this->ci->db->where('id', $id)->update('payment_webhooks', array('processed'=>1,'processed_at'=>gmdate('Y-m-d H:i:s')));
            return array('ok'=>true,'ignored'=>true);
        }

        // Resolution order matters. An adapter that can name the transaction
        // directly (a crypto callback resolves it from the receive address it
        // issued) is authoritative and is tried first: matching on
        // provider_tx_id alone can land on a *different, older* transaction
        // that happens to carry the same id, and silently no-op because that
        // one is already SUCCESS.
        $tx = null;
        if (!empty($event['metadata']['payment_transaction_id'])) {
            $tx = $this->ci->Payment_transaction_model->find_by_id(
                (int) $event['metadata']['payment_transaction_id']
            );
        }
        if (!$tx && !empty($event['provider_tx_id'])) {
            $tx = $this->ci->Payment_transaction_model->find_by_provider_tx($event['provider_tx_id']);
        }
        if (!$tx && !empty($event['metadata']['idempotency_key'])) {
            $tx = $this->ci->Payment_transaction_model->find_by_idempotency_key($event['metadata']['idempotency_key']);
        }
        if (!$tx && !empty($event['metadata']['wallet_user_id'])) {
            // A virtual-account credit: the customer pushed money to their
            // standing bank account without opening a deposit first, so there
            // is no transaction to match — FundsveraGateway resolved the
            // account's OWNER instead. Record a deposit for exactly what the
            // provider says arrived and credit it below, once.
            $tx = $this->virtual_account_credit($event);
            if (!$tx) {
                $this->ci->db->where('id', $id)->update('payment_webhooks', array(
                    'error' => 'retryable: could not record the virtual-account credit',
                ));
                return array('ok'=>false,'retryable'=>true,'error'=>'Could not record the virtual-account credit');
            }
        }
        if (!$tx) {
            // Accepted and logged, but there is nothing to reconcile — the
            // event references no transaction of ours. Treat it as processed
            // so the gateway stops retrying; the error column flags it for the
            // operator.
            $this->ci->db->where('id', $id)->update('payment_webhooks', array(
                'processed' => 1,
                'processed_at' => gmdate('Y-m-d H:i:s'),
                'error' => 'no matching transaction',
            ));
            return array('ok'=>true,'unmatched'=>true,'error'=>'No matching transaction');
        }

        $coverage = $this->provider_payment_covers_deposit($tx, $event);
        if (empty($coverage['ok'])) {
            $this->record_shortfall($tx, $event, $coverage);
            $this->ci->db->where('id', $id)->update('payment_webhooks', array(
                'payment_transaction_id' => $tx->id,
                'processed' => 1,
                'processed_at' => gmdate('Y-m-d H:i:s'),
                'error' => substr($coverage['message'], 0, 250),
            ));
            $flag = strtolower((string)$coverage['code']);
            return array('ok'=>true, $flag=>true, 'error'=>$coverage['message']);
        }

        $res = $this->confirm($tx, 'WEBHOOK', $event['provider_tx_id'] ?? null);
        if (empty($res['ok'])) {
            // Transient processing failure (e.g. the ledger write rolled
            // back). Leave the row UNPROCESSED so the gateway's retry — and
            // the reconciliation sweep over unprocessed() — re-runs it, and
            // flag it retryable so the controller answers 503 (which real
            // gateways retry) instead of a swallowed 200.
            $this->ci->db->where('id', $id)->update('payment_webhooks', array(
                'payment_transaction_id' => $tx->id,
                'error' => substr('retryable: '.($res['error'] ?? 'confirmation failed'), 0, 250),
            ));
            return array('ok'=>false,'retryable'=>true,'error'=>$res['error'] ?? 'Payment processing failed');
        }
        $this->ci->db->where('id', $id)->update('payment_webhooks', array(
            'payment_transaction_id' => $tx->id,
            'processed' => 1,
            'processed_at' => gmdate('Y-m-d H:i:s'),
        ));
        return $res;
    }

    /* -------------------------------------------------------------- */

    /**
     * Record the deposit behind a spontaneous virtual-account credit.
     *
     * The amount is what the PROVIDER says arrived, never a figure the
     * customer typed — a standing account has no quoted amount to check
     * against. Exactly-once is anchored on the provider's own transaction
     * reference (the webhook table already de-duplicates the event itself;
     * this idempotency key additionally protects the replay path, where a
     * stored-but-unprocessed event is re-run without record_once()).
     *
     * @return object|null the payment transaction, or null when the event
     *                     carries no usable amount
     */
    private function virtual_account_credit(array $event) {
        $user_id = (int)($event['metadata']['wallet_user_id'] ?? 0);
        if ($user_id <= 0) return null;

        $amount = $this->normalise_amount($event['amount'] ?? null);
        if ($amount === null) {
            log_message('error', 'fundsvera virtual-account credit without a usable amount: '
                .json_encode($event['metadata']));
            return null;
        }

        $trx_ref = trim((string)($event['provider_tx_id'] ?? ''));
        $idem = 'payment:va:'.($trx_ref !== '' ? $trx_ref : $user_id.':'.($event['event_id'] ?? marvy_public_id()));

        $existing = $this->ci->Payment_transaction_model->find_by_idempotency_key($idem);
        if ($existing) return $existing;

        // The bank reported an amount in ITS currency (Fundsvera is a Nigerian
        // bank rail, so naira). Price it the same way a declared deposit is
        // priced: the reported figure is the charge, and the base leg is what
        // the wallet gets. No rate means no credit — a spontaneous transfer is
        // never worth guessing at.
        $currency = strtoupper((string)($event['currency'] ?? $this->pay_currency()));
        $quote = $this->quote($amount, $currency);
        if (empty($quote['ok'])) {
            log_message('error', 'virtual-account credit for user '.$user_id
                .' cannot be priced: '.$quote['error']);
            return null;
        }

        // The method's fee/bonus rules apply to a bank-transfer credit exactly
        // as they would to a declared deposit, when the row can be found.
        $method     = $this->resolve_method('fundsvera');
        $fee_base   = $method ? $this->calculate_fee($method, $quote['base_amount']) : '0.00000000';
        $bonus_base = $method ? $this->calculate_bonus($method, $quote['base_amount']) : '0.00000000';
        $credited_base = bcadd(bcsub($quote['base_amount'], $fee_base, 8), $bonus_base, 8);

        $public_id = marvy_public_id();
        $tx = $this->persist_transaction(array(
            'public_id'          => $public_id,
            'internal_reference' => 'MVS-'.strtoupper($public_id),
            'provider'           => 'fundsvera',
            'payment_method'     => 'virtual_account',
            'initiated_at'       => gmdate('Y-m-d H:i:s'),
            'user_id'            => $user_id,
            'payment_method_id'  => $method ? (int)$method->id : null,
            'amount'             => $amount,
            'fee'                => $this->in_pay_currency($fee_base, $quote['fx_rate']),
            'bonus'              => $this->in_pay_currency($bonus_base, $quote['fx_rate']),
            'credited_amount'    => $this->in_pay_currency($credited_base, $quote['fx_rate']),
            'currency'           => $currency,
            'base_currency'        => $quote['base_currency'],
            'base_amount'          => $quote['base_amount'],
            'credited_base_amount' => $credited_base,
            'fx_rate'              => $quote['fx_rate'],
            'status'             => self::STATUS_PENDING,
            'idempotency_key'    => $idem,
            'metadata'           => json_encode(array(
                'virtual_account_no' => $event['metadata']['virtual_account_no'] ?? null,
                'customer_email'     => $event['metadata']['customer_email'] ?? null,
                'trx_ref'            => $trx_ref !== '' ? $trx_ref : null,
                'purpose'            => 'virtual_account',
            ), JSON_UNESCAPED_SLASHES),
            'created_at'         => gmdate('Y-m-d H:i:s'),
        ));
        $this->transition($tx->id, null, self::STATUS_PENDING, 'WEBHOOK', 'Virtual account credit received');

        return $this->ci->Payment_transaction_model->find_by_id($tx->id);
    }

    public function calculate_fee($method, $amount) {
        $pct = (float)$method->fee_percent;
        $fixed = (float)$method->fee_fixed;
        $fee = bcadd(bcmul($amount, (string)($pct/100), 8), (string)$fixed, 8);
        return bccomp($fee, '0', 8) > 0 ? number_format((float)$fee, 8, '.', '') : '0.00000000';
    }

    public function calculate_bonus($method, $amount) {
        $pct = (float)$method->bonus_percent;
        if ($pct <= 0) return '0.00000000';
        return number_format((float)bcmul($amount, (string)($pct/100), 8), 8, '.', '');
    }

    private function resolve_method($code) {
        if (!$code) return null;
        return $this->ci->db->where('code', $code)->get('payment_methods')->row();
    }

    private function gateway_for($method) {
        return $this->gateway_for_code($method->code, $method);
    }

    /**
     * The adapter that serves a payment-method row.
     *
     * Public because reconciliation has to ask a gateway what happened to a
     * deposit whose webhook never arrived, and building the adapter itself
     * would duplicate the routing table.
     */
    public function adapter_for($method) {
        return $this->gateway_for_code($method->code ?? '', $method);
    }

    /**
     * The adapter that handles a payment-method code.
     *
     * Only adapters that are actually implemented and wired are routed here.
     * Everything else falls back to ManualGateway, which marks the deposit
     * PENDING for admin review — a deposit that waits for a human is always
     * safer than one handed to an untested integration.
     */
    private function gateway_for_code($code, $method_row = null) {
        $code = strtolower((string)$code);
        switch ($code) {
            case 'blockonomics':
            case 'btc':
                return new BlockonomicsGateway($method_row);
            case 'fundsvera':
                return new FundsveraGateway($method_row);
            case 'stripe':
                return new StripeGateway($method_row);
            case 'paypal':
                return new PaypalGateway($method_row);
            case 'flutterwave':
                return new FlutterwaveGateway($method_row);
            case 'razorpay':
                return new RazorpayGateway($method_row);
            case 'paystack':
                return new PaystackGateway($method_row);
            case 'coinpayments':
                return new CoinpaymentsGateway($method_row);
            case 'manual':
            default:
                return new ManualGateway($method_row);
        }
    }

    /**
     * Payment methods a customer can actually pay with right now.
     *
     * `is_active` is the operator's intent; being *configured* is whether the
     * adapter has credentials. Offering a method that will fail at the last
     * step — after the customer has typed an amount and pressed Pay — is worse
     * than not offering it, so an active-but-unconfigured gateway is hidden
     * from Add funds and flagged in the admin console instead.
     */
    public function payable_methods() {
        $rows = $this->ci->db->where('is_active', 1)->order_by('sorting', 'ASC')
            ->get('payment_methods')->result();

        $out = array();
        foreach ($rows as $row) {
            // A configured provider that cannot collect the current default
            // currency is still not payable. In particular, Fundsvera is an
            // NGN bank rail: showing it while the default is USD would let the
            // customer complete the form only to fail at the provider.
            $currency = $this->charge_currency_for($row);
            if ($this->method_is_configured($row)
                    && $this->method_supports_currency($row, $currency)) {
                $out[] = $row;
            }
        }
        return $out;
    }

    /**
     * The first configured hosted card gateway for a card fallback.
     *
     * Fundsvera is a bank-transfer provider; its checkout URL is a transfer
     * instructions page, not a card form. To still let a Fundsvera deposit be
     * paid by card, the deposit page can hand the exact same transaction to a
     * hosted card gateway (Paystack / Flutterwave / Razorpay / Stripe). This
     * helper answers which one is actually ready — configured — so the page
     * never offers a card button that fails at the first click.
     *
     * @return object|null a payment_methods row for the card gateway
     */
    public function card_method_for_deposit() {
        // A configured card gateway is enough for the Fundsvera fallback even
        // when its own method row is not shown on Add funds — the operator has
        // supplied working credentials, and the fallback is the only way a
        // customer can pay that deposit by card.
        foreach (self::CARD_GATEWAY_CODES as $code) {
            $row = $this->resolve_method($code);
            if ($row && $this->method_is_configured($row)) return $row;
        }
        return null;
    }

    /**
     * Start (or resume) a card checkout against an existing pending deposit.
     *
     * The deposit was created as a Fundsvera bank-transfer payment. This does
     * not create a second deposit: it asks a hosted card gateway for a
     * checkout URL using the SAME transaction, so a successful card webhook
     * credits the same deposit exactly once. The card URL is stored on the
     * transaction metadata so a customer who closes the tab can resume it.
     *
     * @param object $tx   an existing PaymentTransaction
     * @param object $user the signed-in customer
     * @return array{ok:bool, redirect_url?:string, checkout?:array, method?:object, error?:string, code?:string}
     */
    public function card_checkout($tx, $user) {
        if (!$tx) {
            return array('ok' => false, 'error' => 'No deposit to pay for', 'code' => 'NO_TRANSACTION');
        }
        if ($tx->status === self::STATUS_SUCCESS) {
            return array('ok' => false, 'error' => 'This deposit is already completed', 'code' => 'ALREADY_COMPLETE');
        }
        if ($tx->status === self::STATUS_FAILED) {
            return array('ok' => false, 'error' => 'This deposit has already failed — start a new deposit',
                'code' => 'ALREADY_FAILED');
        }
        if (!in_array($tx->status, array(self::STATUS_CREATED, self::STATUS_PENDING), true)) {
            return array('ok' => false, 'error' => 'This deposit cannot be paid now', 'code' => 'BAD_STATE');
        }

        // Reuse an already-created card checkout rather than opening a second
        // one on every page refresh.
        $meta = json_decode((string)$tx->metadata, true);
        if (is_array($meta) && !empty($meta['card_checkout']['redirect_url'])) {
            return array(
                'ok'           => true,
                'redirect_url' => (string)$meta['card_checkout']['redirect_url'],
                'checkout'     => $meta['card_checkout'],
            );
        }

        $method = $this->card_method_for_deposit();
        if (!$method) {
            return array('ok' => false,
                'error' => 'Card payment is not available yet. Enable Paystack, Flutterwave, '
                    .'Razorpay or Stripe in Admin → Settings, or use bank transfer.',
                'code' => 'CARD_UNAVAILABLE');
        }

        $gateway = $this->gateway_for($method);
        $init = $gateway->initiate($tx, $user);
        if (empty($init['ok'])) {
            return array('ok' => false,
                'error' => $init['error'] ?? 'Could not start the card payment',
                'code' => $init['code'] ?? 'CARD_INIT_FAILED');
        }

        $this->store_card_checkout($tx, $init);
        return array(
            'ok'           => true,
            'redirect_url' => $init['redirect_url'] ?? null,
            'checkout'     => $init['checkout'] ?? null,
            'method'       => $method,
        );
    }

    /** Persist the card checkout URL on the transaction so it can be resumed. */
    private function store_card_checkout($tx, array $init) {
        $meta = json_decode((string)$tx->metadata, true);
        $meta = is_array($meta) ? $meta : array();
        $checkout = is_array($init['checkout'] ?? null) ? $init['checkout'] : array();
        $meta['card_checkout'] = array_merge(
            $checkout,
            array(
                'redirect_url' => $init['redirect_url'] ?? null,
                'provider'     => $checkout['provider'] ?? '',
            )
        );
        $this->ci->Payment_transaction_model->update_status($tx->id, array(
            'metadata'   => json_encode($meta, JSON_UNESCAPED_SLASHES),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ));
    }

    /**
     * The customer's standing Fundsvera virtual account — created on first
     * use, returned unchanged afterwards (their API does the same).
     *
     * A virtual account is "pay into this any time" banking: unlike a deposit
     * there is nothing to initiate, and unlike a checkout there is no expiry.
     * Every credit the bank reports against it is settled by the webhook
     * through confirm() — see FundsveraGateway::parse_event, which resolves
     * the account number to its owner.
     *
     * @return array{ok:bool, account?:object, existing?:bool, error?:string, code?:string}
     */
    public function virtual_account($user) {
        $method = $this->resolve_method('fundsvera');
        if (!$method || !(int)$method->is_active) {
            return array('ok'=>false,'error'=>'Bank transfer deposits are not available right now.',
                'code'=>'METHOD_INACTIVE');
        }
        $currency = $this->charge_currency_for($method);
        if (!$this->method_supports_currency($method, $currency)) {
            return $this->unsupported_currency($method, $currency);
        }

        $gateway = $this->gateway_for_code('fundsvera', $method);
        if (method_exists($gateway, 'is_configured') && !$gateway->is_configured()) {
            return array('ok'=>false,'error'=>'Bank transfer deposits are not configured yet.',
                'code'=>'METHOD_INACTIVE');
        }
        return $gateway->create_virtual_account($user);
    }

    /**
     * Open a deposit the customer intends to settle into their standing
     * virtual account.
     *
     * No provider call is made — the account already exists, so there is
     * nothing to initiate. What this does is record the intent so the payment
     * has a quoted amount to be checked against: the fundsvera_checkouts row
     * (marked as a virtual-account row by its missing expiry) is what the
     * webhook matches and amount-checks, exactly as it does for a
     * secured-checkout deposit. A credit that arrives with no open intent
     * still credits the owner — see virtual_account_credit().
     *
     * @param object $user
     * @param string|float $amount
     * @return array{ok:bool, transaction?:object, account?:object, error?:string, code?:string}
     */
    public function open_virtual_account_deposit($user, $amount) {
        $method = $this->resolve_method('fundsvera');
        if (!$method || !(int)$method->is_active) {
            return array('ok'=>false,'error'=>'Bank transfer deposits are not available right now.',
                'code'=>'METHOD_INACTIVE');
        }

        $amount = $this->normalise_amount($amount);
        if ($amount === null) return array('ok'=>false,'error'=>'Invalid amount','code'=>'BAD_AMOUNT');

        // Typed by the customer, so it is in the default currency shown on
        // Add Funds. Resolve it through the same method policy as an ordinary
        // Fundsvera checkout rather than maintaining a second currency rule.
        $currency = $this->charge_currency_for($method);
        if (!$this->method_supports_currency($method, $currency)) {
            return $this->unsupported_currency($method, $currency);
        }
        $quote = $this->quote($amount, $currency);
        if (empty($quote['ok'])) return $quote;

        if ($method->min_amount !== null && bccomp($quote['base_amount'], (string)$method->min_amount, 8) < 0)
            return array('ok'=>false,'code'=>'AMOUNT_TOO_LOW',
                'error'=>'Minimum is '.marvy_money($this->in_pay_currency($method->min_amount, $quote['fx_rate']), $currency));
        if ($method->max_amount !== null && bccomp($quote['base_amount'], (string)$method->max_amount, 8) > 0)
            return array('ok'=>false,'code'=>'AMOUNT_TOO_HIGH',
                'error'=>'Maximum is '.marvy_money($this->in_pay_currency($method->max_amount, $quote['fx_rate']), $currency));

        // The account the customer will pay into — created on first use.
        $va = $this->virtual_account($user);
        if (empty($va['ok'])) return $va;
        $account = $va['account'];

        $fee_base   = $this->calculate_fee($method, $quote['base_amount']);
        $bonus_base = $this->calculate_bonus($method, $quote['base_amount']);
        $credited_base = bcadd(bcsub($quote['base_amount'], $fee_base, 8), $bonus_base, 8);

        $public_id = marvy_public_id();
        $tx = $this->persist_transaction(array(
            'public_id'          => $public_id,
            'internal_reference' => 'MVS-'.strtoupper($public_id),
            'provider'           => 'fundsvera',
            'payment_method'     => 'virtual_account',
            'initiated_at'       => gmdate('Y-m-d H:i:s'),
            'user_id'            => $user->id,
            'payment_method_id'  => (int)$method->id,
            'amount'             => $amount,
            'fee'                => $this->in_pay_currency($fee_base, $quote['fx_rate']),
            'bonus'              => $this->in_pay_currency($bonus_base, $quote['fx_rate']),
            'credited_amount'    => $this->in_pay_currency($credited_base, $quote['fx_rate']),
            'currency'           => $currency,
            'base_currency'        => $quote['base_currency'],
            'base_amount'          => $quote['base_amount'],
            'credited_base_amount' => $credited_base,
            'fx_rate'              => $quote['fx_rate'],
            'status'             => self::STATUS_PENDING,
            'idempotency_key'    => 'va-deposit:'.$user->id.':'.$public_id,
            'metadata'           => json_encode(array(
                'virtual_account' => $account->account_number ?? null,
                'purpose'         => 'virtual_account',
            ), JSON_UNESCAPED_SLASHES),
            'created_at'         => gmdate('Y-m-d H:i:s'),
        ));
        $this->transition($tx->id, null, self::STATUS_PENDING, 'SYSTEM', 'Virtual account deposit opened');

        // The row the webhook validates against. No checkout_url and no
        // expires_at — a standing account never closes — which is exactly how
        // Fundsvera_checkout_model::open_virtual_account_for_user() finds it.
        $this->ci->load->model('Fundsvera_checkout_model');
        $this->ci->Fundsvera_checkout_model->open(array(
            'payment_transaction_id' => $tx->id,
            'user_id'                => $user->id,
            'request_id'             => 'MVS-'.strtoupper($public_id),
            // What the BANK must report, so it is the charge currency.
            'expected_amount'        => $amount,
            'currency'               => $currency,
            'account_number'         => $account->account_number ?? null,
            'account_name'           => $account->account_name ?? null,
            'bank_name'              => $account->bank_name ?? null,
        ));

        return array(
            'ok'          => true,
            'transaction' => $this->ci->Payment_transaction_model->find_by_id($tx->id),
            'account'     => $account,
        );
    }

    /**
     * Whether the adapter behind a payment method can take a payment.
     *
     * Manual bank transfer needs no credentials — a human reconciles it — so
     * it is always considered configured.
     */
    public function method_is_configured($method) {
        $code = strtolower((string)$method->code);
        if (!in_array($code, $this->implemented_gateways(), true)) return true;

        $gateway = $this->gateway_for_code($code, $method);
        if (!method_exists($gateway, 'is_configured')) return true;

        try {
            return (bool)$gateway->is_configured();
        } catch (Throwable $e) {
            log_message('error', 'could not read '.$code.' configuration: '.$e->getMessage());
            return false;
        }
    }

    /**
     * Whether this method's adapter needs API credentials at all.
     *
     * Manual bank transfer does not: a human reads the bank statement and
     * approves the deposit. Reporting it as "not configured" would send an
     * operator hunting for keys that do not exist.
     */
    public function method_needs_credentials($method) {
        $code = strtolower((string)$method->code);
        if (!in_array($code, $this->implemented_gateways(), true)) return false;
        return method_exists($this->gateway_for_code($code, $method), 'is_configured');
    }

    /**
     * Payment-method codes whose adapter is wired and trusted to verify its
     * own callbacks.
     *
     * Every adapter here calls the provider's real API and verifies the
     * provider's real signature. An adapter with no credentials configured
     * answers null from verify_webhook() — "cannot verify" — which stores the
     * event for the operator without moving money, instead of discarding a
     * genuine callback or crediting an unverified one.
     */
    public function implemented_gateways() {
        return array('manual', 'blockonomics', 'fundsvera',
                     'paystack', 'flutterwave', 'stripe', 'paypal', 'razorpay', 'coinpayments');
    }

    private function persist_transaction(array $data) {
        $this->ci->db->insert('payment_transactions', $data);
        return $this->ci->Payment_transaction_model->find_by_id($this->ci->db->insert_id());
    }

    private function transition($tx_id, $from, $to, $source, $reason = null) {
        // Persist the new state first, then append to the (append-only) event
        // log. Writing only the log would leave the transaction stuck in its
        // previous status forever.
        if ($from !== $to) {
            $this->ci->Payment_transaction_model->update_status($tx_id, array(
                'status'     => $to,
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ));
        }
        $this->ci->db->insert('payment_events', array(
            'payment_transaction_id' => $tx_id,
            'from_status' => $from,
            'to_status' => $to,
            'source' => $source,
            'reason' => $reason,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ));
    }

    /**
     * Verify an HMAC-SHA256 signature header against the configured secret.
     *
     * @return bool|null true/false when we can decide, null when no secret is
     *                   configured (the caller must not process the event).
     */
    private function verify_generic_signature($gateway_type, $raw_body, array $headers) {
        $sig = null;
        foreach ($headers as $name => $value) {
            if (stripos((string)$name, 'signature') !== false) { $sig = trim((string)$value); break; }
        }
        // An unsigned callback is always rejected.
        if ($sig === null || $sig === '') return false;

        $secret = $this->ci->Setting_model->get('payments.'.$gateway_type.'.webhook_secret');
        if (!$secret) $secret = getenv('MARVYSOCIALS_'.strtoupper($gateway_type).'_WEBHOOK_SECRET') ?: null;
        if (!$secret) return null;

        $expected = hash_hmac('sha256', (string)$raw_body, (string)$secret);
        // Strip an optional "sha256=" / "v1=" prefix before comparing.
        if (strpos($sig, '=') !== false) {
            $parts = explode('=', $sig, 2);
            if (ctype_alnum($parts[0])) $sig = $parts[1];
        }
        return hash_equals($expected, $sig);
    }

    /** Parse a plain JSON webhook envelope into the normalised event shape. */
    private function parse_generic_event($raw_body) {
        $data = json_decode((string)$raw_body, true);
        if (!is_array($data)) return array('event_id'=>null,'type'=>'unknown');
        $pick = function(array $keys) use ($data) {
            foreach ($keys as $k) {
                if (isset($data[$k]) && $data[$k] !== '') return $data[$k];
            }
            return null;
        };
        return array(
            'event_id'       => $pick(array('id','event_id','eventId')),
            'type'           => $pick(array('type','event','event_type')) ?: 'unknown',
            'provider_tx_id' => $pick(array('provider_tx_id','transaction_id','reference','txn_id')),
            'status'         => $pick(array('status','state','result')),
            'amount'         => $pick(array('amount','value')),
            'currency'       => $pick(array('currency','currency_code')),
            'metadata'       => isset($data['metadata']) && is_array($data['metadata']) ? $data['metadata'] : array(),
        );
    }

    private function normalise_amount($v) {
        if ($v === null || $v === '' || !is_numeric($v)) return null;
        $f = (float)$v;
        if ($f <= 0 || !is_finite($f)) return null;
        return number_format($f, 8, '.', '');
    }

    private function normalise_idem($key, $user) {
        if (!$key) return 'deposit:'.$user->id.':'.marvy_public_id();
        $clean = preg_replace('/[^a-zA-Z0-9._\-]/', '', (string)$key);
        return substr(self::IDEM_SCOPE.':'.$clean, 0, 128);
    }
}
