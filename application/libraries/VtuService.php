<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * VtuService — airtime, data, cable, electricity and exam PINs (§9, §23).
 *
 * Deliberately thin. All money handling — charging, refunding on rejection,
 * status history, idempotency — belongs to TransactionEngine, which every
 * other domain will share too. What lives here is only what is specific to
 * VTU: validating a recipient, resolving a price, and translating a purchase
 * into the right adapter call.
 *
 * Pricing rules (§15):
 *   - Fixed-price products (data, cable, exam) use vtu_products.price.
 *   - Airtime is variable-amount: the customer names the amount and pays
 *     face value less discount_percent, so a ₦100 top-up at 2% costs ₦98.
 *   - provider_cost is frozen on the transaction for margin reporting.
 */
class VtuService {

    /** Recipient must be a Nigerian MSISDN in local or international form. */
    const MSISDN_PATTERN = '/^(?:\+?234|0)([789][01]\d{8})$/';

    private static $variable_amount_types = array('AIRTIME', 'ELECTRICITY');

    private $ci;

    public function __construct() {
        $this->ci =& get_instance();
        $this->ci->load->model(array(
            'Vtu_network_model', 'Vtu_product_model', 'Vtu_transaction_model',
            'Service_transaction_model', 'Provider_transaction_model', 'Provider_model',
        ));
        $this->ci->load->library(array('TransactionEngine', 'Provider_manager'));
    }

    /**
     * Buy airtime.
     *
     * @param array $input network (code or id), msisdn, amount, idempotency_key?
     */
    public function airtime($user, array $input) {
        $network = $this->resolve_network($input, 'AIRTIME');
        if (!$network) return $this->err('Unknown network', 'NO_NETWORK');

        $msisdn = $this->normalise_msisdn($input['msisdn'] ?? '');
        if (!$msisdn) return $this->err('Enter a valid phone number', 'BAD_MSISDN');

        // Airtime has one variable-amount product row per network, which
        // carries the bounds and the discount.
        $product = $this->variable_product($network->id, 'AIRTIME');
        if (!$product) return $this->err('Airtime is unavailable for that network', 'NO_PRODUCT');

        $face = $this->money($input['amount'] ?? 0);
        $bounds = $this->check_bounds($face, $product);
        if ($bounds) return $this->err($bounds, 'BAD_AMOUNT');

        $charge = $this->discounted($face, $product->discount_percent);

        return $this->purchase($user, array(
            'service_type' => 'AIRTIME',
            'network'      => $network,
            'product'      => $product,
            'amount'       => $charge,
            'face_value'   => $face,
            'recipient'    => $msisdn,
            'input'        => $input,
            'payload'      => array('msisdn' => $msisdn, 'amount' => $face,
                                    'network_code' => $network->code),
            'call'         => 'airtime',
        ));
    }

    /**
     * Buy a data bundle.
     *
     * @param array $input network, product (code or id), msisdn, idempotency_key?
     */
    public function data($user, array $input) {
        $network = $this->resolve_network($input, 'DATA');
        if (!$network) return $this->err('Unknown network', 'NO_NETWORK');

        $msisdn = $this->normalise_msisdn($input['msisdn'] ?? '');
        if (!$msisdn) return $this->err('Enter a valid phone number', 'BAD_MSISDN');

        $product = $this->resolve_product($input, $network->id, 'DATA');
        if (!$product) return $this->err('That bundle is unavailable', 'NO_PRODUCT');
        if ($product->price === null) return $this->err('That bundle has no price', 'NO_PRICE');

        return $this->purchase($user, array(
            'service_type' => 'DATA',
            'network'      => $network,
            'product'      => $product,
            'amount'       => $this->money($product->price),
            'face_value'   => $product->face_value,
            'recipient'    => $msisdn,
            'input'        => $input,
            'payload'      => array('msisdn' => $msisdn, 'network_code' => $network->code,
                                    'variation_code' => $product->provider_code ?: $product->code),
            'call'         => 'data',
        ));
    }

