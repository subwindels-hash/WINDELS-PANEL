<?php
defined('BASEPATH') OR exit('No direct script access allowed');

require_once __DIR__.'/VtuProviderInterface.php';

/**
 * VtpassAdapter — live VTU integration with VTpass (§14, §23).
 *
 * StandardVtuAdapter was written to "VTpass-style" from memory and gets three
 * things wrong for the real API, each of which is a money bug:
 *
 *  1. Auth. VTpass does not use `Authorization: Bearer`. GET requests carry
 *     `api-key` + `public-key`; POST requests carry `api-key` + `secret-key`.
 *     A Bearer header authenticates as nobody and every call fails 401.
 *  2. Requery identity. `/requery` takes the *request_id we sent*, not the
 *     transactionId VTpass returns. Storing the transactionId as the provider
 *     reference makes settlement impossible: the cron would requery a value
 *     VTpass has never heard of and the customer's PROCESSING purchase would
 *     never resolve.
 *  3. Indeterminate outcomes. A timeout on `/pay` does not mean "no purchase".
 *     VTpass explicitly says to treat no-response, timeouts and unrecognised
 *     codes as pending and requery. Reporting those as a failure refunds a
 *     customer whose airtime was actually delivered.
 *
 * So this is a separate adapter rather than a tweak: the shapes are close
 * enough to look interchangeable and different enough to lose money.
 *
 * Credentials live in `providers.api_key_encrypted` as a JSON blob
 * {"api_key":"...","public_key":"PK_...","secret_key":"SK_..."} so one
 * encrypted column still holds the three secrets VTpass issues. A bare string
 * is accepted as the api-key alone, which is what the shared admin form
 * produces for single-key providers.
 *
 * Nothing here throws for a provider-side rejection: the engine treats a
 * rejection as a normal result and refunds it.
 */
class VtpassAdapter implements VtuProviderInterface {

    /** Sandbox host, for the admin hint and the docs. */
    const SANDBOX_URL = 'https://sandbox.vtpass.com/api';
    const LIVE_URL    = 'https://vtpass.com/api';

    /** VTpass timestamps request_ids in West Africa Time, not UTC. */
    const REQUEST_TZ = 'Africa/Lagos';

    /** Accepted, still settling — requery until terminal. */
    private static $pending_codes = array('099', '001', '044', '089');

    /**
     * Terminal rejections, mapped to something an admin can act on.
     * Anything absent from this list and not in $pending_codes is treated as
     * pending, because inventing a failure is the expensive direction.
     */
    private static $failure_codes = array(
        '010' => 'The selected variation is not available',
        '011' => 'The provider rejected the request arguments',
        '012' => 'The provider does not offer this product',
        '013' => 'Amount is below the minimum for this product',
        '014' => 'That request id has already been used',
        '015' => 'The provider does not recognise this request',
        '016' => 'The transaction failed at the provider',
        '017' => 'Amount is above the maximum for this product',
        '018' => 'Provider wallet balance is too low',
        '019' => 'The provider flagged this as a duplicate transaction',
        '021' => 'The provider account is locked',
        '022' => 'The provider account is suspended',
        '023' => 'The provider account is not permitted to use the API',
        '024' => 'The provider account is inactive',
        '027' => 'This server is not IP-whitelisted with the provider',
        '028' => 'This product is not enabled on the provider account',
        '030' => 'The biller could not be reached',
        '031' => 'Below the minimum quantity for this product',
        '032' => 'Above the maximum quantity for this product',
        '034' => 'That service is suspended',
        '035' => 'That service is inactive',
        '040' => 'The transaction was reversed by the provider',
        '083' => 'The provider reported a system error',
        '085' => 'The provider rejected the request id',
        '087' => 'The provider credentials were rejected',
        '091' => 'The provider did not process the transaction',
    );

