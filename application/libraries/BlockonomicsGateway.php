<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * BlockonomicsGateway — non-custodial Bitcoin deposits via Blockonomics.
 *
 * ## How the flow works
 *
 * Blockonomics is an address-generation service, not a hosted checkout. The
 * merchant supplies an *extended public key* (xPub); Blockonomics derives the
 * next unused receive address from it and calls a webhook as the payment
 * confirms. Coins go straight to the merchant's own wallet — Blockonomics
 * never holds them and there is no API key that can move funds.
 *
 *   1. `initiate()`  → POST /api/new_address, store the address and the fiat
 *                      amount quoted at that moment.
 *   2. customer pays the address from any wallet.
 *   3. Blockonomics GETs the callback URL with `?status=&addr=&value=&txid=`,
 *      once at 0 confirmations and again at each subsequent confirmation.
 *   4. `parse_event()` normalises that into the shape PaymentService expects;
 *      the wallet is credited exactly once, when the configured confirmation
 *      threshold is reached.
 *
 * ## Why the callback secret matters
 *
 * The callback is an unauthenticated GET to a public URL, so *anything* on the
 * internet can hit it. Blockonomics' documented protection is a secret embedded
 * in the callback URL itself, which the request echoes back. Without a
 * configured secret this adapter reports "cannot verify" (NULL) rather than
 * FALSE, which makes PaymentService store the event for inspection and move no
 * money — the same fail-closed behaviour every other unverified gateway gets.
 *
 * ## Amount safety
 *
 * The BTC amount is quoted at initiation and stored; the callback reports what
 * actually arrived, in satoshis. A payment is only treated as complete when the
 * received value covers the quoted amount less a small tolerance for exchange
 * drift, so an underpayment can never credit a full deposit.
 *
 * ## Status
 *
 * Implemented and unit-tested against recorded fixtures. It has NOT been run
 * against a live Blockonomics merchant account — that needs production
 * credentials (see docs/payments-blockonomics.md).
 */
class BlockonomicsGateway implements GatewayInterface {

    /** Blockonomics REST base. */
    const API_BASE = 'https://www.blockonomics.co/api';

    /** Satoshis in one bitcoin. */
    const SATOSHIS = 100000000;

    /** Fraction of the quoted amount a payment may fall short by (0.5%). */
    const UNDERPAYMENT_TOLERANCE = 0.005;

    /** Blockonomics numeric statuses. */
    const STATUS_UNCONFIRMED = 0;
    const STATUS_PARTIAL = 1;
    const STATUS_CONFIRMED = 2;

    /**
     * Base-unit precision per crypto, straight from the docs.
     *
     * BTC callbacks report satoshis (8 decimals); USDT callbacks report base
     * units of 6 decimals — dividing a USDT value by 1e8 would understate a
     * real payment a hundredfold and refuse every genuine deposit. A crypto
     * absent from this map is one the panel neither quotes nor holds.
     */
    const UNIT_PRECISION = array('BTC' => 8, 'USDT' => 6);

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
     * Merchant settings, admin-managed and read at call time.
     *
     * Settings win over environment variables so an operator can complete the
     * configuration from Admin → Payment methods without shell access, while a
     * containerised deployment can still inject them as env.
     */
    public function config() {
        return array(
            'api_key'        => $this->setting('blockonomics_api_key', 'BLOCKONOMICS_API_KEY'),
            'callback_secret'=> $this->setting('blockonomics_callback_secret', 'BLOCKONOMICS_CALLBACK_SECRET'),
            'confirmations'  => (int) ($this->setting('blockonomics_confirmations', 'BLOCKONOMICS_CONFIRMATIONS') ?: 2),
            'btc_enabled'    => $this->flag('blockonomics_btc_enabled', true),
            'usdt_enabled'   => $this->flag('blockonomics_usdt_enabled', false),
            'timeout_minutes'=> (int) ($this->setting('blockonomics_timeout_minutes', 'BLOCKONOMICS_TIMEOUT_MINUTES') ?: 60),
            // Optional store fragment (docs: new_address?match_callback=<fragment>).
            // A merchant running several stores pins the store each address belongs to.
            'match_callback' => $this->setting('blockonomics_match_callback', 'BLOCKONOMICS_MATCH_CALLBACK'),
        );
    }

    /** True when enough is configured to actually take a payment. */
    public function is_configured() {
        $cfg = $this->config();
        return !empty($cfg['api_key']);
    }

