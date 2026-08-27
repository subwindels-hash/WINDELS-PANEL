<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Api_v1 — Reseller API (Session 12).
 *
 * Auth via X-Api-Key (sha256 lookup, IP whitelist, expiry), fixed-window
 * rate limiting, and JSON envelope {success, data|error, requestId}. All
 * mutation endpoints accept an Idempotency-Key. Public IDs (ULIDs) only.
 */
class Api_v1 extends MY_Controller {

    const DEFAULT_LIMIT = 50;
    const MAX_LIMIT = 200;

    private $key;
    private $user;
    private $raw_body = '';
    private $started_at;
    private $usage_logged = false;
    private $usage_endpoint = '/api/v1';

    public function __construct() {
        $this->started_at = microtime(true);
        parent::__construct();
        $this->output->set_content_type('application/json');
        $this->usage_endpoint = $this->normalized_endpoint();

        // Documentation is intentionally public even though historical routes
        // point it at this authenticated controller.
        if ($this->is_docs_request()) return;

        // Operator switch: settings `api_enabled` (default on). Turning it off
        // shuts the reseller API down without revoking any keys. The
        // `reseller_api` feature flag (Admin → Settings → Feature flags) is
        // the same kill switch surfaced on the module-toggle screen rather
        // than the settings form — both must be on.
        try {
            $this->load->model('Setting_model');
            $api_on = $this->Setting_model->get('api_enabled', true);
            if ($api_on !== null && $api_on !== '' && !in_array(strtolower(trim((string)$api_on)), array('1','true','yes','on'), true)) {
                $this->fail(503, 'API_DISABLED', 'The reseller API is currently disabled.');
            }
            if (!marvy_feature_enabled('reseller_api', true)) {
                $this->fail(503, 'API_DISABLED', 'The reseller API is currently disabled.');
            }
        } catch (Throwable $e) { /* settings unavailable — fail open */ }

        $this->load->library(array('ApiAuthenticator','ApiRateLimiter'));
        $this->load->model(array(
            'Service_model','Order_model','Order_status_history_model','Refill_model',
        ));
        $this->load->library(array('PricingService','OrderService','RefillService'));

        $this->key = $this->apiauthenticator->authenticate();
        if (!$this->key) {
            $err = $this->apiauthenticator->last_error();
            // Preserve only the matched key identity for denied-request usage
            // evidence; raw credentials are never returned by the authenticator.
            $this->key = $this->apiauthenticator->resolved_key();
            $this->fail($err['http'], $err['code'], $err['message']);
        }
        $this->user = $this->key->user;
        $this->enforce_rate_limit();
    }

    /* ------------------------------ services ----------------------------- */

    /** GET /api/v1/services */
    public function services() {
        $this->require_get();
        $this->require_scope('services.read', '/api/v1/services');
        $category = $this->input->get('category', true);
        $q = trim((string)$this->input->get('q', true));
        $limit = $this->clamp_limit();
        $offset = $this->offset($limit);

        $this->db->where('status','ACTIVE');
        if ($category) {
            $cat = $this->db->where('slug', $category)->get('service_categories')->row();
            if ($cat) $this->db->where('category_id', $cat->id);
        }
        if ($q !== '') {
            if (preg_match('/^[0-9a-zA-Z\s\-_.,]{3,}$/', $q)) {
                $this->db->group_start()
                    ->where('MATCH(name, description) AGAINST ('.$this->db->escape($q).' IN NATURAL LANGUAGE MODE)', null, false)
                    ->or_like('name', $q)->group_end();
            } else {
                $this->db->like('name', $q);
            }
        }
        $total = $this->db->count_all_results('services', false);
        $rows = $this->db->order_by('sorting','ASC')->limit($limit, $offset)->get('services')->result();

        $data = array();
        foreach ($rows as $s) {
            $data[] = $this->service_payload($s, $this->pricingservice->price_for($s, $this->user));
        }
        $this->ok($data, $this->meta($total, $limit));
    }

