<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * FundsveraGateway — Nigerian bank-transfer collections via Fundsvera.
 *
 * Implements the provider's documented v1 API (https://fundsvera.co/docs):
 *
 *   POST /api/v1/create-virtual-account  — a persistent account per customer
 *   POST /api/v1/secured-checkout        — a 30-minute account + signed checkout URL
 *   webhook                              — HMAC-SHA256 in X-FUNDSVERA-SIGNATURE
 *
 * ## Collections only
 *
 * Fundsvera documents no disbursement/payout endpoint. This adapter therefore
 * only takes money in. Paying earnings *out* is a separate, deliberately manual
 * workflow (see PayoutService) — an integration cannot be invented for an API
 * that does not exist.
 *
 * ## Why the webhook is the only thing that credits
 *
 * `secured-checkout` returns a `redirect_url` the customer lands on after
 * paying. That redirect proves nothing: it is a URL the customer's browser can
 * be pointed at by anyone. Money moves only when a webhook arrives whose HMAC
 * matches our secret, whose `request_id` names a transaction we created, and
 * whose `amount_paid` covers what that transaction expected.
 *
 * ## Amount safety
 *
 * The expected amount is written to `fundsvera_checkouts` at initiation. The
 * webhook reports what actually arrived. A short payment is recorded and
 * flagged, never credited as if it were complete — Fundsvera's own fee is
 * deducted into `settlement_amount`, so `amount_paid` is the figure to compare
 * against and `settlement_amount` is what the merchant actually receives.
 */
class FundsveraGateway implements GatewayInterface {

    /** Documented production base URL. */
    const DEFAULT_BASE_URL = 'https://fundsvera.co/api/v1';

    /** Signature header the provider sends. */
    const SIGNATURE_HEADER = 'X-FUNDSVERA-SIGNATURE';

    /** Fundsvera requires request_id to be at least this long. */
    const MIN_REQUEST_ID = 20;

    /** Their documented minimum collectible amount, in NGN. */
    const MIN_AMOUNT_NGN = 100;

    /** Secured-checkout accounts are valid for 30 minutes. */
    const CHECKOUT_TTL_MINUTES = 30;

    /** Currently the only bank code their virtual-account API accepts. */
    const VIRTUAL_ACCOUNT_BANK_CODE = '100033';

    private $ci;
    private $method;

    public function __construct($method_row = null) {
        $this->ci =& get_instance();
        $this->method = $method_row;
    }

    /* ------------------------------------------------------------------ */
    /* Configuration                                                       */
    /* ------------------------------------------------------------------ */

    /**
     * Credentials and behaviour.
     *
     * Secrets are read from the environment first and the settings table
     * second. Environment wins because a containerised deployment injects them
     * that way and must not be silently overridden by a database row; the
     * settings fallback exists so a cPanel operator with no shell can still
     * complete the configuration.
     */
    public function config() {
        return array(
            'enabled'        => $this->flag('FUNDSVERA_ENABLED', 'fundsvera_enabled', false),
            'base_url'       => rtrim($this->secret('FUNDSVERA_BASE_URL', 'fundsvera_base_url')
                                      ?: self::DEFAULT_BASE_URL, '/'),
            'public_key'     => $this->secret('FUNDSVERA_PUBLIC_KEY', 'fundsvera_public_key'),
            'secret_key'     => $this->secret('FUNDSVERA_SECRET_KEY', 'fundsvera_secret_key'),
            'webhook_secret' => $this->secret('FUNDSVERA_WEBHOOK_SECRET', 'fundsvera_webhook_secret'),
        );
    }

    /** Whether a payment can actually be taken. */
    public function is_configured() {
        $cfg = $this->config();
        return !empty($cfg['public_key']) && !empty($cfg['secret_key']);
    }

    /**
     * The key webhook signatures are verified with.
     *
     * Fundsvera's documentation signs webhooks with the *business secret key*.
     * FUNDSVERA_WEBHOOK_SECRET is honoured when set, because operators
     * reasonably expect that variable to work and because the provider may
     * later separate the two — but the secret key remains the documented
     * default rather than a silent "no secret configured".
     */
    public function webhook_secret() {
        $cfg = $this->config();
        return !empty($cfg['webhook_secret']) ? $cfg['webhook_secret'] : $cfg['secret_key'];
    }