    /**
     * Our network codes → VTpass serviceIDs.
     *
     * Core_seeder names networks for humans (IKEDC, 9MOBILE); VTpass names
     * them for itself (ikeja-electric, etisalat). Without this table every
     * live call returns 012 "product does not exist". Per-provider overrides
     * go in providers.retry_policy under vtpass.service_ids.
     */
    private static $service_ids = array(
        // airtime
        'MTN' => 'mtn', 'GLO' => 'glo', 'AIRTEL' => 'airtel', '9MOBILE' => 'etisalat',
        'ETISALAT' => 'etisalat',
        // data
        'MTN-DATA' => 'mtn-data', 'MTND' => 'mtn-data',
        'GLO-DATA' => 'glo-data', 'GLO-SME-DATA' => 'glo-sme-data',
        'AIRTEL-DATA' => 'airtel-data',
        '9MOBILE-DATA' => 'etisalat-data', 'ETISALAT-DATA' => 'etisalat-data',
        'SMILE' => 'smile-direct', 'SPECTRANET' => 'spectranet',
        // cable
        'DSTV' => 'dstv', 'GOTV' => 'gotv', 'STARTIMES' => 'startimes',
        'SHOWMAX' => 'showmax',
        // electricity
        'IKEDC' => 'ikeja-electric', 'EKEDC' => 'eko-electric',
        'AEDC' => 'abuja-electric', 'PHED' => 'portharcourt-electric',
        'KEDCO' => 'kano-electric', 'JED' => 'jos-electric',
        'IBEDC' => 'ibadan-electric', 'KAEDCO' => 'kaduna-electric',
        'EEDC' => 'enugu-electric', 'BEDC' => 'benin-electric',
        // education
        'WAEC' => 'waec', 'NECO' => 'neco', 'JAMB' => 'jamb',
    );

    /** VTpass identifiers for the catalogue sync, per service type. */
    private static $catalogue_types = array('DATA', 'CABLE', 'EXAM_PIN');

    private $provider;
    private $http;
    private $paths;
    private $service_map;
    private $credentials = null;

    public function __construct($provider_row, $http = null) {
        $this->provider = $provider_row;
        $timeout = isset($provider_row->timeout_ms) ? $provider_row->timeout_ms / 1000 : 20;
        $this->http = $http ?: new SecureHttpClient(array('timeout' => $timeout));

        $cfg = $this->config_blob();
        $this->paths = array_merge(array(
            'pay'        => '/pay',
            'verify'     => '/merchant-verify',
            'requery'    => '/requery',
            'balance'    => '/balance',
            'variations' => '/service-variations',
        ), isset($cfg['paths']) && is_array($cfg['paths']) ? $cfg['paths'] : array());

        $overrides = array();
        if (isset($cfg['service_ids']) && is_array($cfg['service_ids'])) {
            foreach ($cfg['service_ids'] as $k => $v) $overrides[strtoupper((string)$k)] = (string)$v;
        }
        $this->service_map = array_merge(self::$service_ids, $overrides);
    }

    /* ------------------------------ purchases ---------------------------- */

    public function airtime(array $p) {
        return $this->pay($p, array(
            'serviceID' => $this->service_id($p, 'network_code'),
            'amount'    => $this->amount($p),
            'phone'     => $p['msisdn'] ?? ($p['phone'] ?? null),
        ));
    }

    public function data(array $p) {
        return $this->pay($p, array(
            'serviceID'      => $this->service_id($p, 'network_code'),
            'billersCode'    => $p['msisdn'] ?? null,
            'variation_code' => $p['variation_code'] ?? null,
            'phone'          => $p['msisdn'] ?? ($p['phone'] ?? null),
        ));
    }

    public function cable(array $p) {
        // A renewal is priced by the biller, so VTpass wants the amount from
        // /merchant-verify; a new package is priced by its variation_code.
        $fields = array(
            'serviceID'      => $this->service_id($p, 'provider_code'),
            'billersCode'    => $p['smartcard'] ?? null,
            'variation_code' => $p['variation_code'] ?? null,
            'phone'          => $p['phone'] ?? null,
            'subscription_type' => 'change',
        );
        if (!empty($p['amount'])) $fields['amount'] = $this->amount($p);
        return $this->pay($p, $fields);
    }

    public function electricity(array $p) {
        return $this->pay($p, array(
            'serviceID'      => $this->service_id($p, 'disco_code'),
            'billersCode'    => $p['meter'] ?? null,
            // VTpass carries the meter type as the variation code, lowercase.
            'variation_code' => strtolower((string)($p['meter_type'] ?? 'prepaid')),
            'amount'         => $this->amount($p),
            'phone'          => $p['phone'] ?? null,
        ));
    }

