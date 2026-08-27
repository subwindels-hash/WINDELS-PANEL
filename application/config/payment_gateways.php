<?php
defined('BASEPATH') OR exit('No direct script access allowed');
/*
 * Gateway registry — no secrets here; secrets via env + EncryptionService.
 *
 * HONEST STATUS: `manual` and `blockonomics` are wired into PaymentService.
 * PaymentService::gateway_for_code() routes those two to their adapters and
 * everything else to ManualGateway (fail-safe: funds move only after admin
 * review), and
 * PaymentService::record_webhook() verifies non-manual callbacks with the
 * fail-closed generic HMAC envelope (no secret configured => event is stored
 * but money NEVER moves).
 *
 * The other adapter classes below are integration scaffolds: their initiate()
 * payloads and webhook verification have NOT been exercised against the live
 * provider APIs and PaymentService does not instantiate them. They stay
 * enabled=FALSE until each one is completed, wired in and verified with real
 * sandbox credentials. The seeded payment_methods rows for these gateways are
 * likewise inactive by default.
 */
$config['payment_gateways'] = array(
    'stripe'       => array('class' => 'StripeGateway',       'enabled' => FALSE, 'note' => 'Scaffold — NOT wired into PaymentService; untested against live API'),
    'paypal'       => array('class' => 'PaypalGateway',       'enabled' => FALSE, 'note' => 'Scaffold — NOT wired into PaymentService; untested against live API'),
    'flutterwave'  => array('class' => 'FlutterwaveGateway',  'enabled' => FALSE, 'note' => 'Scaffold — NOT wired into PaymentService; untested against live API'),
    'razorpay'     => array('class' => 'RazorpayGateway',     'enabled' => FALSE, 'note' => 'Scaffold — NOT wired into PaymentService; untested against live API'),
    'paystack'     => array('class' => 'PaystackGateway',     'enabled' => FALSE, 'note' => 'Scaffold — NOT wired into PaymentService; untested against live API'),
    'coinpayments' => array('class' => 'CoinpaymentsGateway', 'enabled' => FALSE, 'note' => 'Scaffold — NOT wired into PaymentService; untested against live API'),
    'blockonomics' => array('class' => 'BlockonomicsGateway', 'enabled' => TRUE,  'note' => 'Non-custodial BTC via Blockonomics; wired into PaymentService. Needs an API key + callback secret (Admin -> Settings -> Payments).'),
    'manual'       => array('class' => 'ManualGateway',       'enabled' => TRUE),
);