    /* ------------------------------------------------------------------ */
    /* GatewayInterface                                                    */
    /* ------------------------------------------------------------------ */

    /**
     * Open a secured bank-transfer checkout for a deposit.
     *
     * @return array{ok:bool, status?:string, checkout?:array, redirect_url?:string, error?:string, code?:string}
     */
    public function initiate($transaction, $user) {
        $cfg = $this->config();

        if (empty($cfg['enabled'])) {
            return $this->fail('PROVIDER_DISABLED', 'Bank transfer payments are currently unavailable.');
        }
        if (!$this->is_configured()) {
            return $this->fail('CONFIG_MISSING', 'Bank transfer payments are not configured yet.');
        }

        $currency = strtoupper((string)$transaction->currency);
        if ($currency !== 'NGN') {
            // Their collections are NGN-denominated. Refusing loudly is better
            // than sending a figure the provider will interpret as naira.
            return $this->fail('CURRENCY_UNSUPPORTED',
                'Bank transfer deposits are only available in NGN.');
        }

        $amount = (float)$transaction->amount;
        if ($amount < self::MIN_AMOUNT_NGN) {
            return $this->fail('AMOUNT_TOO_LOW',
                'The minimum bank transfer deposit is NGN '.number_format(self::MIN_AMOUNT_NGN).'.');
        }

        $request_id = $this->request_id_for($transaction);
        $payload = array(
            'customer_email' => (string)$user->email,
            'customer_name'  => $this->sanitise_name($user),
            // Whole naira: the provider validates a number, and sending eight
            // decimal places invites a rounding mismatch at reconciliation.
            'amount'         => round($amount, 2),
            'request_id'     => $request_id,
            'redirect_url'   => $this->redirect_url(),
        );
        $phone = $this->sanitise_phone($user);
        if ($phone !== null) $payload['customer_phone'] = $phone;

        $res = $this->post('/secured-checkout', $payload, $cfg);
        if (empty($res['ok'])) {
            return $this->fail('PROVIDER_ERROR', $res['error']);
        }
        $body = $res['body'];

        // Record what we expect *before* returning instructions. The webhook is
        // matched and amount-checked against this row.
        $this->ci->load->model('Fundsvera_checkout_model');
        $this->ci->Fundsvera_checkout_model->open(array(
            'payment_transaction_id' => $transaction->id,
            'user_id'                => $transaction->user_id,
            'request_id'             => $request_id,
            'trx_ref'                => isset($body['trx_ref']) ? (string)$body['trx_ref'] : null,
            'expected_amount'        => (string)$transaction->amount,
            'currency'               => $currency,
            'account_number'         => isset($body['account_number']) ? (string)$body['account_number'] : null,
            'account_name'           => isset($body['account_name']) ? (string)$body['account_name'] : null,
            'bank_name'              => isset($body['bank_name']) ? (string)$body['bank_name'] : null,
            'checkout_url'           => isset($body['checkout_url']) ? (string)$body['checkout_url'] : null,
            'expires_at'             => gmdate('Y-m-d H:i:s', time() + (self::CHECKOUT_TTL_MINUTES * 60)),
        ));

        return array(
            'ok'           => true,
            'status'       => 'PENDING',
            'redirect_url' => isset($body['checkout_url']) ? (string)$body['checkout_url'] : null,
            'checkout'     => array(
                'provider'       => 'fundsvera',
                'method'         => 'bank_transfer',
                'account_number' => $body['account_number'] ?? null,
                'account_name'   => $body['account_name'] ?? null,
                'bank_name'      => $body['bank_name'] ?? null,
                'amount'         => (string)$transaction->amount,
                'currency'       => $currency,
                'reference'      => $request_id,
                'checkout_url'   => $body['checkout_url'] ?? null,
                'validity'       => self::CHECKOUT_TTL_MINUTES.' minutes',
                'instructions'   => 'Transfer exactly '.marvy_money($transaction->amount, $currency)
                                    .' to the account shown. The account is valid for '
                                    .self::CHECKOUT_TTL_MINUTES.' minutes and your wallet is credited '
                                    .'automatically once the transfer is confirmed.',
            ),
        );
    }