    /** GET /api/v1/services/:public_id */
    public function service_detail($public_id) {
        $this->require_get();
        $this->require_scope('services.read', '/api/v1/services/:id');
        $s = $this->Service_model->find_by_public_id($public_id);
        if (!$s || $s->status !== 'ACTIVE') $this->fail(404, 'SERVICE_NOT_FOUND', 'Service not found');
        $this->ok($this->service_payload($s, $this->pricingservice->price_for($s, $this->user)));
    }

    /* ------------------------------- orders ------------------------------ */

    /** GET /api/v1/orders */
    public function orders() {
        if (strtoupper($this->input->method()) === 'POST') return $this->create_order();
        $this->require_get();
        $this->require_scope('orders.read', '/api/v1/orders');
        $status = $this->input->get('status', true);
        $limit = $this->clamp_limit();
        $offset = $this->offset($limit);
        $rows = $this->Order_model->for_user_with_service($this->user->id, $limit, $offset, $status ?: null);
        $total = $this->Order_model->count_for_user($this->user->id, $status ?: null);
        $data = array_map(array($this, 'order_payload'), $rows);
        $this->ok($data, $this->meta($total, $limit));
    }

    /** POST /api/v1/orders */
    public function create_order() {
        $this->require_post();
        $this->require_scope('orders.write', '/api/v1/orders');
        $body = $this->json_body();
        $payload = array(
            'service'         => $body['service'] ?? null,
            'link'            => $body['link'] ?? null,
            'quantity'        => $body['quantity'] ?? null,
            'fields'          => isset($body['fields']) && is_array($body['fields']) ? $body['fields'] : null,
            'note'            => $body['note'] ?? null,
            'source'          => 'API',
            'idempotency_key' => $this->idem_key($body),
        );
        $res = $this->orderservice->place($this->user, $payload);
        if (empty($res['ok'])) {
            $map = array(
                'NO_SERVICE'=>404,'SERVICE_INACTIVE'=>409,'BAD_QUANTITY'=>422,
                'BAD_LINK'=>422,'BLACKLISTED'=>422,'INSUFFICIENT_BALANCE'=>402,
                'IDEMPOTENCY_CONFLICT'=>409,'CHARGE_FAILED'=>402,
                'SUBMIT_FAILED'=>502,'PERSIST_FAILED'=>500,
            );
            $code = $res['code'] ?? 'ORDER_FAILED';
            $this->fail($map[$code] ?? 400, $code, $res['error'] ?? 'Could not place order');
        }
        $this->ok($this->order_payload($res['order']), null, !empty($res['duplicate']) ? 200 : 201);
    }

    /** POST /api/v1/orders/mass — place up to 100 independent instructions. */
    public function create_mass_order() {
        $this->require_post();
        $this->require_scope('orders.write', '/api/v1/orders/mass');
        $this->load->model('Feature_flag_model');
        if (!$this->Feature_flag_model->enabled('mass_order')) {
            $this->fail(404, 'FEATURE_DISABLED', 'Mass orders are not available.');
        }

        $this->load->library('MassOrderService');
        $body = $this->json_body(MassOrderService::MAX_BYTES);
        if (!isset($body['orders']) || !is_array($body['orders'])) {
            $this->fail(422, 'BAD_REQUEST', 'orders must be an array of instructions.');
        }

        $res = $this->massorderservice->process_instructions(
            $this->user,
            $body['orders'],
            $this->mass_idem_key($body)
        );
        if (empty($res['ok'])) {
            $map = array(
                'EMPTY_BATCH'=>422, 'TOO_MANY_ROWS'=>422, 'PAYLOAD_TOO_LARGE'=>413,
                'BAD_BATCH_PAYLOAD'=>422, 'BAD_BATCH_TOKEN'=>422,
                'BATCH_TOKEN_CONFLICT'=>409, 'BATCH_IN_PROGRESS'=>409,
            );
            $code = $res['code'] ?? 'MASS_ORDER_FAILED';
            $this->fail($map[$code] ?? 400, $code, $res['error'] ?? 'Could not process mass order.');
        }

        $this->ok(array(
            'successful' => $res['successful'],
            'failed' => $res['failed'],
            'successfulCount' => $res['successful_count'],
            'failedCount' => $res['failed_count'],
            'replayed' => !empty($res['replayed']),
        ), null, !empty($res['replayed']) ? 200 : 201);
    }

