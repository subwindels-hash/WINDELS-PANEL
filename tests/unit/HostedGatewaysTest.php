<?php
use PHPUnit\Framework\TestCase;

/**
 * Hosted payment gateways (Paystack, Flutterwave, Stripe, PayPal, Razorpay,
 * CoinPayments).
 *
 * These adapters used to be scaffolds: `initiate()` built a checkout URL out
 * of string concatenation and never called the provider at all, so a customer
 * clicking "Pay" landed on a dead page while the panel recorded a pending
 * deposit. This suite pins the three things that make each adapter real:
 *
 *   1. initiate() calls the documented endpoint and returns the URL the
 *      PROVIDER gave back — never a fabricated one;
 *   2. verify_webhook() implements that provider's signature scheme, refuses a
 *      forged one, and answers null (not false) when no secret is configured,
 *      so an unverifiable event is stored rather than discarded;
 *   3. parse_event() normalises the callback and echoes back OUR reference, so
 *      PaymentService can match the deposit.
 *
 * No network: SecureHttpClient is replaced by a scripted double.
 */
class HostedGatewaysTest extends TestCase
{
    private static $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(dirname(__DIR__));
        if (!defined('BASEPATH')) define('BASEPATH', self::$root.'/system/');
        if (!defined('APPPATH')) define('APPPATH', self::$root.'/application/');
        if (!function_exists('get_instance')) {
            eval('function &get_instance(){ return $GLOBALS["__fake_ci"]; }');
        }
        if (!function_exists('log_message')) eval('function log_message($l,$m){}');
        if (!function_exists('site_url')) {
            eval('function site_url($p=""){ return "https://panel.example/".ltrim($p,"/"); }');
        }
        if (!function_exists('marvy_site_name')) eval('function marvy_site_name(){ return "MarvySocials"; }');
        if (!function_exists('marvy_request_id')) eval('function marvy_request_id(){ return "req-test"; }');

