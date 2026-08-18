<?php
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__).'/_support/FakeDb.php';
require_once dirname(__DIR__).'/_support/IntegrationHarness.php';

/**
 * Identity verification, NIN/BVN (Session 26, rebuild-spec phase E).
 *
 * Every other domain in this panel is tested primarily for its money. This one
 * is tested primarily for its *data*, because that is where it can do real
 * harm: the lifecycle is a single request and a single answer, but the answer
 * is a stranger's name, date of birth and phone number, and the question
 * contains a number that identifies them to their bank and their government.
 *
 * So the assertions below are weighted accordingly. The money rules still get
 * their tests — a not-found lookup must refund, a vendor outage must refund —
 * but the ones that would be hardest to notice going wrong are the storage
 * assertions: that no column anywhere holds the raw identifier, that the
 * vendor's photograph never survives, that a purge really empties the blob,
 * and that reading a result is impossible without leaving a trace.
 *
 * Three halves, as in NumbersTest: real stack against the migration-derived
 * schema for behaviour, scripted fixtures for the Dojah contract, and
 * source-level gates for the admin surface, the registry and the schedule.
 */
class IdentityTest extends TestCase
{
    private static $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(dirname(__DIR__));
        if (!defined('BASEPATH')) define('BASEPATH', self::$root.'/system/');
        if (!class_exists('CI_Model')) {
            eval('#[AllowDynamicProperties] class CI_Model { public $db; }');
        }
        if (!function_exists('get_instance')) {
            eval('function get_instance(){ return $GLOBALS["__fake_ci"]; }');
        }
        if (!function_exists('log_message')) eval('function log_message($l,$m){}');
        require_once self::$root.'/application/core/MY_Model.php';
        require_once self::$root.'/application/helpers/windels_helper.php';
        require_once self::$root.'/application/libraries/DojahAdapter.php';
        require_once self::$root.'/application/libraries/MockIdentityAdapter.php';
    }

    protected function setUp(): void
    {
        // The mock vendor records every identifier it was asked about; a test
        // that inspected leftovers from the previous one would pass by luck.
        MockIdentityAdapter::reset();
    }

    /** A world with a customer who can afford a few lookups. */
    private function app($balance = '10000')
    {
        $app = new IntegrationHarness();
        $app->seed_minimal();
        $app->seed_identity();
        $user = $app->register('id_user', 'id@x.test');
        $app->credit($user, $balance);
        $app->library('IdentityService');
        $app->model(array('Identity_check_model', 'Identity_product_model',
                          'Service_transaction_model', 'Audit_log_model'));
        return array($app, $user);
    }

    /** A well-formed request; override any field per test. */
    private function request(array $overrides = array())
    {
        return array_merge(array(
            'product'    => 'NIN_BASIC',
            'identifier' => '70123456781',
            'consent'    => true,
            'consent_ip' => '102.89.1.7',
            'source'     => 'WEB',
        ), $overrides);
    }

    /* ========================= the happy path =========================== */

    public function testAVerifiedLookupChargesOnceAndSettlesImmediately()
    {
        list($app, $user) = $this->app();

        $res = $app->identityservice->verify($user, $this->request());

        $this->assertTrue($res['ok'], $res['error'] ?? '');
        $this->assertTrue($res['found']);
        $this->assertSame('SUCCESSFUL', $res['transaction']->status,
            'a lookup has no settlement window — the answer arrives with the call');
        $this->assertSame('IDENTITY', $res['transaction']->service_domain);
        $this->assertSame('NIN', $res['transaction']->service_type);
        $this->assertSame('9750.00000000', $app->balance($user));
        list($d, $c) = $app->ledger_is_balanced();
        $this->assertSame($d, $c);
    }

    public function testTheCheckRowRecordsTheOutcomeAndTheFrozenCost()
    {
        list($app, $user) = $this->app();

        $res = $app->identityservice->verify($user, $this->request());
        $check = $res['check'];

        $this->assertSame('VERIFIED', $check->status);
        $this->assertSame('NIN', $check->id_type);
        $this->assertSame('IDENTIFIER', $check->lookup_field);
        $this->assertNotEmpty($check->provider_reference,
            'without a vendor reference a dispute cannot be traced back');
        // Margin has to stay auditable after the fact, like every other domain.
        $this->assertSame('120.00000000', $res['transaction']->provider_cost);
    }

    public function testTheVendorIsCalledWithTheIdentifierAndTheTransactionReference()
    {
        list($app, $user) = $this->app();

        $app->identityservice->verify($user, $this->request());

        $this->assertCount(1, MockIdentityAdapter::$calls);
        $call = MockIdentityAdapter::$calls[0];
        $this->assertSame('70123456781', $call['identifier']);
        $this->assertSame('NIN', $call['id_type']);
        $this->assertNotEmpty($call['reference'],
            'the vendor call must carry our public id so support can correlate it');
    }

    /* ===================== the identifier never lands ==================== */

    /**
     * The single most important assertion in this file. Not "the column is
     * hashed" — that a reviewer can see — but that the raw number appears in
     * no column of any table after a complete, successful lookup. A future
     * convenience column, a metadata blob, a failure_reason built from the
     * request, would each be caught here.
     */
    public function testTheRawIdentifierIsNowhereInTheDatabase()
    {
        list($app, $user) = $this->app();
        $nin = '70123456781';

        $app->identityservice->verify($user, $this->request(array('identifier' => $nin)));

        foreach (array('identity_checks', 'service_transactions', 'provider_transactions',
                       'audit_logs', 'wallet_transactions', 'ledger_entries') as $table) {
            foreach ($app->rows($table) as $row) {
                foreach ($row as $column => $value) {
                    if (!is_scalar($value)) continue;
                    $this->assertStringNotContainsString($nin, (string)$value,
                        "{$table}.{$column} contains the raw identifier");
                }
            }
        }
    }

    public function testTheIdentifierIsStoredOnlyAsABlindIndexAndAMaskedTail()
    {
        list($app, $user) = $this->app();

        $res = $app->identityservice->verify($user, $this->request(array('identifier' => '70123456781')));
        $check = $res['check'];

        $this->assertSame('6781', $check->identifier_last4);
        $this->assertSame(64, strlen($check->identifier_hash),
            'the blind index is a hex sha256 HMAC');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $check->identifier_hash);
    }

    /**
     * The blind index has to answer "is this the number on that receipt?" —
     * that is the whole reason for keeping it — while a different number must
     * not collide.
     */
    public function testSupportCanMatchAQuotedNumberWithoutTheDatabaseHoldingIt()
    {
        list($app, $user) = $this->app();
        $app->library('EncryptionService');

        $app->identityservice->verify($user, $this->request(array('identifier' => '70123456781')));

        $same = $app->encryptionservice->blind_index('70123456781', 'NIN');
        $other = $app->encryptionservice->blind_index('70123456782', 'NIN');

        $found = $app->Identity_check_model->by_identifier_hash($same);
        $this->assertCount(1, $found);
        $this->assertCount(0, $app->Identity_check_model->by_identifier_hash($other));
    }

    /** Spacing is a typing habit, not a different identity. */
    public function testFormattingIsNormalisedBeforeHashing()
    {
        list($app, $user) = $this->app('20000');
        $app->library('EncryptionService');

        $app->identityservice->verify($user, $this->request(array('identifier' => '7012 3456 781')));
        $app->identityservice->verify($user, $this->request(array('identifier' => '70123456781')));

        $hash = $app->encryptionservice->blind_index('70123456781', 'NIN');
        $this->assertCount(2, $app->Identity_check_model->by_identifier_hash($hash),
            'the same number typed two ways must produce one blind index');
    }

    /** A NIN and a BVN that happen to share digits are not the same subject. */
    public function testTheBlindIndexIsScopedToTheIdentifierType()
    {
        list($app, $user) = $this->app();
        $app->library('EncryptionService');

        $this->assertNotSame(
            $app->encryptionservice->blind_index('22123456781', 'NIN'),
            $app->encryptionservice->blind_index('22123456781', 'BVN'));
    }

    /* ======================== the stored result ========================== */

    public function testTheResultIsEncryptedAtRestAndUnreadableAsStored()
    {
        list($app, $user) = $this->app();

        $res = $app->identityservice->verify($user, $this->request());
        $blob = $app->rows('identity_checks')[0]['result_encrypted'];

        $this->assertNotEmpty($blob);
        $this->assertStringNotContainsString('Okafor', $blob,
            'the stored payload must not be readable without the key');
        $this->assertStringNotContainsString('1990-04-12', $blob);
        // AES-256-GCM base64 of iv|tag|ciphertext.
        $this->assertGreaterThan(28, strlen(base64_decode($blob, true)));
    }

    /**
     * The mock vendor deliberately returns a photo. Nothing in the panel is
     * allowed to keep it: not the encrypted blob, and not the reveal output.
     */
    public function testTheVendorPhotographIsNeverStoredOrReturned()
    {
        list($app, $user) = $this->app();
        $app->library('EncryptionService');

        $res = $app->identityservice->verify($user, $this->request());
        $blob = $app->rows('identity_checks')[0]['result_encrypted'];
        $plain = $app->encryptionservice->open($blob);

        $this->assertStringNotContainsString('portrait', $plain,
            'the base64 photograph must be dropped before encryption');
        $this->assertArrayNotHasKey('photo', json_decode($plain, true));

        $revealed = $app->identityservice->reveal($res['check'], $user, 'CUSTOMER');
        $this->assertArrayNotHasKey('photo', $revealed['entity']);
    }

    public function testTheStoredResultKeepsOnlyAllowListedFields()
    {
        list($app, $user) = $this->app();
        $app->library('EncryptionService');

        $app->identityservice->verify($user, $this->request());
        $entity = json_decode(
            $app->encryptionservice->open($app->rows('identity_checks')[0]['result_encrypted']), true);

        $this->assertSame('Ada', $entity['first_name']);
        $this->assertSame('1990-04-12', $entity['date_of_birth']);
        foreach (array_keys($entity) as $key) {
            $this->assertContains($key, array('first_name','middle_name','last_name',
                'date_of_birth','gender','phone_number','nationality',
                'state_of_origin','lga_of_origin'),
                'an unexpected field reached storage: '.$key);
        }
    }

    /* ============================ the reveal ============================= */

    public function testRevealingAResultCountsTheAccessOnTheRow()
    {
        list($app, $user) = $this->app();
        $res = $app->identityservice->verify($user, $this->request());

        $this->assertSame(0, (int)$res['check']->reveal_count,
            'running a check is not the same act as reading its result');

        $out = $app->identityservice->reveal($res['check'], $user, 'CUSTOMER');

        $this->assertTrue($out['ok']);
        $this->assertSame('Ada', $out['entity']['first_name']);
        $row = $app->Identity_check_model->find_by_id($res['check']->id);
        $this->assertSame(1, (int)$row->reveal_count);
        $this->assertNotEmpty($row->last_revealed_at);
        $this->assertSame((string)$user->id, (string)$row->last_revealed_by);
    }

    public function testEveryRevealIsAuditedWithoutCopyingThePayloadIntoTheLog()
    {
        list($app, $user) = $this->app();
        $res = $app->identityservice->verify($user, $this->request());

        $app->identityservice->reveal($res['check'], $user, 'ADMIN');

        $entries = array();
        foreach ($app->rows('audit_logs') as $row) {
            if ($row['action'] === 'identity.result.reveal') $entries[] = $row;
        }
        $this->assertCount(1, $entries);
        $after = json_decode($entries[0]['after_json'], true);
        $this->assertSame('ADMIN', $after['by']);
        $this->assertSame('6781', $after['last4']);
        // audit_logs is written in clear, so it must carry no identity data.
        $this->assertStringNotContainsString('Okafor', $entries[0]['after_json']);
        $this->assertStringNotContainsString('1990-04-12', $entries[0]['after_json']);
        $this->assertStringNotContainsString('70123456781', $entries[0]['after_json']);
    }

    public function testRepeatedRevealsAccumulateRatherThanOverwrite()
    {
        list($app, $user) = $this->app();
        $res = $app->identityservice->verify($user, $this->request());

        for ($i = 0; $i < 3; $i++) {
            $check = $app->Identity_check_model->find_by_id($res['check']->id);
            $app->identityservice->reveal($check, $user, 'ADMIN');
        }

        $this->assertSame(3, (int)$app->Identity_check_model->find_by_id($res['check']->id)->reveal_count);
    }

    public function testANotFoundCheckHasNothingToReveal()
    {
        list($app, $user) = $this->app();
        $res = $app->identityservice->verify($user, $this->request(array('identifier' => '70123459999')));

        $out = $app->identityservice->reveal($res['check'], $user, 'CUSTOMER');

        $this->assertFalse($out['ok']);
        $this->assertSame('NO_RESULT', $out['code']);
    }

    /**
     * A blob that does not decrypt — a rotated key, a tampered row — must read
     * as "no result". decrypt() would hand the base64 straight back and the
     * screen would render it as if it were the customer's record.
     */
    public function testAnUndecryptableResultIsRefusedRatherThanRendered()
    {
        list($app, $user) = $this->app();
        $res = $app->identityservice->verify($user, $this->request());
        $app->db->where('id', $res['check']->id)
                ->update('identity_checks', array('result_encrypted' => 'bm90LWEtcmVhbC1ibG9iLWF0LWFsbC1qdXN0LWJhc2U2NA=='));

        $out = $app->identityservice->reveal(
            $app->Identity_check_model->find_by_id($res['check']->id), $user, 'ADMIN');

        $this->assertFalse($out['ok']);
        $this->assertSame('UNREADABLE', $out['code']);
        $this->assertArrayNotHasKey('entity', $out);
    }

    /* =========================== the money rules ========================= */

    /**
     * The rule the whole domain turns on. Dojah bills us for a lookup that
     * finds nobody; the customer still gets their money back, because they
     * did not receive what they bought.
     */
    public function testANotFoundLookupIsRefundedInFullAndRecordedAsItsOwnOutcome()
    {
        list($app, $user) = $this->app();

        $res = $app->identityservice->verify($user, $this->request(array('identifier' => '70123459999')));

        $this->assertFalse($res['ok']);
        $this->assertFalse($res['found']);
        $this->assertSame('10000.00000000', $app->balance($user),
            'a lookup that found nobody must cost the customer nothing');
        $this->assertSame('NOT_FOUND', $res['check']->status);
        $this->assertSame('FAILED', $res['transaction']->status);
        $this->assertSame('250.00000000', $res['transaction']->refunded_amount);
        list($d, $c) = $app->ledger_is_balanced();
        $this->assertSame($d, $c);
    }

    /**
     * NOT_FOUND and FAILED are different events on purpose: one is a fraud or
     * typo signal, the other is an outage signal. Collapsing them would make
     * both unanswerable.
     */
    public function testAVendorOutageIsRefundedButRecordedAsFailedNotNotFound()
    {
        list($app, $user) = $this->app();

        $res = $app->identityservice->verify($user, $this->request(array('identifier' => '70123450000')));

        $this->assertFalse($res['ok']);
        $this->assertSame('FAILED', $res['check']->status,
            'we never got an answer — that is not the same as "no such person"');
        $this->assertNull($res['found']);
        $this->assertSame('10000.00000000', $app->balance($user));
    }

    /** A refunded not-found still leaves a receipt the customer can open. */
    public function testARefundedLookupStillProducesATransactionToShowTheCustomer()
    {
        list($app, $user) = $this->app();

        $res = $app->identityservice->verify($user, $this->request(array('identifier' => '70123459999')));

        $this->assertNotEmpty($res['transaction'],
            'the dashboard redirects to this receipt; without it the customer sees only a banner');
        $this->assertNotEmpty($res['transaction']->public_id);
    }

    public function testAnAdminRefundOfAVerifiedCheckReturnsTheMoneyOnce()
    {
        list($app, $user) = $this->app();
        $app->library('TransactionEngine');
        $res = $app->identityservice->verify($user, $this->request());

        $first  = $app->transactionengine->transition($res['transaction']->id, 'REFUNDED', 'ADMIN', 'goodwill');
        $second = $app->transactionengine->transition($res['transaction']->id, 'REFUNDED', 'ADMIN', 'again');

        $this->assertTrue($first['ok']);
        $this->assertFalse($second['ok'], 'a second refund must be refused, not silently ignored');
        $this->assertSame('10000.00000000', $app->balance($user));
        list($d, $c) = $app->ledger_is_balanced();
        $this->assertSame($d, $c);
    }

    public function testACustomerWhoCannotAffordTheCheckIsNeverSentToTheVendor()
    {
        list($app, $user) = $this->app('10');

        $res = $app->identityservice->verify($user, $this->request());

        $this->assertFalse($res['ok']);
        $this->assertSame('INSUFFICIENT_BALANCE', $res['code']);
        $this->assertCount(0, MockIdentityAdapter::$calls,
            'a lookup we cannot charge for must not cost us a vendor call');
        $this->assertCount(0, $app->rows('identity_checks'));
    }

    public function testARetriedSubmissionChargesOnce()
    {
        list($app, $user) = $this->app();
        $req = $this->request(array('idempotency_key' => 'idt:1:same'));

        $first  = $app->identityservice->verify($user, $req);
        $second = $app->identityservice->verify($user, $req);

        $this->assertTrue($second['ok']);
        $this->assertSame($first['transaction']->public_id, $second['transaction']->public_id);
        $this->assertSame('9750.00000000', $app->balance($user));
        $this->assertCount(1, MockIdentityAdapter::$calls,
            'a double-submit must not become a second billed lookup');
    }

    /* ====================== consent and validation ======================= */

    /**
     * Running a government identity check on someone who has not agreed to it
     * is the illegal version of this product. It is refused before anything is
     * charged and before the vendor is called.
     */
    public function testALookupWithoutConsentIsRefusedBeforeAnythingIsCharged()
    {
        list($app, $user) = $this->app();

        $res = $app->identityservice->verify($user, $this->request(array('consent' => false)));

        $this->assertFalse($res['ok']);
        $this->assertSame('NO_CONSENT', $res['code']);
        $this->assertSame('10000.00000000', $app->balance($user));
        $this->assertCount(0, MockIdentityAdapter::$calls);
        $this->assertCount(0, $app->rows('service_transactions'));
    }

    public function testConsentIsRecordedOnTheRowThatProvesTheLookupHappened()
    {
        list($app, $user) = $this->app();

        $res = $app->identityservice->verify($user, $this->request());

        $this->assertNotEmpty($res['check']->consent_at);
        $this->assertSame('102.89.1.7', $res['check']->consent_ip);
    }

    /**
     * Pre-charge validation is a money control, not a UX nicety: the vendor
     * bills us for a lookup it was always going to reject.
     */
    public function testAMalformedIdentifierIsRejectedBeforeItCostsAnything()
    {
        list($app, $user) = $this->app();

        foreach (array('7012345678', '701234567812', 'abcdefghijk', '') as $bad) {
            $res = $app->identityservice->verify($user, $this->request(array('identifier' => $bad)));
            $this->assertFalse($res['ok'], 'accepted a malformed identifier: '.$bad);
            $this->assertSame('BAD_IDENTIFIER', $res['code']);
        }
        $this->assertSame('10000.00000000', $app->balance($user));
        $this->assertCount(0, MockIdentityAdapter::$calls);
    }

    /** The commonest customer error: pasting a NIN into the BVN form. */
    public function testANinPastedIntoTheBvnFormIsCaughtLocally()
    {
        list($app, $user) = $this->app();

        $res = $app->identityservice->verify($user, $this->request(
            array('product' => 'BVN_BASIC', 'identifier' => '70123456781')));

        $this->assertFalse($res['ok']);
        $this->assertSame('BAD_IDENTIFIER', $res['code']);
        $this->assertStringContainsString('22', $res['error']);
        $this->assertCount(0, MockIdentityAdapter::$calls);
    }

    public function testABvnLookupWithARealBvnIsAccepted()
    {
        list($app, $user) = $this->app();

        $res = $app->identityservice->verify($user, $this->request(
            array('product' => 'BVN_BASIC', 'identifier' => '22222222221')));

        $this->assertTrue($res['ok'], $res['error'] ?? '');
        $this->assertSame('BVN', $res['transaction']->service_type);
        $this->assertSame('9700.00000000', $app->balance($user));
    }

    /** A phone-lookup product validates a phone number, not an 11-digit id. */
    public function testAPhoneLookupValidatesAPhoneNumberInstead()
    {
        list($app, $user) = $this->app();

        $bad = $app->identityservice->verify($user, $this->request(
            array('product' => 'NIN_PHONE', 'identifier' => '70123456781')));
        $this->assertFalse($bad['ok']);
        $this->assertSame('BAD_IDENTIFIER', $bad['code']);

        foreach (array('08031234561', '2348031234561') as $good) {
            $res = $app->identityservice->verify($user, $this->request(
                array('product' => 'NIN_PHONE', 'identifier' => $good)));
            $this->assertTrue($res['ok'], $res['error'] ?? '');
            $this->assertSame('PHONE', $res['check']->lookup_field);
        }
    }

    /* ========================== the catalogue ============================ */

    public function testAnUnpricedProductIsNotSellable()
    {
        list($app, $user) = $this->app();

        $res = $app->identityservice->verify($user, $this->request(array('product' => 'NIN_UNPRICED')));

        $this->assertFalse($res['ok']);
        $this->assertSame('NO_PRICE', $res['code'],
            'a catalogue row with no price would be a free lookup that still costs us a vendor call');
        $this->assertCount(0, MockIdentityAdapter::$calls);
    }

    public function testAnUnpricedProductIsNotOfferedToCustomersEither()
    {
        list($app,) = $this->app();

        $codes = array();
        foreach ($app->Identity_product_model->active() as $p) $codes[] = $p->code;

        $this->assertContains('NIN_BASIC', $codes);
        $this->assertNotContains('NIN_UNPRICED', $codes, 'the storefront must hide unpriced rows');
        $this->assertNotContains('BVN_OFF', $codes, 'the storefront must hide inactive rows');
    }

    public function testAnInactiveProductCannotBeBoughtByCode()
    {
        list($app, $user) = $this->app();

        $res = $app->identityservice->verify($user, $this->request(array('product' => 'BVN_OFF')));

        $this->assertFalse($res['ok']);
        $this->assertSame('NO_PRODUCT', $res['code']);
    }

    /* ============================= retention ============================= */

    public function testThePurgeSweepEmptiesThePayloadAndKeepsTheEvidence()
    {
        list($app, $user) = $this->app();
        $res = $app->identityservice->verify($user, $this->request());
        // Age the check past any plausible retention window.
        $app->db->where('id', $res['check']->id)->update('identity_checks',
            array('created_at' => gmdate('Y-m-d H:i:s', time() - (400 * 86400))));

        $out = $app->identityservice->purge_expired(30);

        $this->assertSame(1, $out['processed']);
        $row = $app->Identity_check_model->find_by_id($res['check']->id);
        $this->assertNull($row->result_encrypted);
        $this->assertNotEmpty($row->purged_at);
        // The proof that a paid check happened must survive the erasure.
        $this->assertSame('VERIFIED', $row->status);
        $this->assertSame('6781', $row->identifier_last4);
        $this->assertCount(1, $app->rows('service_transactions'));
    }

    public function testThePurgeSweepLeavesResultsInsideTheirWindowAlone()
    {
        list($app, $user) = $this->app();
        $app->identityservice->verify($user, $this->request());

        $out = $app->identityservice->purge_expired(30);

        $this->assertSame(0, $out['processed']);
        $this->assertNotEmpty($app->rows('identity_checks')[0]['result_encrypted']);
    }

    public function testASecondSweepDoesNoWorkAndKeepsTheOriginalPurgeTimestamp()
    {
        list($app, $user) = $this->app();
        $res = $app->identityservice->verify($user, $this->request());
        $app->db->where('id', $res['check']->id)->update('identity_checks',
            array('created_at' => gmdate('Y-m-d H:i:s', time() - (400 * 86400))));

        $app->identityservice->purge_expired(30);
        $first = $app->Identity_check_model->find_by_id($res['check']->id)->purged_at;
        $second_run = $app->identityservice->purge_expired(30);

        $this->assertSame(0, $second_run['processed']);
        $this->assertSame($first,
            $app->Identity_check_model->find_by_id($res['check']->id)->purged_at);
    }

    public function testAPurgedResultCannotBeRevealed()
    {
        list($app, $user) = $this->app();
        $res = $app->identityservice->verify($user, $this->request());
        $app->Identity_check_model->purge($res['check']->id);

        $out = $app->identityservice->reveal(
            $app->Identity_check_model->find_by_id($res['check']->id), $user, 'ADMIN');

        $this->assertFalse($out['ok']);
        $this->assertSame('PURGED', $out['code']);
    }

    public function testRetentionIsConfigurableAndCanBeDisabled()
    {
        list($app,) = $this->app();

        $this->assertSame(30, $app->identityservice->retention_days());
        $app->config->set_item('identity_retention_days', 7);
        $this->assertSame(7, $app->identityservice->retention_days());

        $out = $app->identityservice->purge_expired(0);
        $this->assertSame('retention disabled', $out['message'],
            'a zero window must mean "keep", not "delete everything immediately"');
    }

    /* ============================== the worker =========================== */

    public function testTheWorkerScrubsExpiredResults()
    {
        list($app, $user) = $this->app();
        $app->library('CronWorkers');
        $res = $app->identityservice->verify($user, $this->request());
        $app->db->where('id', $res['check']->id)->update('identity_checks',
            array('created_at' => gmdate('Y-m-d H:i:s', time() - (400 * 86400))));

        $out = $app->cronworkers->identity_purge();

        $this->assertSame(1, $out['processed']);
        $this->assertSame(0, $out['failed']);
        $this->assertNull($app->Identity_check_model->find_by_id($res['check']->id)->result_encrypted);
    }

    public function testTheWorkerIsAQuietNoOpWithNothingToScrub()
    {
        list($app,) = $this->app();
        $app->library('CronWorkers');

        $out = $app->cronworkers->identity_purge();

        $this->assertSame(0, $out['processed']);
        $this->assertSame(0, $out['failed']);
        $this->assertSame('nothing to purge', $out['message']);
    }

    /* ======================== the Dojah integration ====================== */

    private static function fixture($name)
    {
        $path = self::$root.'/tests/fixtures/dojah/'.$name;
        if (!file_exists($path)) throw new RuntimeException('missing fixture '.$name);
        return file_get_contents($path);
    }

    private function provider(array $overrides = array())
    {
        return (object)array_merge(array(
            'id' => 11, 'public_id' => 'PROV0000000000000000000012', 'name' => 'Dojah',
            'api_url' => DojahAdapter::SANDBOX_URL,
            'api_key_encrypted' => '{"api_key":"test_sk_live_xyz","app_id":"6512a0f0e1"}',
            'api_type' => 'DOJAH', 'status' => 'ACTIVE', 'currency' => 'NGN',
            'timeout_ms' => 30000, 'retry_policy' => null,
        ), $overrides);
    }

    private function adapter(array $script, array $overrides = array())
    {
        $GLOBALS['__fake_ci'] = new IdentityFakeCI();
        $http = new IdentityFakeHttp($script);
        return array(new DojahAdapter($this->provider($overrides), $http), $http);
    }

    private static function ok($body, $code = 200)
    {
        return array('http_code' => $code, 'body' => $body, 'request_id' => 'rid');
    }

    /**
     * The mistake every other vendor in this codebase trains you to make.
     * Dojah wants the raw key in Authorization; "Bearer " in front is a 401.
     */
    public function testTheAuthorizationHeaderIsTheRawKeyWithNoBearerPrefix()
    {
        list($adapter, $http) = $this->adapter(array(self::ok(self::fixture('nin_found.json'))));
        $adapter->lookup(array('id_type' => 'NIN', 'lookup_field' => 'IDENTIFIER',
                               'identifier' => '70123456789'));

        $headers = $http->calls[0]['headers'];
        $this->assertContains('Authorization: test_sk_live_xyz', $headers);
        $this->assertNotContains('Authorization: Bearer test_sk_live_xyz', $headers);
        $this->assertContains('AppId: 6512a0f0e1', $headers,
            'the app id travels in its own header, not in Authorization');
    }

    public function testTheLookupHitsTheRightEndpointForEachIdTypeAndField()
    {
        $cases = array(
            array('NIN', 'IDENTIFIER', '/api/v1/kyc/nin',  'nin'),
            array('BVN', 'IDENTIFIER', '/api/v1/kyc/bvn',  'bvn'),
            array('NIN', 'PHONE', '/api/v1/kyc/nin/phone_number', 'phone_number'),
        );
        foreach ($cases as $case) {
            list($type, $field, $path, $param) = $case;
            list($adapter, $http) = $this->adapter(array(self::ok(self::fixture('nin_found.json'))));
            $adapter->lookup(array('id_type' => $type, 'lookup_field' => $field,
                                   'identifier' => '70123456789'));

            $this->assertStringContainsString($path, $http->calls[0]['url']);
            $this->assertSame('70123456789', $http->calls[0]['query'][$param]);
        }
    }

    public function testAVerifiedLookupIsMappedOntoTheStableEntityShape()
    {
        list($adapter,) = $this->adapter(array(self::ok(self::fixture('nin_found.json'))));

        $res = $adapter->lookup(array('id_type' => 'NIN', 'lookup_field' => 'IDENTIFIER',
                                      'identifier' => '70123456789'));

        $this->assertTrue($res['ok']);
        $this->assertTrue($res['found']);
        $this->assertSame('Ada', $res['entity']['first_name']);
        $this->assertSame('Okafor', $res['entity']['last_name'],
            'vendors shout; screens should not');
        $this->assertSame('1990-04-12', $res['entity']['date_of_birth']);
        $this->assertSame('08031234567', $res['entity']['phone_number']);
    }

    /** The photograph is dropped at the boundary, before anything else sees it. */
    public function testThePhotoIsStrippedByTheAdapterItself()
    {
        list($adapter,) = $this->adapter(array(self::ok(self::fixture('nin_found.json'))));

        $res = $adapter->lookup(array('id_type' => 'NIN', 'lookup_field' => 'IDENTIFIER',
                                      'identifier' => '70123456789'));

        $this->assertArrayNotHasKey('photo', $res['entity']);
        $this->assertArrayNotHasKey('image', $res['entity']);
        $this->assertStringNotContainsString('base64', json_encode($res['entity']));
    }

    /** Dojah's advance/vNIN responses use different field spellings entirely. */
    public function testTheAlternateVendorFieldSpellingsAreUnderstood()
    {
        list($adapter,) = $this->adapter(array(self::ok(self::fixture('nin_advance.json'))));

        $res = $adapter->lookup(array('id_type' => 'NIN', 'lookup_field' => 'IDENTIFIER',
                                      'identifier' => '70123456789'));

        $this->assertSame('John', $res['entity']['first_name'], 'firstname → first_name');
        $this->assertSame('Doe', $res['entity']['last_name'], 'surname → last_name');
        $this->assertSame('1985-11-02', $res['entity']['date_of_birth'], 'birthdate → date_of_birth');
        $this->assertSame('2348051234567', $res['entity']['phone_number']);
        $this->assertSame('Lagos', $res['entity']['state_of_origin']);
        $this->assertArrayNotHasKey('photo', $res['entity']);
    }

    /** The basic BVN lookup answers {value,status} per field, not bare strings. */
    public function testThePerFieldBvnResponseShapeIsFlattened()
    {
        list($adapter,) = $this->adapter(array(self::ok(self::fixture('bvn_found.json'))));

        $res = $adapter->lookup(array('id_type' => 'BVN', 'lookup_field' => 'IDENTIFIER',
                                      'identifier' => '22222222222'));

        $this->assertTrue($res['found']);
        $this->assertSame('Ada', $res['entity']['first_name']);
        $this->assertSame('Female', $res['entity']['gender']);
        $this->assertIsString($res['entity']['date_of_birth']);
        $this->assertArrayNotHasKey('image', $res['entity']);
    }

    public function testTheBvnPhoneLookupReadsTheFlatShape()
    {
        list($adapter,) = $this->adapter(array(self::ok(self::fixture('bvn_phone_found.json'))));

        $res = $adapter->lookup(array('id_type' => 'BVN', 'lookup_field' => 'PHONE',
                                      'identifier' => '08099887766'));

        $this->assertTrue($res['found']);
        $this->assertSame('Musa', $res['entity']['first_name']);
        $this->assertSame('08099887766', $res['entity']['phone_number'],
            'phone_number1 is the vendor spelling of the primary number');
    }

    /**
     * 404 is the answer, not a failure. Reading it as an outage would look
     * like the vendor is broken; reading it as a success would charge the
     * customer for an empty result.
     */
    public function testAnUnknownIdentityIsAnAnswerRatherThanAnError()
    {
        list($adapter,) = $this->adapter(array(self::ok(self::fixture('nin_not_found.json'), 404)));

        $res = $adapter->lookup(array('id_type' => 'NIN', 'lookup_field' => 'IDENTIFIER',
                                      'identifier' => '70123400000'));

        $this->assertTrue($res['ok'], '404 means the vendor answered');
        $this->assertFalse($res['found']);
        $this->assertSame(array(), $res['entity']);
        $this->assertNull($res['error']);
    }

    /** Some 200s carry "not found" in prose instead of a status code. */
    public function testAProseNotFoundAtHttp200IsTreatedTheSameWay()
    {
        list($adapter,) = $this->adapter(array(self::ok(self::fixture('nin_not_found.json'), 200)));

        $res = $adapter->lookup(array('id_type' => 'NIN', 'lookup_field' => 'IDENTIFIER',
                                      'identifier' => '70123400000'));

        $this->assertTrue($res['ok']);
        $this->assertFalse($res['found']);
    }

    /** 200 with an empty entity must not become a verified identity. */
    public function testAnEmptyEntityIsNotAVerifiedIdentity()
    {
        list($adapter,) = $this->adapter(array(self::ok(self::fixture('empty_entity.json'))));

        $res = $adapter->lookup(array('id_type' => 'NIN', 'lookup_field' => 'IDENTIFIER',
                                      'identifier' => '70123456789'));

        $this->assertTrue($res['ok']);
        $this->assertFalse($res['found']);
    }

    /**
     * The two that must never be charged as a delivered lookup: our own wallet
     * being empty, and the government source being down. Neither says anything
     * about the customer's data.
     */
    public function testOurOwnBillingAndUpstreamOutagesAreFailuresNotAnswers()
    {
        $cases = array(
            array('error_402.json', 402, 'wallet'),
            array('error_424.json', 424, 'NIMC'),
            array('error_401.json', 401, 'credentials'),
            array('error_429.json', 429, 'rate-limiting'),
        );
        foreach ($cases as $case) {
            list($fixture, $code, $needle) = $case;
            list($adapter,) = $this->adapter(array(self::ok(self::fixture($fixture), $code)));

            $res = $adapter->lookup(array('id_type' => 'NIN', 'lookup_field' => 'IDENTIFIER',
                                          'identifier' => '70123456789'));

            $this->assertFalse($res['ok'], 'HTTP '.$code.' must not read as an answer');
            $this->assertArrayNotHasKey('found', $res);
            $this->assertStringContainsString($needle, $res['error'],
                'HTTP '.$code.' must produce an operator-readable message');
        }
    }

    /**
     * Error messages are built from the status code, never from the request.
     * A vendor that echoes the identifier back in its message must not become
     * the route by which a NIN reaches a flash message and a log.
     */
    public function testAVendorMessageEchoingTheIdentifierIsNotRepeatedBack()
    {
        list($adapter,) = $this->adapter(array(self::ok(self::fixture('error_400_echo.json'), 400)));

        $res = $adapter->lookup(array('id_type' => 'NIN', 'lookup_field' => 'IDENTIFIER',
                                      'identifier' => '70123456789'));

        $this->assertFalse($res['ok']);
        $this->assertStringNotContainsString('70123456789', $res['error']);
        $this->assertSame('The vendor rejected the identifier as malformed', $res['error']);
    }

    /** SecureHttpClient logs the URL it fetched; the URL holds a NIN. */
    public function testTheRequestIdIsRedactedSoTheUrlIsNeverCorrelatedToACustomer()
    {
        list($adapter, $http) = $this->adapter(array(self::ok(self::fixture('nin_found.json'))));
        $adapter->lookup(array('id_type' => 'NIN', 'lookup_field' => 'IDENTIFIER',
                               'identifier' => '70123456789'));

        $this->assertSame('identity-redacted', $http->calls[0]['options']['request_id']);
    }

    public function testTransportFailuresReportAsUnreachableRatherThanLeakTheUrl()
    {
        list($adapter,) = $this->adapter(array(
            array('http_code' => 0, 'body' => '', 'error' => 'connect timeout to sandbox.dojah.io')));

        $res = $adapter->lookup(array('id_type' => 'NIN', 'lookup_field' => 'IDENTIFIER',
                                      'identifier' => '70123456789'));

        $this->assertFalse($res['ok']);
        $this->assertSame('Could not reach the identity vendor', $res['error']);
    }

    public function testAnUnofferedCheckIsRefusedWithoutACall()
    {
        list($adapter, $http) = $this->adapter(array());

        $res = $adapter->lookup(array('id_type' => 'PASSPORT', 'lookup_field' => 'IDENTIFIER',
                                      'identifier' => '70123456789'));

        $this->assertFalse($res['ok']);
        $this->assertCount(0, $http->calls);
    }

    public function testAnEmptyIdentifierNeverReachesTheVendor()
    {
        list($adapter, $http) = $this->adapter(array());

        $res = $adapter->lookup(array('id_type' => 'NIN', 'lookup_field' => 'IDENTIFIER',
                                      'identifier' => '   '));

        $this->assertFalse($res['ok']);
        $this->assertCount(0, $http->calls);
    }

    /** A vendor renaming an endpoint must be a config change, not a release. */
    public function testEndpointsAreOverridableFromTheProviderRow()
    {
        list($adapter, $http) = $this->adapter(
            array(self::ok(self::fixture('nin_found.json'))),
            array('retry_policy' => json_encode(array('dojah' => array('endpoints' => array(
                'NIN:IDENTIFIER' => array('/api/v2/kyc/nin/lookup', 'id'),
            )))))
        );

        $adapter->lookup(array('id_type' => 'NIN', 'lookup_field' => 'IDENTIFIER',
                               'identifier' => '70123456789'));

        $this->assertStringContainsString('/api/v2/kyc/nin/lookup', $http->calls[0]['url']);
        $this->assertSame('70123456789', $http->calls[0]['query']['id']);
    }

    public function testTheBalanceProbeReadsTheVendorWallet()
    {
        list($adapter,) = $this->adapter(array(self::ok(self::fixture('balance.json'))));

        $res = $adapter->balance();

        $this->assertTrue($res['ok']);
        $this->assertSame('148250.75000000', $res['balance']);
        $this->assertSame('NGN', $res['currency']);
    }

    public function testABadCredentialBalanceProbeFailsLoudly()
    {
        list($adapter,) = $this->adapter(array(self::ok(self::fixture('error_401.json'), 401)));

        $res = $adapter->balance();

        $this->assertFalse($res['ok']);
        $this->assertStringContainsString('credentials', $res['error']);
    }

    /**
     * Dojah bills its own prepaid wallet and returns no per-call figure. The
     * adapter must say so rather than invent a cost that would corrupt every
     * margin report in the panel.
     */
    public function testNoPerCallCostIsInvented()
    {
        list($adapter,) = $this->adapter(array(self::ok(self::fixture('nin_found.json'))));

        $res = $adapter->lookup(array('id_type' => 'NIN', 'lookup_field' => 'IDENTIFIER',
                                      'identifier' => '70123456789'));

        $this->assertArrayHasKey('cost', $res);
        $this->assertNull($res['cost']);
    }

    /* ========================= registry + wiring ========================= */

    public function testTheRegistryBuildsIdentityAdaptersForTheirOwnFamily()
    {
        $app = new IntegrationHarness();
        $app->seed_minimal();
        $app->library('Provider_manager');

        $types = Provider_manager::supported_types(Provider_manager::FAMILY_IDENTITY);
        $this->assertContains('DOJAH', $types);
        $this->assertContains('MOCK_IDENTITY', $types);
        $this->assertNotContains('DOJAH',
            Provider_manager::supported_types(Provider_manager::FAMILY_VTU),
            'a KYC vendor cannot service an airtime purchase');

        $adapter = $app->provider_manager->adapter($this->provider(), Provider_manager::FAMILY_IDENTITY);
        $this->assertInstanceOf('DojahAdapter', $adapter);
        $this->assertInstanceOf('IdentityProviderInterface', $adapter);
    }

    public function testBothIdentityAdaptersImplementTheWholeInterface()
    {
        foreach (array('DojahAdapter', 'MockIdentityAdapter') as $class) {
            foreach (array('lookup', 'balance') as $method) {
                $this->assertTrue(method_exists($class, $method), $class.' must implement '.$method.'()');
            }
        }
    }

    public function testTheAdminApiTypeWhitelistIncludesTheIdentityFamily()
    {
        require_once self::$root.'/application/libraries/Provider_manager.php';
        require_once self::$root.'/application/libraries/ProviderSyncService.php';

        $this->assertContains('DOJAH', ProviderSyncService::supported_types(),
            'a registered adapter the create form refuses is unusable');
        $this->assertSame(Provider_manager::FAMILY_IDENTITY,
            ProviderSyncService::family((object)array('api_type' => 'DOJAH')));
    }

    /**
     * Dojah needs two credentials. A create form that accepts one silently
     * produces a provider that 401s on its first real lookup.
     */
    public function testTheProviderFormDemandsBothDojahCredentials()
    {
        $src = file_get_contents(self::$root.'/application/libraries/ProviderSyncService.php');
        $this->assertStringContainsString('dojah_credential_errors', $src);
        $this->assertStringContainsString("'app_id'", $src);

        $view = file_get_contents(self::$root.'/application/views/admin/providers/index.php');
        $this->assertStringContainsString('AppId', $view,
            'the create form must ask for the app id, not just the secret key');
    }

    /** Identity vendors publish no catalogue; saying so beats a fatal error. */
    public function testCatalogueSyncIsExplicitlyUnsupportedForIdentityVendors()
    {
        $src = file_get_contents(self::$root.'/application/libraries/ProviderSyncService.php');
        $this->assertStringContainsString('Identity vendors have no catalogue to sync', $src);
    }

    /* ====================== admin-surface contract ======================= */

    private function controller()
    {
        return file_get_contents(self::$root.'/application/controllers/admin/Identity.php');
    }

    /** Core_seeder, loaded with its base class, whichever test ran first. */
    private static function seeder()
    {
        if (!class_exists('Core_seeder')) {
            if (!class_exists('Seeder')) require_once self::$root.'/application/libraries/Seeder.php';
            require_once self::$root.'/application/seeds/Core_seeder.php';
        }
        return 'Core_seeder';
    }

    public function testTheAdminScreensExist()
    {
        $this->assertFileExists(self::$root.'/application/controllers/admin/Identity.php',
            'identity permissions are seeded, so the screens that use them must exist');
        $this->assertFileExists(self::$root.'/application/views/admin/identity/index.php');
        $this->assertFileExists(self::$root.'/application/views/admin/identity/detail.php');
    }

    public function testEveryAdminMutationIsPostOnlyAndGuarded()
    {
        $src = $this->controller();
        foreach (array('index', 'detail', 'reveal', 'refund', 'purge') as $action) {
            $this->assertStringContainsString('function '.$action.'(', $src,
                "admin/Identity.php must define {$action}()");
        }
        $this->assertStringContainsString("method(true) !== 'POST') show_404()", $src,
            'admin/Identity.php must reject non-POST mutations');
        $this->assertSame(3, substr_count($src, '$this->guard('),
            'every mutation must go through guard()');
    }

    /**
     * Seeing that a check ran and reading the person's record are different
     * levels of access. If reveal only needed identity.view, the separate
     * permission would be decoration.
     */
    public function testRevealingARecordNeedsItsOwnPermission()
    {
        $src = $this->controller();
        $this->assertStringContainsString("require_perm('identity.view')", $src);
        $this->assertStringContainsString("'identity.reveal'", $src);
        $this->assertStringContainsString("'identity.refund'", $src);

        $matrix = self::seeder()::role_matrix();
        $this->assertContains('identity.view', $matrix['STAFF']);
        $this->assertNotContains('identity.reveal', $matrix['STAFF'],
            'support does not need a stranger\'s date of birth to answer "did my check work?"');
        $this->assertContains('identity.reveal', $matrix['ADMIN']);
    }

    public function testTheIdentityPermissionsAreSeeded()
    {
        $catalog = self::seeder()::permission_catalog();
        $this->assertArrayHasKey('identity', $catalog);
        foreach (array('identity.view','identity.manage','identity.refund','identity.reveal') as $perm) {
            $this->assertContains($perm, $catalog['identity']);
        }
    }

    public function testAdminMutationsAreAuditLogged()
    {
        $src = $this->controller();
        $this->assertStringContainsString('Audit_log_model', $src);
        // reveal() is audited inside IdentityService, on the path that does
        // the decryption, so it cannot be bypassed by a second caller.
        $this->assertSame(2, substr_count($src, '$this->audit('),
            'refund and purge must each record what they did');
        $service = file_get_contents(self::$root.'/application/libraries/IdentityService.php');
        $this->assertStringContainsString("'identity.result.reveal'", $service);
    }

    public function testNeitherControllerMovesMoneyOrDecryptsAnythingItself()
    {
        foreach (array('admin/Identity.php', 'dashboard/Identity.php') as $rel) {
            $src = file_get_contents(self::$root.'/application/controllers/'.$rel);
            $this->assertStringNotContainsString('ledgerservice->', $src,
                $rel.': refunds must go through the service layer, not the ledger');
            $this->assertStringNotContainsString("update('wallets'", $src);
            $this->assertStringNotContainsString("update('service_transactions'", $src,
                $rel.': the status column belongs to TransactionEngine');
            $this->assertStringNotContainsString('encryptionservice->', $src,
                $rel.': the only path to a plaintext result is IdentityService::reveal()');
        }
    }

    public function testTheCustomerScreensNeverSeeAnotherCustomersCheck()
    {
        $src = file_get_contents(self::$root.'/application/controllers/dashboard/Identity.php');
        $this->assertStringContainsString('find_public_for_user', $src,
            'a check must be looked up scoped to the signed-in user');
        $this->assertStringNotContainsString('admin_find', $src);
    }

    /**
     * A NIN in a flash message is a NIN in the session store, and a NIN in a
     * redirect is a NIN in the access log of every proxy in between.
     */
    public function testTheCustomerControllerNeverPutsTheIdentifierInASessionOrAUrl()
    {
        $src = file_get_contents(self::$root.'/application/controllers/dashboard/Identity.php');
        $this->assertSame(1, substr_count($src, "post('identifier'"),
            'the identifier is read once, handed to the service and dropped');
        $this->assertDoesNotMatchRegularExpression(
            '~set_flashdata\([^)]*identifier~i', $src,
            'a decrypted identifier must never reach the session store');
        $this->assertDoesNotMatchRegularExpression(
            '~redirect\([^)]*identifier~i', $src);
    }

    /** Likewise for the decrypted record: rendered, never flashed. */
    public function testARevealedRecordIsRenderedRatherThanFlashedOrRedirectedTo()
    {
        $src = file_get_contents(self::$root.'/application/controllers/dashboard/Identity.php');
        $this->assertDoesNotMatchRegularExpression(
            '~set_flashdata\([^)]*\$res\[.entity.\]~', $src);
        $this->assertStringContainsString("'entity'  => \$res['entity']", $src,
            'the record goes into this one response and nowhere else');
    }

    public function testTheListEndpointsAreBounded()
    {
        foreach (array('admin/Identity.php', 'dashboard/Identity.php') as $rel) {
            $src = file_get_contents(self::$root.'/application/controllers/'.$rel);
            $this->assertStringContainsString('const PER_PAGE', $src,
                $rel.' must paginate its queue');
        }
    }

    public function testTheViewsCarryCsrfTokensAndNeverRenderASecretOrAPhoto()
    {
        $views = array(
            'admin/identity/index.php', 'admin/identity/detail.php',
            'dashboard/identity/index.php', 'dashboard/identity/detail.php',
            'dashboard/identity/history.php',
        );
        foreach ($views as $rel) {
            $src = file_get_contents(self::$root.'/application/views/'.$rel);
            if (strpos($src, 'method="post"') !== false) {
                $this->assertStringContainsString('get_csrf_token_name()', $src,
                    $rel.' has a POST form without a CSRF token');
            }
            $this->assertStringNotContainsString('api_key_encrypted', $src);
            $this->assertStringNotContainsString('identifier_hash', $src,
                $rel.': the blind index is a lookup key, not something to display');
            $this->assertStringNotContainsString('<img', $src,
                $rel.': the panel never renders a vendor photograph');
        }
    }

    /**
     * The admin queue is a list of 25 rows. Joining the encrypted payload into
     * it would drag 25 identity records through the app on every page load,
     * and every one of those reads would be unaudited.
     */
    public function testTheAdminQueueNeverSelectsTheEncryptedPayload()
    {
        $src = file_get_contents(self::$root.'/application/models/Service_transaction_model.php');
        $this->assertStringContainsString('identity_checks.identifier_last4', $src);
        $this->assertStringNotContainsString('identity_checks.result_encrypted', $src,
            'a queue must never carry encrypted identity records');
        $this->assertStringNotContainsString('identity_checks.identifier_hash', $src);
    }

    public function testTheAdminProjectionIsScopedToTheIdentityDomain()
    {
        list($app, $user) = $this->app();
        $app->identityservice->verify($user, $this->request());

        $rows = $app->Service_transaction_model->admin_search(array('domain' => 'IDENTITY'), 25, 0);

        $this->assertCount(1, $rows);
        $this->assertSame('NIN', $rows[0]->id_type);
        $this->assertSame('6781', $rows[0]->identifier_last4);
        $this->assertSame('VERIFIED', $rows[0]->check_status);
        $this->assertSame('IDENTITY', $rows[0]->service_domain);
        // What the projection must *not* select is asserted against the query
        // itself in testTheAdminQueueNeverSelectsTheEncryptedPayload: FakeDb
        // merges every joined column onto the base row for `table.*`, so it
        // cannot distinguish a column that was selected from one that was not.
    }

    /* ============================= scheduling ============================ */

    /**
     * Retention that is configured but never runs is worse than no retention:
     * it is a promise on the customer-facing page that the database does not
     * keep.
     */
    public function testThePurgeWorkerIsScheduledAndWired()
    {
        $config = file_get_contents(self::$root.'/application/config/windels.php');
        $this->assertStringContainsString("'identity_purge'", $config,
            'the retention sweep must have a schedule');

        $controller = file_get_contents(self::$root.'/application/controllers/Cron.php');
        $this->assertStringContainsString('function identity_purge(', $controller);
        $this->assertStringContainsString("\$this->execute('identity_purge'", $controller,
            'the job must run under the JobRunner lock');

        $crontab = file_get_contents(self::$root.'/cron/crontab.example');
        $this->assertStringContainsString('cron identity_purge', $crontab);
    }

    public function testTheRetentionWindowIsAnOperatorSetting()
    {
        $found = false;
        foreach (self::seeder()::default_settings() as $setting) {
            if ($setting[0] === 'identity_retention_days') $found = true;
        }
        $this->assertTrue($found, 'identity_retention_days must be a seeded setting');
    }

    /* =============================== schema ============================== */

    public function testTheCheckTableHoldsNoRecoverableIdentifier()
    {
        $sql = $this->table_sql('identity_checks');

        $this->assertStringContainsString('identifier_hash CHAR(64) NOT NULL', $sql);
        $this->assertStringContainsString('identifier_last4', $sql);
        // The column that must not exist. An encrypted identifier would still
        // be a recoverable copy of the most sensitive field in the system.
        $this->assertDoesNotMatchRegularExpression('/\bidentifier\s+VARCHAR/i', $sql,
            'the raw identifier must have no column at all');
        $this->assertDoesNotMatchRegularExpression('/identifier_encrypted/i', $sql);
    }

    /** A cleartext convenience column would defeat both controls on day one. */
    public function testTheCheckTableHasNoCleartextPiiColumn()
    {
        $sql = $this->table_sql('identity_checks');
        foreach (array('full_name', 'first_name', 'last_name', 'date_of_birth', 'photo') as $column) {
            $this->assertDoesNotMatchRegularExpression('/\b'.$column.'\s+(VARCHAR|TEXT|DATE)/i', $sql,
                'identity_checks must not hold '.$column.' beside the encrypted blob');
        }
    }

    public function testTheCheckTableCarriesTheRetentionAndAccessTrail()
    {
        $sql = $this->table_sql('identity_checks');
        foreach (array('result_encrypted', 'purged_at', 'reveal_count',
                       'last_revealed_at', 'last_revealed_by', 'consent_at', 'consent_ip') as $column) {
            $this->assertStringContainsString($column, $sql, 'identity_checks needs '.$column);
        }
        $this->assertStringContainsString('idx_idchk_purge', $sql,
            'the nightly sweep scans (purged_at, created_at) and needs the index');
        $this->assertStringContainsString('idx_idchk_hash', $sql,
            'lookup by blind index must not be a table scan');
        $this->assertStringContainsString(
            'service_transaction_id BIGINT UNSIGNED NOT NULL UNIQUE', $sql,
            'one check per transaction, like every other domain table');
        $this->assertDoesNotMatchRegularExpression('/\bamount\s+DECIMAL/i', $sql,
            'money lives on service_transactions; a domain table must never duplicate it');
    }

    public function testTheCatalogueCanBeUnpricedButNeverUnnamed()
    {
        $sql = $this->table_sql('identity_products');
        $this->assertStringContainsString('price DECIMAL(20,8) NULL', $sql,
            'a synced row must be able to land without a price');
        $this->assertStringContainsString('code VARCHAR(48) NOT NULL UNIQUE', $sql);
        $this->assertStringContainsString('lookup_field', $sql);
    }

    private function table_sql($table)
    {
        foreach (IntegrationHarness::ddl() as $stmt) {
            if (strpos($stmt, 'CREATE TABLE IF NOT EXISTS '.$table) !== false) return $stmt;
        }
        $this->fail_missing($table);
    }

    private function fail_missing($table)
    {
        $this->assertNotEmpty(null, $table.' must exist in the migrations');
        return '';
    }
}

