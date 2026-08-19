<?php
defined('BASEPATH') OR exit('No direct script access allowed');
/* Gateway registry — no secrets here; secrets via env + EncryptionService */
$config['payment_gateways'] = array(
    'stripe' => array('class' => 'StripeGateway', 'enabled' => TRUE, 'note' => 'Implemented turn 3 — needs live Stripe key + webhook secret validation'),
    'paypal' => array('class' => 'PaypalGateway', 'enabled' => TRUE, 'note' => 'Implemented turn 3 — needs live PayPal client/secret + webhook cert verification'),
    'flutterwave' => array('class' => 'FlutterwaveGateway', 'enabled' => TRUE, 'note' => 'Implemented turn 3 — needs live Flutterwave secret + webhook endpoint validation'),
    'razorpay' => array('class' => 'RazorpayGateway', 'enabled' => TRUE, 'note' => 'Implemented turn 3 — needs live Razorpay key + webhook HMAC validation'),
    'paystack' => array('class' => 'PaystackGateway', 'enabled' => TRUE, 'note' => 'Implemented turn 3 — needs live Paystack key + webhook endpoint validation'),
    'coinpayments' => array('class' => 'CoinpaymentsGateway', 'enabled' => TRUE, 'note' => 'Implemented turn 3 — needs live CoinPayments merchant/IPN secret validation'),
    'manual' => array('class' => 'ManualGateway', 'enabled' => TRUE),
);
