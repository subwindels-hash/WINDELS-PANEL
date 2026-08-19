<?php
defined('BASEPATH') OR exit('No direct script access allowed');

// Instantiated with `new` below; CI3 does not autoload plain library
// classes, so require the dependency explicitly.
require_once __DIR__.'/SecureHttpClient.php';

require_once __DIR__.'/NumberProviderInterface.php';

/**
 * FiveSimAdapter — live virtual-number / OTP integration with 5sim (§10, §14).
 *
 * Built against https://5sim.net/docs. Four properties of that API shape this
 * class, and each one is a money bug if it is got wrong:
 *
 *  1. **Errors are plain text, not JSON, and often arrive with HTTP 200.**
 *     `no free phones`, `not enough user balance`, `order not found` come back
 *     as a bare string. Code that json_decode()s and trusts the result reads
 *     every rejection as a successful reservation and charges the customer for
 *     a number they never got.
 *
 *  2. **The vendor owns the deadline.** The buy response carries `expires`.
 *     Computing our own from "now + 15 minutes" drifts against the vendor and
 *     eventually either refunds a live reservation or holds a dead one open.
 *     `expires_at` is always taken from the vendor and normalised to UTC.
 *
 *  3. **Prices are in roubles.** 5sim quotes RUB; this panel is denominated in
 *     naira. There is no defensible default exchange rate to hardcode, so a
 *     cost is only reported when the operator has configured one, under
 *     `providers.retry_policy → fivesim.rate_to_base`. Without it the vendor
 *     figure is returned as `cost_vendor` and left out of `cost`, so the
 *     margin report shows "unknown" instead of a number that is wrong by a
 *     factor of twenty.
 *
 *  4. **Finish/cancel/ban are not interchangeable.** `cancel` is refused once
 *     an SMS has arrived (`order has sms`), and `ban` costs vendor rating.
 *     NumberService picks; this adapter just reports what the vendor said.
 *
 * Auth is a bearer token — genuinely, here — in `providers.api_key_encrypted`.
 * Nothing throws for a vendor-side rejection.
 */
class FiveSimAdapter implements NumberProviderInterface {

    const BASE_URL = 'https://5sim.net/v1';

    /** Vendor order status → our reservation vocabulary. */
    private static $state_map = array(
        'PENDING'  => 'RESERVED',
        'RECEIVED' => 'RECEIVED',
        'FINISHED' => 'COMPLETED',
        'CANCELED' => 'CANCELLED',
        'TIMEOUT'  => 'EXPIRED',
        'BANNED'   => 'BANNED',
    );

    /**
     * Plain-text vendor errors, mapped to something an operator can act on.
     * Anything unrecognised is passed through verbatim rather than swallowed.
     */
    private static $errors = array(
        'no free phones'        => 'That number is out of stock right now',
        'not enough user balance'=> 'The vendor account is out of funds',
        'not enough rating'     => 'The vendor account rating is too low to buy this',
        'select country'        => 'No country was supplied to the vendor',
        'select operator'       => 'No operator was supplied to the vendor',
        'bad country'           => 'The vendor does not recognise that country',
        'bad operator'          => 'The vendor does not recognise that operator',
        'no product'            => 'The vendor does not sell that service',
        'server offline'        => 'The vendor is offline',
        'order not found'       => 'The vendor has no record of that reservation',
        'order expired'         => 'That reservation has already expired',
        'order has sms'         => 'That reservation already received a code',
        'hosting order'         => 'That reservation is a rental, not an activation',
    );

    /** Our country codes → 5sim country slugs. */
    private static $countries = array(
        'NG' => 'nigeria', 'GH' => 'ghana', 'KE' => 'kenya', 'ZA' => 'southafrica',
        'GB' => 'england', 'UK' => 'england', 'US' => 'usa', 'CA' => 'canada',
        'IN' => 'india', 'ID' => 'indonesia', 'PH' => 'philippines',
        'RU' => 'russia', 'UA' => 'ukraine', 'BR' => 'brazil',
    );

    /** Our service codes → 5sim product slugs. */
    private static $products = array(
        'WHATSAPP' => 'whatsapp', 'TELEGRAM' => 'telegram', 'FACEBOOK' => 'facebook',
        'INSTAGRAM'=> 'instagram','GOOGLE'   => 'google',   'TWITTER'  => 'twitter',
        'TIKTOK'   => 'tiktok',   'UBER'     => 'uber',     'AMAZON'   => 'amazon',
        'DISCORD'  => 'discord',  'SIGNAL'   => 'signal',   'VIBER'    => 'viber',
        'OTHER'    => 'other',
    );