    public function education(array $p) {
        return $this->pay($p, array(
            'serviceID'      => $this->service_id($p, 'exam_code'),
            'variation_code' => $p['variation_code'] ?? null,
            'quantity'       => (int)($p['quantity'] ?? 1),
            'phone'          => $p['phone'] ?? null,
        ));
    }

    /* ------------------------------- lookups ----------------------------- */

    /**
     * Resolve a meter or smartcard before the customer is charged.
     *
     * VTpass answers 020 for a confirmed biller and flags an unknown number
     * inside the payload as WrongBillersCode rather than with an error code,
     * so both have to be checked.
     */
    public function verify(array $p) {
        $fields = array(
            'serviceID'   => $this->service_id($p, 'disco_code'),
            'billersCode' => $p['meter'] ?? ($p['smartcard'] ?? null),
        );
        if (!empty($p['meter'])) {
            $fields['type'] = strtolower((string)($p['meter_type'] ?? 'prepaid'));
        }

        $res = $this->call($this->paths['verify'], $fields);
        if (empty($res['ok'])) {
            return array('ok' => false, 'error' => $res['error']);
        }

        $data = $res['data'];
        $code = $this->code_of($data);
        if ($code !== '' && $code !== '000' && $code !== '020') {
            return array('ok' => false, 'error' => $this->describe($code, $data));
        }

        $inner = isset($data['content']) && is_array($data['content']) ? $data['content'] : $data;
        if (!empty($inner['WrongBillersCode'])) {
            return array('ok' => false, 'error' => 'That number is not recognised by the biller');
        }
        if (!empty($inner['error'])) {
            return array('ok' => false, 'error' => (string)$inner['error']);
        }

        $name = $inner['Customer_Name'] ?? ($inner['customer_name'] ?? ($inner['name'] ?? null));
        if (!$name) {
            return array('ok' => false, 'error' => 'Could not resolve that account');
        }
        // Can_Vend is the biller saying "this meter cannot accept a payment".
        if (isset($inner['Can_Vend']) && $this->is_false($inner['Can_Vend'])) {
            return array('ok' => false, 'error' => 'The biller cannot vend to that meter right now');
        }

        return array(
            'ok'          => true,
            'name'        => trim((string)$name),
            'address'     => $inner['Address'] ?? ($inner['address'] ?? null),
            'meter_type'  => $inner['Meter_Type'] ?? null,
            'account_type'=> $inner['Customer_Account_Type'] ?? null,
            'raw'         => $inner,
        );
    }

    /**
     * Re-check a purchase. $reference is the request_id we sent, which is why
     * pay() returns that and not the provider's transactionId.
     */
    public function status($reference) {
        $res = $this->call($this->paths['requery'], array('request_id' => $reference));
        if (empty($res['ok'])) {
            // Cannot reach the provider: say nothing rather than guess, so the
            // cron leaves the transaction PROCESSING and tries again.
            return array('ok' => false, 'error' => $res['error'], 'reference' => $reference);
        }

        $data  = $res['data'];
        $code  = $this->code_of($data);
        $inner = $this->transactions_of($data);
        $out   = array('ok' => true, 'reference' => $reference,
                       'raw' => (string)($inner['status'] ?? $code));

        if ($code === '000') {
            $out['status'] = $this->map_status($inner['status'] ?? '');
        } elseif (in_array($code, self::$pending_codes, true)) {
            $out['status'] = 'PROCESSING';
        } elseif (isset(self::$failure_codes[$code])) {
            $out['status'] = 'FAILED';
            $out['error']  = self::$failure_codes[$code];
        } else {
            $out['status'] = 'PROCESSING';
        }

        $detail = $this->detail_of($data);
        if ($detail) $out['detail'] = $detail;
        return $out;
    }

    /** Provider float. Note VTpass answers `contents` (plural) and code 1. */
    public function balance() {
        $res = $this->call($this->paths['balance'], array(), 'GET');
        if (empty($res['ok'])) return array('ok' => false, 'error' => $res['error']);

        $data = $res['data'];
        $balance = $data['contents']['balance']
            ?? ($data['content']['balance'] ?? ($data['balance'] ?? null));
        if ($balance === null) {
            return array('ok' => false, 'error' => 'No balance in the provider response');
        }
        return array(
            'ok'       => true,
            'balance'  => number_format((float)$balance, 8, '.', ''),
            'currency' => $this->provider->currency ?? 'NGN',
        );
    }

    /* ---------------------------- catalogue ------------------------------ */