    /** GET /api/v1/orders/:public_id */
    public function order_detail($public_id) {
        $this->require_get();
        $this->require_scope('orders.read', '/api/v1/orders/:id');
        $o = $this->Order_model->find_public_for_user($public_id, $this->user->id);
        if (!$o) $this->fail(404, 'ORDER_NOT_FOUND', 'Order not found');
        $this->ok($this->order_payload($o, true));
    }

    /** POST /api/v1/orders/status — bulk status lookup */
    public function orders_status() {
        $this->require_post();
        $this->require_scope('orders.read', '/api/v1/orders/status');
        $body = $this->json_body();
        $ids = isset($body['orderIds']) && is_array($body['orderIds']) ? array_slice($body['orderIds'], 0, 100) : array();
        if (!$ids) $this->fail(422, 'BAD_REQUEST', 'Provide an array of orderIds (up to 100).');
        // One query for the batch rather than one per id (Session 18).
        $found = $this->Order_model->find_public_many_for_user($ids, $this->user->id);
        $out = array();
        foreach ($ids as $id) {
            $o = $found[(string)$id] ?? null;
            // Unknown ids still get an explicit null so the response shape
            // matches the request exactly.
            $out[(string)$id] = $o ? array('status'=>$o->status,'charge'=>$o->charge,'currency'=>$o->currency,'remains'=>$o->remains,'start_count'=>$o->start_count) : null;
        }
        $this->ok($out);
    }

    /* ------------------------------- refills ----------------------------- */

    /** POST /api/v1/refills */
    public function refills() {
        $this->require_post();
        $this->require_scope('orders.write', '/api/v1/refills');
        $body = $this->json_body();
        $orderId = $body['orderId'] ?? null;
        if (!$orderId) $this->fail(422, 'BAD_REQUEST', 'orderId is required');
        $res = $this->refillservice->request($orderId, $this->user);
        if (empty($res['ok'])) {
            $map = array('NO_ORDER'=>404,'UNSUPPORTED'=>422,'NOT_REFILLABLE'=>409,'DUPLICATE'=>409);
            $code = $res['code'] ?? 'REFILL_FAILED';
            $this->fail($map[$code] ?? 400, $code, $res['error'] ?? 'Could not request refill');
        }
        $this->ok(array(
            'refill' => $res['refill']->public_id,
            'status' => $res['refill']->status,
        ), null, 201);
    }

    /** GET /api/v1/refills/:public_id */
    public function refill_detail($public_id) {
        $this->require_get();
        $this->require_scope('orders.read', '/api/v1/refills/:id');
        $r = $this->Refill_model->find_public_for_user($public_id, $this->user->id);
        if (!$r) $this->fail(404, 'REFILL_NOT_FOUND', 'Refill not found');
        $this->ok(array(
            'id' => $r->public_id, 'status' => $r->status,
            'requested_at' => gmdate('c', strtotime($r->requested_at)),
            'completed_at' => $r->completed_at ? gmdate('c', strtotime($r->completed_at)) : null,
        ));
    }