    private $provider;
    private $http;
    private $base;
    private $country_map;
    private $product_map;
    private $rate_to_base;

    public function __construct($provider_row, $http = null) {
        $this->provider = $provider_row;
        $timeout = isset($provider_row->timeout_ms) ? $provider_row->timeout_ms / 1000 : 20;
        $this->http = $http ?: new SecureHttpClient(array('timeout' => $timeout));

        $url = isset($provider_row->api_url) ? rtrim((string)$provider_row->api_url, '/') : '';
        $this->base = $url !== '' ? $url : self::BASE_URL;

        $cfg = $this->config_blob();
        $this->country_map = array_merge(self::$countries, $this->upper_keys($cfg['countries'] ?? array()));
        $this->product_map = array_merge(self::$products,  $this->upper_keys($cfg['products'] ?? array()));

        // Base-currency units per 1 vendor unit (RUB). Deliberately opt-in.
        $this->rate_to_base = isset($cfg['rate_to_base']) && is_numeric($cfg['rate_to_base'])
            && (float)$cfg['rate_to_base'] > 0
            ? (string)$cfg['rate_to_base'] : null;
    }

    /* ----------------------------- reservation ---------------------------- */

    /**
     * Rent a number.
     *
     * `maxPrice` is only honoured by 5sim when the operator is `any`, so
     * sending it alongside a pinned operator would be silently ignored — a
     * spend cap that does not cap. It is therefore only sent when it can work.
     */
    public function reserve(array $p) {
        $country  = $this->country_slug($p['country'] ?? '');
        $operator = trim((string)($p['operator'] ?? 'any')) ?: 'any';
        $product  = $this->product_slug($p['product'] ?? ($p['service'] ?? ''));

        if ($country === '' || $product === '') {
            return array('ok' => false, 'error' => 'That number is not configured for the vendor');
        }

        $path = '/user/buy/activation/'.rawurlencode($country).'/'
              .rawurlencode($operator).'/'.rawurlencode($product);

        $query = array();
        if (!empty($p['reference'])) $query['ref'] = substr((string)$p['reference'], 0, 64);
        if (isset($p['max_price']) && $p['max_price'] !== null && $operator === 'any') {
            $query['maxPrice'] = $this->to_vendor_price($p['max_price']);
        }
        if ($query) $path .= '?'.http_build_query(array_filter($query, function ($v) {
            return $v !== null && $v !== '';
        }));

        $res = $this->call($path);
        if (empty($res['ok'])) return array('ok' => false, 'error' => $res['error']);

        return $this->reservation($res['data']);
    }

    public function status($reference) {
        return $this->order_call('/user/check/', $reference);
    }

    public function finish($reference) {
        return $this->order_call('/user/finish/', $reference);
    }

    public function cancel($reference) {
        return $this->order_call('/user/cancel/', $reference);
    }

    public function ban($reference) {
        return $this->order_call('/user/ban/', $reference);
    }

    /* ------------------------------ catalogue ----------------------------- */

    /**
     * Vendor price list for one country.
     *
     * 5sim answers a map keyed by product slug, so the shape has to be
     * inverted into rows and mapped back onto our service codes. A product
     * the panel does not sell is skipped rather than invented: the catalogue
     * is an operator decision, not a vendor one.
     */
    public function products($country) {
        $slug = $this->country_slug($country);
        if ($slug === '') return array('ok' => false, 'error' => 'Unknown country '.$country);

        $res = $this->call('/guest/products/'.rawurlencode($slug).'/any');
        if (empty($res['ok'])) return array('ok' => false, 'error' => $res['error']);
        if (!is_array($res['data'])) {
            return array('ok' => false, 'error' => 'The vendor returned an unusable price list');
        }

        $reverse = array_flip($this->product_map);
        $out = array();
        foreach ($res['data'] as $slug_key => $row) {
            if (!is_array($row)) continue;
            // Activations only: 'hosting' rows are long-term rentals with a
            // completely different lifecycle (see NumberProviderInterface).
            if (isset($row['Category']) && strtolower((string)$row['Category']) !== 'activation') continue;

            $service = $reverse[(string)$slug_key] ?? strtoupper((string)$slug_key);
            $vendor_cost = isset($row['Price']) ? (string)$row['Price'] : null;
            $out[] = array(
                'service'          => $service,
                'provider_product' => (string)$slug_key,
                'operator'         => 'any',
                'cost'             => $this->to_base($vendor_cost),
                'cost_vendor'      => $vendor_cost,
                'stock'            => isset($row['Qty']) ? (int)$row['Qty'] : null,
            );
        }
        return array('ok' => true, 'products' => $out);
    }

