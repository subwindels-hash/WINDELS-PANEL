<?php
defined('BASEPATH') OR exit('No direct script access allowed');

// Instantiated with `new` below; CI3 does not autoload plain library
// classes, so require the dependency explicitly.
require_once __DIR__.'/SecureHttpClient.php';

require_once __DIR__.'/NumberProviderInterface.php';

/**
 * FiveSimAdapter — live virtual-number / OTP integration with 5sim (§10, §14).
 *
 * **Protocol: the current 5sim API ("5SIM protocol"), not the deprecated one.**
 * 5sim issue two dashboard keys: the API key for the *5sim protocol* — the one
 * this adapter is built for, documented at https://5sim.net/docs — and the
 * *API1 protocol (deprecated)* key for the legacy `handler_api.php`
 * compatibility API. The two are not interchangeable: a deprecated key against
 * these endpoints (or these endpoints called with API1 action semantics) fails
 * with rejected credentials. Three things in the adapter keep us on the
 * current protocol:
 *
 *  1. The credential always comes from the environment first
 *     (`FIVESIM_API_KEY`, or its portable `VP_FIVESIM_API_KEY` spelling) — the
 *     key an operator rotates in production — and falls back to the encrypted
 *     `providers.api_key_encrypted` column. It is a Bearer token on the wire
 *     and is never logged, never cached, and never rendered to any client.
 *
 *  2. The base URL is pinned to the current API: an https URL on
 *     `5sim.net` whose path is empty or `/v1`. The constructor refuses
 *     `handler_api.php` / `stubs/` URLs (the deprecated API1 protocol) and any
 *     non-5sim host, so a misconfigured provider row cannot quietly put
 *     customer traffic on the deprecated API.
 *
 *  3. Every call is a GET under `/v1` (`guest/countries`, `guest/prices`,
 *     `guest/products/{country}/{operator}`, `user/profile`,
 *     `user/buy/activation/...`, `user/check|finish|cancel|ban/{id}`), exactly
 *     the current documentation's surface.
 *
 * Four properties of the current API shape this class, and each one is a money
 * bug if it is got wrong:
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
 * Auth is a bearer token — genuinely, here. Nothing throws for a vendor-side
 * rejection; the constructor throws only for a provider row pointed at the
 * wrong (deprecated) API, before any customer money can move.
 */
class FiveSimAdapter implements NumberProviderInterface {

    const BASE_URL = 'https://5sim.net/v1';

    /** The only host the current 5sim API is served from. */
    const API_HOST = '5sim.net';

    /**
     * Test/ops override for log_message. When callable it receives
     * ($level, $message) instead of the CI log, so a test (or a cron wrapper)
     * can capture what the adapter logged. Log lines carry the endpoint, the
     * HTTP status and mapped, safe errors — never the bearer token, never a
     * response body, never a customer's number beyond what the vendor path
     * already contains (nothing: ids only).
     */
    public static $log_sink = null;

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
        'bandwidth limit'       => 'The vendor is rate-limiting us — retry shortly',
        'too many requests'     => 'The vendor is rate-limiting us — retry shortly',
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

    /**
     * @param object $provider_row row from `providers`
     * @param object $http         SecureHttpClient or a test double
     * @throws RuntimeException when api_url points at the deprecated API1
     *                          protocol (handler_api.php / stubs) or at any
     *                          host other than 5sim.net — fail before money
     *                          moves, not on the vendor's answer.
     */
    public function __construct($provider_row, $http = null) {
        $this->provider = $provider_row;
        $timeout = isset($provider_row->timeout_ms) ? $provider_row->timeout_ms / 1000 : 20;
        $this->http = $http ?: new SecureHttpClient(array('timeout' => $timeout));

        $url = isset($provider_row->api_url) ? trim((string)$provider_row->api_url) : '';
        $this->base = $this->resolve_base($url);

        $cfg = $this->config_blob();
        $this->country_map = array_merge(self::$countries, $this->upper_keys($cfg['countries'] ?? array()));
        $this->product_map = array_merge(self::$products,  $this->upper_keys($cfg['products'] ?? array()));

        // Base-currency units per 1 vendor unit (RUB). Deliberately opt-in.
        $this->rate_to_base = isset($cfg['rate_to_base']) && is_numeric($cfg['rate_to_base'])
            && (float)$cfg['rate_to_base'] > 0
            ? (string)$cfg['rate_to_base'] : null;
    }