    /**
     * Create (or fetch) the customer's persistent virtual account.
     *
     * Separate from initiate(): a virtual account is a standing "pay into this
     * any time" facility, not a checkout for one deposit. Their API returns an
     * existing account rather than duplicating, and this mirrors that.
     *
     * @return array{ok:bool, account?:object, error?:string, code?:string}
     */
    public function create_virtual_account($user) {
        $cfg = $this->config();
        if (empty($cfg['enabled'])) {
            return $this->fail('PROVIDER_DISABLED', 'Bank transfer payments are currently unavailable.');
        }
        if (!$this->is_configured()) {
            return $this->fail('CONFIG_MISSING', 'Bank transfer payments are not configured yet.');
        }

        $this->ci->load->model('Fundsvera_virtual_account_model');
        $existing = $this->ci->Fundsvera_virtual_account_model->for_user($user->id);
        if ($existing) {
            return array('ok' => true, 'account' => $existing, 'existing' => true);
        }

        $phone = $this->sanitise_phone($user);
        if ($phone === null) {
            // Their API requires exactly 11 digits. Asking the customer for a
            // phone number is a better failure than sending a malformed one.
            return $this->fail('PHONE_REQUIRED',
                'Add an 11-digit phone number to your profile before creating a bank account.');
        }

        $res = $this->post('/create-virtual-account', array(
            'email'     => (string)$user->email,
            'name'      => $this->sanitise_name($user),
            'bank_code' => self::VIRTUAL_ACCOUNT_BANK_CODE,
            'phone'     => $phone,
        ), $cfg);

        if (empty($res['ok'])) {
            return $this->fail('PROVIDER_ERROR', $res['error']);
        }

        $va = isset($res['body']['virtual_account']) ? $res['body']['virtual_account'] : array();
        if (empty($va['account_number'])) {
            log_message('error', 'fundsvera: create-virtual-account returned no account number');
            return $this->fail('PROVIDER_ERROR', 'The bank account service returned an unexpected response.');
        }

        $id = $this->ci->Fundsvera_virtual_account_model->store($user, array(
            'account_number' => (string)$va['account_number'],
            'account_name'   => (string)($va['account_name'] ?? ''),
            'bank_name'      => (string)($va['bank_name'] ?? ''),
            'bank_code'      => (string)($va['bank_code'] ?? self::VIRTUAL_ACCOUNT_BANK_CODE),
            'account_status' => (string)($va['account_status'] ?? 'Active'),
            'customer_email' => (string)$user->email,
            'customer_phone' => $phone,
            'raw_response'   => json_encode($res['body'], JSON_UNESCAPED_SLASHES),
        ));

        return array(
            'ok'      => true,
            'account' => $this->ci->Fundsvera_virtual_account_model->find_by_id($id),
        );
    }

    /**
     * Verify the webhook HMAC.
     *
     * @return bool|null TRUE verified, FALSE rejected, NULL cannot verify
     */
    public function verify_webhook($raw_body, array $headers) {
        $secret = $this->webhook_secret();
        if (empty($secret)) {
            // Fail closed upstream: PaymentService stores the event and credits
            // nothing when this is NULL.
            return null;
        }

        $presented = $this->header_value($headers, self::SIGNATURE_HEADER);
        if ($presented === null || $presented === '') return false;

        $expected = hash_hmac('sha256', (string)$raw_body, $secret);

        // hash_equals is timing-safe but throws on non-strings and compares
        // length first; both operands are hex digests here.
        return hash_equals($expected, trim($presented));
    }