    public function balance() {
        $res = $this->call('/user/profile');
        if (empty($res['ok'])) return array('ok' => false, 'error' => $res['error']);

        $data = $res['data'];
        if (!is_array($data) || !isset($data['balance'])) {
            return array('ok' => false, 'error' => 'No balance in the vendor response');
        }
        // The vendor's float is in its own currency; converting it with a rate
        // the operator may not have set would misreport the panel's exposure.
        $converted = $this->to_base((string)$data['balance']);
        return array(
            'ok'          => true,
            'balance'     => $converted !== null ? $converted
                : number_format((float)$data['balance'], 8, '.', ''),
            'currency'    => $converted !== null
                ? ($this->provider->currency ?? windels_base_currency()) : 'RUB',
            'raw_balance' => (string)$data['balance'],
        );
    }

    /* ------------------------------ internals ----------------------------- */

    /** finish/cancel/ban/check all return the same order object. */
    private function order_call($prefix, $reference) {
        $reference = trim((string)$reference);
        if ($reference === '') {
            return array('ok' => false, 'error' => 'No vendor reference to act on');
        }
        $res = $this->call($prefix.rawurlencode($reference));
        if (empty($res['ok'])) {
            return array('ok' => false, 'error' => $res['error'], 'reference' => $reference);
        }
        return $this->reservation($res['data'], $reference);
    }

    /** The vendor's order object → the shape NumberProviderInterface promises. */
    private function reservation(array $data, $fallback_reference = null) {
        $reference = isset($data['id']) ? (string)$data['id'] : (string)$fallback_reference;
        $vendor_state = strtoupper((string)($data['status'] ?? ''));

        $out = array(
            'ok'         => true,
            'reference'  => $reference,
            'msisdn'     => isset($data['phone']) ? (string)$data['phone'] : null,
            'operator'   => isset($data['operator']) ? (string)$data['operator'] : null,
            'state'      => self::$state_map[$vendor_state] ?? 'RESERVED',
            'raw_state'  => $vendor_state,
            'expires_at' => $this->utc($data['expires'] ?? null),
            'messages'   => $this->messages($data),
        );

        if (isset($data['price'])) {
            $out['cost_vendor'] = (string)$data['price'];
            $cost = $this->to_base((string)$data['price']);
            if ($cost !== null) $out['cost'] = $cost;
        }
        // A vendor that reports PENDING while an SMS is already in the payload
        // has received the code; trust the payload over the label.
        if ($out['messages'] && $out['state'] === 'RESERVED') $out['state'] = 'RECEIVED';

        return $out;
    }

    private function messages(array $data) {
        $rows = isset($data['sms']) && is_array($data['sms']) ? $data['sms'] : array();
        $out = array();
        foreach ($rows as $sms) {
            if (!is_array($sms)) continue;
            $text = isset($sms['text']) ? (string)$sms['text'] : '';
            $code = isset($sms['code']) && $sms['code'] !== '' ? (string)$sms['code'] : $this->extract_code($text);
            $out[] = array(
                'id'          => isset($sms['id']) ? (string)$sms['id'] : null,
                'sender'      => isset($sms['sender']) ? (string)$sms['sender'] : null,
                'text'        => $text,
                'code'        => $code,
                'received_at' => $this->utc($sms['date'] ?? ($sms['created_at'] ?? null)),
            );
        }
        return $out;
    }

    /**
     * Last-resort OTP extraction.
     *
     * 5sim usually fills `code` itself. When it does not, showing the customer
     * the whole SMS is better than showing nothing, so this only fires on an
     * unambiguous 4-8 digit run and returns null rather than guessing.
     */
    private function extract_code($text) {
        if (!preg_match_all('/\b(\d{4,8})\b/', (string)$text, $m)) return null;
        return count($m[1]) === 1 ? $m[1][0] : null;
    }

    /** Vendor timestamps are ISO-8601 with an offset; we store UTC DATETIME. */
    private function utc($raw) {
        if ($raw === null || $raw === '') return null;
        try {
            $dt = new DateTime((string)$raw);
        } catch (Exception $e) {
            return null;
        }
        $dt->setTimezone(new DateTimeZone('UTC'));
        return $dt->format('Y-m-d H:i:s');
    }