    /**
     * Pin a stored base URL to the current 5sim protocol.
     *
     * Accepts `https://5sim.net`, `https://5sim.net/` and
     * `https://5sim.net/v1` (normalised to the /v1 form). Everything else is
     * named and refused:
     *
     *  - `handler_api.php` or a `/stubs/` path — that is the **deprecated
     *    API1 protocol**, whose key is a different dashboard credential; the
     *    panel must never talk to it;
     *  - any other host — the current API is served from 5sim.net only;
     *  - plain http — the vendor serves TLS only, and the bearer token must
     *    not cross the wire in the clear;
     *  - any other path — there is no `/v2` and no mirror.
     *
     * @return string the normalised base URL, trailing slash trimmed
     */
    public static function current_protocol_base($url) {
        $parts = parse_url(trim((string)$url));
        $host  = strtolower((string)($parts['host'] ?? ''));
        if ($host !== self::API_HOST && $host !== 'www.'.self::API_HOST) {
            throw new RuntimeException(
                'The 5sim provider URL must be https://'.self::API_HOST.'/v1 (the current '
                .'5sim protocol). Refused host: '.($host !== '' ? $host : '(none)').'.');
        }
        if (strtolower((string)($parts['scheme'] ?? '')) !== 'https') {
            throw new RuntimeException(
                'The 5sim provider URL must be https — the bearer API key must not '
                .'cross plain http.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new RuntimeException('The 5sim provider URL must not embed credentials.');
        }

        $path = rtrim((string)($parts['path'] ?? ''), '/');
        if (strpos($path, 'handler_api') !== false || stripos($path, '/stubs') !== false) {
            throw new RuntimeException(
                'The 5sim provider URL points at the deprecated API1 protocol '
                .'(handler_api.php). Configure the API key for the 5sim protocol and the '
                .'https://'.self::API_HOST.'/v1 base URL instead.');
        }
        if ($path !== '' && strtolower($path) !== '/v1') {
            throw new RuntimeException(
                'The 5sim provider URL must be https://'.self::API_HOST.'/v1 (the current '
                .'5sim protocol). Refused path: '.$path.'.');
        }
        return 'https://'.self::API_HOST.'/v1';
    }

    /**
     * Resolve the base URL: the provider row, or the explicit environment
     * override, or the pinned default — in that order.
     *
     * `FIVESIM_BASE_URL` exists so a staging build can be pointed at a
     * sandboxed 5sim without touching production credentials, exactly the
     * "test authentication first, then continue" drill. It is **refused in
     * production**: `ENVIRONMENT=production` always speaks to 5sim.net over
     * TLS, no matter what the environment says, so a stray variable on a
     * live host cannot silently redirect customer traffic to a test backend.
     * A non-production override is still held to the protocol: https only,
     * and `handler_api.php` / `/stubs/` paths are refused everywhere.
     *
     * @return string base URL, trailing slash trimmed
     */
    private function resolve_base($stored_url) {
        $override = getenv('FIVESIM_BASE_URL');
        if ($override !== false && trim((string)$override) !== '') {
            if (defined('ENVIRONMENT') && ENVIRONMENT === 'production') {
                self::log('error', '5sim: FIVESIM_BASE_URL is ignored in production — '
                    .'the panel always speaks to https://'.self::API_HOST.'/v1');
            } else {
                $base = trim((string)$override);
                $parts = parse_url($base);
                $scheme = strtolower((string)($parts['scheme'] ?? ''));
                $path   = rtrim((string)($parts['path'] ?? ''), '/');
                if ($scheme === 'http' || $scheme === 'https') {
                    if (strpos($path, 'handler_api') !== false || stripos($path, '/stubs') !== false) {
                        throw new RuntimeException(
                            'FIVESIM_BASE_URL points at the deprecated API1 protocol '
                            .'(handler_api.php) — the panel refuses it.');
                    }
                    return rtrim($base, '/');
                }
                throw new RuntimeException('FIVESIM_BASE_URL must be an http(s) URL.');
            }
        }
        return $stored_url !== '' ? self::current_protocol_base($stored_url) : self::BASE_URL;
    }

    /* ----------------------------- reservation ---------------------------- */

    /**
     * Rent a number.
     *
     * `maxPrice` is only honoured by 5sim when the operator is `any`, so
     * sending it alongside a pinned operator would be silently ignored — a
     * spend cap that does not cap. It is therefore only sent when it can work.
     *
     * The buy request deliberately carries **no** other query parameters: the
     * current API's `ref` parameter is a referral code, not a client order
     * reference, and our own linkage (transaction ↔ provider order id) lives in
     * `provider_transactions`, not in a misused vendor field.
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
        if (isset($p['max_price']) && $p['max_price'] !== null && $operator === 'any') {
            $query['maxPrice'] = $this->to_vendor_price($p['max_price']);
        }
        if ($query) $path .= '?'.http_build_query(array_filter($query, function ($v) {
            return $v !== null && $v !== '';
        }));

        // A purchase is the one call that must not be blindly retried: if the
        // first attempt reached the vendor and only the response was lost, a
        // retry buys a second number and the customer pays once. Fail fast;
        // the transaction engine refunds the reservation.
        $res = $this->call('reserve', $path, 0);
        if (empty($res['ok'])) return array('ok' => false, 'error' => $res['error']);

        return $this->reservation($res['data']);
    }

    public function status($reference) {
        return $this->order_call('status', '/user/check/', $reference);
    }

    public function finish($reference) {
        return $this->order_call('finish', '/user/finish/', $reference);
    }

    public function cancel($reference) {
        return $this->order_call('cancel', '/user/cancel/', $reference);
    }

    public function ban($reference) {
        return $this->order_call('ban', '/user/ban/', $reference);
    }

    /* ------------------------------ catalogue ----------------------------- */

    /**
     * Countries and operators the vendor currently carries (current API:
     * `GET /guest/countries` — the same response powers both, because 5sim
     * has no separate operators endpoint; operators are listed per country).
     *
     * @return array{ok:bool,countries?:array,country_codes?:array,error?:string}
     *               countries: vendor slug => operator[]
     *               country_codes: our ISO code => vendor slug, for the subset
     *               the panel names
     */
    public function countries() {
        $res = $this->call('countries', '/guest/countries');
        if (empty($res['ok'])) return array('ok' => false, 'error' => $res['error']);
        if (!is_array($res['data'])) {
            return array('ok' => false, 'error' => 'The vendor returned an unusable country list');
        }

        $countries = array();
        foreach ($res['data'] as $slug => $operators) {
            if (!is_string($slug) || $slug === '') continue;
            $countries[$slug] = is_array($operators) ? array_values($operators) : array();
        }
        return array(
            'ok'            => true,
            'countries'     => $countries,
            'country_codes' => $this->country_map,
        );
    }

    /**
     * The operators 5sim carries for one country (ours or a vendor slug).
     *
     * @return array{ok:bool,operators?:string[],error?:string}
     */
    public function operators($country) {
        $slug = $this->country_slug($country);
        if ($slug === '') return array('ok' => false, 'error' => 'Unknown country '.$country);

        $res = $this->countries();
        if (empty($res['ok'])) return array('ok' => false, 'error' => $res['error']);
        if (!isset($res['countries'][$slug])) {
            return array('ok' => false, 'error' => 'The vendor does not carry that country');
        }
        return array('ok' => true, 'operators' => $res['countries'][$slug]);
    }

    /**
     * Vendor prices and availability (current API: `GET /guest/prices`).
     *
     * This is the number-domain counterpart of an SMM price list: what each
     * (product, operator) costs and how many numbers are behind it, before the
     * panel commits to anything. Filters are all optional; country is the one
     * the catalogue sync always wants.
     *
     * @param string $country  our country code (or a vendor slug)
     * @param string $product  our service code (or a vendor product slug)
     * @param string $operator vendor operator, or 'any'
     * @return array{ok:bool,prices?:array[],error?:string} rows of
     *               {service, provider_product, operator, cost, cost_vendor, stock}
     */
    public function prices($country, $product = null, $operator = null) {
        $slug = $this->country_slug($country);
        if ($slug === '') return array('ok' => false, 'error' => 'Unknown country '.$country);

        $query = array('country' => $slug);
        if ($product !== null && trim((string)$product) !== '') {
            $query['product'] = $this->product_slug((string)$product);
        }
        if ($operator !== null && trim((string)$operator) !== '') {
            $query['operator'] = (string)$operator;
        }

        $res = $this->call('prices', '/guest/prices?'.http_build_query($query));
        if (empty($res['ok'])) return array('ok' => false, 'error' => $res['error']);
        if (!is_array($res['data'])) {
            return array('ok' => false, 'error' => 'The vendor returned an unusable price list');
        }

        // The answer nests country → product → operator → {cost, count, rate};
        // with a country filter the outer key is that country alone. Rows are
        // flattened so a caller never re-walks the vendor's shape.
        $reverse = array_flip($this->product_map);
        $out = array();
        foreach ($res['data'] as $country_key => $products) {
            if (!is_array($products)) continue;
            foreach ($products as $product_slug => $operators) {
                if (!is_array($operators)) continue;
                foreach ($operators as $operator_slug => $info) {
                    if (!is_array($info)) continue;
                    $vendor_cost = isset($info['cost']) ? (string)$info['cost'] : null;
                    $out[] = array(
                        'service'          => $reverse[(string)$product_slug] ?? strtoupper((string)$product_slug),
                        'provider_product' => (string)$product_slug,
                        'operator'         => (string)$operator_slug,
                        'cost'             => $this->to_base($vendor_cost),
                        'cost_vendor'      => $vendor_cost,
                        'stock'            => isset($info['count']) ? (int)$info['count'] : null,
                    );
                }
            }
        }
        return array('ok' => true, 'prices' => $out);
    }

    /**
     * Vendor price list for one country (current API:
     * `GET /guest/products/{country}/{operator}`).
     *
     * 5sim answers a map keyed by product slug, so the shape has to be
     * inverted into rows and mapped back onto our service codes. A product
     * the panel does not sell is skipped rather than invented: the catalogue
     * is an operator decision, not a vendor one.
     */
    public function products($country) {
        $slug = $this->country_slug($country);
        if ($slug === '') return array('ok' => false, 'error' => 'Unknown country '.$country);

        $res = $this->call('products', '/guest/products/'.rawurlencode($slug).'/any');
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
        $res = $this->call('balance', '/user/profile');
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
                ? ($this->provider->currency ?? marvy_base_currency()) : 'RUB',
            'raw_balance' => (string)$data['balance'],
        );
    }

