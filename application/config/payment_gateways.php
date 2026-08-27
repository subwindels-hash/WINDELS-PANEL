<?php
defined('BASEPATH') OR exit('No direct script access allowed');
/*
 * Gateway registry — no secrets here; secrets via env + EncryptionService.
 *
 * PaymentService::gateway_for_code() instantiates these adapters. A method
 * still needs an ACTIVE payment_methods row and live credentials before
 * money can move. Missing keys fail closed at initiate()/verify_webhook().
 */
$config['payment_gateways'] = array(
    'stripe'       => array('class' => 'StripeGateway',       'enabled' => TRUE,  'note' => 'Needs STRIPE_SECRET_KEY and STRIPE_WEBHOOK_SECRET.'),
    'paypal'       => array('class' => 'PaypalGateway',       'enabled' => TRUE,  'note' => 'Needs PAYPAL_CLIENT_ID and PAYPAL_SECRET.'),
    'flutterwave'  => array('class' => 'FlutterwaveGateway',  'enabled' => TRUE,  'note' => 'Needs FLUTTERWAVE_SECRET_KEY.'),
    'razorpay'     => array('class' => 'RazorpayGateway',     'enabled' => TRUE,  'note' => 'Needs RAZORPAY_KEY_ID and RAZORPAY_KEY_SECRET.'),
    'paystack'     => array('class' => 'PaystackGateway',     'enabled' => TRUE,  'note' => 'Needs PAYSTACK_SECRET_KEY. Webhook HMAC-SHA512.'),
    'coinpayments' => array('class' => 'CoinpaymentsGateway', 'enabled' => TRUE,  'note' => 'Needs COINPAYMENTS_MERCHANT and IPN secret.'),
    'blockonomics' => array('class' => 'BlockonomicsGateway', 'enabled' => TRUE,  'note' => 'Non-custodial BTC. API key + callback secret in Settings.'),
    'fundsvera'    => array('class' => 'FundsveraGateway',    'enabled' => TRUE,  'note' => 'Bank transfers / virtual accounts.'),
    'manual'       => array('class' => 'ManualGateway',       'enabled' => TRUE),
);