    /** POST /api/v1/cancellations */
    public function cancellations() {
        $this->require_post();
        $this->require_scope('orders.write', '/api/v1/cancellations');
        $body = $this->json_body();
        $orderId = $body['orderId'] ?? null;
        if (!$orderId) $this->fail(422, 'BAD_REQUEST', 'orderId is required');
        $res = $this->orderservice->cancel($orderId, $this->user);
        if (empty($res['ok'])) {
            $map = array('NO_ORDER'=>404,'NOT_CANCELLABLE'=>409,'CANCEL_UNSUPPORTED'=>422);
            $code = $res['code'] ?? 'CANCEL_FAILED';
            $this->fail($map[$code] ?? 400, $code, $res['error'] ?? 'Could not cancel order');
        }
        $this->ok(array('order'=>$res['order']->public_id,'status'=>$res['order']->status));
    }

    /* ------------------------------- balance ----------------------------- */

    /** GET /api/v1/balance */
    public function balance() {
        $this->require_get();
        $this->require_scope('account.read', '/api/v1/balance');
        $wallet = $this->db->where('user_id', $this->user->id)->get('wallets')->row();
        $this->ok(array(
            'balance'  => $wallet ? (string)$wallet->balance : '0.00000000',
            'currency' => $wallet ? $wallet->currency : marvy_base_currency(),
        ));
    }

    /* ----------------------------- referrals ----------------------------- */

    /** GET /api/v1/referrals — the key owner's affiliate summary (read-only). */
    public function referrals() {
        $this->require_get();
        $this->require_scope('referrals.read', '/api/v1/referrals');
        $this->load->library('AffiliateService');
        $stats = $this->affiliateservice->stats($this->user, 0);

        $this->ok(array(
            'code'      => $stats['code'],
            'link'      => $stats['link'],
            'percent'   => (string)$stats['percent'],
            'referred'  => (int)$stats['referred'],
            'earned'    => (string)$stats['earned'],
            'pending'   => (string)$stats['pending'],
            'paid'      => (string)$stats['paid'],
            'currency'  => marvy_base_currency(),
        ));
    }

    /* ------------------------------- docs -------------------------------- */

    /** GET /api/docs — human-readable docs */
    public function docs() {
        $this->output->set_content_type('text/html');
        $this->load->view('api/docs');
    }

    /** GET /api/docs/json — machine-readable endpoint list */
    public function docs_json() {
        $this->ok(array(
            'name' => 'MarvySocials Reseller API',
            'version' => 'v1',
            'auth' => 'Send your key as `X-Api-Key: wind_...`.',
            'envelope' => '{ success:bool, data?:mixed, error?:{code,message}, meta?:object, requestId:string }',
            'scopes' => array('services.read','orders.read','orders.write','account.read','referrals.read'),
            'endpoints' => array(
                array('method'=>'GET','path'=>'/api/v1/services','desc'=>'List active services with your price'),
                array('method'=>'GET','path'=>'/api/v1/services/:public_id','desc'=>'Single service'),
                array('method'=>'GET','path'=>'/api/v1/balance','desc'=>'Wallet balance'),
                array('method'=>'POST','path'=>'/api/v1/orders','desc'=>'Place an order (Idempotency-Key supported)'),
                array('method'=>'POST','path'=>'/api/v1/orders/mass','desc'=>'Place up to 100 orders with separate successful and failed rows'),
                array('method'=>'GET','path'=>'/api/v1/orders','desc'=>'List your orders'),
                array('method'=>'GET','path'=>'/api/v1/orders/:public_id','desc'=>'Order status + charge'),
                array('method'=>'POST','path'=>'/api/v1/orders/status','desc'=>'Bulk status: {orderIds:[]}'),
                array('method'=>'POST','path'=>'/api/v1/refills','desc'=>'Request a refill: {orderId}'),
                array('method'=>'GET','path'=>'/api/v1/refills/:public_id','desc'=>'Refill status'),
                array('method'=>'POST','path'=>'/api/v1/cancellations','desc'=>'Cancel an order: {orderId}'),
                array('method'=>'GET','path'=>'/api/v1/referrals','desc'=>'Your referral code, link and commission totals'),
            ),
        ));
    }

