<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * ManualGateway — bank-transfer / manual top-up (the only enabled gateway).
 *
 * initiate() marks the transaction PENDING and returns the bank instructions;
 * an admin confirms it in the back office (Session 15 admin UI) — there are no
 * webhooks for a manual transfer.
 */
class ManualGateway implements GatewayInterface {
    private $method;
    public function __construct($method_row = null) { $this->method = $method_row; }

    public function initiate($transaction, $user) {
        return array(
            'ok' => true,
            'status' => 'PENDING',
            'checkout' => array(
                'instructions' => $this->method && $this->method->instructions ? $this->method->instructions
                    : 'Transfer the exact amount to the displayed account and include your reference. Funds are credited after admin review.',
                'reference' => $transaction->public_id,
            ),
        );
    }

    public function verify_webhook($raw_body, array $headers) { return false; }
    public function parse_event($raw_body) { return array('event_id' => null, 'type' => 'manual.none'); }
}