    /** Vendor currency → base currency, or null when no rate is configured. */
    private function to_base($vendor_amount) {
        if ($vendor_amount === null || $vendor_amount === '' || $this->rate_to_base === null) return null;
        if (!is_numeric($vendor_amount)) return null;
        return bcmul(number_format((float)$vendor_amount, 8, '.', ''), $this->rate_to_base, 8);
    }

    /** Base currency → vendor currency, for the spend cap. */
    private function to_vendor_price($base_amount) {
        if ($this->rate_to_base === null || !is_numeric($base_amount)) return null;
        $vendor = bcdiv(number_format((float)$base_amount, 8, '.', ''), $this->rate_to_base, 8);
        return rtrim(rtrim(number_format((float)$vendor, 2, '.', ''), '0'), '.');
    }

    private function country_slug($code) {
        $key = strtoupper(trim((string)$code));
        if ($key === '') return '';
        return $this->country_map[$key] ?? strtolower($key);
    }

    private function product_slug($code) {
        $key = strtoupper(trim((string)$code));
        if ($key === '') return '';
        return $this->product_map[$key] ?? strtolower($key);
    }

    private function upper_keys($map) {
        $out = array();
        if (is_array($map)) {
            foreach ($map as $k => $v) $out[strtoupper((string)$k)] = (string)$v;
        }
        return $out;
    }

    /** The fivesim block of providers.retry_policy, if any. */
    private function config_blob() {
        if (empty($this->provider->retry_policy)) return array();
        $decoded = json_decode($this->provider->retry_policy, true);
        if (!is_array($decoded)) return array();
        foreach (array('fivesim', '5sim', 'number') as $key) {
            if (isset($decoded[$key]) && is_array($decoded[$key])) return $decoded[$key];
        }
        return array();
    }

    private function token() {
        if (empty($this->provider->api_key_encrypted)) return '';
        $ci =& get_instance();
        $ci->load->library('EncryptionService');
        $raw = (string)$ci->encryptionservice->decrypt($this->provider->api_key_encrypted);
        // The shared admin form stores a bare key; accept a JSON blob too so a
        // provider row created for another vendor shape still resolves.
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) return (string)($decoded['api_key'] ?? '');
        return $raw;
    }

    /**
     * One HTTP call. Every 5sim endpoint is a GET.
     *
     * @return array{ok:bool,data?:array,error?:string}
     */
    private function call($path) {
        $url = $this->base.'/'.ltrim($path, '/');
        $headers = array('Authorization: Bearer '.$this->token(), 'Accept: application/json');

        try {
            $res = $this->http->get($url, $headers);
        } catch (Exception $e) {
            log_message('error', '5sim transport error: '.$e->getMessage());
            return array('ok' => false, 'error' => 'Vendor unreachable');
        }

        $http_code = isset($res['http_code']) ? (int)$res['http_code'] : 0;
        $body = isset($res['body']) ? trim((string)$res['body']) : '';

        if ($http_code === 401 || $http_code === 403) {
            return array('ok' => false, 'error' => 'The vendor rejected our credentials');
        }
        if ($http_code === 0 || $http_code >= 500) {
            return array('ok' => false, 'error' => 'The vendor did not respond'
                .(!empty($res['error']) ? ': '.$res['error'] : ''));
        }

        // The documented failure mode: a plain-text reason, frequently with a
        // 200. Check this before decoding, or every rejection reads as success.
        $plain = $this->plain_error($body);
        if ($plain !== null) return array('ok' => false, 'error' => $plain);

        $data = json_decode($body, true);
        if (!is_array($data)) {
            if ($http_code >= 400) {
                return array('ok' => false, 'error' => 'The vendor rejected the request (HTTP '.$http_code.')');
            }
            return array('ok' => false, 'error' => 'The vendor returned an unusable response');
        }
        // Some endpoints wrap an error in JSON rather than plain text.
        if (isset($data['error']) && $data['error'] !== '') {
            return array('ok' => false, 'error' => $this->describe((string)$data['error']));
        }
        return array('ok' => true, 'data' => $data);
    }

    /** A body that is a bare vendor error string, or null if it is not one. */
    private function plain_error($body) {
        if ($body === '') return 'The vendor returned an empty response';
        if ($body[0] === '{' || $body[0] === '[') return null;
        return $this->describe($body);
    }

    private function describe($raw) {
        $key = strtolower(trim((string)$raw));
        if (isset(self::$errors[$key])) return self::$errors[$key];
        return mb_substr(trim((string)$raw), 0, 160);
    }
}
