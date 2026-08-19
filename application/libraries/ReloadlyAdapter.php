<?php
defined('BASEPATH') OR exit('No direct script access allowed');

// Instantiated with `new` below; CI3 does not autoload plain library
// classes, so require the dependency explicitly.
require_once __DIR__.'/SecureHttpClient.php';

require_once __DIR__.'/GiftcardProviderInterface.php';

/**
 * ReloadlyAdapter — live gift cards with Reloadly (§23, §14).
 *
 * Built against https://docs.reloadly.com/gift-cards. Five properties of that
 * API shape this class, and each one is a money bug or a support incident if
 * it is got wrong:
 *
 *  1. **Auth is OAuth2 with a two-month token, not an API key.** Every other
 *     vendor in this codebase signs each call with a static secret. Reloadly
 *     issues a bearer token from auth.reloadly.com that lives ~60 days, and
 *     fetching a fresh one on every call would double the latency of every
 *     order and rate-limit us for no reason. The token is therefore cached on
 *     the provider row (retry_policy → reloadly.token) with its expiry, and
 *     refetched only when it is missing, near expiry, or a call comes back
 *     401. The audience in the token request must name the *gift card* API —
 *     a token minted for airtime authenticates nothing here.
 *
 *  2. **The Accept header is a vendor media type.** `application/json` is not
 *     enough; Reloadly wants `application/com.reloadly.giftcards-v1+json` and
 *     answers 4xx without it. Easy to lose in a refactor, so it is one
 *     constant used by every call.
 *
 *  3. **Ordering and receiving are two calls.** POST /orders returns a
 *     transactionId; the card numbers come from
 *     GET /orders/transactions/{id}/cards, which can 404 for a short while
 *     after a successful order while the vendor issues the card. That 404 is
 *     *not* a failure — it is "not yet", and treating it as an error would
 *     refund a customer whose card is about to arrive. codes() reports it as
 *     ok:true, ready:false, and the retry worker comes back.
 *
 *  4. **customIdentifier is the idempotency key.** It is set to our
 *     transaction's public_id, exactly as VTpass's request_id is, so a
 *     network timeout during POST /orders cannot become two purchases. The
 *     order-status lookup can then find our order by our own reference.
 *
 *  5. **The account currency is not the card currency.** A product has a
 *     recipientCurrencyCode (what the card is worth: USD, EUR) and a
 *     senderCurrencyCode (what our Reloadly wallet is billed in — NGN for a
 *     Nigerian account). Costs are only reported when the vendor is actually
 *     billing in our base currency; if the account is denominated in something
 *     else, no cost is reported at all rather than a number that is wrong by a
 *     factor of a thousand. Same rule as FiveSimAdapter's rouble handling.
 *
 * Credentials live in `providers.api_key_encrypted` as a JSON blob
 * {"client_id":"...","client_secret":"..."}, matching the pattern VtpassAdapter
 * and DojahAdapter established for multi-credential vendors.
 */
class ReloadlyAdapter implements GiftcardProviderInterface {

    const BASE_URL    = 'https://giftcards.reloadly.com';
    const SANDBOX_URL = 'https://giftcards-sandbox.reloadly.com';
    const AUTH_URL    = 'https://auth.reloadly.com/oauth/token';

    /** Reloadly rejects plain application/json on these endpoints. */
    const MEDIA_TYPE  = 'application/com.reloadly.giftcards-v1+json';

    /** Refresh this long before the token actually expires. */
    const TOKEN_SKEW_SECONDS = 86400;

    /**
     * HTTP status → what an operator can actually do about it.
     * 404 is absent from the order path on purpose: on codes() it means
     * "not issued yet", which is handled as ready:false.
     */
    private static $errors = array(
        400 => 'The vendor rejected the order details',
        401 => 'The vendor rejected our credentials',
        // The one an operator must be able to recognise instantly: our own
        // float is empty, so every order will fail until somebody tops it up.
        402 => 'The vendor wallet is out of funds',
        403 => 'The vendor account is not permitted to buy this product',
        404 => 'The vendor does not have that product',
        422 => 'The vendor rejected the order details',
        429 => 'The vendor is rate-limiting us — try again shortly',
        500 => 'The vendor had an internal error',
        502 => 'The vendor is unreachable',
        503 => 'The vendor is temporarily unavailable',
    );

