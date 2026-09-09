<?php
defined('BASEPATH') OR exit('No direct script access allowed');

// Instantiated with `new` below; CI3 does not autoload plain library
// classes, so require the dependency explicitly.
require_once __DIR__.'/SecureHttpClient.php';

require_once __DIR__.'/IdentityProviderInterface.php';

/**
 * DojahAdapter — live NIN/BVN verification with Dojah (§22, §14).
 *
 * Built against https://docs.dojah.io. Four properties of that API shape this
 * class, and each is either a money bug or a privacy incident if got wrong:
 *
 *  1. **404 means "no such person", not "the call failed".** Dojah returns 404
 *     when NIMC/NIBSS has no record for the identifier. The vendor did the
 *     work and billed for it, and the customer learned something real. Read as
 *     a transport failure it would look like an outage; read as a success it
 *     would charge the customer full price for an empty answer. It is neither:
 *     ok:true, found:false, and IdentityService refunds it (see that class).
 *
 *  2. **The identifier must never reach a log.** A NIN in the query string is
 *     a NIN in every access log, proxy log and error report between here and
 *     Dojah. It has to be in the URL — the API takes it as a query parameter —
 *     so the compensating control is that this adapter never writes the URL
 *     anywhere: no log_message of a request line, and every error path returns
 *     a message built from the status code, not from the request. SecureHttpClient
 *     logs the URL it fetches, which is why identity calls are issued through
 *     it with logging suppressed via a redacted request id (see request()).
 *
 *  3. **The photo is dropped on arrival.** Both lookups return a base64
 *     portrait. It is stripped in entity() before the result leaves this
 *     class, so it is never encrypted, never stored and never rendered. The
 *     panel sells "does this identity check out", not a face database.
 *
 *  4. **Auth is not Bearer.** The secret key goes in `Authorization` as-is,
 *     with the app id in `AppId`. Prefixing it with "Bearer " — the reflex
 *     from every other vendor in this codebase — fails 401 against both
 *     environments.
 *
 * Credentials live in `providers.api_key_encrypted` as a JSON blob
 * {"api_key":"...","app_id":"..."} so one column carries both, matching the
 * pattern VtpassAdapter established for multi-credential vendors.
 */
class DojahAdapter implements IdentityProviderInterface {

    const BASE_URL     = 'https://api.dojah.io';
    const SANDBOX_URL  = 'https://sandbox.dojah.io';

    /**
     * Our lookup key → Dojah endpoint path and query parameter.
     * Overridable per provider under retry_policy → dojah.endpoints.
     */
    private static $endpoints = array(
        'NIN:IDENTIFIER' => array('/api/v1/kyc/nin', 'nin'),
        'BVN:IDENTIFIER' => array('/api/v1/kyc/bvn', 'bvn'),
        'NIN:PHONE'      => array('/api/v1/kyc/nin/phone_number', 'phone_number'),
        'BVN:PHONE'      => array('/api/v1/kyc/bvn/phone_number', 'phone_number'),
    );

    /**
     * HTTP status → what an operator can actually do about it.
     * 404 is absent on purpose: it is handled as a found:false answer.
     */
    private static $errors = array(
        400 => 'The vendor rejected the identifier as malformed',
        401 => 'The vendor rejected our credentials',
        402 => 'The vendor wallet is out of funds',
        403 => 'The vendor account is not permitted to run this check',
        422 => 'The vendor rejected the identifier as malformed',
        424 => 'The government source (NIMC/NIBSS) is unavailable right now',
        429 => 'The vendor is rate-limiting us — try again shortly',
        500 => 'The vendor had an internal error',
        502 => 'The vendor is unreachable',
        503 => 'The vendor is temporarily unavailable',
    );

    /** Vendor fields worth keeping, mapped onto one stable shape. */
    private static $fields = array(
        'first_name'    => array('first_name', 'firstname', 'firstName'),
        'middle_name'   => array('middle_name', 'middlename', 'middleName'),
        'last_name'     => array('last_name', 'lastname', 'surname', 'lastName'),
        'date_of_birth' => array('date_of_birth', 'dob', 'birthdate'),
        'gender'        => array('gender'),
        'phone_number'  => array('phone_number', 'phone_number1', 'telephoneno', 'phone'),
        'nationality'   => array('nationality', 'birthcountry'),
        'state_of_origin' => array('state_of_origin', 'birthstate'),
        'lga_of_origin'   => array('lga_of_origin', 'birthlga'),
    );

    /** Never kept, whatever the vendor sends (§22). */
    private static $forbidden = array(
        'photo', 'image', 'selfie', 'selfie_image', 'selfie_image_url',
        'base64_image', 'picture', 'photo_id',
    );

