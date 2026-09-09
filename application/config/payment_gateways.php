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
    'stripe'       => array('class' => 'StripeGateway',       'enabled' => TRUE,  'note' => 'Checkout Sessions. Needs STRIPE_SECRET_KEY + STRIPE_WEBHOOK_SECRET (whsec_…). Endpoint: /webhook/stripe.'),
    'paypal'       => array('class' => 'PaypalGateway',       'enabled' => TRUE,  'note' => 'Orders v2. Needs PAYPAL_CLIENT_ID + PAYPAL_CLIENT_SECRET + PAYPAL_WEBHOOK_ID. Endpoint: /webhook/paypal.'),
    'flutterwave'  => array('class' => 'FlutterwaveGateway',  'enabled' => TRUE,  'note' => 'Standard v3. Needs FLUTTERWAVE_SECRET_KEY + FLUTTERWAVE_SECRET_HASH. Endpoint: /webhook/flutterwave.'),
    'razorpay'     => array('class' => 'RazorpayGateway',     'enabled' => TRUE,  'note' => 'Payment Links. Needs RAZORPAY_KEY_ID + RAZORPAY_KEY_SECRET + RAZORPAY_WEBHOOK_SECRET. Endpoint: /webhook/razorpay.'),
    'paystack'     => array('class' => 'PaystackGateway',     'enabled' => TRUE,  'note' => 'Transaction initialize. Needs PAYSTACK_SECRET_KEY (also signs webhooks, HMAC-SHA512). Endpoint: /webhook/paystack.'),
    'coinpayments' => array('class' => 'CoinpaymentsGateway', 'enabled' => TRUE,  'note' => 'create_transaction. Needs COINPAYMENTS_PUBLIC_KEY + PRIVATE_KEY + MERCHANT_ID + IPN_SECRET. IPN: /webhook/coinpayments.'),
    'blockonomics' => array('class' => 'BlockonomicsGateway', 'enabled' => TRUE,  'note' => 'Non-custodial BTC. API key + callback secret in Settings.'),
    'fundsvera'    => array('class' => 'FundsveraGateway',    'enabled' => TRUE,  'note' => 'Bank transfers / virtual accounts.'),
    'manual'       => array('class' => 'ManualGateway',       'enabled' => TRUE),
);