    /**
     * Pay a cable TV subscription.
     *
     * @param array $input network (DSTV|GOTV|...), product, smartcard
     */
    public function cable($user, array $input) {
        $network = $this->resolve_network($input, 'CABLE');
        if (!$network) return $this->err('Unknown cable provider', 'NO_NETWORK');

        $smartcard = trim((string)($input['smartcard'] ?? ''));
        if (!preg_match('/^\d{8,20}$/', $smartcard)) {
            return $this->err('Enter a valid smartcard number', 'BAD_SMARTCARD');
        }

        $product = $this->resolve_product($input, $network->id, 'CABLE');
        if (!$product) return $this->err('That package is unavailable', 'NO_PRODUCT');
        if ($product->price === null) return $this->err('That package has no price', 'NO_PRICE');

        return $this->purchase($user, array(
            'service_type' => 'CABLE',
            'network'      => $network,
            'product'      => $product,
            'amount'       => $this->money($product->price),
            'face_value'   => $product->face_value,
            'recipient'    => $smartcard,
            'recipient_name' => $input['customer_name'] ?? null,
            'input'        => $input,
            'payload'      => array('smartcard' => $smartcard, 'provider_code' => $network->code,
                                    'variation_code' => $product->provider_code ?: $product->code),
            'call'         => 'cable',
        ));
    }

    /**
     * Buy electricity units.
     *
     * @param array $input network (disco), meter, meter_type PREPAID|POSTPAID, amount
     */
    public function electricity($user, array $input) {
        $network = $this->resolve_network($input, 'ELECTRICITY');
        if (!$network) return $this->err('Unknown distribution company', 'NO_NETWORK');

        $meter = trim((string)($input['meter'] ?? ''));
        if (!preg_match('/^\d{6,20}$/', $meter)) {
            return $this->err('Enter a valid meter number', 'BAD_METER');
        }
        $meter_type = strtoupper($input['meter_type'] ?? 'PREPAID');
        if (!in_array($meter_type, array('PREPAID', 'POSTPAID'), true)) {
            return $this->err('Meter type must be prepaid or postpaid', 'BAD_METER_TYPE');
        }

        $product = $this->variable_product($network->id, 'ELECTRICITY');
        if (!$product) return $this->err('That disco is unavailable', 'NO_PRODUCT');

        $face = $this->money($input['amount'] ?? 0);
        $bounds = $this->check_bounds($face, $product);
        if ($bounds) return $this->err($bounds, 'BAD_AMOUNT');

        return $this->purchase($user, array(
            'service_type' => 'ELECTRICITY',
            'network'      => $network,
            'product'      => $product,
            'amount'       => $this->discounted($face, $product->discount_percent),
            'face_value'   => $face,
            'recipient'    => $meter,
            'recipient_name' => $input['customer_name'] ?? null,
            'extra'        => array('meter_type' => $meter_type),
            'input'        => $input,
            'payload'      => array('meter' => $meter, 'disco_code' => $network->code,
                                    'meter_type' => $meter_type, 'amount' => $face),
            'call'         => 'electricity',
        ));
    }

    /**
     * Buy an exam PIN (JAMB/WAEC/NECO).
     *
     * @param array $input network (exam body), product, quantity?
     */
    public function education($user, array $input) {
        $network = $this->resolve_network($input, 'EXAM_PIN');
        if (!$network) return $this->err('Unknown exam body', 'NO_NETWORK');

        $product = $this->resolve_product($input, $network->id, 'EXAM_PIN');
        if (!$product) return $this->err('That PIN is unavailable', 'NO_PRODUCT');
        if ($product->price === null) return $this->err('That PIN has no price', 'NO_PRICE');

        $qty = (int)($input['quantity'] ?? 1);
        if ($qty < 1 || $qty > 10) return $this->err('Quantity must be between 1 and 10', 'BAD_QUANTITY');

        return $this->purchase($user, array(
            'service_type' => 'EXAM_PIN',
            'network'      => $network,
            'product'      => $product,
            'amount'       => bcmul($this->money($product->price), (string)$qty, 8),
            'face_value'   => $product->face_value,
            'recipient'    => trim((string)($input['phone'] ?? '')) ?: 'N/A',
            'extra'        => array('quantity' => $qty),
            'input'        => $input,
            'payload'      => array('exam_code' => $network->code, 'quantity' => $qty,
                                    'variation_code' => $product->provider_code ?: $product->code),
            'call'         => 'education',
        ));
    }

