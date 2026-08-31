<?php
use PHPUnit\Framework\TestCase;

/**
 * Payments tests (Session 11) — deposit initialization, fee/bonus math,
 * idempotent confirmation and webhook reconciliation. No DB or network.
 */
class PaymentsTest extends TestCase
{
    private static $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(dirname(__DIR__));
        if (!defined('BASEPATH')) define('BASEPATH', self::$root.'/system/');
        // CI models reach the query builder through CI_Model's magic __get;
        // the double sets ->db directly, so the stub declares the property
        // rather than creating it dynamically (deprecated in PHP 8.2).
        if (!class_exists('CI_Model')) eval('#[AllowDynamicProperties] class CI_Model { public $db; }');
        if (!function_exists('get_instance')) {
            eval('function &get_instance(){ return $GLOBALS["__fake_ci"]; }');
        }
        if (!function_exists('log_message')) eval('function log_message($l,$m){}');
        if (!function_exists('marvy_public_id')) require_once self::$root.'/application/helpers/marvy_helper.php';
        // The Fundsvera gateway builds its redirect URL with site_url(); the
        // suite runs without a booted CI config, so a fixed stub stands in.
        if (!function_exists('site_url')) eval('function site_url($p=""){ return "http://panel.test/".$p; }');
        require_once self::$root.'/application/libraries/GatewayInterface.php';
        require_once self::$root.'/application/libraries/HostedGateway.php';
        require_once self::$root.'/application/libraries/ManualGateway.php';
        require_once self::$root.'/application/libraries/PricingService.php';
        require_once self::$root.'/application/libraries/LedgerService.php';
        require_once self::$root.'/application/libraries/EncryptionService.php';
        require_once self::$root.'/application/libraries/PaymentService.php';
    }

    /* ----------------------------- fees ----------------------------- */

    public function testFeeAndBonusMath()
    {
        $ci = $this->fresh();
        $svc = new PaymentService();
        $method = (object)array('fee_percent'=>'2.5','fee_fixed'=>'0.30','bonus_percent'=>'5.0');
        // 2.5% of 100 = 2.50 + 0.30 = 2.80
        $this->assertSame('2.80000000', $svc->calculate_fee($method, '100.00'));
        // 5% bonus of 100 = 5.00
        $this->assertSame('5.00000000', $svc->calculate_bonus($method, '100.00'));
    }

    public function testZeroFeeWhenNoSurcharge()
    {
        $this->fresh();
        $svc = new PaymentService();
        $m = (object)array('fee_percent'=>'0','fee_fixed'=>'0','bonus_percent'=>'0');
        $this->assertSame('0.00000000', $svc->calculate_fee($m, '10'));
        $this->assertSame('0.00000000', $svc->calculate_bonus($m, '10'));
    }

    /* --------------------------- deposit ---------------------------- */

    public function testDepositRejectsUnknownOrInactiveMethod()
    {
        $ci = $this->fresh();
        $svc = new PaymentService();
        $res = $svc->deposit($ci->user, array('payment_method'=>'nope','amount'=>10));
        $this->assertFalse($res['ok']); $this->assertSame('NO_METHOD', $res['code']);

        $ci->method->is_active = 0;
        $res = $svc->deposit($ci->user, array('payment_method'=>'manual','amount'=>10));
        $this->assertFalse($res['ok']); $this->assertSame('METHOD_INACTIVE', $res['code']);
    }

    public function testDepositValidatesAmountAndBounds()
    {
        $ci = $this->fresh();
        $svc = new PaymentService();
        $res = $svc->deposit($ci->user, array('payment_method'=>'manual','amount'=>-5));
        $this->assertFalse($res['ok']); $this->assertSame('BAD_AMOUNT', $res['code']);
        $res = $svc->deposit($ci->user, array('payment_method'=>'manual','amount'=>0.5));
        $this->assertFalse($res['ok']); $this->assertSame('AMOUNT_TOO_LOW', $res['code']);
        $res = $svc->deposit($ci->user, array('payment_method'=>'manual','amount'=>999999));
        $this->assertFalse($res['ok']); $this->assertSame('AMOUNT_TOO_HIGH', $res['code']);
    }

    public function testDepositCreatesPendingTransactionForManualGateway()
    {
        $ci = $this->fresh();
        $svc = new PaymentService();
        $res = $svc->deposit($ci->user, array('payment_method'=>'manual','amount'=>100,'currency'=>'NGN'));
        $this->assertTrue($res['ok'], $res['error'] ?? '');
        $tx = $res['transaction'];
        $this->assertSame('PENDING', $tx->status);
        $this->assertSame('100.00000000', $tx->amount);
        $this->assertSame('97.20000000', $tx->credited_amount); // 100 - 2.80 + 0
        $this->assertSame('2.80000000', $tx->fee);
        $this->assertSame(1, $ci->inserts['payment_transactions']);
        $this->assertSame(2, $ci->inserts['payment_events']); // CREATED->PENDING
        $this->assertNull($res['redirect_url']);
        $this->assertIsArray($res['checkout']);
    }

    public function testDepositIsIdempotent()
    {
        $ci = $this->fresh();
        $svc = new PaymentService();
        $payload = array('payment_method'=>'manual','amount'=>50,'idempotency_key'=>'dep-1');
        $first = $svc->deposit($ci->user, $payload);
        $this->assertTrue($first['ok']);
        $second = $svc->deposit($ci->user, $payload);
        $this->assertTrue($second['ok']);
        $this->assertTrue(!empty($second['duplicate']));
        $this->assertSame(1, $ci->inserts['payment_transactions']);
    }

    /* --------------------------- confirm ---------------------------- */

    public function testConfirmCreditsWalletOnce()
    {
        $ci = $this->fresh();
        $svc = new PaymentService();
        $dep = $svc->deposit($ci->user, array('payment_method'=>'manual','amount'=>100));
        $tx = $dep['transaction'];

        $res = $svc->confirm($tx, 'ADMIN');
        $this->assertTrue($res['ok']);
        $this->assertSame('SUCCESS', $res['transaction']->status);
        $this->assertSame(1, $ci->ledger_credits);
        $this->assertNotNull($res['transaction']->wallet_transaction_id);
        $this->assertSame('97.20000000', $res['transaction']->credited_amount);

        // Re-confirm is a no-op (idempotent).
        $again = $svc->confirm($res['transaction'], 'ADMIN');
        $this->assertTrue($again['ok']);
        $this->assertSame(1, $ci->ledger_credits, 'must not double-credit');
    }

    public function testConfirmRejectsNonPendingStates()
    {
        $ci = $this->fresh();
        $svc = new PaymentService();
        $tx = (object)array('id'=>5,'public_id'=>'X','user_id'=>7,'status'=>'FAILED','credited_amount'=>'10','amount'=>'10','idempotency_key'=>null);
        $res = $svc->confirm($tx, 'ADMIN');
        $this->assertFalse($res['ok']);
        $this->assertSame('BAD_STATE', $res['code']);
    }

    /* --------------------------- webhooks --------------------------- */

    public function testWebhookIsIdempotentByEventId()
    {
        $ci = $this->fresh();
        $ci->webhook_sig = true;
        $svc = new PaymentService();
        $body = json_encode(array('id'=>'evt_1','status'=>'success','reference'=>null));
        $sig  = hash_hmac('sha256', $body, 'test-webhook-secret');
        $first = $svc->record_webhook('acme', $body, array('x-signature'=>$sig));
        $this->assertTrue($first['ok']);
        $second = $svc->record_webhook('acme', $body, array('x-signature'=>$sig));
        $this->assertTrue($second['ok']);
        $this->assertTrue(!empty($second['already_seen']));
        $this->assertSame(1, $ci->inserts['payment_webhooks']);
    }

    public function testWebhookWithInvalidSignatureIsRejected()
    {
        $ci = $this->fresh();
        $ci->webhook_sig = false;
        $svc = new PaymentService();
        $res = $svc->record_webhook('acme', '{}', array());
        $this->assertFalse($res['ok']);
        $this->assertSame('Invalid signature', $res['error']);
    }

    public function testWebhookProcessingFailureIsRetryableNotSwallowed()
    {
        $ci = $this->fresh();
        $ci->webhook_sig = true;
        $svc = new PaymentService();
        // The event matches one of our transactions, so confirm() runs...
        $ci->Payment_transaction_model->seed_idem('k-retry', $ci->tx);
        $body = json_encode(array(
            'id' => 'evt_retry', 'status' => 'success',
            'metadata' => array('idempotency_key' => 'k-retry'),
        ));
        $sig = hash_hmac('sha256', $body, 'test-webhook-secret');

        // ...but the ledger momentarily fails. The event must NOT be marked
        // processed and the answer must say retryable, so the gateway (and
        // the controller's 503) keeps a failed credit from being lost.
        $ci->ledger_should_fail = true;
        $first = $svc->record_webhook('acme', $body, array('x-signature' => $sig));
        $this->assertFalse($first['ok']);
        $this->assertTrue(!empty($first['retryable']), 'transient failure must be flagged retryable');
        $this->assertSame(0, $ci->ledger_credits, 'a failed credit must not move money');

        // The gateway retries the SAME event id: it reprocesses rather than
        // returning as a duplicate, and credits exactly once.
        $ci->ledger_should_fail = false;
        $second = $svc->record_webhook('acme', $body, array('x-signature' => $sig));
        $this->assertTrue($second['ok'], json_encode($second));
        $this->assertSame(1, $ci->ledger_credits);

        // And a third delivery is now a true duplicate — no double credit.
        $third = $svc->record_webhook('acme', $body, array('x-signature' => $sig));
        $this->assertTrue(!empty($third['already_seen']));
        $this->assertSame(1, $ci->ledger_credits);

        // The controller maps the retryable flag to 503, not the old
        // swallowed 200.
        $wh = file_get_contents(self::$root.'/application/controllers/Webhooks.php');
        $this->assertStringContainsString("respond(503", $wh);
        $this->assertStringContainsString("'retryable'", $wh);
    }

    /**
     * A refused delivery must never be mistaken for a handled one.
     *
     * Gateway event ids are guessable (Paystack's are sequential integers).
     * If a row stored as "invalid signature" counted as a duplicate, anyone
     * could POST a junk-signed callback carrying the id the real payment will
     * use, and the genuine, correctly signed delivery would be dropped as a
     * duplicate — the customer pays and is never credited.
     */
    public function testARefusedWebhookDoesNotBlockTheGenuineOne()
    {
        require_once self::$root.'/application/core/MY_Model.php';
        require_once self::$root.'/application/models/Payment_webhook_model.php';

        $db = new PayWhFakeDb();
        $model = new Payment_webhook_model();
        $model->db = $db;

        // 1. A forged delivery: stored, refused, closed.
        $id = $model->record_once('paystack', 'evt-1', '{}', false, 'charge_success');
        $this->assertSame(1, $id);
        $db->rows[1]['processed'] = 1;
        $db->rows[1]['error'] = 'invalid signature';

        // 2. The genuine delivery of the SAME id, correctly signed.
        $reopened = $model->record_once('paystack', 'evt-1', '{"real":true}', true, 'charge_success');
        $this->assertSame(1, $reopened, 'the refused row must be reopened, not treated as a duplicate');
        $this->assertSame(0, (int)$db->rows[1]['processed'], 'it must be processable again');
        $this->assertSame(1, (int)$db->rows[1]['signature_valid']);
        $this->assertNull($db->rows[1]['error']);

        // 3. A genuine duplicate of a VERIFIED, processed event still no-ops.
        $db->rows[1]['processed'] = 1;
        $this->assertFalse($model->record_once('paystack', 'evt-1', '{"real":true}', true, 'charge_success'),
            'a replay of an already-credited event must stay idempotent');
    }

    /* --------------------------- fundsvera -------------------------- */

    /**
     * The webhook success vocabulary must be broad and case-insensitive.
     *
     * Matching only the exact string 'SUCCESSFUL' was the second half of the
     * stuck-Processing bug: a signed webhook carrying "SUCCESS" (or "success",
     * or "COMPLETED") verified, matched the deposit, and was then thrown away
     * as "still pending" — the customer had paid and nothing would ever
     * credit.
     */
    public function testFundsveraWebhookStatusesAreRecognisedWhateverTheCasing()
    {
        $ci = $this->fresh();
        $ci->Fundsvera_checkout_model = new PayFakeCheckoutModel();
        $gateway = $this->fundsvera_gateway();

        foreach (array('SUCCESSFUL', 'success', 'COMPLETED', 'Paid', 'SETTLED', 'approved') as $raw) {
            $event = $gateway->parse_event($this->fundsvera_body(array('transaction_status' => $raw)));
            $this->assertSame('SUCCESS', $event['status'],
                'a webhook reporting "'.$raw.'" is a completed payment');
        }
        foreach (array('FAILED', 'failed', 'Reversed', 'refunded', 'CANCELLED') as $raw) {
            $event = $gateway->parse_event($this->fundsvera_body(array('transaction_status' => $raw)));
            $this->assertSame('FAILED', $event['status'],
                'a webhook reporting "'.$raw.'" must not wait for ever');
        }
        foreach (array('PENDING', '', 'something-unexpected') as $raw) {
            $event = $gateway->parse_event($this->fundsvera_body(array('transaction_status' => $raw)));
            $this->assertSame('PENDING', $event['status']);
        }
    }

    /** `status` is the shorthand spelling their payloads sometimes use. */
    public function testFundsveraWebhookFallsBackToTheStatusField()
    {
        $ci = $this->fresh();
        $ci->Fundsvera_checkout_model = new PayFakeCheckoutModel();
        $event = $this->fundsvera_gateway()->parse_event($this->fundsvera_body(array(
            'transaction_status' => null,
            'status'             => 'SUCCESSFUL',
        )));
        $this->assertSame('SUCCESS', $event['status']);
    }

    /**
     * The whole path that used to strand the customer: a signed Fundsvera
     * webhook arrives for a PENDING deposit, matches its checkout row, and
     * must credit the wallet exactly once — with a replay crediting nothing.
     */
    public function testFundsveraWebhookCreditsTheDepositExactlyOnce()
    {
        $ci = $this->fresh();
        $checkout = new PayFakeCheckoutModel(); // by_request_id resolves to tx id 42
        $ci->Fundsvera_checkout_model = $checkout;

        putenv('FUNDSVERA_WEBHOOK_SECRET=fv-webhook-secret');
        try {
            $svc = new PaymentService();
            $body = $this->fundsvera_body(array(
                'trx_ref'            => 'FVTRX0001',
                'request_id'         => 'MVS-PAY00000000000000001',
                'transaction_status' => 'SUCCESSFUL',
                'amount_paid'        => '1000',
            ));
            $sig = hash_hmac('sha256', $body, 'fv-webhook-secret');

            $first = $svc->record_webhook('fundsvera', $body, array('X-FUNDSVERA-SIGNATURE' => $sig));
            $this->assertTrue($first['ok'], json_encode($first));
            $this->assertSame(1, $ci->ledger_credits, 'the paid deposit must credit once');
            $this->assertSame('SUCCESS', $ci->tx->status);

            // A redelivery of the same event is a duplicate, not a second credit.
            $second = $svc->record_webhook('fundsvera', $body, array('X-FUNDSVERA-SIGNATURE' => $sig));
            $this->assertTrue(!empty($second['already_seen']));
            $this->assertSame(1, $ci->ledger_credits);

            // The checkout row closed as PAID with the provider's reference.
            $this->assertSame('PAID', $checkout->results[0]['status']);
            $this->assertSame('FVTRX0001', $checkout->results[0]['trx_ref']);
        } finally {
            putenv('FUNDSVERA_WEBHOOK_SECRET');
        }
    }

    /** A failed-payment webhook must not credit, and must close the checkout. */
    public function testFundsveraFailedWebhookClosesTheCheckoutWithoutCrediting()
    {
        $ci = $this->fresh();
        $checkout = new PayFakeCheckoutModel();
        $ci->Fundsvera_checkout_model = $checkout;

        $event = $this->fundsvera_gateway()->parse_event($this->fundsvera_body(array(
            'trx_ref'            => 'FVTRX0002',
            'request_id'         => 'MVS-PAY00000000000000001',
            'transaction_status' => 'FAILED',
        )));

        $this->assertSame('FAILED', $event['status']);
        $this->assertSame(0, $ci->ledger_credits);
        $this->assertSame('FAILED', $checkout->results[0]['status']);
    }

    /**
     * Reconciliation's server-side safety net: when the webhook never
     * arrives, verify() asks the provider what happened.
     */
    public function testFundsveraVerifyReadsTheProviderStatus()
    {
        putenv('FUNDSVERA_PUBLIC_KEY=pk-test');
        putenv('FUNDSVERA_SECRET_KEY=sk-test');
        putenv('FUNDSVERA_ENABLED=1');
        try {
            $http = new PayFakeHttp(array(
                array('http_code' => 200, 'body' => json_encode(array(
                    'request_id'         => 'MVS-PAY00000000000000001',
                    'trx_ref'            => 'FVTRX0009',
                    'transaction_status' => 'SUCCESSFUL',
                    'amount_paid'        => '1000',
                )), 'request_id' => 'r'),
            ));
            $gateway = new FundsveraGateway(null, $http);
            $res = $gateway->verify('MVS-PAY00000000000000001');

            $this->assertTrue($res['ok']);
            $this->assertSame('SUCCESS', $res['status']);
            $this->assertSame('1000', $res['amount']);
            $this->assertSame('FVTRX0009', $res['provider_tx_id']);
            $this->assertStringContainsString('/transaction/MVS-PAY00000000000000001', $http->calls[0]['url']);
            $this->assertSame('GET', $http->calls[0]['method']);
        } finally {
            putenv('FUNDSVERA_PUBLIC_KEY'); putenv('FUNDSVERA_SECRET_KEY'); putenv('FUNDSVERA_ENABLED');
        }
    }

    /** Unknown-but-configured references read as still pending, not as errors. */
    public function testFundsveraVerifyTreatsAnUnknownReferenceAsStillPending()
    {
        putenv('FUNDSVERA_PUBLIC_KEY=pk-test');
        putenv('FUNDSVERA_SECRET_KEY=sk-test');
        try {
            $http = new PayFakeHttp(array(
                array('http_code' => 404, 'body' => '', 'request_id' => 'r'),
                array('http_code' => 404, 'body' => '', 'request_id' => 'r'),
            ));
            $res = (new FundsveraGateway(null, $http))->verify('MVS-UNKNOWN0000000001');

            $this->assertTrue($res['ok']);
            $this->assertSame('PENDING', $res['status'],
                'an unknown reference is an unconfirmed payment, not an outage');
        } finally {
            putenv('FUNDSVERA_PUBLIC_KEY'); putenv('FUNDSVERA_SECRET_KEY');
        }
    }

    /** A provider transport failure must read as unreachable, not pending. */
    public function testFundsveraVerifyReportsTransportFailureAsUnreachable()
    {
        putenv('FUNDSVERA_PUBLIC_KEY=pk-test');
        putenv('FUNDSVERA_SECRET_KEY=sk-test');
        try {
            $http = new PayFakeHttp(array(
                array('http_code' => 0, 'body' => null, 'error' => 'connection timed out', 'request_id' => 'r'),
            ));
            $res = (new FundsveraGateway(null, $http))->verify('MVS-PAY00000000000000001');

            $this->assertFalse($res['ok']);
            $this->assertArrayNotHasKey('status', $res,
                'no answer must not become a guessed one');
        } finally {
            putenv('FUNDSVERA_PUBLIC_KEY'); putenv('FUNDSVERA_SECRET_KEY');
        }
    }

    /** Without credentials, reconciliation must skip Fundsvera, not stall it. */
    public function testFundsveraVerifyWithoutConfigIsUnsupportedNotUnreachable()
    {
        putenv('FUNDSVERA_PUBLIC_KEY');
        putenv('FUNDSVERA_SECRET_KEY');
        $res = (new FundsveraGateway(null, new PayFakeHttp(array())))->verify('MVS-PAY00000000000000001');

        $this->assertFalse($res['ok']);
        $this->assertTrue(!empty($res['unsupported']),
            'the cron maps unsupported to NO_VERIFIER so the age-out rule keeps working');
    }

    /**
     * Initiation is a synchronous call the customer waits on: one bounded
     * attempt, no retry ladder. Three 15-second retries plus backoff held the
     * "Processing…" button for over a minute whenever the provider was slow —
     * the visible half of "it does not work".
     */
    public function testFundsveraInitiationFailsFastInsteadOfHanging()
    {
        putenv('FUNDSVERA_PUBLIC_KEY=pk-test');
        putenv('FUNDSVERA_SECRET_KEY=sk-test');
        putenv('FUNDSVERA_ENABLED=1');
        try {
            $http = new PayFakeHttp(array(
                array('http_code' => 200, 'body' => json_encode(array(
                    'trx_ref'        => 'FVTRX0010',
                    'account_number' => '9021234567',
                    'account_name'   => 'MVS/Maria customer',
                    'bank_name'      => 'Palmpay',
                    'checkout_url'   => 'https://fundsvera.co/pay/abc',
                )), 'request_id' => 'r'),
            ));
            $gateway = new FundsveraGateway(null, $http);
            $tx = (object)array(
                'id' => 42, 'public_id' => 'PAY000000000000000001', 'user_id' => 7,
                'internal_reference' => 'MVS-PAY00000000000000001',
                'amount' => '1000.00000000', 'currency' => 'NGN',
            );
            $user = (object)array(
                'id' => 7, 'email' => 'maria@example.com', 'first_name' => 'Maria',
                'last_name' => 'Ngozi', 'username' => 'maria', 'phone' => '08031234567',
            );
            $res = $gateway->initiate($tx, $user);

            $this->assertTrue($res['ok'], json_encode($res));
            $this->assertCount(1, $http->calls, 'one attempt, not a retry ladder');
            $this->assertLessThanOrEqual(15, (int)$http->calls[0]['options']['timeout'],
                'the customer is waiting on this call');
            $this->assertSame('https://fundsvera.co/pay/abc', $res['redirect_url']);

            // The source must pin the client to no internal retries.
            $src = file_get_contents(self::$root.'/application/libraries/FundsveraGateway.php');
            $this->assertStringContainsString("'max_retries' => 0", $src);
        } finally {
            putenv('FUNDSVERA_PUBLIC_KEY'); putenv('FUNDSVERA_SECRET_KEY'); putenv('FUNDSVERA_ENABLED');
        }
    }

    /** Reconciliation must leave Fundsvera deposits to the age-out rule... */
    public function testReconciliationTreatsAnUnsupportedVerifierAsNoVerifier()
    {
        $src = file_get_contents(self::$root.'/application/libraries/CronWorkers.php');
        $this->assertStringContainsString("res['unsupported']", $src);
        $this->assertStringContainsString('return $none;', $src,
            'unsupported must fall through to NO_VERIFIER, not UNREACHABLE');
    }

    /** Signatures may arrive prefixed with their algorithm. */
    public function testFundsveraSignatureAcceptsAPrefixedDigest()
    {
        putenv('FUNDSVERA_WEBHOOK_SECRET=fv-webhook-secret');
        try {
            $gateway = $this->fundsvera_gateway();
            $body = '{"trx_ref":"FV1"}';
            $sig = hash_hmac('sha256', $body, 'fv-webhook-secret');
            $this->assertTrue($gateway->verify_webhook($body, array('X-FUNDSVERA-SIGNATURE' => $sig)));
            $this->assertTrue($gateway->verify_webhook($body, array('X-FUNDSVERA-SIGNATURE' => 'sha256='.$sig)),
                'an "sha256="-prefixed digest is still the digest');
            $this->assertFalse($gateway->verify_webhook($body, array('X-FUNDSVERA-SIGNATURE' => strrev($sig))));
        } finally {
            putenv('FUNDSVERA_WEBHOOK_SECRET');
        }
    }

    private function fundsvera_gateway() {
        return new FundsveraGateway(null, new PayFakeHttp(array()));
    }

    private function fundsvera_body(array $over = array()) {
        return json_encode(array_merge(array(
            'trx_ref'            => 'FVTRX0001',
            'request_id'         => 'MVS-PAY00000000000000001',
            'trx_type'           => 'Bank Transfer',
            'transaction_status' => 'SUCCESSFUL',
            'amount_paid'        => '1000',
            'settlement_amount'  => '985',
            'fee'                => '15',
            'customer'           => array('email' => 'maria@example.com', 'virtual_account_no' => null),
        ), $over), JSON_UNESCAPED_SLASHES);
    }

    /* ---------------------------- source ---------------------------- */

    public function testWalletControllerPostsToPaymentService()
    {
        $src = file_get_contents(self::$root.'/application/controllers/dashboard/Wallet.php');
        $this->assertStringContainsString('PaymentService', $src);
        $this->assertStringContainsString('function deposit', $src);
        $this->assertStringContainsString("method(true) !== 'POST') show_404()", $src);
        $this->assertStringNotContainsString("ledgerservice->credit", $src);
        $this->assertStringNotContainsString("insert('wallet_transactions'", $src);
    }

    public function testWebhookRouteAndControllerExist()
    {
        $routes = file_get_contents(self::$root.'/application/config/routes.php');
        $this->assertStringContainsString("'webhook/(:any)'", $routes);
        $this->assertFileExists(self::$root.'/application/controllers/Webhooks.php');
        $wh = file_get_contents(self::$root.'/application/controllers/Webhooks.php');
        $this->assertStringContainsString('record_webhook', $wh);
        $this->assertStringContainsString('extends MY_Controller', $wh);
    }

    /* ----------------------------- fakes ---------------------------- */

    private function fresh() {
        $ci = new PayFakeCI();
        $GLOBALS['__fake_ci'] = $ci;
        return $ci;
    }
}

