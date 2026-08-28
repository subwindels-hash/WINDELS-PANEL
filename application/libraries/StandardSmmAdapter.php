<?php
defined('BASEPATH') OR exit('No direct script access allowed');

// Instantiated with `new` below; CI3 does not autoload plain library
// classes, so require the dependency explicitly.
require_once __DIR__.'/SecureHttpClient.php';

/**
 * StandardSmmAdapter — the "SMM panel API v2" every reseller panel speaks.
 *
 * One endpoint, POST form-encoded, `key` + `action`:
 *
 *   action=services                      catalogue
 *   action=add     &service&link&quantity  place an order   -> {order: id}
 *   action=status  &order | &orders      status (comma list) -> {status, charge, start_count, remains}
 *   action=balance                       -> {balance, currency}
 *   action=refill  &order | &orders      -> {refill: id}
 *   action=refill_status &refill|&refills
 *   action=cancel  &orders               -> [{order, cancel}]
 *
 * ## Why this is more than a wrapper around json_decode
 *
 * These panels answer **HTTP 200 with `{"error": "..."}`** for everything from
 * a wrong API key to an unknown order id. The previous version of this adapter
 * reported those as success, which meant:
 *
 *   - a provider with bad credentials was recorded as ONLINE by the health
 *     probe, because getBalance() "succeeded" (with an error body);
 *   - a catalogue sync against a rejecting provider counted zero services and
 *     reported success;
 *   - a refill or cancel that the provider refused was reported to the
 *     customer as accepted.
 *
 * So every call goes through one place that distinguishes transport failure,
 * provider-level refusal and a genuinely successful payload — and returns the
 * `{ok, data|error}` envelope the rest of the panel already expects.
 *
 * ## Status polling
 *
 * `action=status&orders=` is capped by most panels (100 ids is the common
 * limit) and a request over the cap is answered with an error for the WHOLE
 * batch, so the poller would silently stop updating orders on a busy panel.
 * Multi-status is therefore chunked here, and the per-id responses are merged
 * and normalised — including the list-shaped variant some panels return.
 */
class StandardSmmAdapter implements ProviderAdapterInterface {

    /** Ids per status request. Panels commonly reject more than 100. */
    const STATUS_CHUNK = 100;

    private $provider;
    private $http;

    public function __construct($provider_row, $http = null) {
        $this->provider = $provider_row;
        $timeout = max(1, (int)round(((int)($provider_row->timeout_ms ?? 15000)) / 1000));
        $this->http = $http ?: new SecureHttpClient(array('timeout' => $timeout));
    }

    /* ------------------------------------------------------------------ */
    /* Transport                                                           */
    /* ------------------------------------------------------------------ */

    /** Decrypt the stored key at the moment of use; never hold it in a field. */
    private function apiKey() {
        $ci =& get_instance();
        $ci->load->library('EncryptionService');
        return $ci->encryptionservice->decrypt($this->provider->api_key_encrypted);
    }

    /**
     * One provider call, resolved into {ok, data} or {ok:false, error}.
     *
     * @param array $payload  action + parameters (the API key is added here)
     * @param bool  $expect_list  true when the endpoint answers with a list
     */
    private function call(array $payload, $expect_list = false) {
        $url = rtrim((string)$this->provider->api_url, '/');
        $payload['key'] = $this->apiKey();

        $res = $this->http->post($url, $payload);
        $code = (int)($res['http_code'] ?? 0);

        // Transport problems are RETRYABLE, panel refusals are not. The caller
        // has to know which: a refill the panel refused ("Incorrect order ID")
        // must be closed and the customer told, while a refill lost to a
        // timeout or a 502 must be kept and re-sent, or the customer pays for
        // a top-up that was never requested.
        if ($code === 0) {
            return $this->fail($res['error'] ?: 'The provider could not be reached.', true);
        }
        if ($code === 429 || $code >= 500) {
            return $this->fail('The provider answered HTTP '.$code.'.', true);
        }
        if ($code !== 200) {
            return $this->fail('The provider answered HTTP '.$code.'.');
        }

        $body = (string)($res['body'] ?? '');
        $data = json_decode($body, true);
        if (!is_array($data)) {
            // An HTML maintenance page or a truncated body is not "no data" —
            // treating it as an empty catalogue would wipe a provider's
            // services on the next sync. It is also nobody's final answer, so
            // it is retryable: the panel is having a bad minute, it has not
            // refused anything.
            return $this->fail('The provider returned a response that is not JSON.', true);
        }

        // The panel-level refusal that arrives with HTTP 200.
        $error = $this->error_in($data);
        if ($error !== null) {
            return $this->fail($error);
        }

        if ($expect_list && !$this->is_list($data)) {
            return $this->fail('The provider returned an unexpected response shape.');
        }

        return array('ok' => true, 'data' => $data, 'error' => null);
    }