    /* ------------------------------ helpers ------------------------------ */

    private function enforce_rate_limit() {
        $per_min = (int)($this->key->rate_limit_per_minute ?? 0);
        if ($per_min <= 0) {
            // Fall back to the declared platform default. The old key
            // ('api_rate_limit_per_minute') was defined in no config file, so
            // it always evaluated NULL and every key without an explicit
            // limit silently got the hardcoded 60. 'rate_limits.api_global'
            // in config/marvy.php is the intended, tunable source.
            $limits = $this->config->item('rate_limits');
            $per_min = max(1, (int)($limits['api_global']['limit'] ?? 60));
        }
        $r = $this->apiratelimiter->check('key:'.$this->key->id, $per_min, 60);
        header('X-RateLimit-Limit: '.$r['limit']);
        header('X-RateLimit-Remaining: '.$r['remaining']);
        if (!$r['allowed']) {
            header('Retry-After: '.$r['retry_after']);
            $this->fail(429, 'RATE_LIMITED', 'Rate limit exceeded. Retry after '.$r['retry_after'].'s.');
        }
    }

    private function service_payload($s, $price) {
        return array(
            'service' => $s->public_id,
            'name' => $s->name,
            'type' => $s->service_type,
            'rate' => (string)$price,
            'min' => (int)$s->min_quantity,
            'max' => (int)$s->max_quantity,
            'step' => (int)($s->increment_step ?: 1),
            'average_time' => $s->average_time,
            'refill' => (bool)$s->refill_supported,
            'cancel' => (bool)$s->cancel_supported,
            'dripfeed' => (bool)$s->dripfeed_supported,
        );
    }

    private function order_payload($o, $with_history = false) {
        $data = array(
            'order' => $o->public_id,
            'service' => isset($o->service_public_id) ? $o->service_public_id : null,
            'status' => $o->status,
            'quantity' => (int)$o->quantity,
            'charge' => (string)$o->charge,
            'currency' => $o->currency,
            'remains' => $o->remains !== null ? (int)$o->remains : null,
            'start_count' => $o->start_count,
            'created_at' => gmdate('c', strtotime($o->created_at)),
        );
        if ($with_history) {
            $rows = $this->Order_status_history_model->for_order($o->id);
            $data['history'] = array_map(function($h){ return array(
                'status' => $h->new_status, 'source' => $h->source,
                'at' => gmdate('c', strtotime($h->created_at)),
                'reason' => $h->reason,
            ); }, $rows);
        }
        return $data;
    }

    private function meta($total, $limit) {
        $page = max(1, (int)$this->input->get('page'));
        return array('page'=>$page,'limit'=>$limit,'total'=>(int)$total,'totalPages'=>(int)ceil($total/max(1,$limit)));
    }

    private function clamp_limit() {
        $l = (int)$this->input->get('limit');
        if ($l <= 0) $l = self::DEFAULT_LIMIT;
        return min(self::MAX_LIMIT, $l);
    }
    private function offset($limit) { return (max(1,(int)$this->input->get('page'))-1)*$limit; }

    private function idem_key($body) {
        $idem = $this->input->get_request_header('Idempotency-Key', true);
        if ($idem) return substr(preg_replace('/[^a-zA-Z0-9._\-]/','', $idem), 0, 100);
        // Fall back to a deterministic key from service+link+quantity so a
        // quick double-click doesn't double-charge.
        return 'api:'.$this->user->id.':'.sha1(($body['service']??'').'|'.($body['link']??'').'|'.($body['quantity']??''));
    }