    /**
     * Vendor error codes that mean "this will never work, stop retrying".
     * Anything not listed is treated as possibly transient.
     */
    private static $permanent = array(
        'PRODUCT_NOT_FOUND', 'INVALID_PRODUCT_ID', 'PRODUCT_INACTIVE',
        'INVALID_DENOMINATION', 'DENOMINATION_NOT_SUPPORTED',
        'INVALID_RECIPIENT_EMAIL', 'DUPLICATE_CUSTOM_IDENTIFIER',
    );

    private $provider;
    private $http;
    private $base;
    private $credentials = null;
    private $token = null;

    public function __construct($provider_row, $http = null) {
        $this->provider = $provider_row;
        $timeout = isset($provider_row->timeout_ms) ? $provider_row->timeout_ms / 1000 : 30;
        $this->http = $http ?: new SecureHttpClient(array('timeout' => $timeout));

        $url = isset($provider_row->api_url) ? rtrim((string)$provider_row->api_url, '/') : '';
        $this->base = $url !== '' ? $url : self::BASE_URL;
    }

    /* -------------------------------- order ------------------------------- */

    public function order(array $p) {
        $product_id = (string)($p['product_id'] ?? '');
        $quantity   = max(1, (int)($p['quantity'] ?? 1));
        $unit_price = isset($p['unit_price']) ? (float)$p['unit_price'] : 0.0;

        if ($product_id === '') {
            return array('ok' => false, 'error' => 'No vendor product id was configured for this card');
        }
        if ($unit_price <= 0) {
            return array('ok' => false, 'error' => 'No card denomination was supplied');
        }

        $body = array(
            'productId'        => is_numeric($product_id) ? (int)$product_id : $product_id,
            'quantity'         => $quantity,
            // The denomination in the *recipient's* currency, which is what
            // the vendor's fixedRecipientDenominations list is quoted in.
            'unitPrice'        => $unit_price,
            'customIdentifier' => (string)($p['reference'] ?? ''),
            'senderName'       => $this->sender_name($p),
            'preOrder'         => false,
        );
        // Reloadly emails the card when an address is given. The panel is
        // still the source of truth — the codes are stored and shown in the
        // dashboard either way — so this is a convenience, not the delivery.
        if (!empty($p['recipient_email'])) {
            $body['recipientEmail'] = (string)$p['recipient_email'];
        }

        $res = $this->request('POST', '/orders', $body);
        if (!empty($res['transport_error'])) {
            return array('ok' => false, 'error' => $res['transport_error']);
        }

        $code = (int)$res['http_code'];
        $data = json_decode((string)$res['body'], true);

        if ($code < 200 || $code >= 300) {
            return array(
                'ok'        => false,
                'error'     => $this->error_for($code, $data),
                'permanent' => $this->is_permanent($data),
            );
        }
        if (!is_array($data) || empty($data['transactionId'])) {
            return array('ok' => false, 'error' => 'The vendor accepted the order without returning a reference');
        }

        // A vendor status of SUCCESSFUL means the order went through, not that
        // the card numbers are in hand — those come from codes(). Anything
        // else (PROCESSING, PENDING) is the same as far as we are concerned:
        // placed, undelivered.
        return array(
            'ok'        => true,
            'reference' => (string)$data['transactionId'],
            'status'    => 'PLACED',
            'cost'      => $this->cost_from($data),
            'error'     => null,
        );
    }

    /* -------------------------------- codes ------------------------------- */