    /**
     * Normalise a Fundsvera event into the shape PaymentService reconciles.
     *
     * Their bank-transfer and virtual-account webhooks share a payload shape.
     * The event id is the provider's own `trx_ref` — globally unique per
     * transaction — so a redelivery of the same event de-duplicates while a
     * genuinely new payment does not.
     */
    public function parse_event($raw_body) {
        $data = json_decode((string)$raw_body, true);
        if (!is_array($data)) {
            return array('event_id' => null, 'type' => 'fundsvera.unparseable', 'status' => 'IGNORED');
        }

        $trx_ref    = isset($data['trx_ref']) ? (string)$data['trx_ref'] : '';
        $request_id = isset($data['request_id']) ? (string)$data['request_id'] : '';
        $tx_status  = strtoupper((string)($data['transaction_status'] ?? ''));
        $amount     = isset($data['amount_paid']) ? (string)$data['amount_paid'] : null;

        $event = array(
            // Fall back to request_id so a virtual-account credit (which has no
            // request_id) and a checkout (which does) both get a stable key.
            'event_id'       => $trx_ref !== '' ? $trx_ref : ($request_id !== '' ? $request_id : null),
            'type'           => 'fundsvera.'.strtolower((string)($data['trx_type'] ?? 'payment')),
            'provider_tx_id' => $trx_ref !== '' ? $trx_ref : null,
            'status'         => $tx_status === 'SUCCESSFUL' ? 'SUCCESS' : 'PENDING',
            'amount'         => $amount,
            'currency'       => 'NGN',
            'metadata'       => array(
                'request_id'         => $request_id,
                'settlement_amount'  => $data['settlement_amount'] ?? null,
                'fee'                => $data['fee'] ?? null,
                'virtual_account_no' => $data['customer']['virtual_account_no'] ?? null,
                'customer_email'     => $data['customer']['email'] ?? null,
            ),
        );

        // Resolve the transaction ourselves. PaymentService prefers an
        // adapter-supplied id precisely because the adapter knows how its own
        // references map to our rows.
        $resolved = $this->resolve_transaction($request_id, $trx_ref, $data);
        if ($resolved) {
            $event['metadata']['payment_transaction_id'] = (int)$resolved['transaction_id'];
            if (!empty($resolved['underpaid'])) {
                // Confirmed by the bank but short of what we quoted. Recording
                // it as a success would credit a full deposit for a partial
                // payment.
                $event['status'] = 'UNDERPAID';
            }
        } elseif ($event['status'] === 'SUCCESS') {
            $event['status'] = 'IGNORED';
        }

        return $event;
    }

    /* ------------------------------------------------------------------ */
    /* Internals                                                           */
    /* ------------------------------------------------------------------ */

    /**
     * Find the payment this event belongs to and check the amount.
     *
     * @return array{transaction_id:int, underpaid:bool}|null
     */
    private function resolve_transaction($request_id, $trx_ref, array $data) {
        $this->ci->load->model('Fundsvera_checkout_model');

        $row = null;
        if ($request_id !== '') {
            $row = $this->ci->Fundsvera_checkout_model->by_request_id($request_id);
        }
        if (!$row && $trx_ref !== '') {
            $row = $this->ci->Fundsvera_checkout_model->by_trx_ref($trx_ref);
        }

        if ($row) {
            $paid = isset($data['amount_paid']) ? (string)$data['amount_paid'] : '0';
            $underpaid = bccomp($paid, (string)$row->expected_amount, 8) < 0;

            $this->ci->Fundsvera_checkout_model->record_result($row->id, array(
                'status'            => $underpaid ? 'FAILED' : 'PAID',
                'amount_paid'       => $paid,
                'settlement_amount' => isset($data['settlement_amount']) ? (string)$data['settlement_amount'] : null,
                'provider_fee'      => isset($data['fee']) ? (string)$data['fee'] : null,
                'trx_ref'           => $trx_ref !== '' ? $trx_ref : $row->trx_ref,
                'paid_at'           => $underpaid ? null : gmdate('Y-m-d H:i:s'),
            ));

            return array('transaction_id' => (int)$row->payment_transaction_id, 'underpaid' => $underpaid);
        }

        // A virtual-account credit has no checkout row: the customer pushed
        // money to their standing account without opening a deposit first.
        $va = $data['customer']['virtual_account_no'] ?? null;
        if ($va) {
            log_message('info', 'fundsvera: unsolicited virtual-account credit to '.$va
                .' — recorded, awaiting operator reconciliation');
        }
        return null;
    }