#[AllowDynamicProperties]
class PayFakeCI {
    public $user, $method, $wallet, $tx, $db, $load, $input, $auth, $request_id='test';
    public $inserts=array(), $ledger_credits=0, $webhook_sig=null;
    public $ledger_should_fail=false, $webhook_processed=false;
    public function __construct() {
        // Register before constructing anything that calls get_instance()
        // inside its own constructor (the real libraries below do).
        $GLOBALS['__fake_ci'] = $this;
        $this->user = (object)array('id'=>7,'role'=>'CUSTOMER','status'=>'ACTIVE');
        $this->method = (object)array(
            'id'=>1,'code'=>'manual','name'=>'Manual','type'=>'MANUAL','is_active'=>1,
            'min_amount'=>'5.00000000','max_amount'=>'5000.00000000',
            'fee_percent'=>'2.5','fee_fixed'=>'0.30','bonus_percent'=>'0','instructions'=>'Bank details here.',
        );
        $this->wallet = (object)array('id'=>11,'balance'=>'100.00000000','currency'=>'NGN');
        $this->tx = (object)array('id'=>42,'public_id'=>'PAY1','status'=>'PENDING','amount'=>'100','credited_amount'=>'97.2','fee'=>'2.8','bonus'=>'0','user_id'=>7,'idempotency_key'=>null,'currency'=>'NGN');
        $this->db = new PayFakeDb($this);
        $this->input = new PayFakeInput();
        $this->auth = new PayFakeAuth($this);
        $this->load = new PayFakeLoader();
        $this->Payment_transaction_model = new PayFakeTxModel($this);
        $this->Payment_webhook_model = new PayFakeWhModel($this);
        $this->Payment_event_model = new PayFakeEventModel($this);
        $this->Wallet_model = new PayFakeWalletModel($this);
        $this->Setting_model = new PayFakeSettings();
        $this->pricingservice = new PricingService();
        $this->ledgerservice = new PayFakeLedger($this);
        $this->encryptionservice = new EncryptionService();
    }
}
class PayFakeLoader { function model($n){} function library($n){} }
class PayFakeInput { function ip_address(){return '127.0.0.1';} function user_agent(){return 'PHPUnit';} }
class PayFakeAuth { private $ci; function __construct($ci){$this->ci=$ci;} function id(){return $this->ci->user->id;} }