    /* ------------------------------------------------------------------ */
    /* GatewayInterface                                                    */
    /* ------------------------------------------------------------------ */

    /**
     * Reserve a receive address for this deposit.
     *
     * @return array{ok:bool, status?:string, checkout?:array, error?:string, code?:string}
     */
    public function initiate($transaction, $user) {
        $cfg = $this->config();
        if (empty($cfg['api_key'])) {
            return array('ok' => false, 'code' => 'CONFIG_MISSING',
                         'error' => 'Bitcoin payments are not configured yet.');
        }
        if (empty($cfg['btc_enabled'])) {
            return array('ok' => false, 'code' => 'CRYPTO_DISABLED',
                         'error' => 'Bitcoin deposits are currently disabled.');
        }

        $address = $this->request_address($cfg);
        if (empty($address['ok'])) {
            return array('ok' => false, 'code' => 'ADDRESS_FAILED', 'error' => $address['error']);
        }

        $fiat = (string) $transaction->amount;
        $currency = strtoupper($transaction->currency);
        $rate = $this->btc_rate($currency);

        // No rate means no honest quote. Better to refuse the deposit than to
        // show the customer a BTC amount we invented.
        if ($rate === null || (float) $rate <= 0) {
            return array('ok' => false, 'code' => 'RATE_UNAVAILABLE',
                         'error' => 'Could not fetch a live BTC rate. Try again shortly.');
        }

        $btc = bcdiv($fiat, $rate, 8);
        $expires = gmdate('Y-m-d H:i:s', time() + ($cfg['timeout_minutes'] * 60));

        $this->ci->db->insert('blockonomics_addresses', array(
            'public_id'              => marvy_public_id(),
            'payment_transaction_id' => $transaction->id,
            'user_id'                => $transaction->user_id,
            'crypto'                 => 'BTC',
            'address'                => $address['address'],
            'expected_crypto_amount' => $btc,
            'fiat_amount'            => $fiat,
            'fiat_currency'          => $currency,
            'rate_used'              => $rate,
            'required_confirmations' => $cfg['confirmations'],
            'status'                 => 'AWAITING',
            'expires_at'             => $expires,
            'created_at'             => gmdate('Y-m-d H:i:s'),
            'updated_at'             => gmdate('Y-m-d H:i:s'),
        ));

        return array(
            'ok' => true,
            'status' => 'PENDING',
            'checkout' => array(
                'crypto'        => 'BTC',
                'address'       => $address['address'],
                'amount_crypto' => $btc,
                'amount_fiat'   => $fiat,
                'currency'      => $currency,
                'rate'          => $rate,
                'confirmations' => $cfg['confirmations'],
                'expires_at'    => $expires,
                'uri'           => 'bitcoin:'.$address['address'].'?amount='.rtrim(rtrim($btc, '0'), '.'),
                'reference'     => $transaction->public_id,
                'instructions'  => 'Send exactly '.rtrim(rtrim($btc, '0'), '.').' BTC to the address above. '
                                  .'Your wallet is credited automatically after '.$cfg['confirmations']
                                  .' network confirmation'.($cfg['confirmations'] === 1 ? '' : 's').'.',
            ),
        );
    }

    /**
     * Verify a Blockonomics callback.
     *
     * @return bool|null TRUE verified, FALSE rejected, NULL cannot verify
     */
    public function verify_webhook($raw_body, array $headers) {
        $cfg = $this->config();
        if (empty($cfg['callback_secret'])) {
            // Fail closed upstream: PaymentService stores the event but will
            // not credit anything on a NULL.
            return null;
        }

        $params = $this->callback_params($raw_body, $headers);
        $presented = (string) ($params['secret'] ?? '');
        if ($presented === '') return false;

        return hash_equals((string) $cfg['callback_secret'], $presented);
    }