    /** Service types this adapter can pull a price list for. */
    public static function catalogue_types() { return self::$catalogue_types; }

    /**
     * Variations (bundles/packages/PIN types) for one of our network codes.
     *
     * @return array{ok:bool,variations?:array,service_id?:string,error?:string}
     */
    public function variations($network_code) {
        $service_id = $this->map_service_id($network_code);
        $res = $this->call(
            $this->paths['variations'].'?serviceID='.rawurlencode($service_id),
            array(), 'GET'
        );
        if (empty($res['ok'])) return array('ok' => false, 'error' => $res['error']);

        $data = $res['data'];
        $rows = $data['content']['variations'] ?? ($data['content']['varations'] ?? null);
        if (!is_array($rows)) {
            $code = $this->code_of($data);
            return array('ok' => false, 'error' => $this->describe($code, $data));
        }

        $out = array();
        foreach ($rows as $row) {
            if (!is_array($row) || empty($row['variation_code'])) continue;
            $out[] = array(
                'variation_code' => (string)$row['variation_code'],
                'name'           => (string)($row['name'] ?? $row['variation_code']),
                'amount'         => isset($row['variation_amount'])
                    ? number_format((float)$row['variation_amount'], 8, '.', '') : null,
                'fixed_price'    => !isset($row['fixedPrice'])
                    || !$this->is_false($row['fixedPrice']),
            );
        }
        return array('ok' => true, 'service_id' => $service_id, 'variations' => $out);
    }

    /* ----------------------------- internals ----------------------------- */

    /**
     * A purchase. Every outcome except an explicit terminal rejection leaves
     * the transaction settleable by /requery.
     */
    private function pay(array $p, array $fields) {
        $request_id = $this->request_id($p['reference'] ?? null);
        $fields['request_id'] = $request_id;

        $res = $this->call($this->paths['pay'], $this->clean($fields));

        if (empty($res['ok'])) {
            // Indeterminate: the request may well have been executed. Accept
            // it as in-flight so the settlement cron decides, and let an
            // unknown request_id come back as 015 → FAILED → refund.
            if (!empty($res['indeterminate'])) {
                return array(
                    'ok'        => true,
                    'reference' => $request_id,
                    'status'    => 'PROCESSING',
                    'error'     => $res['error'],
                );
            }
            return array('ok' => false, 'error' => $res['error'], 'reference' => $request_id);
        }

        $data  = $res['data'];
        $code  = $this->code_of($data);
        $inner = $this->transactions_of($data);

        if ($code !== '' && $code !== '000') {
            if (isset(self::$failure_codes[$code])) {
                return array(
                    'ok'        => false,
                    'error'     => self::$failure_codes[$code],
                    'reference' => $request_id,
                );
            }
            // 099 and anything unrecognised: accepted, settle by requery.
            return array(
                'ok'        => true,
                'reference' => $request_id,
                'status'    => 'PROCESSING',
            );
        }

        $out = array(
            'ok'        => true,
            'reference' => $request_id,
            'status'    => $this->map_status($inner['status'] ?? 'delivered'),
        );
        if (isset($inner['total_amount'])) {
            $out['cost'] = number_format((float)$inner['total_amount'], 8, '.', '');
        } elseif (isset($inner['amount'])) {
            $out['cost'] = number_format((float)$inner['amount'], 8, '.', '');
        }

        $detail = $this->detail_of($data);
        // The provider's own id is not the requery key, but it is what support
        // quotes to VTpass, so keep it on the record.
        if (!empty($inner['transactionId'])) {
            $detail['provider_transaction_id'] = (string)$inner['transactionId'];
        }
        if ($detail) $out['detail'] = $detail;

        return $out;
    }

    /**
     * request_id per VTpass: first 12 characters are YYYYMMDDHHII in West
     * Africa Time, followed by an alphanumeric suffix. Anything else is
     * rejected with 085, and a UTC clock is wrong for an hour either side of
     * midnight — which is exactly when nobody is watching.
     */
    public function request_id($reference = null, $now = null) {
        $when = $now instanceof DateTimeInterface
            ? DateTime::createFromFormat('U', (string)$now->getTimestamp())
            : new DateTime('now', new DateTimeZone('UTC'));
        $when->setTimezone(new DateTimeZone(self::REQUEST_TZ));

        $suffix = preg_replace('/[^a-zA-Z0-9]/', '', (string)$reference);
        if ($suffix === '') $suffix = bin2hex(random_bytes(6));
        // Keep the whole id comfortably short; the tail of a ULID is the
        // random part, so it stays unique.
        $suffix = substr($suffix, -12);

        return $when->format('YmdHi').$suffix;
    }