class PayFakeDb {
    private $ci; private $wheres=array();
    public function __construct($ci){$this->ci=$ci;}
    public function query($sql,$b=false){ return new PayFakeResult($this->ci->wallet); }
    public function where($k,$v=null){ if(!is_array($k)) $this->wheres[$k]=$v; return $this; }
    public function where_in($k,$v){ return $this; }
    public function order_by($k,$d='ASC'){ return $this; }
    public function limit($l,$o=0){ return $this; }
    public function select($s,$b=false){ return $this; }
    public function from($t){ return $this; }
    public function join($t,$on,$type=''){ return $this; }
    public function trans_start(){} public function trans_complete(){} public function trans_rollback(){} public function trans_status(){return true;}
    public function insert_id(){ return 42; }
    public function count_all_results($t){ return 0; }
    public function insert($t,$d=array()){
        $this->ci->inserts[$t]=($this->ci->inserts[$t]??0)+1;
        if ($t==='payment_transactions') { $this->ci->tx = (object)array_merge((array)$this->ci->tx,$d); }
        return true;
    }
    public function update($t,$d){
        // Mirror the real row: once the webhook is flagged processed, a repeat
        // delivery is a genuine duplicate (see PayFakeWhModel::record_once).
        if ($t==='payment_webhooks' && !empty($d['processed'])) {
            $this->ci->webhook_processed = true;
        }
        return true;
    }
    public function get($t=null){
        $w = $this->wheres; $this->wheres = array();
        if ($t==='payment_methods') {
            // A code that isn't the seeded method must return no row.
            $code = $w['code'] ?? null;
            return new PayFakeResult(
                ($code === null || $code === $this->ci->method->code) ? $this->ci->method : null
            );
        }
        if ($t==='wallets') return new PayFakeResult($this->ci->wallet);
        if ($t==='payment_transactions') return new PayFakeResult($this->ci->tx);
        if ($t==='wallet_transactions') return new PayFakeResult((object)array('id'=>99));
        return new PayFakeResult(null);
    }
}
class PayFakeResult {
    private $row; public $rows;
    public function __construct($row){$this->row=$row;$this->rows=$row?array($row):array();}
    public function row(){return $this->row;} public function result(){return $this->rows;}
}
class PayFakeTxModel {
    private $ci; private $idem=array(); function __construct($ci){$this->ci=$ci;}
    function find_by_idempotency_key($k){
        // Rows persisted through insert() are keyed here too, which is what
        // makes a repeated deposit with the same key resolve to a duplicate.
        if (isset($this->idem[$k])) return $this->idem[$k];
        $tx = $this->ci->tx;
        return ($tx && isset($tx->idempotency_key) && $tx->idempotency_key === $k) ? $tx : null;
    }
    function seed_idem($k,$tx){$this->idem[$k]=$tx;}
    function find_by_id($id){return $this->ci->tx;}
    function find_by_provider_tx($id){return null;}
    function find_public_for_user($p,$u){return $this->ci->tx;}
    function update_status($id,$d){foreach($d as $k=>$v)$this->ci->tx->$k=$v;}
    function for_user($id,$l=25,$o=0){return array();}
}
class PayFakeWhModel {
    private $ci; private $seen=array(); function __construct($ci){$this->ci=$ci;}
    function record_once($gw,$eid,$payload,$sig,$type){
        if ($eid && isset($this->seen[$gw.':'.$eid])) {
            // Mirror the real model: a stored-but-unprocessed event was a
            // transient failure and must reprocess on the gateway's retry;
            // a processed one is a genuine duplicate.
            return $this->ci->webhook_processed ? false : 7;
        }
        if ($eid) $this->seen[$gw.':'.$eid]=1;
        $this->ci->inserts['payment_webhooks']=($this->ci->inserts['payment_webhooks']??0)+1;
        return 7;
    }
}
class PayFakeEventModel {
    private $ci; function __construct($ci){$this->ci=$ci;}
    function insert($d){$this->ci->inserts['payment_events']=($this->ci->inserts['payment_events']??0)+1;}
}
class PayFakeWalletModel { private $ci; function __construct($ci){$this->ci=$ci;} function for_user($id){return $this->ci->wallet;} }
class PayFakeSettings {
    function get($k,$d=null){
        if (substr($k, -15) === '.webhook_secret') return 'test-webhook-secret';
        return $d;
    }
}
class PayFakeLedger {
    private $ci; function __construct($ci){$this->ci=$ci;}
    function credit($wid,$amt,$type,$rt,$rid,$idem,$meta=null){
        if ($this->ci->ledger_should_fail) {
            return array('ok'=>false,'error'=>'simulated ledger outage');
        }
        $this->ci->ledger_credits++;
        return array('ok'=>true,'public_id'=>'WT','balance_after'=>bcadd($this->ci->wallet->balance,$amt,8));
    }
    function charge($wid,$amt,$rt,$rid,$idem,$meta=null){return array('ok'=>true);}
    function refund($wid,$amt,$rt,$rid,$idem=null){return array('ok'=>true);}
}