    /* ------------------------------ internals ----------------------------- */

    /** finish/cancel/ban/check all return the same order object. */
    private function order_call($action, $prefix, $reference) {
        $reference = trim((string)$reference);
        if ($reference === '') {
            return array('ok' => false, 'error' => 'No vendor reference to act on');
        }
        $res = $this->call($action, $prefix.rawurlencode($reference));
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

    /**
     * The bearer credential, from the environment first.
     *
     * Production rotates the key by editing the environment
     * (`FIVESIM_API_KEY`, or the portable `VP_FIVESIM_API_KEY` spelling that
     * Env also exposes under the plain name) — the encrypted
     * `providers.api_key_encrypted` column is the fallback for hosts with no
     * env access, not the primary. Either way the key exists only here, on the
     * server, inside the Authorization header.
     *
     * @return string '' when nothing is configured (call() refuses to fire)
     */
    private function token() {
        foreach (array('FIVESIM_API_KEY', 'VP_FIVESIM_API_KEY') as $name) {
            $value = getenv($name);
            if ($value !== false && trim((string)$value) !== '') return trim((string)$value);
        }

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
     * One HTTP call. Every current-protocol endpoint is a GET with the bearer
     * token in the Authorization header — never in the URL, so request logs
     * (ours and any intermediate's) never see the key.
     *
     * The diagnostics line is built for the exact support question this class
     * exists to answer — "which URL did we call, with what, and what came
     * back?" It carries the full URL (path and query: they contain country /
     * product / order id, never the token), the method, the header *names* and
     * the response status and size. The Authorization value, the request body
     * and the response body never reach the log.
     *
     * @param string $action short label for the log line (reserve, status, …)
     * @param string $path   endpoint path beginning with /
     * @param int    $max_retries retry budget for this call; purchases pass 0
     *                            (a retried buy can double-order the vendor)
     * @return array{ok:bool,data?:array,error?:string}
     */
    private function call($action, $path, $max_retries = null) {
        $token = $this->token();
        if ($token === '') {
            self::log('error', '5sim '.$action.': no API key configured — set FIVESIM_API_KEY '
                .'(the current-protocol key) in the environment or the provider record');
            return array('ok' => false,
                'error' => 'The virtual-number vendor is not configured — contact support');
        }

        $url = $this->base.'/'.ltrim($path, '/');
        $headers = array('Authorization: Bearer '.$token, 'Accept: application/json');

        $options = array();
        if ($max_retries !== null) $options['max_retries'] = max(0, (int)$max_retries);
        try {
            $res = $this->http->get($url, $headers, $options);
        } catch (Exception $e) {
            self::log('error', '5sim '.$action.' GET '.$url.' transport error: '.$e->getMessage());
            return array('ok' => false, 'error' => 'Vendor unreachable');
        }

        $http_code = isset($res['http_code']) ? (int)$res['http_code'] : 0;
        $body = isset($res['body']) ? trim((string)$res['body']) : '';
        // The URL (no token), the header names (no values) and the status are
        // safe and useful; the body is not logged because vendor errors are
        // customer-reachable and the mapped message already carries the gist.
        $header_names = implode(',', array_map(function ($h) {
            return strtolower(trim(strtok($h, ':')));
        }, $headers));
        self::log($http_code >= 400 || $http_code === 0 ? 'error' : 'debug',
            '5sim '.$action.' GET '.$url
            .' ['.$header_names.'] -> HTTP '.$http_code
            .($body !== '' ? ' body '.strlen($body).'B' : ' empty'));

        if ($http_code === 401 || $http_code === 403) {
            return array('ok' => false, 'error' => 'The vendor rejected our credentials');
        }
        if ($http_code === 429) {
            return array('ok' => false, 'error' => self::$errors['bandwidth limit']);
        }
        if ($http_code === 404) {
            // The classic symptom of calling the deprecated API1 paths: the
            // vendor answers 404 for /stubs/handler_api.php on 5sim.net. Name
            // the URL so the operator can see exactly which endpoint 404'd.
            return array('ok' => false,
                'error' => 'The vendor answered 404 for that endpoint ('.parse_url($url, PHP_URL_PATH).')'
                    .' — the current API is https://'.self::API_HOST.'/v1 with a Bearer token;'
                    .' handler_api.php / api1.5sim.net are the deprecated protocol and are never called.');
        }
        if ($http_code === 0 || $http_code >= 500) {
            return array('ok' => false, 'error' => 'The vendor did not respond'
                .(!empty($res['error']) ? ': '.$res['error'] : ''));
        }

        // The documented failure mode: a plain-text reason, frequently with a
        // 200. Check this before decoding, or every rejection reads as success.
        $plain = $this->plain_error($body);
        if ($plain !== null) {
            if ($http_code >= 400) self::log('error', '5sim '.$action.' rejected: '.$plain);
            return array('ok' => false, 'error' => $plain);
        }

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

    /** log_message, or the test/ops sink when one is installed. */
    private static function log($level, $message) {
        if (is_callable(self::$log_sink)) {
            call_user_func(self::$log_sink, $level, $message);
            return;
        }
        log_message($level, $message);
    }
}