    /** VTpass calls a delivered purchase "delivered"; everything else waits. */
    private function map_status($raw) {
        $raw = strtolower(trim((string)$raw));
        if (in_array($raw, array('delivered', 'successful', 'success', 'completed'), true)) {
            return 'SUCCESSFUL';
        }
        if (in_array($raw, array('failed', 'reversed', 'reversal', 'declined', 'refunded'), true)) {
            return 'FAILED';
        }
        // initiated | pending | processing | anything new VTpass invents.
        return 'PROCESSING';
    }

    /** Tokens, units, exam cards — whatever the customer actually bought. */
    private function detail_of(array $data) {
        $content = isset($data['content']) && is_array($data['content']) ? $data['content'] : array();
        $detail  = array();

        $token = $this->first_scalar(array(
            $data['purchased_code'] ?? null,
            $content['purchased_code'] ?? null,
            $data['token'] ?? null,
            $content['token'] ?? null,
            $data['Token'] ?? null,
        ));
        // Electricity tokens arrive as "Token : 1234-5678-…".
        if ($token !== null) {
            $token = trim(preg_replace('/^\s*tokens?\s*:\s*/i', '', $token));
            if ($token !== '') $detail['token'] = mb_substr($token, 0, 128);
        }

        $units = $this->first_scalar(array(
            $data['units'] ?? null, $content['units'] ?? null,
            $data['Units'] ?? null,
        ));
        if ($units !== null && $units !== '') $detail['units'] = mb_substr($units, 0, 64);

        // WAEC/NECO return a cards[] of {Serial, Pin} — richer than one token.
        $cards = $data['cards'] ?? ($content['cards'] ?? null);
        if (is_array($cards) && $cards) {
            $parts = array();
            foreach ($cards as $card) {
                if (!is_array($card)) continue;
                $serial = $card['Serial'] ?? ($card['serial'] ?? null);
                $pin    = $card['Pin'] ?? ($card['pin'] ?? null);
                if ($pin === null) continue;
                $parts[] = $serial !== null ? $serial.':'.$pin : (string)$pin;
            }
            if ($parts) {
                $detail['cards'] = $parts;
                if (!isset($detail['token'])) {
                    $detail['token'] = mb_substr(implode(' ', $parts), 0, 128);
                }
            }
        }
        return $detail;
    }

    private function first_scalar(array $candidates) {
        foreach ($candidates as $c) {
            if ($c === null || is_array($c)) continue;
            $c = (string)$c;
            if ($c !== '') return $c;
        }
        return null;
    }

    /** VTpass answers `code` for transactions and `response_description` for lists. */
    private function code_of(array $data) {
        if (isset($data['code'])) return (string)$data['code'];
        // The catalogue endpoints put the code in response_description.
        if (isset($data['response_description']) && preg_match('/^\d{3}$/', (string)$data['response_description'])) {
            return (string)$data['response_description'];
        }
        return '';
    }

    private function transactions_of(array $data) {
        if (isset($data['content']['transactions']) && is_array($data['content']['transactions'])) {
            return $data['content']['transactions'];
        }
        return array();
    }

    private function describe($code, array $data) {
        if (isset(self::$failure_codes[$code])) return self::$failure_codes[$code];
        $desc = $data['response_description'] ?? null;
        if (is_string($desc) && $desc !== '' && !preg_match('/^\d{3}$/', $desc)) return $desc;
        return $code === '' ? 'The provider returned an unusable response' : 'Provider code '.$code;
    }

    private function service_id(array $p, $key) {
        $code = $p[$key] ?? ($p['network_code'] ?? '');
        return $this->map_service_id($code);
    }

    /** Our stable code → VTpass serviceID, with per-provider overrides. */
    public function map_service_id($code) {
        $key = strtoupper(trim((string)$code));
        if ($key === '') return '';
        if (isset($this->service_map[$key])) return $this->service_map[$key];
        // Several VTpass ids are simply the lowercase code (mtn, dstv, waec).
        return strtolower($key);
    }