/** Minimal query-builder double for the webhook model's two statements. */
class PayWhFakeDb {
    public $rows = array();
    private $where = array();
    private $next_id = 1;

    public function where($k, $v = null) {
        if (is_array($k)) { foreach ($k as $kk => $vv) $this->where[$kk] = $vv; }
        else $this->where[$k] = $v;
        return $this;
    }
    public function get($table) {
        $match = null;
        foreach ($this->rows as $row) {
            $ok = true;
            foreach ($this->where as $k => $v) {
                if (!array_key_exists($k, $row) || (string)$row[$k] !== (string)$v) { $ok = false; break; }
            }
            if ($ok) { $match = $row; break; }
        }
        $this->where = array();
        return new PayWhFakeResult($match);
    }
    public function insert($table, array $data) {
        $data['id'] = $this->next_id;
        $data += array('processed' => 0, 'error' => null);
        $this->rows[$this->next_id] = $data;
        $this->next_id++;
        return true;
    }
    public function update($table, array $data) {
        $id = $this->where['id'] ?? null;
        $this->where = array();
        if ($id !== null && isset($this->rows[$id])) {
            $this->rows[$id] = array_merge($this->rows[$id], $data);
        }
        return true;
    }
    public function insert_id() { return $this->next_id - 1; }
}