/* -------------------------------- doubles -------------------------------- */

/**
 * Scripted stand-in for SecureHttpClient.
 *
 * Records the parsed query as well as the URL, because half of what these
 * tests assert is which parameter the identifier travelled in. Throws on an
 * unscripted call so a test that makes an unexpected request — a retry, a
 * second lookup — fails loudly instead of silently reusing a response.
 */
class IdentityFakeHttp
{
    public $calls = array();
    private $script;

    public function __construct(array $script) { $this->script = $script; }

    public function get($url, $headers = array(), $options = array())
    {
        $query = array();
        parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
        $this->calls[] = array(
            'method' => 'GET', 'url' => $url,
            'path' => parse_url($url, PHP_URL_PATH),
            'query' => $query, 'headers' => $headers, 'options' => $options,
        );
        if (!$this->script) {
            throw new RuntimeException('IdentityFakeHttp: unscripted GET '.parse_url($url, PHP_URL_PATH));
        }
        return array_shift($this->script);
    }

    public function post($url, $data = null, $headers = array(), $options = array())
    {
        throw new RuntimeException('IdentityFakeHttp: Dojah lookups are GET-only');
    }
}

/** Minimal container for the adapter's own get_instance() credential read. */
#[AllowDynamicProperties]
class IdentityFakeCI
{
    public $load;
    public function __construct()
    {
        $this->load = new IdentityFakeLoader();
        $this->encryptionservice = new IdentityPassthroughEncryption();
    }
}

class IdentityFakeLoader
{
    public function library($n, $p = null, $o = null) { return $this; }
    public function model($n, $a = null, $d = false) { return $this; }
    public function helper($n = '') { return $this; }
}

class IdentityPassthroughEncryption
{
    public function encrypt($plain) { return 'enc:'.base64_encode((string)$plain); }
    public function decrypt($blob)
    {
        return strpos((string)$blob, 'enc:') === 0
            ? base64_decode(substr((string)$blob, 4)) : (string)$blob;
    }
}