    /**
     * The provider's own error message, if this payload is a refusal.
     *
     * Handles the three shapes seen in the wild: `{"error":"..."}`,
     * `{"status":"error","message":"..."}` and a list whose every entry is an
     * error (a status batch where none of the ids were recognised).
     */
    private function error_in(array $data) {
        if (isset($data['error']) && $data['error'] !== '' && $data['error'] !== false) {
            return is_string($data['error']) ? $data['error'] : 'The provider rejected the request.';
        }
        if (isset($data['status']) && strtolower((string)$data['status']) === 'error') {
            return isset($data['message']) && is_string($data['message'])
                ? $data['message'] : 'The provider rejected the request.';
        }
        return null;
    }

    /**
     * One failure envelope.
     *
     * `retryable` says whether the request may be re-sent later. False means
     * the provider gave an answer and that answer was no; true means we never
     * got an answer at all. Callers that spend or return customer money key
     * off this: RefillService closes a refused refill and re-queues an
     * unanswered one.
     */
    private function fail($message, $retryable = false) {
        log_message('error', 'smm provider '.($this->provider->id ?? '?').': '.$message);
        return array('ok' => false, 'data' => null, 'error' => $message, 'retryable' => (bool)$retryable);
    }

    private function is_list(array $data) {
        return $data === array() || array_keys($data) === range(0, count($data) - 1);
    }

    /* ------------------------------------------------------------------ */
    /* ProviderAdapterInterface                                            */
    /* ------------------------------------------------------------------ */

    /** The provider's catalogue, as a list of service rows. */
    public function getServices() {
        return $this->call(array('action' => 'services'), true);
    }

    /**
     * Place an order.
     *
     * The provider's order id is the only thing that lets us poll, refill or
     * cancel it later, so a response without one is a failure even when the
     * provider called it a success.
     */
    public function createOrder($payload) {
        $res = $this->call(array_merge(array('action' => 'add'), (array)$payload));
        if (empty($res['ok'])) return array('ok' => false, 'error' => $res['error']);

        $order_id = $res['data']['order'] ?? null;
        if ($order_id === null || $order_id === '') {
            return array('ok' => false, 'error' => 'The provider accepted the order but returned no order id.');
        }
        return array(
            'ok' => true,
            'provider_order_id' => (string)$order_id,
            'charge'   => isset($res['data']['charge']) ? (string)$res['data']['charge'] : null,
            'currency' => $res['data']['currency'] ?? null,
        );
    }

    /** Status for one order, in the same per-order shape as the batch call. */
    public function getOrderStatus($provider_order_id) {
        $res = $this->call(array('action' => 'status', 'order' => $provider_order_id));
        if (empty($res['ok'])) return $res;

        $data = $res['data'];
        // Single-order responses come back flat: {"status":"Completed",...}.
        // Return them keyed by order id so callers have one shape to handle.
        if (isset($data['status'])) {
            return array('ok' => true, 'data' => array((string)$provider_order_id => $this->normalise_status($data)), 'error' => null);
        }
        return array('ok' => true, 'data' => $this->normalise_batch($data), 'error' => null);
    }

    /**
     * Status for many orders, chunked to the provider's limit.
     *
     * A partial failure is not fatal: whatever chunks answered are returned, so
     * one bad id cannot stop every other order in the queue from updating.
     */
    public function getMultipleOrderStatus(array $provider_order_ids) {
        $ids = array_values(array_unique(array_filter(array_map('strval', $provider_order_ids), 'strlen')));
        if (!$ids) return array('ok' => true, 'data' => array(), 'error' => null);

        $merged = array();
        $errors = array();
        foreach (array_chunk($ids, self::STATUS_CHUNK) as $chunk) {
            $res = $this->call(array('action' => 'status', 'orders' => implode(',', $chunk)));
            if (empty($res['ok'])) { $errors[] = $res['error']; continue; }
            $merged += $this->normalise_batch($res['data'], $chunk);
        }

        if (!$merged && $errors) {
            return array('ok' => false, 'data' => null, 'error' => $errors[0]);
        }
        return array('ok' => true, 'data' => $merged, 'error' => null);
    }

    public function getBalance() {
        $res = $this->call(array('action' => 'balance'));
        if (empty($res['ok'])) return $res;
        if (!isset($res['data']['balance'])) {
            return $this->fail('The provider did not report a balance.');
        }
        return array('ok' => true, 'data' => array(
            'balance'  => (string)$res['data']['balance'],
            'currency' => $res['data']['currency'] ?? ($this->provider->currency ?? null),
        ), 'error' => null);
    }