class PayWhFakeResult {
    private $row;
    public function __construct($row) { $this->row = $row; }
    public function row() { return $this->row === null ? null : (object)$this->row; }
}

/**
 * Scripted stand-in for SecureHttpClient: records what went on the wire
 * (method, URL, headers, per-call options) and answers from the script.
 * An unscripted call throws so a test that triggers an unexpected provider
 * request fails loudly instead of silently.
 */
class PayFakeHttp
{
    public $calls = array();
    private $script;

    public function __construct(array $script) { $this->script = $script; }

    public function get($url, $headers = array(), $options = array())
    {
        return $this->push('GET', $url, null, $headers, $options);
    }

    public function post($url, $data = null, $headers = array(), $options = array())
    {
        return $this->push('POST', $url, $data, $headers, $options);
    }

    private function push($method, $url, $data, $headers, $options)
    {
        if (!$this->script) {
            throw new RuntimeException('PayFakeHttp: unscripted '.$method.' '.$url);
        }
        $this->calls[] = array(
            'method' => $method, 'url' => $url, 'data' => $data,
            'headers' => $headers, 'options' => $options,
        );
        return array_shift($this->script);
    }
}

/**
 * Stand-in for Fundsvera_checkout_model: resolves one known checkout row
 * (payment_transaction_id 42, expected 1000) and records record_result calls
 * so tests can assert what the webhook wrote.
 */
class PayFakeCheckoutModel
{
    public $results = array();
    public $row;

    public function __construct($row = null)
    {
        $this->row = $row ?: (object)array(
            'id'                     => 5,
            'payment_transaction_id' => 42,
            'user_id'                => 7,
            'request_id'             => 'MVS-PAY00000000000000001',
            'trx_ref'                => null,
            'expected_amount'        => '1000.00000000',
            'status'                 => 'PENDING',
        );
    }

    public $opened = array();

    public function open(array $data)
    {
        $this->opened[] = $data;
        $this->row = (object)array_merge((array)$this->row, $data);
        return 5;
    }

    public function by_request_id($request_id)
    {
        return ($this->row && $this->row->request_id === $request_id) ? $this->row : null;
    }

    public function by_trx_ref($trx_ref)
    {
        return null;
    }

    public function record_result($id, array $data)
    {
        $this->results[] = $data;
        return true;
    }
}