        require_once self::$root.'/application/libraries/GatewayInterface.php';
        require_once self::$root.'/application/libraries/HostedGateway.php';
        foreach (array('Paystack', 'Flutterwave', 'Stripe', 'Paypal', 'Razorpay', 'Coinpayments') as $name) {
            require_once self::$root.'/application/libraries/'.$name.'Gateway.php';
        }
    }

    protected function tearDown(): void
    {
        foreach ($GLOBALS['__hg_env'] ?? array() as $key) putenv($key);
        $GLOBALS['__hg_env'] = array();
    }

    /* ------------------------------ helpers ------------------------------ */

    /** Boot a fake CI with scripted HTTP responses and settings. */
    private function boot(array $settings = array(), array $responses = array())
    {
        $ci = new HgFakeCI($settings, $responses);
        $GLOBALS['__fake_ci'] = $ci;
        return $ci;
    }

    private function transaction()
    {
        return (object)array(
            'id' => 42,
            'public_id' => '01HZTESTPUBLICID0000000000',
            'internal_reference' => 'MVS-01HZTESTPUBLICID0000000000',
            'amount' => '5000.00000000',
            'currency' => 'NGN',
            'user_id' => 7,
        );
    }

    private function user()
    {
        return (object)array(
            'id' => 7, 'public_id' => 'USR-1', 'email' => 'buyer@example.com',
            'username' => 'buyer', 'first_name' => 'Ada', 'last_name' => 'Obi', 'phone' => '08030000000',
        );
    }

    /** Every adapter, so cross-cutting rules can be asserted on all of them. */
    private function adapters()
    {
        return array(
            'paystack'     => new PaystackGateway(),
            'flutterwave'  => new FlutterwaveGateway(),
            'stripe'       => new StripeGateway(),
            'paypal'       => new PaypalGateway(),
            'razorpay'     => new RazorpayGateway(),
            'coinpayments' => new CoinpaymentsGateway(),
        );
    }

    /* --------------------------- cross-cutting --------------------------- */

    public function testNoAdapterFabricatesACheckoutUrl()
    {
        // Nothing configured: every adapter must refuse rather than invent a
        // link. This is the exact bug the scaffolds shipped.
        $this->boot(array());
        foreach ($this->adapters() as $code => $gateway) {
            $res = $gateway->initiate($this->transaction(), $this->user());
            $this->assertFalse((bool)($res['ok'] ?? false), $code.' must refuse when unconfigured');
            $this->assertSame('CONFIG_MISSING', $res['code'], $code.' must say what is missing');
            $this->assertArrayNotHasKey('redirect_url', $res, $code.' must not return a URL it made up');
        }
    }

    public function testEveryAdapterSourceCallsTheProviderRatherThanBuildingALink()
    {
        foreach (array('Paystack', 'Flutterwave', 'Stripe', 'Paypal', 'Razorpay', 'Coinpayments') as $name) {
            $src = file_get_contents(self::$root.'/application/libraries/'.$name.'Gateway.php');
            $this->assertMatchesRegularExpression('~\$this->(post_json|post_form|get_json)\(~', $src,
                $name.'Gateway must call the provider API');
            $this->assertStringNotContainsString('SCAFFOLD', $src, $name.'Gateway is still marked as a scaffold');
            // A hardcoded checkout host is the signature of a fabricated URL.
            $this->assertDoesNotMatchRegularExpression(
                "~'https://(checkout|www)\\.[a-z]+\\.(com|net)/'\\s*\\.~", $src,
                $name.'Gateway must not concatenate a checkout URL');
        }
    }

    public function testUnconfiguredWebhookIsUnverifiableRatherThanRejected()
    {
        $this->boot(array());
        foreach ($this->adapters() as $code => $gateway) {
            // null = "cannot verify": PaymentService stores the event and
            // credits nothing. false would throw a real payment away.
            $this->assertNull($gateway->verify_webhook('{}', array()),
                $code.' with no secret must answer null, not false');
        }
    }

    public function testEveryAdapterIsRoutedByPaymentService()
    {
        $src = file_get_contents(self::$root.'/application/libraries/PaymentService.php');
        foreach (array_keys($this->adapters()) as $code) {
            $this->assertStringContainsString("case '".$code."':", $src, $code.' must be routed by gateway_for_code()');
            $this->assertMatchesRegularExpression("~implemented_gateways\(\)[\s\S]*?'".$code."'~", $src,
                $code.' must be listed as implemented');
        }
    }

    /* ------------------------------ Paystack ----------------------------- */

    public function testPaystackInitiateReturnsTheProvidersAuthorizationUrl()
    {
        $ci = $this->boot(
            array('paystack_secret_key' => 'sk_test_123'),
            array(array('code' => 200, 'body' => array('status' => true, 'data' => array(
                'authorization_url' => 'https://checkout.paystack.com/abc123',
                'access_code' => 'abc123',
            ))))
        );
        $res = (new PaystackGateway())->initiate($this->transaction(), $this->user());

        $this->assertTrue($res['ok']);
        $this->assertSame('https://checkout.paystack.com/abc123', $res['redirect_url']);
        $this->assertSame('MVS-01HZTESTPUBLICID0000000000', $res['provider_tx_id']);

        $call = $ci->securehttpclient->calls[0];
        $this->assertSame('https://api.paystack.co/transaction/initialize', $call['url']);
        $sent = json_decode($call['body'], true);
        $this->assertSame(500000, $sent['amount'], 'NGN must be sent in kobo');
        $this->assertSame('MVS-01HZTESTPUBLICID0000000000', $sent['reference']);
        $this->assertStringContainsString('Bearer sk_test_123', implode(' ', $call['headers']));
    }

    public function testPaystackRefusesACurrencyItCannotSettle()
    {
        $this->boot(array('paystack_secret_key' => 'sk_test_123'));
        $tx = $this->transaction();
        $tx->currency = 'INR';
        $res = (new PaystackGateway())->initiate($tx, $this->user());
        $this->assertSame('CURRENCY_UNSUPPORTED', $res['code']);
    }

    public function testPaystackVerifiesItsSha512Signature()
    {
        $this->boot(array('paystack_secret_key' => 'sk_test_123'));
        $gateway = new PaystackGateway();
        $body = '{"event":"charge.success"}';
        $good = hash_hmac('sha512', $body, 'sk_test_123');

        $this->assertTrue($gateway->verify_webhook($body, array('x-paystack-signature' => $good)),
            'lower-case header (nginx/FPM) must still verify');
        $this->assertTrue($gateway->verify_webhook($body, array('X-Paystack-Signature' => $good)));
        $this->assertFalse($gateway->verify_webhook($body, array('X-Paystack-Signature' => 'forged')));
        $this->assertFalse($gateway->verify_webhook($body, array()));
    }

    public function testPaystackParsesAChargeAndTrustsItsStatusNotItsEventName()
    {
        $this->boot(array('paystack_secret_key' => 'sk'));
        $gateway = new PaystackGateway();

        $paid = $gateway->parse_event(json_encode(array('event' => 'charge.success', 'data' => array(
            'id' => 998877, 'reference' => 'MVS-REF', 'status' => 'success',
            'amount' => 500000, 'currency' => 'NGN',
        ))));
        $this->assertSame('SUCCESS', $paid['status']);
        $this->assertSame('998877', $paid['event_id']);
        $this->assertSame('MVS-REF', $paid['provider_tx_id']);
        $this->assertSame('5000', $paid['amount']);

        $lying = $gateway->parse_event(json_encode(array('event' => 'charge.success', 'data' => array(
            'id' => 1, 'reference' => 'MVS-REF', 'status' => 'failed',
        ))));
        $this->assertSame('FAILED', $lying['status'], 'the charge status decides, not the event name');
    }

    /* ---------------------------- Flutterwave ---------------------------- */

    public function testFlutterwaveInitiateReturnsTheProvidersLink()
    {
        $ci = $this->boot(
            array('flutterwave_secret_key' => 'FLWSECK-test'),
            array(array('code' => 200, 'body' => array('status' => 'success', 'data' => array(
                'link' => 'https://checkout.flutterwave.com/v3/hosted/pay/xyz',
            ))))
        );
        $res = (new FlutterwaveGateway())->initiate($this->transaction(), $this->user());

        $this->assertTrue($res['ok']);
        $this->assertSame('https://checkout.flutterwave.com/v3/hosted/pay/xyz', $res['redirect_url']);
        $sent = json_decode($ci->securehttpclient->calls[0]['body'], true);
        $this->assertSame('MVS-01HZTESTPUBLICID0000000000', $sent['tx_ref']);
        $this->assertSame('5000', $sent['amount'], 'Flutterwave takes the major unit, not kobo');
    }

    public function testFlutterwaveComparesTheSecretHashItWasGiven()
    {
        $this->boot(array('flutterwave_secret_key' => 'k', 'flutterwave_secret_hash' => 'my-hash'));
        $gateway = new FlutterwaveGateway();
        $this->assertTrue($gateway->verify_webhook('{}', array('verif-hash' => 'my-hash')));
        $this->assertFalse($gateway->verify_webhook('{}', array('verif-hash' => 'other')));
        $this->assertFalse($gateway->verify_webhook('{}', array()));
    }

    public function testFlutterwaveParsesBothCallbackShapes()
    {
        $this->boot(array('flutterwave_secret_key' => 'k'));
        $gateway = new FlutterwaveGateway();

        $v3 = $gateway->parse_event(json_encode(array('event' => 'charge.completed', 'data' => array(
            'id' => 12345, 'tx_ref' => 'MVS-REF', 'status' => 'successful', 'amount' => 5000, 'currency' => 'NGN',
        ))));
        $this->assertSame('SUCCESS', $v3['status']);
        $this->assertSame('MVS-REF', $v3['provider_tx_id']);

        $flat = $gateway->parse_event(json_encode(array(
            'id' => 999, 'txRef' => 'MVS-OLD', 'status' => 'failed',
        )));
        $this->assertSame('FAILED', $flat['status']);
        $this->assertSame('MVS-OLD', $flat['provider_tx_id']);
    }

    /* ------------------------------- Stripe ------------------------------ */

    public function testStripeInitiateCreatesACheckoutSession()
    {
        $ci = $this->boot(
            array('stripe_secret_key' => 'sk_test_stripe'),
            array(array('code' => 200, 'body' => array(
                'id' => 'cs_test_1', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_1',
            )))
        );
        $res = (new StripeGateway())->initiate($this->transaction(), $this->user());

        $this->assertTrue($res['ok']);
        $this->assertSame('https://checkout.stripe.com/c/pay/cs_test_1', $res['redirect_url']);
        $call = $ci->securehttpclient->calls[0];
        $this->assertSame('https://api.stripe.com/v1/checkout/sessions', $call['url']);
        parse_str($call['body'], $sent);
        $this->assertSame('500000', (string)$sent['line_items'][0]['price_data']['unit_amount']);
        $this->assertSame('MVS-01HZTESTPUBLICID0000000000', $sent['client_reference_id']);
    }

    public function testStripeSignatureIsCheckedWithATimestampTolerance()
    {
        $this->boot(array('stripe_secret_key' => 'sk', 'stripe_webhook_secret' => 'whsec_test'));
        $gateway = new StripeGateway();
        $body = '{"id":"evt_1","type":"checkout.session.completed"}';

        $now = time();
        $sig = hash_hmac('sha256', $now.'.'.$body, 'whsec_test');
        $this->assertTrue($gateway->verify_webhook($body, array('Stripe-Signature' => "t={$now},v1={$sig}")));

        // A signature that was valid an hour ago must not be replayable.
        $old = $now - 3600;
        $old_sig = hash_hmac('sha256', $old.'.'.$body, 'whsec_test');
        $this->assertFalse($gateway->verify_webhook($body, array('Stripe-Signature' => "t={$old},v1={$old_sig}")),
            'an expired signature must be refused');

        $this->assertFalse($gateway->verify_webhook($body, array('Stripe-Signature' => "t={$now},v1=deadbeef")));
    }

    public function testStripeOnlyCreditsAPaidSession()
    {
        $this->boot(array('stripe_secret_key' => 'sk'));
        $gateway = new StripeGateway();

        $paid = $gateway->parse_event(json_encode(array(
            'id' => 'evt_1', 'type' => 'checkout.session.completed',
            'data' => array('object' => array(
                'client_reference_id' => 'MVS-REF', 'payment_status' => 'paid',
                'amount_total' => 500000, 'currency' => 'ngn',
            )),
        )));
        $this->assertSame('SUCCESS', $paid['status']);
        $this->assertSame('MVS-REF', $paid['provider_tx_id']);
        $this->assertSame('NGN', $paid['currency']);

        $unpaid = $gateway->parse_event(json_encode(array(
            'id' => 'evt_2', 'type' => 'checkout.session.completed',
            'data' => array('object' => array('client_reference_id' => 'MVS-REF', 'payment_status' => 'unpaid')),
        )));
        $this->assertSame('PENDING', $unpaid['status'], 'a completed session that is not paid must not credit');
    }

    /* ------------------------------- PayPal ------------------------------ */

    public function testPaypalInitiateAuthenticatesThenReturnsTheApproveLink()
    {
        $ci = $this->boot(
            array('paypal_client_id' => 'id', 'paypal_client_secret' => 'secret'),
            array(
                array('code' => 200, 'body' => array('access_token' => 'A21AA', 'expires_in' => 32000)),
                array('code' => 201, 'body' => array('id' => '5O190127TN364715T', 'links' => array(
                    array('rel' => 'self', 'href' => 'https://api-m.paypal.com/v2/checkout/orders/5O1'),
                    array('rel' => 'approve', 'href' => 'https://www.paypal.com/checkoutnow?token=5O1'),
                ))),
            )
        );
        $res = (new PaypalGateway())->initiate($this->transaction(), $this->user());

        $this->assertTrue($res['ok']);
        $this->assertSame('https://www.paypal.com/checkoutnow?token=5O1', $res['redirect_url']);
        $this->assertStringContainsString('/v1/oauth2/token', $ci->securehttpclient->calls[0]['url']);
        $this->assertStringContainsString('/v2/checkout/orders', $ci->securehttpclient->calls[1]['url']);
    }

    public function testPaypalWebhookVerificationAsksPaypalAndFailsSoft()
    {
        $headers = array(
            'paypal-transmission-id' => 'tid', 'paypal-transmission-time' => 'now',
            'paypal-transmission-sig' => 'sig', 'paypal-cert-url' => 'https://api.paypal.com/cert',
            'paypal-auth-algo' => 'SHA256withRSA',
        );

        $this->boot(
            array('paypal_client_id' => 'id', 'paypal_client_secret' => 's', 'paypal_webhook_id' => 'WH-1'),
            array(
                array('code' => 200, 'body' => array('access_token' => 'A21AA', 'expires_in' => 300)),
                array('code' => 200, 'body' => array('verification_status' => 'SUCCESS')),
            )
        );
        $this->assertTrue((new PaypalGateway())->verify_webhook('{"id":"WH"}', $headers));

        $this->boot(
            array('paypal_client_id' => 'id', 'paypal_client_secret' => 's', 'paypal_webhook_id' => 'WH-1'),
            array(
                array('code' => 200, 'body' => array('access_token' => 'A21AA', 'expires_in' => 300)),
                array('code' => 200, 'body' => array('verification_status' => 'FAILURE')),
            )
        );
        $this->assertFalse((new PaypalGateway())->verify_webhook('{"id":"WH"}', $headers));

        // PayPal unreachable: unverifiable, not forged.
        $this->boot(
            array('paypal_client_id' => 'id', 'paypal_client_secret' => 's', 'paypal_webhook_id' => 'WH-1'),
            array(array('code' => 0, 'body' => array()))
        );
        $this->assertNull((new PaypalGateway())->verify_webhook('{"id":"WH"}', $headers));

        // Missing PayPal headers is a forged callback, not an outage.
        $this->boot(array('paypal_client_id' => 'id', 'paypal_client_secret' => 's', 'paypal_webhook_id' => 'WH-1'));
        $this->assertFalse((new PaypalGateway())->verify_webhook('{"id":"WH"}', array()));
    }

    public function testPaypalParsesACapture()
    {
        $this->boot(array('paypal_client_id' => 'i', 'paypal_client_secret' => 's'));
        $event = (new PaypalGateway())->parse_event(json_encode(array(
            'id' => 'WH-77', 'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
            'resource' => array('custom_id' => 'MVS-REF', 'status' => 'COMPLETED',
                                'amount' => array('value' => '5000.00', 'currency_code' => 'USD')),
        )));
        $this->assertSame('SUCCESS', $event['status']);
        $this->assertSame('MVS-REF', $event['provider_tx_id']);
        $this->assertSame('USD', $event['currency']);
    }

    /* ------------------------------ Razorpay ----------------------------- */

    public function testRazorpayInitiateReturnsTheShortUrl()
    {
        $ci = $this->boot(
            array('razorpay_key_id' => 'rzp_test', 'razorpay_key_secret' => 'secret'),
            array(array('code' => 200, 'body' => array('id' => 'plink_1', 'short_url' => 'https://rzp.io/i/abc')))
        );
        $res = (new RazorpayGateway())->initiate($this->transaction(), $this->user());

        $this->assertTrue($res['ok']);
        $this->assertSame('https://rzp.io/i/abc', $res['redirect_url']);
        $sent = json_decode($ci->securehttpclient->calls[0]['body'], true);
        $this->assertSame(500000, $sent['amount'], 'Razorpay takes the minor unit');
        $this->assertSame('MVS-01HZTESTPUBLICID0000000000', $sent['reference_id']);
    }

    public function testRazorpayUsesTheWebhookSecretNotTheApiSecret()
    {
        $this->boot(array('razorpay_key_id' => 'k', 'razorpay_key_secret' => 'api-secret',
                          'razorpay_webhook_secret' => 'hook-secret'));
        $gateway = new RazorpayGateway();
        $body = '{"event":"payment.captured"}';

        $this->assertTrue($gateway->verify_webhook($body,
            array('X-Razorpay-Signature' => hash_hmac('sha256', $body, 'hook-secret'))));
        $this->assertFalse($gateway->verify_webhook($body,
            array('X-Razorpay-Signature' => hash_hmac('sha256', $body, 'api-secret'))));
    }

    public function testRazorpayParsesACapturedPayment()
    {
        $this->boot(array('razorpay_key_id' => 'k', 'razorpay_key_secret' => 's'));
        $event = (new RazorpayGateway())->parse_event(json_encode(array(
            'event' => 'payment.captured',
            'payload' => array(
                'payment' => array('entity' => array('id' => 'pay_1', 'status' => 'captured',
                    'amount' => 500000, 'currency' => 'INR',
                    'notes' => array('internal_reference' => 'MVS-REF'))),
            ),
        )));
        $this->assertSame('SUCCESS', $event['status']);
        $this->assertSame('MVS-REF', $event['provider_tx_id']);
        $this->assertSame('5000', $event['amount']);
    }

    /* ---------------------------- CoinPayments --------------------------- */

    public function testCoinpaymentsInitiateSignsTheRequestAndReturnsTheCheckoutUrl()
    {
        $ci = $this->boot(
            array('coinpayments_public_key' => 'pub', 'coinpayments_private_key' => 'priv',
                  'coinpayments_merchant_id' => 'merch'),
            array(array('code' => 200, 'body' => array('error' => 'ok', 'result' => array(
                'txn_id' => 'CPTX1', 'address' => 'bc1qexample', 'amount' => '0.0123',
                'checkout_url' => 'https://www.coinpayments.net/index.php?cmd=checkout&id=CPTX1',
                'confirms_needed' => '2', 'timeout' => 3600,
            ))))
        );
        $res = (new CoinpaymentsGateway())->initiate($this->transaction(), $this->user());

        $this->assertTrue($res['ok']);
        $this->assertStringContainsString('coinpayments.net/index.php?cmd=checkout', $res['redirect_url']);
        $this->assertSame('bc1qexample', $res['checkout']['address']);
        $call = $ci->securehttpclient->calls[0];
        $this->assertStringContainsString('HMAC: ', implode(' ', $call['headers']));
        parse_str($call['body'], $sent);
        $this->assertSame('create_transaction', $sent['cmd']);
        $this->assertSame('MVS-01HZTESTPUBLICID0000000000', $sent['custom']);
    }

    public function testCoinpaymentsSurfacesAProviderLevelError()
    {
        $this->boot(
            array('coinpayments_public_key' => 'pub', 'coinpayments_private_key' => 'priv',
                  'coinpayments_merchant_id' => 'merch'),
            array(array('code' => 200, 'body' => array('error' => 'Amount is too small')))
        );
        $res = (new CoinpaymentsGateway())->initiate($this->transaction(), $this->user());
        $this->assertFalse($res['ok']);
        $this->assertStringContainsString('too small', $res['error']);
    }

    public function testCoinpaymentsIpnVerifiesTheHmacAndTheMerchantId()
    {
        $this->boot(array('coinpayments_public_key' => 'p', 'coinpayments_private_key' => 'k',
                          'coinpayments_merchant_id' => 'merch', 'coinpayments_ipn_secret' => 'ipn'));
        $gateway = new CoinpaymentsGateway();
        $body = http_build_query(array('merchant' => 'merch', 'status' => 100, 'custom' => 'MVS-REF'));

        $this->assertTrue($gateway->verify_webhook($body, array('HMAC' => hash_hmac('sha512', $body, 'ipn'))));
        $this->assertFalse($gateway->verify_webhook($body, array('HMAC' => 'forged')));

        $other = http_build_query(array('merchant' => 'someone-else', 'status' => 100));
        $this->assertFalse($gateway->verify_webhook($other, array('HMAC' => hash_hmac('sha512', $other, 'ipn'))),
            "another merchant's IPN must never credit our wallets");
    }

    public function testCoinpaymentsWaitsForConfirmations()
    {
        $this->boot(array('coinpayments_public_key' => 'p', 'coinpayments_private_key' => 'k',
                          'coinpayments_merchant_id' => 'm', 'coinpayments_ipn_secret' => 'i'));
        $gateway = new CoinpaymentsGateway();

        $seen = $gateway->parse_event(http_build_query(array('status' => 1, 'custom' => 'MVS-REF', 'txn_id' => 'T1')));
        $this->assertSame('PENDING', $seen['status'], 'funds seen but unconfirmed must not credit');

        $done = $gateway->parse_event(http_build_query(array('status' => 100, 'custom' => 'MVS-REF', 'txn_id' => 'T1',
            'amount1' => '5000.00', 'currency1' => 'NGN')));
        $this->assertSame('SUCCESS', $done['status']);
        $this->assertSame('MVS-REF', $done['provider_tx_id']);

        $failed = $gateway->parse_event(http_build_query(array('status' => -1, 'custom' => 'MVS-REF')));
        $this->assertSame('FAILED', $failed['status']);
    }

    /* ------------------------- configuration source ---------------------- */

    public function testEnvironmentBeatsTheSettingsRow()
    {
        $this->boot(array('paystack_secret_key' => 'from-settings'));
        putenv('PAYSTACK_SECRET_KEY=from-env');
        $GLOBALS['__hg_env'] = array('PAYSTACK_SECRET_KEY');

        $cfg = (new PaystackGateway())->config();
        $this->assertSame('from-env', $cfg['secret_key'],
            'a container-injected credential must not be overridden by a database row');
    }

    public function testEveryGatewayCredentialIsManageableFromTheAdminSettings()
    {
        require_once self::$root.'/application/libraries/SettingsService.php';
        $schema = SettingsService::schema();
        foreach (array('paystack_secret_key', 'flutterwave_secret_key', 'flutterwave_secret_hash',
                       'stripe_secret_key', 'stripe_webhook_secret', 'paypal_client_id',
                       'paypal_client_secret', 'paypal_webhook_id', 'razorpay_key_id',
                       'razorpay_key_secret', 'razorpay_webhook_secret', 'coinpayments_public_key',
                       'coinpayments_private_key', 'coinpayments_merchant_id', 'coinpayments_ipn_secret') as $key) {
            $this->assertArrayHasKey($key, $schema, $key.' must be configurable in Admin → Settings');
            $this->assertSame('secret', $schema[$key][0], $key.' must be stored as a secret, never rendered back');
        }
    }
}