    /**
     * A request_id that satisfies "unique per business, >= 20 characters".
     *
     * Derived from the transaction's own internal reference so the value is
     * traceable back to the row rather than an opaque random string.
     */
    public function request_id_for($transaction) {
        $base = !empty($transaction->internal_reference)
            ? (string)$transaction->internal_reference
            : 'MVS-'.strtoupper((string)$transaction->public_id);

        if (strlen($base) < self::MIN_REQUEST_ID) {
            $base .= '-'.strtoupper(bin2hex(random_bytes(8)));
        }
        return substr($base, 0, 64);
    }

    /** Signed POST to the provider. */
    private function post($path, array $payload, array $cfg) {
        $this->ci->load->library('SecureHttpClient');

        $res = $this->ci->securehttpclient->post(
            $cfg['base_url'].$path,
            json_encode($payload, JSON_UNESCAPED_SLASHES),
            array(
                'Authorization: Bearer '.$cfg['secret_key'],
                'Public-Key: '.$cfg['public_key'],
                'Content-Type: application/json',
                'Accept: application/json',
            )
        );

        $code = (int)($res['http_code'] ?? 0);
        $body = json_decode((string)($res['body'] ?? ''), true);

        if ($code === 0) {
            log_message('error', 'fundsvera: '.$path.' unreachable: '.($res['error'] ?? 'unknown'));
            return array('ok' => false, 'error' => 'Could not reach the payment provider. Try again shortly.');
        }
        if ($code !== 200 || !is_array($body)) {
            // Surface the provider's own message: "Duplicate request ID" and
            // "amount greater than or equal to 100" are actionable, and hiding
            // them behind a generic error makes support impossible.
            $message = is_array($body) && !empty($body['message'])
                ? (string)$body['message']
                : 'The payment provider rejected the request (HTTP '.$code.').';
            log_message('error', 'fundsvera: '.$path.' http='.$code.' msg='.$message);
            return array('ok' => false, 'error' => $message);
        }

        return array('ok' => true, 'body' => $body);
    }

    /** Case-insensitive header lookup — PHP and proxies disagree on casing. */
    private function header_value(array $headers, $name) {
        $needle = strtolower(str_replace('_', '-', $name));
        foreach ($headers as $key => $value) {
            if (strtolower(str_replace('_', '-', (string)$key)) === $needle) {
                return is_array($value) ? reset($value) : (string)$value;
            }
        }
        return null;
    }

    /** Their name validation allows letters, digits, spaces, dashes, underscores. */
    private function sanitise_name($user) {
        $name = trim((string)($user->first_name ?? '').' '.(string)($user->last_name ?? ''));
        if ($name === '') $name = (string)($user->username ?? 'Customer');
        $name = preg_replace('/[^A-Za-z0-9 \-_]/', ' ', $name);
        $name = trim(preg_replace('/\s+/', ' ', $name));
        return $name !== '' ? substr($name, 0, 100) : 'Customer';
    }

    /** Exactly 11 digits, or NULL when the profile has no usable number. */
    private function sanitise_phone($user) {
        $raw = preg_replace('/\D+/', '', (string)($user->phone ?? ''));
        if ($raw === '') return null;
        // +234 803... and 0803... are the same Nigerian number.
        if (strlen($raw) === 13 && strpos($raw, '234') === 0) $raw = '0'.substr($raw, 3);
        if (strlen($raw) === 10) $raw = '0'.$raw;
        return strlen($raw) === 11 ? $raw : null;
    }

    /** Where the customer lands after paying. Must carry no query string. */
    private function redirect_url() {
        return rtrim(site_url('dashboard/wallet/deposits'), '/');
    }

    private function fail($code, $message) {
        return array('ok' => false, 'code' => $code, 'error' => $message);
    }

    /** Environment first, settings second. */
    private function secret($env_key, $setting_key) {
        $env = getenv($env_key);
        if ($env !== false && trim((string)$env) !== '') return trim((string)$env);

        try {
            $this->ci->load->model('Setting_model');
            $value = $this->ci->Setting_model->get($setting_key);
            if ($value !== null && $value !== '') return (string)$value;
        } catch (Throwable $e) { /* settings unavailable */ }

        return null;
    }

    private function flag($env_key, $setting_key, $default) {
        $raw = $this->secret($env_key, $setting_key);
        if ($raw === null) return $default;
        return in_array(strtolower(trim((string)$raw)), array('1', 'true', 'yes', 'on'), true);
    }
}