    /**
     * Normalise a callback into PaymentService's event shape.
     *
     * The event id is address + txid + confirmation status, which is exactly
     * the granularity Blockonomics retries at: replaying the same confirmation
     * is a duplicate and is dropped, while the *next* confirmation is a new
     * event that legitimately advances the payment.
     */
    public function parse_event($raw_body, array $headers = array()) {
        $params = $this->callback_params($raw_body, $headers);

        $address = (string) ($params['addr'] ?? '');
        $txid    = (string) ($params['txid'] ?? '');
        $status  = isset($params['status']) ? (int) $params['status'] : -1;
        $value   = isset($params['value']) ? (int) $params['value'] : 0;
        // Docs: crypto absent from the callback means Bitcoin.
        $crypto  = strtoupper(trim((string) ($params['crypto'] ?? 'BTC')));
        if ($crypto === '') $crypto = 'BTC';

        $row = $address !== '' ? $this->address_row($address) : null;

        $event = array(
            'event_id'       => $address.':'.$txid.':'.$status,
            'type'           => 'blockonomics.payment',
            'provider_tx_id' => $txid !== '' ? $txid : null,
            'status'         => 'PENDING',
            'amount'         => null,
            'currency'       => $crypto,
            'metadata'       => array(
                'address'    => $address,
                'satoshi'    => $value, // raw base units as reported
                'blk_status' => $status,
            ),
        );

        if (!$row) {
            // A callback for an address we never issued. Reported as unmatched
            // so it is logged and never credited.
            $event['status'] = 'IGNORED';
            return $event;
        }

        // A callback in a crypto other than the one this address was issued
        // for is never converted by guesswork — the panel quoted and holds
        // the row's crypto, and nothing else.
        if ($crypto !== strtoupper((string) $row->crypto)) {
            $event['status'] = 'IGNORED';
            return $event;
        }

        $divisor = $this->unit_divisor($crypto);
        $event['amount'] = ($value > 0 && $divisor !== null)
            ? bcdiv((string) $value, $divisor, 8) : null;

        // Docs, Callbacks - security notes: an unconfirmed callback may carry
        // rbf=1 — a Replace-By-Fee transaction the SENDER can cancel or
        // replace. Record it (the webhook row is the audit trail) but never
        // show it as payment progress or store its txid as the paying one.
        // rbf only ever marks unconfirmed callbacks; a confirmed one flows on.
        $rbf = isset($params['rbf']) ? (int) $params['rbf'] : 0;
        if ($rbf === 1 && $status < self::STATUS_CONFIRMED) {
            $event['metadata']['rbf'] = 1;
            return $event;
        }

        $event['metadata']['payment_transaction_id'] = (int) $row->payment_transaction_id;
        $this->record_progress($row, $status, $value, $txid);

        if ($status >= self::STATUS_CONFIRMED && $this->amount_sufficient($row, $value)) {
            $event['status'] = 'SUCCESS';
        } elseif ($status >= self::STATUS_CONFIRMED) {
            // Confirmed on-chain but short of the quoted amount. Deliberately
            // not a success: crediting the full deposit for a partial payment
            // is a direct loss.
            $event['status'] = 'UNDERPAID';
        }

        return $event;
    }

    /* ------------------------------------------------------------------ */
    /* Internals                                                           */
    /* ------------------------------------------------------------------ */

    /** Ask Blockonomics for the next unused receive address. */
    private function request_address(array $cfg) {
        $this->ci->load->library('SecureHttpClient');

        // The docs' checkout sample: POST /api/new_address?crypto=BTC&match_callback=<fragment>.
        // The crypto is always named — an account can hold a USDT wallet too —
        // and an unconfigured match_callback is omitted rather than sent empty,
        // which Blockonomics would read as a store fragment that matches none.
        $query = 'crypto=BTC';
        if (!empty($cfg['match_callback'])) {
            $query .= '&match_callback='.rawurlencode((string) $cfg['match_callback']);
        }

        $res = $this->ci->securehttpclient->post(
            self::API_BASE.'/new_address?'.$query,
            '',
            array('Authorization: Bearer '.$cfg['api_key'])
        );

        if (empty($res['http_code']) || $res['http_code'] !== 200) {
            log_message('error', 'blockonomics: new_address failed http='.($res['http_code'] ?? 0)
                .' err='.($res['error'] ?? ''));
            return array('ok' => false, 'error' => 'Could not reserve a Bitcoin address. Try again shortly.');
        }

        $body = json_decode((string) $res['body'], true);
        if (!is_array($body) || empty($body['address'])) {
            log_message('error', 'blockonomics: unexpected new_address body');
            return array('ok' => false, 'error' => 'Bitcoin address service returned an unexpected response.');
        }

        return array('ok' => true, 'address' => (string) $body['address']);
    }

