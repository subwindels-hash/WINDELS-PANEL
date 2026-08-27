<?php
use PHPUnit\Framework\TestCase;

/**
 * BlockonomicsGateway — address issuance, callback verification and the
 * amount/confirmation rules that decide whether money moves.
 *
 * No network: the HTTP client and the database are faked, so these pin the
 * adapter's *decisions*, which is exactly the part that must not be wrong.
 * Nothing here proves the live Blockonomics API behaves as documented — that
 * needs production credentials and is recorded as such in the docs.
 */
class BlockonomicsTest extends TestCase
{
    private static $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(dirname(__DIR__));
        if (!defined('BASEPATH')) define('BASEPATH', self::$root.'/system/');
        if (!defined('APPPATH')) define('APPPATH', self::$root.'/application/');
        if (!class_exists('CI_Model')) eval('class CI_Model {}');
        if (!function_exists('get_instance')) {
            eval('function &get_instance(){ return $GLOBALS["__fake_ci"]; }');
        }
        if (!function_exists('log_message')) eval('function log_message($l,$m){}');
        if (!function_exists('marvy_public_id')) require_once self::$root.'/application/helpers/marvy_helper.php';
        require_once self::$root.'/application/libraries/GatewayInterface.php';
        require_once self::$root.'/application/libraries/BlockonomicsGateway.php';
    }

    private function fresh(array $opts = array())
    {
        $ci = new BlkFakeCI($opts);
        $GLOBALS['__fake_ci'] = $ci;
        return $ci;
    }

    private function tx()
    {
        return (object)array(
            'id' => 42, 'public_id' => 'TX0000000000000000000000AB',
            'user_id' => 7, 'amount' => '50000.00000000', 'currency' => 'NGN',
        );
    }

    /* --------------------------- configuration --------------------------- */

    public function testRefusesToInitiateWithoutAnApiKey()
    {
        $ci = $this->fresh(array('api_key' => null));
        $gw = new BlockonomicsGateway();

        $this->assertFalse($gw->is_configured());
        $res = $gw->initiate($this->tx(), $ci->user);
        $this->assertFalse($res['ok']);
        $this->assertSame('CONFIG_MISSING', $res['code']);
        // Nothing may be written when the gateway cannot take the payment.
        $this->assertSame(0, count($ci->db->inserts));
    }

    public function testRefusesToInitiateWhenBitcoinIsDisabled()
    {
        $ci = $this->fresh(array('btc_enabled' => false));
        $gw = new BlockonomicsGateway();

        $res = $gw->initiate($this->tx(), $ci->user);
        $this->assertFalse($res['ok']);
        $this->assertSame('CRYPTO_DISABLED', $res['code']);
        $this->assertSame(0, count($ci->db->inserts));
    }

    /**
     * A deposit must never be quoted from a guessed exchange rate: showing the
     * customer a BTC amount we invented is how underpayments happen.
     */
    public function testRefusesToInitiateWhenTheRateIsUnavailable()
    {
        $ci = $this->fresh(array('rate_http' => 500));
        $gw = new BlockonomicsGateway();

        $res = $gw->initiate($this->tx(), $ci->user);
        $this->assertFalse($res['ok']);
        $this->assertSame('RATE_UNAVAILABLE', $res['code']);
    }

    /* ----------------------------- initiation ---------------------------- */

    public function testInitiateReservesAnAddressAndQuotesTheAmount()
    {
        $ci = $this->fresh(array('rate' => '100000000.00000000')); // 1 BTC = 100,000,000 NGN
        $gw = new BlockonomicsGateway();

        $res = $gw->initiate($this->tx(), $ci->user);
        $this->assertTrue($res['ok']);
        $this->assertSame('PENDING', $res['status']);

        $checkout = $res['checkout'];
        $this->assertSame('bc1qexampleaddress000000', $checkout['address']);
        // 50,000 NGN / 100,000,000 NGN-per-BTC = 0.0005 BTC
        $this->assertSame('0.00050000', $checkout['amount_crypto']);
        $this->assertSame('BTC', $checkout['crypto']);
        $this->assertStringContainsString('bitcoin:bc1qexampleaddress000000', $checkout['uri']);

        // The quote is persisted, which is what later decides underpayment.
        $this->assertSame(1, count($ci->db->inserts));
        $row = $ci->db->inserts[0];
        $this->assertSame('blockonomics_addresses', $row['table']);
        $this->assertSame('0.00050000', $row['data']['expected_crypto_amount']);
        $this->assertSame('AWAITING', $row['data']['status']);
        $this->assertSame(42, $row['data']['payment_transaction_id']);
    }

    /* ------------------------- webhook verification ---------------------- */

    /**
     * The callback is an unauthenticated GET to a public URL. With no secret
     * configured the adapter must report "cannot verify" (NULL) rather than
     * TRUE — PaymentService then stores the event and moves no money.
     */
    public function testUnconfiguredCallbackSecretCannotVerify()
    {
        $this->fresh(array('callback_secret' => null));
        $gw = new BlockonomicsGateway();
        $this->assertNull($gw->verify_webhook('', array()));
    }

    public function testCallbackWithTheWrongSecretIsRejected()
    {
        $this->fresh(array('callback_secret' => 'right-secret'));
        $gw = new BlockonomicsGateway();
        $this->assertFalse($gw->verify_webhook(http_build_query(array('secret' => 'wrong-secret')), array()));
    }

    public function testCallbackWithNoSecretPresentedIsRejected()
    {
        $this->fresh(array('callback_secret' => 'right-secret'));
        $gw = new BlockonomicsGateway();
        $this->assertFalse($gw->verify_webhook(http_build_query(array('addr' => 'x')), array()));
    }

    public function testCallbackWithTheCorrectSecretIsAccepted()
    {
        $this->fresh(array('callback_secret' => 'right-secret'));
        $gw = new BlockonomicsGateway();
        $this->assertTrue($gw->verify_webhook(http_build_query(array('secret' => 'right-secret')), array()));
    }

    /* ---------------------------- event parsing -------------------------- */

    public function testUnconfirmedCallbackIsNotASuccess()
    {
        $ci = $this->fresh();
        $ci->db->address_row = $this->addressRow();
        $gw = new BlockonomicsGateway();

        $event = $gw->parse_event($this->callback(array('status' => 0, 'value' => 50000)));
        $this->assertSame('PENDING', $event['status']);
    }

    public function testConfirmedAndFullyPaidCallbackIsASuccess()
    {
        $ci = $this->fresh();
        $ci->db->address_row = $this->addressRow();
        $gw = new BlockonomicsGateway();

        // 50,000 satoshi == the quoted 0.0005 BTC.
        $event = $gw->parse_event($this->callback(array('status' => 2, 'value' => 50000)));
        $this->assertSame('SUCCESS', $event['status']);
        $this->assertSame('0.00050000', $event['amount']);
        $this->assertSame('deadbeef', $event['provider_tx_id']);
        $this->assertSame(42, $event['metadata']['payment_transaction_id']);
    }

    /**
     * Confirmed on-chain but short of the quote. Crediting the full deposit
     * here is a direct loss, so it must not be a success.
     */
    public function testConfirmedUnderpaymentIsNotASuccess()
    {
        $ci = $this->fresh();
        $ci->db->address_row = $this->addressRow();
        $gw = new BlockonomicsGateway();

        $event = $gw->parse_event($this->callback(array('status' => 2, 'value' => 25000)));
        $this->assertSame('UNDERPAID', $event['status']);
    }

    /** A payment a hair under the quote (exchange drift) still completes. */
    public function testTinyShortfallIsWithinTolerance()
    {
        $ci = $this->fresh();
        $ci->db->address_row = $this->addressRow();
        $gw = new BlockonomicsGateway();

        // 0.2% under the 50,000 satoshi quote; tolerance is 0.5%.
        $event = $gw->parse_event($this->callback(array('status' => 2, 'value' => 49900)));
        $this->assertSame('SUCCESS', $event['status']);
    }

    public function testCallbackForAnUnknownAddressIsIgnoredNotCredited()
    {
        $ci = $this->fresh();
        $ci->db->address_row = null; // never issued by us
        $gw = new BlockonomicsGateway();

        $event = $gw->parse_event($this->callback(array('status' => 2, 'value' => 999999)));
        $this->assertSame('IGNORED', $event['status']);
        $this->assertArrayNotHasKey('payment_transaction_id', $event['metadata']);
    }

    /**
     * Blockonomics calls back once per confirmation. Replaying the *same*
     * confirmation must be a duplicate, while the next one must be a new event
     * — the event id is what PaymentService de-duplicates on.
     */
    public function testEventIdDistinguishesConfirmationsButNotReplays()
    {
        $ci = $this->fresh();
        $ci->db->address_row = $this->addressRow();
        $gw = new BlockonomicsGateway();

        $first  = $gw->parse_event($this->callback(array('status' => 1, 'value' => 50000)));
        $replay = $gw->parse_event($this->callback(array('status' => 1, 'value' => 50000)));
        $next   = $gw->parse_event($this->callback(array('status' => 2, 'value' => 50000)));

        $this->assertSame($first['event_id'], $replay['event_id'], 'a replay must be a duplicate');
        $this->assertNotSame($first['event_id'], $next['event_id'], 'a new confirmation must be a new event');
    }

    /* ------------------------------ wiring ------------------------------- */

    /**
     * The point of this adapter over the existing scaffolds: PaymentService
     * must actually route to it, otherwise a "supported" gateway silently
     * falls through to manual review.
     */
    public function testPaymentServiceRoutesBlockonomicsToThisAdapter()
    {
        $src = file_get_contents(self::$root.'/application/libraries/PaymentService.php');
        $this->assertStringContainsString('BlockonomicsGateway.php', $src,
            'PaymentService must require the adapter');
        $this->assertStringContainsString("case 'blockonomics':", $src,
            'gateway_for_code() must route blockonomics');
        $this->assertStringContainsString('implemented_gateways', $src,
            'record_webhook() must know which gateways have real adapters');
    }

    public function testSeedAndRegistryShipTheGatewayDisabledUntilConfigured()
    {
        $seed = file_get_contents(self::$root.'/application/seeds/Core_seeder.php');
        $this->assertStringContainsString("array('blockonomics','Bitcoin (BTC)','BLOCKONOMICS',0,", $seed,
            'the seeded payment method must be inactive by default');

        $registry = file_get_contents(self::$root.'/application/config/payment_gateways.php');
        $this->assertStringContainsString("'blockonomics'", $registry);
    }

    /* ------------------------------ helpers ------------------------------ */

    private function addressRow()
    {
        return (object)array(
            'id' => 3, 'payment_transaction_id' => 42, 'user_id' => 7,
            'address' => 'bc1qexampleaddress000000',
            'expected_crypto_amount' => '0.00050000',
            'required_confirmations' => 2, 'txid' => null, 'status' => 'AWAITING',
        );
    }

    private function callback(array $overrides)
    {
        return http_build_query(array_merge(array(
            'addr' => 'bc1qexampleaddress000000',
            'txid' => 'deadbeef',
            'secret' => 'right-secret',
        ), $overrides));
    }
}