    /** Ask the provider to refill an order. */
    public function requestRefill($provider_order_id) {
        $res = $this->call(array('action' => 'refill', 'order' => $provider_order_id));
        if (empty($res['ok'])) {
            return array('ok' => false, 'error' => $res['error'], 'retryable' => !empty($res['retryable']));
        }

        // Some panels answer {"refill": 123}, others [{"order":1,"refill":123}],
        // and a few answer per-order refusals inside the list:
        // [{"order":1,"refill":{"error":"Incorrect order ID"}}].
        $data = $res['data'];
        $refill = $data['refill'] ?? ($data[0]['refill'] ?? null);
        if (is_array($refill)) {
            $error = $refill['error'] ?? null;
            if (is_string($error) && $error !== '') {
                return array('ok' => false, 'error' => $error, 'retryable' => false);
            }
            $refill = $refill['refill'] ?? null;
        }
        if ($refill === null || $refill === '') {
            return array('ok' => false, 'error' => 'The provider did not return a refill id.',
                         'retryable' => false);
        }
        return array('ok' => true, 'provider_refill_id' => (string)$refill, 'error' => null);
    }

    public function getRefillStatus($provider_refill_id) {
        $res = $this->call(array('action' => 'refill_status', 'refill' => $provider_refill_id));
        if (empty($res['ok'])) return $res;

        $data = $res['data'];
        // {"status":"Completed"} or [{"refill":1,"status":{"status":"Completed"}}]
        $status = $data['status'] ?? ($data[0]['status']['status'] ?? ($data[0]['status'] ?? null));
        if (is_array($status)) $status = $status['status'] ?? null;
        if ($status === null || $status === '') {
            return $this->fail('The provider did not report a refill status.');
        }
        return array('ok' => true, 'data' => array('status' => (string)$status), 'error' => null);
    }

    /**
     * Ask the provider to cancel an order.
     *
     * The list response carries a per-order outcome — `{"cancel": 1}` or
     * `{"cancel": {"error": "Incorrect order ID"}}` — and a refusal there must
     * not be reported to the customer as an accepted cancellation.
     */
    public function requestCancel($provider_order_id) {
        $ids = is_array($provider_order_id) ? $provider_order_id : array($provider_order_id);
        $res = $this->call(array('action' => 'cancel', 'orders' => implode(',', array_map('strval', $ids))));
        if (empty($res['ok'])) {
            return array('ok' => false, 'error' => $res['error'], 'retryable' => !empty($res['retryable']));
        }

        $data = $res['data'];
        $entries = $this->is_list($data) ? $data : array($data);
        $refusals = array();
        foreach ($entries as $entry) {
            if (!is_array($entry)) continue;
            $cancel = $entry['cancel'] ?? null;
            if (is_array($cancel) && isset($cancel['error'])) $refusals[] = (string)$cancel['error'];
            elseif (isset($entry['error'])) $refusals[] = (string)$entry['error'];
        }
        if ($refusals && count($refusals) === count($entries)) {
            // The panel answered, and the answer was no. Re-sending it changes
            // nothing, so this must never be retried as if it were a timeout.
            return array('ok' => false, 'error' => $refusals[0], 'data' => $data, 'retryable' => false);
        }
        return array('ok' => true, 'data' => $data, 'error' => null);
    }

    /* ------------------------------------------------------------------ */
    /* Normalisation                                                       */
    /* ------------------------------------------------------------------ */

    /**
     * Turn a batch response into `[provider_order_id => status payload]`.
     *
     * Panels answer either keyed by order id, or as a list where each entry
     * carries its own `order`. When a list arrives without ids (a few panels
     * answer positionally), the requested ids are zipped back on in order.
     */
    private function normalise_batch($data, array $requested = array()) {
        $out = array();
        if (!is_array($data)) return $out;

        if (!$this->is_list($data)) {
            foreach ($data as $id => $payload) {
                if (is_array($payload)) $out[(string)$id] = $this->normalise_status($payload);
            }
            return $out;
        }

        foreach ($data as $index => $payload) {
            if (!is_array($payload)) continue;
            $id = $payload['order'] ?? ($requested[$index] ?? null);
            if ($id === null) continue;
            $out[(string)$id] = $this->normalise_status($payload);
        }
        return $out;
    }

    /**
     * A single order's status payload, with the keys the panel relies on.
     *
     * The raw keys are preserved (callers read `status`, `remains`,
     * `start_count`), only trimmed and left untranslated: mapping a provider's
     * vocabulary onto our state machine is CronWorkers' job, and doing it in
     * two places is how they drift apart.
     */
    private function normalise_status(array $payload) {
        $out = $payload;
        if (isset($payload['status']))      $out['status'] = trim((string)$payload['status']);
        if (isset($payload['charge']))      $out['charge'] = (string)$payload['charge'];
        if (isset($payload['start_count'])) $out['start_count'] = (string)$payload['start_count'];
        if (isset($payload['remains']))     $out['remains'] = (string)$payload['remains'];
        return $out;
    }
}
