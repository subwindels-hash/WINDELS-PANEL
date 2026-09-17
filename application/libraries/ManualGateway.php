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

    /** A manual transfer can be collected in whichever currency the operator makes default. */
    public function supports_currency($currency) {
        return (bool)preg_match('/^[A-Z]{3}$/', strtoupper(trim((string)$currency)));
    }

    public function initiate($transaction, $user) {
        return array(
            'ok' => true,
            'status' => 'PENDING',
            'checkout' => array(
                'instructions' => $this->method && $this->method->instructions ? $this->method->instructions
                    : 'Transfer the exact amount to the displayed account and include your reference. Funds are credited after admin review.',
                'reference' => $transaction->public_id,
                // Keep the manual instructions self-describing when they are
                // consumed by the JSON API or resumed later. PaymentService
                // guarantees this is the panel default currency.
                'amount' => (string)$transaction->amount,
                'currency' => strtoupper((string)$transaction->currency),
            ),
        );
    }

    public function verify_webhook($raw_body, array $headers) { return false; }
    public function parse_event($raw_body) { return array('event_id' => null, 'type' => 'manual.none'); }
}