    private function mass_idem_key($body) {
        $idem = $this->input->get_request_header('Idempotency-Key', true);
        if ($idem) {
            $idem = substr(preg_replace('/[^a-zA-Z0-9._\-]/', '', $idem), 0, 64);
            if ($idem !== '') return 'api.mass.'.$this->user->id.'.'.$idem;
        }
        // Deterministic fallback provides safe exact retries even when a
        // reseller omitted the recommended Idempotency-Key header.
        return 'api.mass.'.$this->user->id.'.'.hash('sha256', json_encode($body['orders'] ?? array()));
    }

    private function json_body($max_bytes = null) {
        $raw = file_get_contents('php://input');
        $this->raw_body = (string)$raw;
        if ($max_bytes !== null && strlen($this->raw_body) > (int)$max_bytes) {
            $this->fail(413, 'PAYLOAD_TOO_LARGE', 'Request body exceeds the allowed size.');
        }
        if ($raw === '') return array();
        $d = json_decode($raw, true);
        if (!is_array($d)) $this->fail(400, 'BAD_JSON', 'Request body must be valid JSON.');
        return $d;
    }

    private function require_get() {
        if (strtoupper($this->input->method()) !== 'GET') $this->fail(405,'METHOD_NOT_ALLOWED','Use GET');
    }

    private function require_post() {
        if (strtoupper($this->input->method()) !== 'POST') $this->fail(405,'METHOD_NOT_ALLOWED','Use POST');
    }

    private function require_scope($scope, $endpoint) {
        $this->usage_endpoint = $endpoint;
        if (!$this->apiauthenticator->allows_scope($this->key, $scope)) {
            $this->fail(403, 'SCOPE_FORBIDDEN', 'This API key does not have the required scope: '.$scope);
        }
    }

    private function is_docs_request() {
        $uri = trim((string)$this->uri->uri_string(), '/');
        return in_array($uri, array('api/docs', 'api/docs.json', 'api/docs/json'), true);
    }

    /** Normalize public IDs so usage summaries remain bounded by route. */
    private function normalized_endpoint() {
        $uri = '/'.trim((string)$this->uri->uri_string(), '/');
        $uri = preg_replace('#^/api/v1/services/[^/]+$#', '/api/v1/services/:id', $uri);
        $uri = preg_replace('#^/api/v1/orders/(?!status$|mass$)[^/]+$#', '/api/v1/orders/:id', $uri);
        $uri = preg_replace('#^/api/v1/refills/[^/]+$#', '/api/v1/refills/:id', $uri);
        return substr($uri, 0, 160);
    }

    /** Usage evidence is best-effort and must never break the API response. */
    private function log_usage($status) {
        if ($this->usage_logged || !$this->key || empty($this->key->id)) return;
        $this->usage_logged = true;
        $duration = (int)round((microtime(true) - $this->started_at) * 1000);
        $duration = max(0, min(16777215, $duration));
        try {
            $this->db->insert('api_usage_logs', array(
                'api_key_id'=>(int)$this->key->id,
                'endpoint'=>substr((string)$this->usage_endpoint, 0, 160),
                'method'=>substr(strtoupper((string)$this->input->method()), 0, 8),
                'ip'=>substr((string)$this->input->ip_address(), 0, 45),
                'status'=>(int)$status,
                'duration_ms'=>$duration,
                'created_at'=>gmdate('Y-m-d H:i:s'),
            ));
        } catch (Throwable $e) {
            log_message('error', 'Unable to record reseller API usage: '.$e->getMessage());
        }
    }

    private function ok($data, $meta = null, $code = 200) {
        $out = array('success'=>true,'data'=>$data,'requestId'=>marvy_request_id());
        if ($meta !== null) $out['meta'] = $meta;
        $this->log_usage($code);
        $this->output->set_status_header($code)->set_output(json_encode($out));
        exit;
    }

    private function fail($http, $code, $message) {
        $this->log_usage($http);
        $this->output->set_status_header($http)->set_output(json_encode(array(
            'success'=>false,
            'error'=>array('code'=>$code,'message'=>$message),
            'requestId'=>marvy_request_id(),
        )));
        exit;
    }
}