/* -------------------------------- doubles -------------------------------- */

#[AllowDynamicProperties]
class HgFakeCI
{
    public $load, $db, $input, $Setting_model, $securehttpclient, $config;

    public function __construct(array $settings, array $responses)
    {
        $this->load = new HgFakeLoader();
        $this->input = new HgFakeInput();
        $this->Setting_model = new HgFakeSettings($settings);
        $this->securehttpclient = new HgFakeHttp($responses);
        $this->config = new HgFakeConfig();
    }
}

class HgFakeLoader
{
    public function model($n = null) {}
    public function library($n = null) {}
}

class HgFakeInput
{
    public function ip_address() { return '127.0.0.1'; }
    public function user_agent() { return 'PHPUnit'; }
}

class HgFakeConfig
{
    public function item($k) { return array(); }
}

class HgFakeSettings
{
    private $values;
    public function __construct(array $values) { $this->values = $values; }
    public function get($key, $default = null)
    {
        if (!array_key_exists($key, $this->values)) return $default;
        $v = $this->values[$key];
        return ($v === null || $v === '') ? $default : $v;
    }
}

/** Scripted HTTP: hands back the queued responses and records what was sent. */
class HgFakeHttp
{
    public $calls = array();
    private $responses;

    public function __construct(array $responses) { $this->responses = $responses; }

    public function get($url, $headers = array(), $options = array())
    {
        return $this->respond('GET', $url, null, $headers);
    }

    public function post($url, $data = null, $headers = array(), $options = array())
    {
        return $this->respond('POST', $url, $data, $headers);
    }

    private function respond($method, $url, $body, $headers)
    {
        $this->calls[] = array('method' => $method, 'url' => $url, 'body' => $body, 'headers' => $headers);
        $next = array_shift($this->responses);
        if ($next === null) {
            return array('http_code' => 0, 'body' => null, 'error' => 'no scripted response');
        }
        return array(
            'http_code' => $next['code'],
            'body'      => json_encode($next['body']),
            'error'     => $next['code'] === 0 ? 'unreachable' : null,
        );
    }
}