    /**
     * Resolve a meter or smartcard to a customer name before purchase.
     * Read-only: no wallet involvement, so it does not go through the engine.
     */
    public function verify($input) {
        $type = strtoupper($input['service_type'] ?? 'ELECTRICITY');
        $network = $this->resolve_network($input, $type);
        if (!$network) return $this->err('Unknown provider', 'NO_NETWORK');

        $provider = $this->provider_for($network);
        if (!$provider) return $this->err('No provider configured', 'NO_PROVIDER');

        try {
            $adapter = $this->ci->provider_manager->adapter($provider, Provider_manager::FAMILY_VTU);
            $res = $adapter->verify(array(
                'meter'      => $input['meter'] ?? null,
                'smartcard'  => $input['smartcard'] ?? null,
                'disco_code' => $network->code,
                'meter_type' => strtoupper($input['meter_type'] ?? 'PREPAID'),
            ));
        } catch (Exception $e) {
            log_message('error', 'VTU verify failed: '.$e->getMessage());
            return $this->err('Could not verify that account right now', 'PROVIDER_ERROR');
        }
        if (empty($res['ok'])) {
            return $this->err($res['error'] ?? 'Account not found', 'NOT_FOUND');
        }
        return array('ok' => true, 'name' => $res['name'], 'address' => $res['address'] ?? null);
    }

    /* ------------------------------------------------------------------ */

    /** Everything shared by the five purchase types. */
    private function purchase($user, array $s) {
        $provider = $this->provider_for($s['network'], $s['product']);
        if (!$provider) return $this->err('No provider configured for that service', 'NO_PROVIDER');

        $product = $s['product'];
        $network = $s['network'];
        $call    = $s['call'];
        $payload = $s['payload'];

        // Frozen provider cost, for margin reporting (§15).
        $provider_cost = null;
        if (isset($product->provider_cost) && $product->provider_cost !== null) {
            $provider_cost = $this->money($product->provider_cost);
            if ($s['service_type'] === 'EXAM_PIN' && isset($s['extra']['quantity'])) {
                $provider_cost = bcmul($provider_cost, (string)$s['extra']['quantity'], 8);
            }
        }

        $manager = $this->ci->provider_manager;
        $tx_model = $this->ci->Vtu_transaction_model;
        $provider_log = $this->ci->Provider_transaction_model;

        return $this->ci->transactionengine->execute($user, array(
            'service_domain'  => 'VTU',
            'service_type'    => $s['service_type'],
            'service_id'      => $product->id,
            'provider_id'     => $provider->id,
            'amount'          => $s['amount'],
            'provider_cost'   => $provider_cost,
            'idempotency_key' => $s['input']['idempotency_key'] ?? null,
            'source'          => $s['input']['source'] ?? 'WEB',
            'metadata'        => array('network' => $network->code, 'product' => $product->code),

            // Domain detail row, written before the provider is called so a
            // failed purchase still shows what was attempted.
            'detail' => function ($tx_id) use ($s, $network, $product, $tx_model) {
                $tx_model->create(array(
                    'service_transaction_id' => $tx_id,
                    'network_id'     => $network->id,
                    'product_id'     => $product->id,
                    'service_type'   => $s['service_type'],
                    'recipient'      => $s['recipient'],
                    'recipient_name' => $s['recipient_name'] ?? null,
                    'variation_code' => $product->provider_code ?: $product->code,
                    'face_value'     => $s['face_value'] ?? null,
                    'extra'          => !empty($s['extra']) ? json_encode($s['extra']) : null,
                    'created_at'     => gmdate('Y-m-d H:i:s'),
                ));
            },

            'dispatch' => function ($tx) use ($manager, $provider, $call, $payload,
                                              $tx_model, $provider_log) {
                $payload['reference'] = $tx->public_id;
                $started = microtime(true);
                $adapter = $manager->adapter($provider, Provider_manager::FAMILY_VTU);
                $res = $adapter->$call($payload);
                $latency = (int)round((microtime(true) - $started) * 1000);

                $provider_log->record(array(
                    'provider_id'            => $provider->id,
                    'service_transaction_id' => $tx->id,
                    'action'                 => 'PURCHASE',
                    'provider_reference'     => $res['reference'] ?? null,
                    'status'                 => !empty($res['ok']) ? 'SUCCESS' : 'FAILED',
                    'cost'                   => $res['cost'] ?? null,
                    'latency_ms'             => $latency,
                    'error'                  => $res['error'] ?? null,
                ));

                // Tokens and units arrive with the purchase response.
                if (!empty($res['ok']) && !empty($res['detail'])) {
                    $tx_model->update_for_transaction($tx->id, array_intersect_key(
                        $res['detail'], array('token' => 1, 'units' => 1)));
                }
                return $res;
            },
        ));
    }

