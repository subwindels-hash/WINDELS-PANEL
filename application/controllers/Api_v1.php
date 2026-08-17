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

    public function __construct() {
        parent::__construct();
        $this->load->library(array('ApiAuthenticator','ApiRateLimiter'));
        $this->load->model(array(
            'Service_model','Order_model','Order_status_history_model','Refill_model',
        ));
        $this->load->library(array('PricingService','OrderService','RefillService'));
        $this->output->set_content_type('application/json');

        $this->key = $this->apiauthenticator->authenticate();
        if (!$this->key) {
            $err = $this->apiauthenticator->last_error();
            $this->fail($err['http'], $err['code'], $err['message']);
        }
        $this->user = $this->key->user;
        $this->enforce_rate_limit();
    }

    /* ------------------------------ services ----------------------------- */

    /** GET /api/v1/services */
    public function services() {
        $this->require_get();
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
        $s = $this->Service_model->find_by_public_id($public_id);
        if (!$s || $s->status !== 'ACTIVE') $this->fail(404, 'SERVICE_NOT_FOUND', 'Service not found');
        $this->ok($this->service_payload($s, $this->pricingservice->price_for($s, $this->user)));
    }

    /* ------------------------------- orders ------------------------------ */

    /** GET /api/v1/orders */
    public function orders() {
        $this->require_get();
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
                'CHARGE_FAILED'=>402,'SUBMIT_FAILED'=>502,'PERSIST_FAILED'=>500,
            );
            $code = $res['code'] ?? 'ORDER_FAILED';
            $this->fail($map[$code] ?? 400, $code, $res['error'] ?? 'Could not place order');
        }
        $this->ok($this->order_payload($res['order']), null, !empty($res['duplicate']) ? 200 : 201);
    }

    /** GET /api/v1/orders/:public_id */
    public function order_detail($public_id) {
        $this->require_get();
        $o = $this->Order_model->find_public_for_user($public_id, $this->user->id);
        if (!$o) $this->fail(404, 'ORDER_NOT_FOUND', 'Order not found');
        $this->ok($this->order_payload($o, true));
    }

    /** POST /api/v1/orders/status — bulk status lookup */
    public function orders_status() {
        $body = $this->json_body();
        $ids = isset($body['orderIds']) && is_array($body['orderIds']) ? array_slice($body['orderIds'], 0, 100) : array();
        if (!$ids) $this->fail(422, 'BAD_REQUEST', 'Provide an array of orderIds (up to 100).');
        $out = array();
        foreach ($ids as $id) {
            $o = $this->Order_model->find_public_for_user((string)$id, $this->user->id);
            $out[(string)$id] = $o ? array('status'=>$o->status,'charge'=>$o->charge,'currency'=>$o->currency,'remains'=>$o->remains,'start_count'=>$o->start_count) : null;
        }
        $this->ok($out);
    }

    /* ------------------------------- refills ----------------------------- */

    /** POST /api/v1/refills */
    public function refills() {
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
        $wallet = $this->db->where('user_id', $this->user->id)->get('wallets')->row();
        $this->ok(array(
            'balance'  => $wallet ? (string)$wallet->balance : '0.00000000',
            'currency' => $wallet ? $wallet->currency : 'USD',
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
            'name' => 'WINDELS PANEL Reseller API',
            'version' => 'v1',
            'auth' => 'Send your key as `X-Api-Key: wind_...`.',
            'envelope' => '{ success:bool, data?:mixed, error?:{code,message}, meta?:object, requestId:string }',
            'endpoints' => array(
                array('method'=>'GET','path'=>'/api/v1/services','desc'=>'List active services with your price'),
                array('method'=>'GET','path'=>'/api/v1/services/:public_id','desc'=>'Single service'),
                array('method'=>'GET','path'=>'/api/v1/balance','desc'=>'Wallet balance'),
                array('method'=>'POST','path'=>'/api/v1/orders','desc'=>'Place an order (Idempotency-Key supported)'),
                array('method'=>'GET','path'=>'/api/v1/orders','desc'=>'List your orders'),
                array('method'=>'GET','path'=>'/api/v1/orders/:public_id','desc'=>'Order status + charge'),
                array('method'=>'POST','path'=>'/api/v1/orders/status','desc'=>'Bulk status: {orderIds:[]}'),
                array('method'=>'POST','path'=>'/api/v1/refills','desc'=>'Request a refill: {orderId}'),
                array('method'=>'GET','path'=>'/api/v1/refills/:public_id','desc'=>'Refill status'),
                array('method'=>'POST','path'=>'/api/v1/cancellations','desc'=>'Cancel an order: {orderId}'),
            ),
        ));
    }

    /* ------------------------------ helpers ------------------------------ */

    private function enforce_rate_limit() {
        $per_min = (int)($this->key->rate_limit_per_minute ?? 0);
        if ($per_min <= 0) $per_min = (int)($this->config->item('api_rate_limit_per_minute') ?? 60);
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

    private function json_body() {
        $raw = file_get_contents('php://input');
        if ($raw === '') return array();
        $d = json_decode($raw, true);
        if (!is_array($d)) $this->fail(400, 'BAD_JSON', 'Request body must be valid JSON.');
        return $d;
    }

    private function require_get() {
        if (strtoupper($this->input->method()) !== 'get') $this->fail(405,'METHOD_NOT_ALLOWED','Use GET');
    }

    private function ok($data, $meta = null, $code = 200) {
        $out = array('success'=>true,'data'=>$data,'requestId'=>windels_request_id());
        if ($meta !== null) $out['meta'] = $meta;
        $this->output->set_status_header($code)->set_output(json_encode($out));
        exit;
    }

    private function fail($http, $code, $message) {
        $this->output->set_status_header($http)->set_output(json_encode(array(
            'success'=>false,
            'error'=>array('code'=>$code,'message'=>$message),
            'requestId'=>windels_request_id(),
        )));
        exit;
    }
}