    /**
     * Live BTC price in the given fiat currency.
     *
     * Returns NULL rather than a guess when the rate cannot be fetched — see
     * initiate(), which refuses the deposit in that case.
     */
    public function btc_rate($currency) {
        $this->ci->load->library('SecureHttpClient');
        // Docs sample: GET /api/price?crypto=BTC&currency=NGN
        $res = $this->ci->securehttpclient->get(
            self::API_BASE.'/price?crypto=BTC&currency='.rawurlencode(strtoupper($currency))
        );
        if (empty($res['http_code']) || $res['http_code'] !== 200) return null;

        $body = json_decode((string) $res['body'], true);
        if (!is_array($body) || !isset($body['price'])) return null;

        $price = (string) $body['price'];
        return (float) $price > 0 ? $price : null;
    }

    /**
     * Read callback parameters.
     *
     * Blockonomics calls back with a GET query string; the raw body is empty.
     * Both are accepted so the adapter also works behind a proxy that rewrites
     * the callback into a POST.
     */
    private function callback_params($raw_body, array $headers) {
        $params = array();

        if (!empty($_GET)) {
            foreach ($_GET as $k => $v) $params[$k] = is_array($v) ? reset($v) : $v;
        }

        $raw_body = (string) $raw_body;
        if ($raw_body !== '') {
            $decoded = json_decode($raw_body, true);
            if (is_array($decoded)) {
                $params = array_merge($params, $decoded);
            } else {
                parse_str($raw_body, $form);
                if (is_array($form)) $params = array_merge($params, $form);
            }
        }

        return $params;
    }

    private function address_row($address) {
        return $this->ci->db->where('address', $address)->get('blockonomics_addresses')->row();
    }

    /**
     * Base units per whole coin for a crypto, e.g. 100000000 for BTC.
     * NULL for a crypto with no precision entry — one the panel does not hold.
     */
    private function unit_divisor($crypto) {
        $decimals = self::UNIT_PRECISION[strtoupper((string) $crypto)] ?? null;
        return $decimals === null ? null : bcpow('10', (string) $decimals, 0);
    }

    /** Persist what the callback reported against the stored address. */
    private function record_progress($row, $status, $value, $txid) {
        $divisor = $this->unit_divisor($row->crypto) ?: (string) self::SATOSHIS;
        $received = bcdiv((string) max($value, 0), $divisor, 8);

        $state = 'AWAITING';
        if ($status >= self::STATUS_CONFIRMED) {
            $state = $this->amount_sufficient($row, $value) ? 'PAID' : 'PARTIAL';
        } elseif ($status === self::STATUS_UNCONFIRMED || $status === self::STATUS_PARTIAL) {
            $state = 'CONFIRMING';
        }

        $this->ci->db->where('id', $row->id)->update('blockonomics_addresses', array(
            'received_crypto_amount' => $received,
            'confirmations'          => $status >= self::STATUS_CONFIRMED ? max((int) $row->required_confirmations, 2) : (int) max($status, 0),
            'txid'                   => $txid !== '' ? substr($txid, 0, 128) : $row->txid,
            'status'                 => $state,
            'updated_at'             => gmdate('Y-m-d H:i:s'),
        ));
    }

    /** Whether the received base units cover the quoted amount within tolerance. */
    private function amount_sufficient($row, $value) {
        $expected = (string) $row->expected_crypto_amount;
        if ((float) $expected <= 0) return true; // nothing quoted; accept what arrived

        $divisor = $this->unit_divisor($row->crypto) ?: (string) self::SATOSHIS;
        $received = bcdiv((string) max($value, 0), $divisor, 8);
        $floor = bcmul($expected, (string) (1 - self::UNDERPAYMENT_TOLERANCE), 8);

        return bccomp($received, $floor, 8) >= 0;
    }

    /** A setting, falling back to an environment variable. */
    private function setting($key, $env_key) {
        try {
            $this->ci->load->model('Setting_model');
            $value = $this->ci->Setting_model->get($key);
            if ($value !== null && $value !== '') return $value;
        } catch (Throwable $e) { /* fall through to env */ }

        $env = getenv($env_key);
        return ($env === false || $env === '') ? null : $env;
    }

    private function flag($key, $default) {
        try {
            $this->ci->load->model('Setting_model');
            $value = $this->ci->Setting_model->get($key);
            if ($value === null || $value === '') return $default;
            return in_array(strtolower((string) $value), array('1', 'true', 'yes', 'on'), true);
        } catch (Throwable $e) {
            return $default;
        }
    }
}