    /** The provider that should handle this product. */
    private function provider_for($network, $product = null) {
        if ($product && !empty($product->provider_id)) {
            $p = $this->ci->Provider_model->find_by_id($product->provider_id);
            if ($p && $p->status === 'ACTIVE') return $p;
        }
        $rows = $this->ci->Provider_model->find_by(array('status' => 'ACTIVE'));
        foreach ($rows as $row) {
            if (in_array(strtoupper($row->api_type),
                         Provider_manager::supported_types(Provider_manager::FAMILY_VTU), true)) {
                return $row;
            }
        }
        return null;
    }

    private function resolve_network(array $input, $service_type) {
        $key = $input['network'] ?? $input['network_code'] ?? null;
        if ($key === null || $key === '') return null;
        $row = ctype_digit((string)$key)
            ? $this->ci->Vtu_network_model->find_by_id((int)$key)
            : $this->ci->Vtu_network_model->find_by_code(strtoupper((string)$key));
        if (!$row || !$row->is_active) return null;
        if (strtoupper($row->service_type) !== strtoupper($service_type)) return null;
        return $row;
    }

    private function resolve_product(array $input, $network_id, $service_type) {
        $key = $input['product'] ?? $input['product_code'] ?? null;
        if ($key === null || $key === '') return null;
        $row = ctype_digit((string)$key)
            ? $this->ci->Vtu_product_model->find_active((int)$key)
            : $this->ci->Vtu_product_model->find_by_code($network_id, $service_type, (string)$key);
        if (!$row || !$row->is_active) return null;
        if ((int)$row->network_id !== (int)$network_id) return null;
        return $row;
    }

    /** The single variable-amount row for a network (airtime, electricity). */
    private function variable_product($network_id, $service_type) {
        $rows = $this->ci->Vtu_product_model->active_for($network_id, $service_type);
        return $rows ? $rows[0] : null;
    }

    private function check_bounds($amount, $product) {
        if (bccomp($amount, '0', 8) <= 0) return 'Enter an amount greater than zero';
        if ($product->min_amount !== null
            && bccomp($amount, $this->money($product->min_amount), 8) < 0) {
            return 'Minimum is '.windels_money($product->min_amount);
        }
        if ($product->max_amount !== null
            && bccomp($amount, $this->money($product->max_amount), 8) > 0) {
            return 'Maximum is '.windels_money($product->max_amount);
        }
        return null;
    }

    /** face less discount%, never below zero. */
    private function discounted($face, $discount_percent) {
        $pct = $this->money($discount_percent ?: 0);
        if (bccomp($pct, '0', 8) <= 0) return $face;
        $off = bcdiv(bcmul($face, $pct, 8), '100', 8);
        $net = bcsub($face, $off, 8);
        return bccomp($net, '0', 8) < 0 ? '0.00000000' : $net;
    }

    private function normalise_msisdn($raw) {
        $raw = preg_replace('/[\s\-()]/', '', (string)$raw);
        if (!preg_match(self::MSISDN_PATTERN, $raw, $m)) return null;
        return '0'.$m[1];
    }

    private function money($v) { return number_format((float)$v, 8, '.', ''); }

    private function err($message, $code) {
        return array('ok' => false, 'error' => $message, 'code' => $code);
    }
}