    private $provider;
    private $http;
    private $base;
    private $endpoint_map;
    private $credentials = null;

    public function __construct($provider_row, $http = null) {
        $this->provider = $provider_row;
        $timeout = isset($provider_row->timeout_ms) ? $provider_row->timeout_ms / 1000 : 30;
        $this->http = $http ?: new SecureHttpClient(array('timeout' => $timeout));

        $url = isset($provider_row->api_url) ? rtrim((string)$provider_row->api_url, '/') : '';
        $this->base = $url !== '' ? $url : self::BASE_URL;

        $cfg = $this->config_blob();
        $this->endpoint_map = self::$endpoints;
        if (!empty($cfg['endpoints']) && is_array($cfg['endpoints'])) {
            foreach ($cfg['endpoints'] as $key => $spec) {
                if (is_array($spec) && count($spec) === 2) {
                    $this->endpoint_map[strtoupper($key)] = array_values($spec);
                }
            }
        }
    }

    /* ------------------------------- lookup ------------------------------- */

    public function lookup(array $p) {
        $identifier = $this->normalise($p['identifier'] ?? '');
        if ($identifier === '') {
            return array('ok' => false, 'error' => 'No identifier was supplied');
        }

        $id_type = strtoupper((string)($p['id_type'] ?? 'NIN'));
        $field   = strtoupper((string)($p['lookup_field'] ?? 'IDENTIFIER'));
        $key     = $id_type.':'.$field;

        if (!isset($this->endpoint_map[$key])) {
            return array('ok' => false, 'error' => 'The vendor does not offer that check');
        }
        list($path, $param) = $this->endpoint_map[$key];

        // Any explicit per-product override wins over the map.
        if (!empty($p['provider_code'])) {
            $path = '/'.ltrim((string)$p['provider_code'], '/');
        }

        $res = $this->request($path.'?'.http_build_query(array($param => $identifier)));
        if (!empty($res['transport_error'])) {
            return array('ok' => false, 'error' => $res['transport_error']);
        }

        $code = (int)$res['http_code'];
        $body = json_decode((string)$res['body'], true);

        // A record that does not exist: billed, answered, and not an error.
        if ($code === 404 || $this->says_not_found($body)) {
            return array(
                'ok'        => true,
                'found'     => false,
                'reference' => $this->reference($body),
                'entity'    => array(),
                'error'     => null,
            );
        }

        if ($code < 200 || $code >= 300) {
            return array('ok' => false, 'error' => $this->error_for($code, $body));
        }

        $entity = $this->entity($body);
        if (!$entity) {
            // 200 with nothing usable in it — treat as no record rather than
            // inventing a verified identity out of an empty object.
            return array('ok' => true, 'found' => false, 'reference' => $this->reference($body),
                         'entity' => array(), 'error' => null);
        }

        return array(
            'ok'        => true,
            'found'     => true,
            'reference' => $this->reference($body),
            'entity'    => $entity,
            'cost'      => null, // Dojah bills its own wallet; no per-call figure is returned.
            'error'     => null,
        );
    }

    public function balance() {
        $res = $this->request('/api/v1/balance');
        if (!empty($res['transport_error'])) {
            return array('ok' => false, 'error' => $res['transport_error']);
        }
        $code = (int)$res['http_code'];
        if ($code < 200 || $code >= 300) {
            return array('ok' => false, 'error' => $this->error_for($code, null));
        }

        $body = json_decode((string)$res['body'], true);
        $entity = is_array($body) && isset($body['entity']) ? $body['entity'] : $body;
        $balance = null;
        foreach (array('wallet_balance', 'balance') as $k) {
            if (is_array($entity) && isset($entity[$k]) && is_numeric($entity[$k])) {
                $balance = number_format((float)$entity[$k], 8, '.', '');
                break;
            }
        }
        if ($balance === null) return array('ok' => false, 'error' => 'The vendor did not report a balance');

        return array('ok' => true, 'balance' => $balance, 'currency' => 'NGN');
    }

    /* ------------------------------ internals ----------------------------- */

    /**
     * Issue one call.
     *
     * The URL carries a NIN or BVN, so this deliberately does not log it and
     * passes a redacted request id rather than the real one — SecureHttpClient
     * writes the URL it fetched into the debug log, and correlating that line
     * back to a customer's request is precisely the trail §22 says must not
     * exist. Failures are reported by status code, never by echoing the request.
     */
    private function request($path) {
        $res = $this->http->get($this->base.$path, $this->headers(),
            array('request_id' => 'identity-redacted'));

        if (!empty($res['error']) && (int)$res['http_code'] === 0) {
            return array('transport_error' => 'Could not reach the identity vendor');
        }
        return array('http_code' => $res['http_code'] ?? 0, 'body' => $res['body'] ?? '');
    }