    private function amount(array $p) {
        if (!isset($p['amount'])) return null;
        // VTpass wants a plain number, not our 8-decimal money string.
        return rtrim(rtrim(number_format((float)$p['amount'], 2, '.', ''), '0'), '.');
    }

    private function clean(array $fields) {
        return array_filter($fields, function ($v) {
            return $v !== null && $v !== '';
        });
    }

    private function is_false($v) {
        if (is_bool($v)) return !$v;
        return in_array(strtolower(trim((string)$v)), array('0', 'false', 'no', 'off', ''), true);
    }

    /* --------------------------- credentials ----------------------------- */

    /** The vtpass block of providers.retry_policy, if any. */
    private function config_blob() {
        if (empty($this->provider->retry_policy)) return array();
        $decoded = json_decode($this->provider->retry_policy, true);
        if (!is_array($decoded)) return array();
        foreach (array('vtpass', 'vtu') as $key) {
            if (isset($decoded[$key]) && is_array($decoded[$key])) return $decoded[$key];
        }
        return array();
    }

    /**
     * Decrypt the credential blob once per adapter.
     * Returns api_key / public_key / secret_key, any of which may be ''.
     */
    private function credentials() {
        if ($this->credentials !== null) return $this->credentials;

        $raw = '';
        if (!empty($this->provider->api_key_encrypted)) {
            $ci =& get_instance();
            $ci->load->library('EncryptionService');
            $raw = (string)$ci->encryptionservice->decrypt($this->provider->api_key_encrypted);
        }

        $creds = array('api_key' => $raw, 'public_key' => '', 'secret_key' => '');
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            foreach (array('api_key', 'public_key', 'secret_key') as $k) {
                if (isset($decoded[$k])) $creds[$k] = (string)$decoded[$k];
            }
            // A JSON blob without api_key is a misconfiguration, not a key.
            if (!isset($decoded['api_key'])) $creds['api_key'] = '';
        }
        return $this->credentials = $creds;
    }

    /**
     * VTpass splits its keys by method: the public key may only read, the
     * secret key may only write. Sending the wrong one is a 401, not a
     * warning.
     */
    private function headers($method) {
        $c = $this->credentials();
        $headers = array('api-key: '.$c['api_key'], 'Accept: application/json');
        $headers[] = strtoupper($method) === 'GET'
            ? 'public-key: '.$c['public_key']
            : 'secret-key: '.$c['secret_key'];
        return $headers;
    }

    /**
     * One HTTP call.
     *
     * @return array{ok:bool,data?:array,error?:string,indeterminate?:bool}
     *   indeterminate=true means "we do not know whether this executed" —
     *   only pay() may act on it, by settling asynchronously.
     */
    private function call($path, array $payload, $method = 'POST') {
        $url = rtrim((string)$this->provider->api_url, '/').'/'.ltrim($path, '/');
        $headers = $this->headers($method);

        try {
            $res = strtoupper($method) === 'GET'
                ? $this->http->get($url, $headers)
                : $this->http->post($url, $payload, $headers);
        } catch (Exception $e) {
            log_message('error', 'VTpass transport error: '.$e->getMessage());
            return array('ok' => false, 'error' => 'Provider unreachable', 'indeterminate' => true);
        }

        $http_code = isset($res['http_code']) ? (int)$res['http_code'] : 0;

        // 0 is SecureHttpClient's "blocked, timed out or 5xx after retries".
        if ($http_code === 0 || $http_code >= 500) {
            return array(
                'ok' => false,
                'error' => 'Provider did not respond'
                    .(!empty($res['error']) ? ': '.$res['error'] : ''),
                'indeterminate' => true,
            );
        }
        if ($http_code >= 400) {
            return array(
                'ok' => false,
                'error' => $http_code === 401 || $http_code === 403
                    ? 'The provider rejected our credentials'
                    : 'Provider returned HTTP '.$http_code,
            );
        }

        $data = json_decode(isset($res['body']) ? (string)$res['body'] : '', true);
        if (!is_array($data)) {
            // 200 with an unparseable body is genuinely ambiguous: VTpass
            // returns HTML when it is in maintenance, and the purchase may
            // still have gone through.
            return array(
                'ok' => false,
                'error' => 'The provider returned an unusable response',
                'indeterminate' => true,
            );
        }
        return array('ok' => true, 'data' => $data);
    }
}