/* ------------------------------- doubles --------------------------------- */

#[AllowDynamicProperties]
class BlkFakeCI
{
    public $user, $db, $load, $input, $Setting_model, $securehttpclient;

    public function __construct(array $opts)
    {
        $GLOBALS['__fake_ci'] = $this;
        $this->user = (object)array('id' => 7, 'status' => 'ACTIVE');
        $this->db = new BlkFakeDb();
        $this->load = new BlkFakeLoader();
        $this->input = new BlkFakeInput();
        $this->Setting_model = new BlkFakeSettings($opts);
        $this->securehttpclient = new BlkFakeHttp($opts);
    }
}

class BlkFakeLoader
{
    public function model($n) {}
    public function library($n) {}
}

class BlkFakeInput
{
    public function ip_address() { return '127.0.0.1'; }
    public function user_agent() { return 'PHPUnit'; }
}

class BlkFakeSettings
{
    private $values;

    public function __construct(array $opts)
    {
        $defaults = array(
            'blockonomics_api_key' => 'test-api-key',
            'blockonomics_callback_secret' => 'right-secret',
            'blockonomics_confirmations' => 2,
            'blockonomics_btc_enabled' => '1',
            'blockonomics_usdt_enabled' => '0',
            'blockonomics_timeout_minutes' => 60,
        );
        if (array_key_exists('api_key', $opts)) $defaults['blockonomics_api_key'] = $opts['api_key'];
        if (array_key_exists('callback_secret', $opts)) $defaults['blockonomics_callback_secret'] = $opts['callback_secret'];
        if (array_key_exists('btc_enabled', $opts)) $defaults['blockonomics_btc_enabled'] = $opts['btc_enabled'] ? '1' : '0';
        $this->values = $defaults;
    }