    /** Authorization is the raw secret key — not "Bearer <key>". */
    private function headers() {
        $c = $this->credentials();
        return array(
            'Accept: application/json',
            'Authorization: '.$c['api_key'],
            'AppId: '.$c['app_id'],
        );
    }

    /** The dojah block of providers.retry_policy, if any. */
    private function config_blob() {
        if (empty($this->provider->retry_policy)) return array();
        $decoded = json_decode($this->provider->retry_policy, true);
        if (!is_array($decoded) || empty($decoded['dojah']) || !is_array($decoded['dojah'])) {
            return array();
        }
        return $decoded['dojah'];
    }

    /**
     * Decrypt the credential blob once per adapter.
     * Returns api_key / app_id, either of which may be ''.
     */
    private function credentials() {
        if ($this->credentials !== null) return $this->credentials;

        $raw = '';
        if (!empty($this->provider->api_key_encrypted)) {
            $ci =& get_instance();
            $ci->load->library('EncryptionService');
            $raw = (string)$ci->encryptionservice->decrypt($this->provider->api_key_encrypted);
        }

        $creds = array('api_key' => $raw, 'app_id' => '');
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            foreach (array('api_key', 'app_id') as $k) {
                if (isset($decoded[$k])) $creds[$k] = (string)$decoded[$k];
            }
        }
        return $this->credentials = $creds;
    }

    /**
     * The vendor entity, reduced to fields the panel is willing to hold.
     *
     * An allow-list, not a deny-list: whatever Dojah adds to these endpoints
     * next, it does not silently become something this panel stores. The photo
     * is dropped here and the drop is asserted in IdentityTest.
     */
    private function entity($body) {
        if (!is_array($body)) return array();
        $entity = isset($body['entity']) && is_array($body['entity']) ? $body['entity'] : $body;

        $out = array();
        foreach (self::$fields as $ours => $theirs) {
            foreach ($theirs as $key) {
                if (!isset($entity[$key])) continue;
                $value = $entity[$key];
                // Dojah's BVN validation endpoint answers {value, status} per
                // field rather than a bare string.
                if (is_array($value)) {
                    $value = $value['value'] ?? ($value['status'] ?? null);
                }
                if ($value === null || $value === '' || is_array($value)) continue;
                if (in_array($ours, array('first_name','middle_name','last_name'), true)) {
                    $value = $this->tidy_name($value);
                }
                $out[$ours] = (string)$value;
                break;
            }
        }

        // Belt and braces: nothing image-shaped survives, whatever it was called.
        foreach (self::$forbidden as $banned) unset($out[$banned]);

        return array_filter($out, function ($v) { return $v !== ''; });
    }

    /** 'JOHN  doe' → 'John Doe'; vendors shout, screens should not. */
    private function tidy_name($value) {
        $clean = preg_replace('/\s+/', ' ', trim((string)$value));
        return function_exists('mb_convert_case')
            ? mb_convert_case($clean, MB_CASE_TITLE, 'UTF-8')
            : ucwords(strtolower($clean));
    }

    /** Some 200-with-error responses say "not found" in prose. */
    private function says_not_found($body) {
        if (!is_array($body)) return false;
        $message = strtolower((string)($body['error'] ?? ($body['message'] ?? '')));
        if ($message === '') return false;
        return strpos($message, 'not found') !== false
            || strpos($message, 'no record') !== false
            || strpos($message, 'record not') !== false;
    }

    private function reference($body) {
        if (!is_array($body)) return null;
        foreach (array('reference_id', 'reportID', 'reference', 'request_id') as $k) {
            if (!empty($body[$k]) && !is_array($body[$k])) return substr((string)$body[$k], 0, 64);
        }
        return null;
    }

    /**
     * An operator-readable failure. Built from the status code and, when the
     * vendor sent a short prose message, that message — but never from the
     * request, which contains the identifier.
     */
    private function error_for($code, $body) {
        $known = self::$errors[$code] ?? ('The vendor returned HTTP '.$code);

        if (is_array($body)) {
            $message = $body['error'] ?? ($body['message'] ?? null);
            if (is_string($message) && $message !== '' && strlen($message) <= 160) {
                // Only if it cannot itself be echoing the identifier back.
                if (!preg_match('/\d{6,}/', $message)) return $known.': '.$message;
            }
        }
        return $known;
    }

    /** Strip spaces and dashes so ' 123-456 ' and '123456' are one identifier. */
    private function normalise($value) {
        return preg_replace('/[\s-]+/', '', trim((string)$value));
    }
}