    public function codes($reference) {
        $reference = trim((string)$reference);
        if ($reference === '') {
            return array('ok' => false, 'ready' => false, 'cards' => array(),
                         'error' => 'That order has no vendor reference');
        }

        $res = $this->request('GET', '/orders/transactions/'.rawurlencode($reference).'/cards');
        if (!empty($res['transport_error'])) {
            return array('ok' => false, 'ready' => false, 'cards' => array(),
                         'error' => $res['transport_error']);
        }

        $code = (int)$res['http_code'];
        $data = json_decode((string)$res['body'], true);

        // Not issued yet. The order is good, the card is being minted; this
        // must never be read as a failed purchase.
        if ($code === 404) {
            return array('ok' => true, 'ready' => false, 'cards' => array(), 'error' => null);
        }
        if ($code < 200 || $code >= 300) {
            return array('ok' => false, 'ready' => false, 'cards' => array(),
                         'error' => $this->error_for($code, $data));
        }

        $cards = $this->cards_from($data);
        if (!$cards) {
            // 200 with nothing usable: same situation as a 404, and the same
            // answer. Inventing an empty card would mark the order delivered
            // with nothing to deliver.
            return array('ok' => true, 'ready' => false, 'cards' => array(), 'error' => null);
        }

        return array('ok' => true, 'ready' => true, 'cards' => $cards, 'error' => null);
    }

    /* ----------------------------- reconciliation -------------------------- */

    public function order_status($reference) {
        $reference = trim((string)$reference);
        if ($reference === '') {
            return array('ok' => false, 'error' => 'That order has no vendor reference');
        }

        $res = $this->request('GET', '/reports/transactions/'.rawurlencode($reference));
        if (!empty($res['transport_error'])) {
            return array('ok' => false, 'error' => $res['transport_error']);
        }

        $code = (int)$res['http_code'];
        $data = json_decode((string)$res['body'], true);
        if ($code < 200 || $code >= 300) {
            return array('ok' => false, 'error' => $this->error_for($code, $data));
        }

        // This endpoint answers with a list on some accounts and a bare object
        // on others; both spellings appear in the vendor's own samples.
        if (is_array($data) && isset($data[0]) && is_array($data[0])) $data = $data[0];
        if (!is_array($data) || empty($data['transactionId'])) {
            return array('ok' => false, 'error' => 'The vendor knows nothing about that order');
        }

        $vendor = strtoupper((string)($data['status'] ?? ''));
        return array(
            'ok'        => true,
            'status'    => in_array($vendor, array('SUCCESSFUL', 'SUCCESS'), true) ? 'PLACED'
                            : ($vendor === 'FAILED' ? 'FAILED' : 'PENDING'),
            'reference' => (string)$data['transactionId'],
            'cost'      => $this->cost_from($data),
            'error'     => null,
        );
    }

    /* ------------------------------- catalogue ----------------------------- */

    public function products($country = null) {
        $path = $country
            ? '/countries/'.rawurlencode(strtoupper((string)$country)).'/products'
            : '/products';

        $res = $this->request('GET', $path);
        if (!empty($res['transport_error'])) {
            return array('ok' => false, 'error' => $res['transport_error']);
        }

        $code = (int)$res['http_code'];
        $data = json_decode((string)$res['body'], true);
        if ($code < 200 || $code >= 300) {
            return array('ok' => false, 'error' => $this->error_for($code, $data));
        }

        // Paginated responses wrap the list in `content`; unpaginated ones are
        // the list. Accept both rather than depending on the account's setting.
        if (is_array($data) && isset($data['content']) && is_array($data['content'])) {
            $data = $data['content'];
        }
        if (!is_array($data)) {
            return array('ok' => false, 'error' => 'The vendor returned an unusable catalogue');
        }

        $products = array();
        foreach ($data as $row) {
            if (!is_array($row) || empty($row['productId'])) continue;
            // A product with no stated recipient currency cannot be described
            // to a customer or priced against, so it is skipped rather than
            // guessed into the catalogue as a dollar card.
            if (empty($row['recipientCurrencyCode'])) continue;
            foreach ($this->denominations($row) as $d) $products[] = $d;
        }

        return array('ok' => true, 'products' => $products);
    }