    public function get($key, $default = null)
    {
        if (!array_key_exists($key, $this->values)) return $default;
        $v = $this->values[$key];
        return ($v === null || $v === '') ? $default : $v;
    }
}

class BlkFakeHttp
{
    private $opts;

    public function __construct(array $opts) { $this->opts = $opts; }

    public function post($url, $data = null, $headers = array(), $options = array())
    {
        if (strpos($url, 'new_address') !== false) {
            return array('http_code' => 200, 'body' => json_encode(array('address' => 'bc1qexampleaddress000000')));
        }
        return array('http_code' => 404, 'body' => '');
    }

    public function get($url, $headers = array(), $options = array())
    {
        if (strpos($url, 'price') !== false) {
            $code = isset($this->opts['rate_http']) ? $this->opts['rate_http'] : 200;
            if ($code !== 200) return array('http_code' => $code, 'body' => '');
            $rate = isset($this->opts['rate']) ? $this->opts['rate'] : '100000000.00000000';
            return array('http_code' => 200, 'body' => json_encode(array('price' => $rate)));
        }
        return array('http_code' => 404, 'body' => '');
    }
}

class BlkFakeDb
{
    public $inserts = array();
    public $updates = array();
    public $address_row = null;
    private $where = array();

    public function where($k, $v = null)
    {
        if (is_array($k)) { foreach ($k as $kk => $vv) $this->where[$kk] = $vv; }
        else $this->where[$k] = $v;
        return $this;
    }

    public function get($table)
    {
        $this->where = array();
        return new BlkFakeResult($this->address_row);
    }

    public function insert($table, $data)
    {
        $this->inserts[] = array('table' => $table, 'data' => $data);
        return true;
    }

    public function update($table, $data)
    {
        $this->updates[] = array('table' => $table, 'data' => $data, 'where' => $this->where);
        $this->where = array();
        return true;
    }

    public function insert_id() { return 1; }
}

class BlkFakeResult
{
    private $row;
    public function __construct($row) { $this->row = $row; }
    public function row() { return $this->row; }
    public function result() { return $this->row ? array($this->row) : array(); }
}
