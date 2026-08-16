<?php
defined('BASEPATH') OR exit('No direct script access allowed');
/* Gateway registry — no secrets here; secrets via env + EncryptionService */
$config['payment_gateways'] = array(
    'stripe' => array('class' => 'StripeGateway', 'enabled' => FALSE),
    'paypal' => array('class' => 'PaypalGateway', 'enabled' => FALSE),
    'flutterwave' => array('class' => 'FlutterwaveGateway', 'enabled' => FALSE),
    'razorpay' => array('class' => 'RazorpayGateway', 'enabled' => FALSE),
    'paystack' => array('class' => 'PaystackGateway', 'enabled' => FALSE),
    'coinpayments' => array('class' => 'CoinpaymentsGateway', 'enabled' => FALSE),
    'manual' => array('class' => 'ManualGateway', 'enabled' => TRUE),
);
