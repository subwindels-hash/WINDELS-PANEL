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

    /**
     * Transaction-status lookup paths, tried in order by verify().
     *
     * Fundsvera's published surface names secured-checkout and
     * create-virtual-account; a status endpoint is how every comparable
     * Nigerian collections API answers "did reference X pay?". verify() probes
     * both spellings and, when the provider answers 404 to both, reports the
     * lookup as *unsupported* rather than unreachable — so reconciliation
     * falls back to the age-out rule instead of holding every deposit open
     * for ever. If Fundsvera confirms an exact path, change it here.
     */
    const STATUS_LOOKUP_PATH = '/transaction/';
    const STATUS_QUERY_PATH  = '/transaction-status';

    /**
     * Webhook statuses that mean the money arrived (case-insensitive), and
     * those that mean it never will. Everything else is "still processing".
     *
     * Matching only the exact string 'SUCCESSFUL' here is how a paid deposit
     * ends up stuck in Processing for ever: the signed webhook arrives, the
     * amount matches, and the panel throws the credit away over vocabulary.
     */
    const SUCCESS_STATUSES = array('successful', 'success', 'completed', 'paid',
                                   'approved', 'settled');
    const FAILED_STATUSES  = array('failed', 'reversed', 'refunded', 'cancelled',
                                   'canceled', 'expired', 'declined', 'abandoned');

    private $ci;
    private $method;
    private $http;

    public function __construct($method_row = null, $http = null) {
        $this->ci =& get_instance();
        $this->method = $method_row;
        // Injectable for tests; built lazily in production (see http()).
        $this->http = $http;
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

        // Some providers prefix the digest with its algorithm ("sha256=…");
        // stripping an alnum prefix costs nothing and unblocks those.
        $presented = trim($presented);
        if (strpos($presented, '=') !== false) {
            $parts = explode('=', $presented, 2);
            if (ctype_alnum($parts[0])) $presented = $parts[1];
        }

        $expected = hash_hmac('sha256', (string)$raw_body, $secret);

        // hash_equals is timing-safe but throws on non-strings and compares
        // length first; both operands are hex digests here.
        $ok = hash_equals($expected, $presented);
        if (!$ok) {
            // Name the source, never the value: an operator whose webhook was
            // rotated in one place but not the other needs exactly this hint.
            $env = getenv('FUNDSVERA_WEBHOOK_SECRET');
            $source = ($env !== false && trim((string)$env) !== '') ? 'FUNDSVERA_WEBHOOK_SECRET env'
                : (getenv('FUNDSVERA_SECRET_KEY') !== false ? 'FUNDSVERA_SECRET_KEY env'
                : 'the fundsvera_webhook_secret/fundsvera_secret_key settings');
            log_message('error', 'fundsvera: webhook signature rejected — verified against '.$source
                .'. Rotate both sides to the same secret.');
        }
        return $ok;
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
        // `transaction_status` is the documented field; `status` is the
        // shorthand their examples sometimes use. Case-insensitive on
        // purpose: webhooks have been seen in both upper and lower case, and
        // a paid deposit must not be dropped over letter casing.
        $tx_status  = strtolower(trim((string)($data['transaction_status']
            ?? ($data['status'] ?? ''))));
        $amount     = isset($data['amount_paid']) ? (string)$data['amount_paid'] : null;

        if (in_array($tx_status, self::SUCCESS_STATUSES, true)) {
            $status = 'SUCCESS';
        } elseif (in_array($tx_status, self::FAILED_STATUSES, true)) {
            $status = 'FAILED';
        } else {
            $status = 'PENDING';
        }

        $event = array(
            // Fall back to request_id so a virtual-account credit (which has no
            // request_id) and a checkout (which does) both get a stable key.
            'event_id'       => $trx_ref !== '' ? $trx_ref : ($request_id !== '' ? $request_id : null),
            'type'           => 'fundsvera.'.strtolower((string)($data['trx_type'] ?? 'payment')),
            'provider_tx_id' => $trx_ref !== '' ? $trx_ref : null,
            'status'         => $status,
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
        $resolved = $this->resolve_transaction($request_id, $trx_ref, $data, $status);
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
    /* Reconciliation                                                      */
    /* ------------------------------------------------------------------ */

    /**
     * Ask Fundsvera directly what happened to one deposit.
     *
     * This is the webhook's safety net: when the callback never arrives (the
     * URL was never registered in the dashboard, the signature was rotated,
     * or their delivery simply failed), payment_reconciliation calls this for
     * every pending deposit and credits the ones the provider says paid —
     * instead of leaving the customer in Processing until the deposit
     * expires days later.
     *
     * Two documented-style lookups are tried, because the provider's status
     * endpoint spelling has not been confirmable from here: a REST-ish
     * `GET /transaction/{ref}`, then `POST /transaction-status` with the
     * request id. When both answer 404 the provider has no such endpoint and
     * the result says `unsupported` — which reconciliation treats as "no
     * verifier", leaving the existing age-out rule in charge — rather than
     * as an outage, which would hold every deposit open for ever.
     *
     * @param string $reference our request_id / internal reference, or a
     *                          provider trx_ref learned from a webhook
     * @return array{ok:bool,status?:string,amount?:string,provider_tx_id?:string,
     *               unsupported?:bool,error?:string}
     */
    public function verify($reference) {
        $reference = trim((string)$reference);
        if ($reference === '') {
            return array('ok' => false, 'error' => 'No reference to verify');
        }
        $cfg = $this->config();
        if (!$this->is_configured()) {
            return array('ok' => false, 'unsupported' => true, 'error' => 'Fundsvera is not configured');
        }

        // The provider's own trx_ref (if a webhook or a checkout row told us)
        // is the better lookup key; our request_id is the fallback.
        $refs = array($reference);
        $row = $this->checkout_row($reference);
        if ($row) {
            foreach (array($row->trx_ref ?? null, $row->request_id ?? null) as $extra) {
                $extra = trim((string)$extra);
                if ($extra !== '' && !in_array($extra, $refs, true)) $refs[] = $extra;
            }
        }

        foreach ($refs as $ref) {
            // 1. GET /transaction/{ref}
            $res = $this->get($cfg['base_url'].self::STATUS_LOOKUP_PATH.rawurlencode($ref), $cfg);
            $code = (int)($res['http_code'] ?? 0);
            if ($code === 404) {
                // 2. POST /transaction-status {request_id}
                $res = $this->post_status_query($cfg, $ref);
                $code = (int)($res['http_code'] ?? 0);
            }

            if ($code === 404) continue; // unknown reference — try the next one
            if ($code === 0) {
                return array('ok' => false, 'error' => 'Could not reach the payment provider');
            }
            if ($code < 200 || $code >= 300) {
                // A 401/403 here is a configuration problem, not an outage —
                // but reconciliation treats any non-answer as UNREACHABLE and
                // retries, which is the safe direction for both.
                return array('ok' => false,
                    'error' => 'The payment provider rejected the status lookup (HTTP '.$code.')');
            }

            $body = json_decode((string)($res['body'] ?? ''), true);
            if (!is_array($body)) {
                return array('ok' => false, 'error' => 'The provider returned an unusable status response');
            }

            $tx = isset($body['data']) && is_array($body['data']) ? $body['data'] : $body;
            $status = $this->normalise_status((string)($tx['transaction_status'] ?? ($tx['status'] ?? '')));
            $amount = isset($tx['amount_paid']) ? (string)$tx['amount_paid']
                : (isset($tx['amount']) ? (string)$tx['amount'] : null);

            return array(
                'ok'             => true,
                'status'         => $status,
                'amount'         => $amount,
                'provider_tx_id' => isset($tx['trx_ref']) ? (string)$tx['trx_ref']
                    : (isset($tx['id']) ? (string)$tx['id'] : $ref),
                'detail'         => (string)($tx['transaction_status'] ?? ($tx['status'] ?? '')),
            );
        }

        // Every reference 404'd. Either the payment does not exist yet (the
        // normal answer moments after initiation) or the endpoint spelling is
        // wrong — indistinguishable from here, so PENDING rather than a guess.
        return array('ok' => true, 'status' => 'PENDING', 'provider_tx_id' => null, 'amount' => null);
    }

    /* ------------------------------------------------------------------ */
    /* Internals                                                           */
    /* ------------------------------------------------------------------ */

    /**
     * Find the payment this event belongs to and check the amount.
     *
     * @return array{transaction_id:int, underpaid:bool}|null
     */
    private function resolve_transaction($request_id, $trx_ref, array $data, $status = 'SUCCESS') {
        $this->ci->load->model('Fundsvera_checkout_model');

        $row = $this->checkout_row($request_id, $trx_ref);

        if ($row) {
            $paid = isset($data['amount_paid']) ? (string)$data['amount_paid'] : '0';
            $underpaid = bccomp($paid, (string)$row->expected_amount, 8) < 0;

            // A terminal failure closes the checkout row too, so support sees
            // FAILED rather than a PENDING row that hides the real outcome.
            if ($status === 'FAILED') {
                $this->ci->Fundsvera_checkout_model->record_result($row->id, array(
                    'status'      => 'FAILED',
                    'amount_paid' => $paid,
                    'trx_ref'     => $trx_ref !== '' ? $trx_ref : $row->trx_ref,
                ));
                return array('transaction_id' => (int)$row->payment_transaction_id, 'underpaid' => false);
            }

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

    /** The checkout row a webhook or reference belongs to, or null. */
    private function checkout_row($request_id, $trx_ref = null) {
        $this->ci->load->model('Fundsvera_checkout_model');

        $row = null;
        if ($request_id !== null && trim((string)$request_id) !== '') {
            $row = $this->ci->Fundsvera_checkout_model->by_request_id(trim((string)$request_id));
        }
        if (!$row && $trx_ref !== null && trim((string)$trx_ref) !== '') {
            $row = $this->ci->Fundsvera_checkout_model->by_trx_ref(trim((string)$trx_ref));
        }
        return $row;
    }

    /** POST /transaction-status with the request id (verify()'s second probe). */
    private function post_status_query($cfg, $ref) {
        try {
            return $this->http()->post(
                $cfg['base_url'].self::STATUS_QUERY_PATH,
                json_encode(array('request_id' => $ref), JSON_UNESCAPED_SLASHES),
                array(
                    'Authorization: Bearer '.$cfg['secret_key'],
                    'Public-Key: '.$cfg['public_key'],
                    'Content-Type: application/json',
                    'Accept: application/json',
                )
            );
        } catch (Exception $e) {
            return array('http_code' => 0, 'body' => null, 'error' => $e->getMessage());
        }
    }

    /** Webhook/status vocabulary → SUCCESS | FAILED | PENDING. */
    private function normalise_status($raw) {
        $value = strtolower(trim((string)$raw));
        if (in_array($value, self::SUCCESS_STATUSES, true)) return 'SUCCESS';
        if (in_array($value, self::FAILED_STATUSES, true))  return 'FAILED';
        return 'PENDING';
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

    /**
     * Signed POST to the provider.
     *
     * Uses a **dedicated, fail-fast client**: the customer is sitting on a
     * "Processing…" button for the duration of this call, and the shared
     * client's retry ladder (3 retries plus backoff on a 15-second timeout)
     * held the page for over a minute before answering — which reads to the
     * customer as a broken site. Initiation moves no money, so a single
     * bounded attempt with a clear, retryable error is the honest behaviour;
     * the webhook, not this call, is what credits anyone.
     */
    private function post($path, array $payload, array $cfg) {
        $res = $this->http()->post(
            $cfg['base_url'].$path,
            json_encode($payload, JSON_UNESCAPED_SLASHES),
            array(
                'Authorization: Bearer '.$cfg['secret_key'],
                'Public-Key: '.$cfg['public_key'],
                'Content-Type: application/json',
                'Accept: application/json',
            ),
            $this->http_options()
        );

        $code = (int)($res['http_code'] ?? 0);
        $body = json_decode((string)($res['body'] ?? ''), true);

        if ($code === 0) {
            $transport_error = (string)($res['error'] ?? 'unknown');
            log_message('error', 'fundsvera: '.$path.' unreachable: '.$transport_error);
            if (stripos($transport_error, 'timed out') !== false
                || stripos($transport_error, 'timeout') !== false) {
                return array('ok' => false,
                    'error' => 'The payment provider took too long to answer. Please try again — '
                              .'your deposit has not been started.');
            }
            return array('ok' => false,
                'error' => 'Could not reach the payment provider. Please try again shortly — '
                          .'your deposit has not been started.');
        }
        // Log the status, never the payload: bodies carry customer emails and
        // account numbers, and the log is not where those belong.
        log_message($code >= 400 ? 'error' : 'debug',
            'fundsvera: POST '.$path.' -> HTTP '.$code);

        if ($code < 200 || $code >= 300) {
            // Surface the provider's own message: "Duplicate request ID" and
            // "amount greater than or equal to 100" are actionable, and hiding
            // them behind a generic error makes support impossible. The
            // provider sends errors both as JSON ({message}) and as plain
            // text (401 'Unauthorized request please use valid keys').
            if (is_array($body) && !empty($body['message'])) {
                return array('ok' => false, 'error' => (string)$body['message']);
            }
            $plain = trim((string)($res['body'] ?? ''));
            if ($plain !== '' && strpos($plain, '{') !== 0 && strpos($plain, '[') !== 0) {
                return array('ok' => false, 'error' => mb_substr($plain, 0, 160));
            }
            return array('ok' => false,
                'error' => 'The payment provider rejected the request (HTTP '.$code.').');
        }
        if (!is_array($body)) {
            return array('ok' => false,
                'error' => 'The payment provider returned an unusable response. Try again.');
        }

        return array('ok' => true, 'body' => $body);
    }

    /** Signed GET, on the same fail-fast terms as post(). */
    private function get($url, array $cfg) {
        try {
            return $this->http()->get($url, array(
                'Authorization: Bearer '.$cfg['secret_key'],
                'Public-Key: '.$cfg['public_key'],
                'Accept: application/json',
            ), $this->http_options());
        } catch (Exception $e) {
            return array('http_code' => 0, 'body' => null, 'error' => $e->getMessage());
        }
    }

    /**
     * The HTTP client for provider calls.
     *
     * A dedicated instance rather than the shared library one: initiation is
     * synchronous and user-facing, so it gets a tight timeout and **no**
     * internal retries. Every second of a retry ladder here is a customer
     * staring at "Processing…".
     */
    private function http() {
        if ($this->http) return $this->http;
        $this->ci->load->library('SecureHttpClient');
        $this->http = new SecureHttpClient($this->http_options());
        return $this->http;
    }

    /**
     * The per-call client options. A customer is waiting on the browser side
     * of initiate(): the provider must fail fast, with no internal retry
     * ladder that turns one slow request into minutes of silence.
     * FUNDSVERA_TIMEOUT_SECONDS lets an operator with a slow uplink tune it
     * (3–60s); the default stays at 12s.
     */
    private function http_options() {
        $timeout = 12;
        $env_timeout = getenv('FUNDSVERA_TIMEOUT_SECONDS');
        if ($env_timeout !== false && is_numeric($env_timeout) && (int)$env_timeout >= 3
            && (int)$env_timeout <= 60) {
            $timeout = (int)$env_timeout;
        }
        return array(
            'timeout' => $timeout,
            'connect_timeout' => 4,
            'max_retries' => 0,
        );
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