    public function balance() {
        $res = $this->request('GET', '/accounts/balance');
        if (!empty($res['transport_error'])) {
            return array('ok' => false, 'error' => $res['transport_error']);
        }
        $code = (int)$res['http_code'];
        $data = json_decode((string)$res['body'], true);
        if ($code < 200 || $code >= 300) {
            return array('ok' => false, 'error' => $this->error_for($code, $data));
        }
        if (!is_array($data) || !isset($data['balance']) || !is_numeric($data['balance'])) {
            return array('ok' => false, 'error' => 'The vendor did not report a balance');
        }

        return array(
            'ok'       => true,
            'balance'  => number_format((float)$data['balance'], 8, '.', ''),
            'currency' => strtoupper((string)($data['currencyCode'] ?? windels_base_currency())),
        );
    }

    /* ------------------------------ internals ----------------------------- */

    /**
     * One denomination of one vendor product, flattened into catalogue rows.
     *
     * A Reloadly FIXED product is really several buyable things — a $25 and a
     * $50 Amazon card share a productId and differ only in unitPrice — so one
     * vendor row becomes several of ours. fixedRecipientToSenderDenominationsMap
     * is the important field: it is the vendor's own answer to "what will this
     * denomination cost me in my account's currency", already converted, which
     * is strictly better than applying an FX rate ourselves.
     */
    private function denominations(array $row) {
        $type = strtoupper((string)($row['denominationType'] ?? 'FIXED'));
        $sender_currency = strtoupper((string)($row['senderCurrencyCode'] ?? ''));
        $base = strtoupper(windels_base_currency());

        $common = array(
            'provider_product_id' => (string)$row['productId'],
            'name'                => (string)($row['productName'] ?? 'Gift card'),
            'brand_id'            => isset($row['brand']['brandId']) ? (string)$row['brand']['brandId'] : null,
            'brand_name'          => isset($row['brand']['brandName']) ? (string)$row['brand']['brandName']
                                        : (string)($row['productName'] ?? 'Gift card'),
            'country_code'        => strtoupper((string)($row['country']['isoName'] ?? 'US')),
            'denomination_type'   => $type === 'RANGE' ? 'RANGE' : 'FIXED',
            // Never defaulted: see the migration. A card whose denomination
            // currency the vendor did not state is dropped by products().
            'recipient_currency'  => strtoupper((string)($row['recipientCurrencyCode'] ?? '')),
            'logo_url'            => isset($row['logoUrls'][0]) ? (string)$row['logoUrls'][0] : null,
            'redeem_instructions' => isset($row['redeemInstruction']['verbose'])
                                        ? (string)$row['redeemInstruction']['verbose']
                                        : (isset($row['redeemInstruction']['concise'])
                                            ? (string)$row['redeemInstruction']['concise'] : null),
        );

        // A RANGE product cannot be turned into fixed rows: the customer names
        // the amount. It is imported so an operator can see it exists, with
        // its bounds, and the service refuses to sell it (see GiftcardService).
        if ($type === 'RANGE') {
            return array(array_merge($common, array(
                'face_value'     => null,
                'min_face_value' => $this->number($row['minRecipientDenomination'] ?? null),
                'max_face_value' => $this->number($row['maxRecipientDenomination'] ?? null),
                'cost'           => null,
            )));
        }

        $map = $this->sender_map($row);
        $out = array();
        foreach ((array)($row['fixedRecipientDenominations'] ?? array()) as $face) {
            if (!is_numeric($face)) continue;
            $face_str = $this->number($face);

            // Only report a cost when the vendor is billing us in the currency
            // this panel keeps its books in. A senderCurrencyCode of USD
            // against an NGN panel would otherwise land a dollar figure in a
            // naira column, which reads as a 99% discount.
            $cost = null;
            if ($sender_currency !== '' && $sender_currency === $base) {
                $key = number_format((float)$face, 2, '.', '');
                if (isset($map[$key])) $cost = $this->number($map[$key]);
            }

            $out[] = array_merge($common, array(
                'face_value'     => $face_str,
                'min_face_value' => null,
                'max_face_value' => null,
                'cost'           => $cost,
            ));
        }
        return $out;
    }

    /**
     * recipient denomination => sender price.
     *
     * The vendor documents this field as an object, and returns it as a list
     * of single-key objects on some endpoints. Both are read here, because the
     * difference is invisible until a sync silently imports zero prices.
     */
    private function sender_map(array $row) {
        $raw = $row['fixedRecipientToSenderDenominationsMap'] ?? array();
        $map = array();
        if (is_array($raw)) {
            foreach ($raw as $k => $v) {
                if (is_array($v)) {
                    foreach ($v as $k2 => $v2) {
                        if (is_numeric($v2)) $map[number_format((float)$k2, 2, '.', '')] = $v2;
                    }
                } elseif (is_numeric($v)) {
                    $map[number_format((float)$k, 2, '.', '')] = $v;
                }
            }
        }
        return $map;
    }

    /** The card list, whichever of the vendor's two shapes came back. */
    private function cards_from($data) {
        if (!is_array($data)) return array();
        // A single-card order answers with one object, a multi-card order with
        // a list. Normalise before reading anything.
        $rows = (isset($data['cardNumber']) || isset($data['pinCode'])) ? array($data) : $data;

        $cards = array();
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $number = isset($row['cardNumber']) ? trim((string)$row['cardNumber']) : '';
            $pin    = isset($row['pinCode']) ? trim((string)$row['pinCode']) : '';
            $url    = isset($row['redemptionUrl']) ? trim((string)$row['redemptionUrl']) : '';
            // A card with neither a number nor a PIN is not a card. Some
            // brands are PIN-only and some are number-only, so either alone
            // is enough — but a row with only a redemption URL is not.
            if ($number === '' && $pin === '') continue;

            $cards[] = array(
                'card_number'    => $number,
                'pin'            => $pin !== '' ? $pin : null,
                'redemption_url' => $url !== '' ? $url : null,
                'expires_on'     => $this->expiry($row),
            );
        }
        return $cards;
    }

    private function expiry(array $row) {
        foreach (array('expiryDate', 'expirationDate', 'expiresOn') as $k) {
            if (empty($row[$k])) continue;
            $ts = strtotime((string)$row[$k]);
            if ($ts) return gmdate('Y-m-d', $ts);
        }
        return null;
    }

    /**
     * What this order actually cost us, in the panel's base currency.
     *
     * balanceInfo.cost is the authoritative figure — it is what left the
     * Reloadly wallet, fees included. It is only used when that wallet is
     * denominated in our base currency; otherwise no cost is reported, which
     * leaves the catalogue's estimate in place rather than overwriting it with
     * a foreign-currency number.
     */
    private function cost_from($data) {
        if (!is_array($data)) return null;
        $base = strtoupper(windels_base_currency());

        if (isset($data['balanceInfo']) && is_array($data['balanceInfo'])) {
            $info = $data['balanceInfo'];
            if (isset($info['cost']) && is_numeric($info['cost'])
                && strtoupper((string)($info['currencyCode'] ?? '')) === $base) {
                return $this->number($info['cost']);
            }
        }
        // Fall back to the order total, which is quoted in the sender currency
        // on the envelope rather than on the product.
        if (isset($data['amount']) && is_numeric($data['amount'])
            && strtoupper((string)($data['currencyCode'] ?? '')) === $base) {
            $total = (float)$data['amount'];
            if (isset($data['totalFee']) && is_numeric($data['totalFee'])) {
                $total += (float)$data['totalFee'];
            }
            return $this->number($total);
        }
        return null;
    }

    /** Whether the vendor's complaint is worth retrying. */
    private function is_permanent($body) {
        if (!is_array($body)) return false;
        $code = strtoupper((string)($body['errorCode'] ?? ''));
        return $code !== '' && in_array($code, self::$permanent, true);
    }

    /**
     * A message an operator can act on.
     *
     * Reloadly's error envelope carries {timeStamp, message, path, errorCode},
     * and its `message` is usually the most useful thing available — but
     * `path` echoes the request, so only the message and code are surfaced.
     */
    private function error_for($code, $body) {
        $detail = '';
        if (is_array($body)) {
            if (!empty($body['message']))   $detail = (string)$body['message'];
            elseif (!empty($body['error'])) $detail = (string)$body['error'];
        }
        $base = self::$errors[$code] ?? ('The vendor returned HTTP '.$code);
        return $detail !== '' ? $base.': '.$detail : $base;
    }

    /**
     * Issue one authenticated call, refreshing the token on a 401.
     *
     * The retry is deliberately once and only once: a genuinely bad
     * client_secret would otherwise loop, and a second 401 after a fresh token
     * means the credentials are wrong, not stale.
     */
    private function request($method, $path, ?array $body = null, $retrying = false) {
        $token = $this->token();
        if ($token === null) {
            return array('transport_error' => 'Could not authenticate with the gift card vendor');
        }

        $url = $this->base.$path;
        $headers = array(
            'Accept: '.self::MEDIA_TYPE,
            'Authorization: Bearer '.$token,
        );
        if ($body !== null) $headers[] = 'Content-Type: application/json';

        $res = $method === 'POST'
            ? $this->http->post($url, $body === null ? null : json_encode($body), $headers)
            : $this->http->get($url, $headers);

        if (!empty($res['error']) && (int)($res['http_code'] ?? 0) === 0) {
            return array('transport_error' => 'Could not reach the gift card vendor');
        }

        if ((int)($res['http_code'] ?? 0) === 401 && !$retrying) {
            // The cached token has been revoked or has expired early. Drop it
            // and try once with a fresh one.
            $this->token = null;
            $this->forget_token();
            return $this->request($method, $path, $body, true);
        }

        return array('http_code' => $res['http_code'] ?? 0, 'body' => $res['body'] ?? '');
    }

    /**
     * A usable access token: the cached one, or a freshly minted one.
     *
     * Returns NULL when authentication itself failed, which callers report as
     * a transport error — there is nothing an order can do without a token.
     */
    private function token() {
        if ($this->token !== null) return $this->token;

        $cached = $this->cached_token();
        if ($cached !== null) return $this->token = $cached;

        $creds = $this->credentials();
        if ($creds['client_id'] === '' || $creds['client_secret'] === '') return null;

        $res = $this->http->post(self::AUTH_URL, json_encode(array(
            'client_id'     => $creds['client_id'],
            'client_secret' => $creds['client_secret'],
            'grant_type'    => 'client_credentials',
            // Must name the gift card API: a token is scoped to one product.
            'audience'      => $this->audience(),
        )), array('Accept: application/json', 'Content-Type: application/json'));

        $code = (int)($res['http_code'] ?? 0);
        if ($code < 200 || $code >= 300) return null;

        $body = json_decode((string)($res['body'] ?? ''), true);
        if (!is_array($body) || empty($body['access_token'])) return null;

        $token = (string)$body['access_token'];
        $ttl   = isset($body['expires_in']) && is_numeric($body['expires_in'])
            ? (int)$body['expires_in'] : 5184000;
        $this->store_token($token, time() + $ttl);

        return $this->token = $token;
    }

    /**
     * The audience the token must be minted for.
     *
     * Derived from the configured base URL so a sandbox provider row cannot
     * end up with a production token — those authenticate, and then every
     * order spends real money in the wrong account.
     */
    private function audience() {
        return strpos($this->base, 'sandbox') !== false ? self::SANDBOX_URL : self::BASE_URL;
    }

    /** The token block of providers.retry_policy, if it is still fresh. */
    private function cached_token() {
        $cfg = $this->config_blob();
        if (empty($cfg['token']) || empty($cfg['token_expires_at'])) return null;
        // Refresh a day early: a token that expires mid-order costs a retry on
        // a call that moves money.
        if ((int)$cfg['token_expires_at'] - self::TOKEN_SKEW_SECONDS <= time()) return null;
        // A token minted for the other environment is worse than none.
        if (!empty($cfg['token_audience']) && $cfg['token_audience'] !== $this->audience()) return null;
        return (string)$cfg['token'];
    }

    private function store_token($token, $expires_at) {
        $this->write_config(array(
            'token'            => $token,
            'token_expires_at' => (int)$expires_at,
            'token_audience'   => $this->audience(),
        ));
    }

    private function forget_token() {
        $this->write_config(array('token' => null, 'token_expires_at' => null, 'token_audience' => null));
    }

    /**
     * Merge a change into providers.retry_policy → reloadly.
     *
     * Written through Provider_model rather than a direct query so the same
     * update path, and the same updated_at, apply as everywhere else. A
     * failure to persist is not fatal — the adapter keeps the token in memory
     * for this request and mints a new one next time.
     */
    private function write_config(array $changes) {
        $decoded = array();
        if (!empty($this->provider->retry_policy)) {
            $parsed = json_decode($this->provider->retry_policy, true);
            if (is_array($parsed)) $decoded = $parsed;
        }
        $block = isset($decoded['reloadly']) && is_array($decoded['reloadly'])
            ? $decoded['reloadly'] : array();
        foreach ($changes as $k => $v) {
            if ($v === null) unset($block[$k]); else $block[$k] = $v;
        }
        $decoded['reloadly'] = $block;
        $encoded = json_encode($decoded);
        $this->provider->retry_policy = $encoded;

        if (empty($this->provider->id)) return;
        // Persisting the token is an optimisation, never a precondition: the
        // in-memory copy above already carries this request. Throwable rather
        // than Exception because a model that will not load raises an Error in
        // PHP 8, and letting that escape would turn a failed cache write into
        // a failed *order* — the customer's money moves either way.
        try {
            $ci =& get_instance();
            $ci->load->model('Provider_model');
            if (!isset($ci->Provider_model)) return;
            $ci->Provider_model->update_provider($this->provider->id, array('retry_policy' => $encoded));
        } catch (Throwable $e) {
            log_message('error', 'ReloadlyAdapter could not cache its access token');
        }
    }

    /** The reloadly block of providers.retry_policy, if any. */
    private function config_blob() {
        if (empty($this->provider->retry_policy)) return array();
        $decoded = json_decode($this->provider->retry_policy, true);
        if (!is_array($decoded) || empty($decoded['reloadly']) || !is_array($decoded['reloadly'])) {
            return array();
        }
        return $decoded['reloadly'];
    }

    /**
     * Decrypt the credential blob once per adapter.
     * Returns client_id / client_secret, either of which may be ''.
     */
    private function credentials() {
        if ($this->credentials !== null) return $this->credentials;

        $raw = '';
        if (!empty($this->provider->api_key_encrypted)) {
            $ci =& get_instance();
            $ci->load->library('EncryptionService');
            $raw = (string)$ci->encryptionservice->decrypt($this->provider->api_key_encrypted);
        }

        $creds = array('client_id' => '', 'client_secret' => $raw);
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            foreach (array('client_id', 'client_secret') as $k) {
                if (isset($decoded[$k])) $creds[$k] = (string)$decoded[$k];
            }
        }
        return $this->credentials = $creds;
    }

    /** What the recipient sees as the sender on the vendor's receipt. */
    private function sender_name(array $p) {
        $name = trim((string)($p['sender_name'] ?? ''));
        if ($name !== '') return mb_substr($name, 0, 60);
        $cfg = $this->config_blob();
        if (!empty($cfg['sender_name'])) return mb_substr((string)$cfg['sender_name'], 0, 60);
        return 'WINDELS PANEL';
    }

    private function number($v) {
        return $v === null || !is_numeric($v) ? null : number_format((float)$v, 8, '.', '');
    }
}
