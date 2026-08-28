<?php
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__).'/_support/ShellSource.php';
require_once dirname(__DIR__).'/_support/FakeDb.php';
require_once dirname(__DIR__).'/_support/IntegrationHarness.php';

/**
 * Gift cards (Session 27, rebuild-spec phase F).
 *
 * The domain's distinguishing feature is that delivery is a *second* round
 * trip: the vendor accepts an order, bills our wallet, and issues the card
 * some time later. Almost everything that can go wrong here lives in that gap,
 * so most of this file is about it — a purchase that is charged but not yet
 * delivered, a code that arrives late, a code that never arrives, a sweep that
 * runs twice, a customer who refreshes the page.
 *
 * The other half is that the payload is a bearer instrument. A gift card code
 * is money to whoever reads it, so the storage assertions here are stricter
 * than the identity ones in one specific way: identity results are scrubbed on
 * a timer and codes must *never* be, because deleting one is indistinguishable
 * from stealing it.
 *
 * Three parts, as in NumbersTest and IdentityTest: the real stack against the
 * migration-derived schema for behaviour, scripted fixtures for the Reloadly
 * contract, and source-level gates for the admin surface, the registry and the
 * schedule.
 */
class GiftcardsTest extends TestCase
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
            eval('function &get_instance(){ return $GLOBALS["__fake_ci"]; }');
        }
        if (!function_exists('log_message')) eval('function log_message($l,$m){}');
        require_once self::$root.'/application/core/MY_Model.php';
        require_once self::$root.'/application/helpers/marvy_helper.php';
        require_once self::$root.'/application/libraries/ReloadlyAdapter.php';
        require_once self::$root.'/application/libraries/MockGiftcardAdapter.php';
    }

    protected function setUp(): void
    {
        // The mock vendor remembers which orders it has issued cards for; a
        // test that inherited that from its predecessor would pass by luck.
        MockGiftcardAdapter::reset();
    }

    /** A world with a customer who can afford a few cards. */
    private function app($balance = '500000')
    {
        $app = new IntegrationHarness();
        $app->seed_minimal();
        $app->seed_giftcards();
        $user = $app->register('gc_user', 'gc@x.test');
        $app->credit($user, $balance);
        $app->library('GiftcardService');
        $app->model(array('Giftcard_order_model', 'Giftcard_code_model',
                          'Giftcard_product_model', 'Giftcard_brand_model',
                          'Service_transaction_model', 'Audit_log_model'));
        return array($app, $user);
    }

    /** A well-formed request; override any field per test. */
    private function request(array $overrides = array())
    {
        return array_merge(array(
            'product'  => 'AMAZON-US-25',
            'quantity' => 1,
            'source'   => 'WEB',
        ), $overrides);
    }

    /* ========================= the happy path =========================== */

    public function testAPurchaseChargesOnceAndDeliversACode()
    {
        list($app, $user) = $this->app();

        $res = $app->giftcardservice->purchase($user, $this->request());

        $this->assertTrue($res['ok'], $res['error'] ?? '');
        $this->assertSame('SUCCESSFUL', $res['transaction']->status,
            'the money is earned once the customer has something spendable');
        $this->assertSame('GIFTCARD', $res['transaction']->service_domain);
        $this->assertSame('DELIVERED', $res['order']->status);
        $this->assertCount(1, $res['cards']);
        $this->assertSame('458000.00000000', $app->balance($user));
        list($d, $c) = $app->ledger_is_balanced();
        $this->assertSame($d, $c);
    }

    public function testTheOrderRowFreezesWhatWasBought()
    {
        list($app, $user) = $this->app();

        $res = $app->giftcardservice->purchase($user, $this->request());
        $order = $res['order'];

        // The catalogue can be re-synced tomorrow; the receipt must still say
        // what this customer actually paid for.
        $this->assertSame('25.00000000', $order->face_value);
        $this->assertSame('USD', $order->recipient_currency);
        $this->assertSame(1, (int)$order->quantity);
        $this->assertNotEmpty($order->provider_order_id,
            'without a vendor reference the codes can never be collected');
        $this->assertNotEmpty($order->placed_at);
        $this->assertNotEmpty($order->delivered_at);
        // Margin has to stay auditable after the fact, like every other domain.
        $this->assertSame('38000.00000000', $res['transaction']->provider_cost);
    }

    public function testBuyingSeveralCardsChargesPerCardAndIssuesOneCodeEach()
    {
        list($app, $user) = $this->app();

        $res = $app->giftcardservice->purchase($user, $this->request(array('quantity' => 3)));

        $this->assertTrue($res['ok'], $res['error'] ?? '');
        $this->assertSame('126000.00000000', $res['transaction']->amount,
            'three cards cost three times one card');
        $this->assertSame('114000.00000000', $res['transaction']->provider_cost);
        $this->assertCount(3, $res['cards']);
        // Stable, distinct slots: a customer handing one card out needs to know
        // which of the three is gone.
        $this->assertSame(array(1, 2, 3),
            array_map(function ($c) { return (int)$c->card_index; }, $res['cards']));
    }

    public function testTheVendorIsCalledWithTheDenominationAndOurReference()
    {
        list($app, $user) = $this->app();

        $res = $app->giftcardservice->purchase($user, $this->request(array('quantity' => 2)));

        $this->assertCount(1, MockGiftcardAdapter::$calls);
        $call = MockGiftcardAdapter::$calls[0];
        $this->assertSame('11', $call['product_id']);
        $this->assertSame(2, $call['quantity']);
        $this->assertSame('25.00000000', $call['unit_price'],
            'the vendor is told the card denomination, not what we charged');
        $this->assertSame($res['transaction']->public_id, $call['reference'],
            'our public id is the vendor idempotency key');
    }

    /* ==================== accepted is not delivered ===================== */

    /**
     * The rule the whole domain is built on. A vendor that accepts an order
     * and issues the card later leaves the customer charged and empty-handed,
     * and that state must be PROCESSING — not SUCCESSFUL, because the engine
     * cannot refund a settled transaction through its normal path.
     */
    public function testAnUndeliveredOrderLeavesThePurchaseInFlight()
    {
        list($app, $user) = $this->app();

        // '...7' at the mock vendor: accepted, never issued.
        $res = $app->giftcardservice->purchase($user, $this->request(
            array('product' => 'AMAZON-US-100')));

        $this->assertTrue($res['ok'], $res['error'] ?? '');
        $this->assertSame('PROCESSING', $res['transaction']->status);
        $this->assertSame('PLACED', $res['order']->status);
        $this->assertSame(array(), $res['cards']);
        // Charged, though: the vendor has our money.
        $this->assertSame('334000.00000000', $app->balance($user));
    }

    public function testALateCodeSettlesThePurchaseWhenItArrives()
    {
        list($app, $user) = $this->app();
        $res = $app->giftcardservice->purchase($user, $this->request(
            array('product' => 'AMAZON-US-100')));
        $order = $res['order'];

        // The vendor finally issues the card between one sweep and the next.
        $this->becomes_ready($order->provider_order_id, 1);
        $out = $app->giftcardservice->collect($order, 'CRON');

        $this->assertTrue($out['ok']);
        $this->assertTrue($out['ready']);
        $this->assertSame('DELIVERED', $out['order']->status);
        $this->assertCount(1, $out['cards']);
        $this->assertSame('SUCCESSFUL',
            $app->Service_transaction_model->find_by_id($order->service_transaction_id)->status);
    }

    public function testCollectingTwiceDoesNotDoubleTheCustomersCards()
    {
        list($app, $user) = $this->app();
        $res = $app->giftcardservice->purchase($user, $this->request(array('quantity' => 2)));
        $order = $res['order'];

        // The sweep and an impatient admin both press the button.
        $app->giftcardservice->collect($order, 'CRON');
        $app->giftcardservice->collect($app->Giftcard_order_model->find_by_id($order->id), 'ADMIN');

        $this->assertSame(2, $app->Giftcard_code_model->count_for_order($order->id),
            'card_index is unique per order precisely so a repeated sweep is a no-op');
    }

    public function testCollectingAnAlreadyDeliveredOrderIsAQuietNoOp()
    {
        list($app, $user) = $this->app();
        $res = $app->giftcardservice->purchase($user, $this->request());
        $before = count(MockGiftcardAdapter::$calls);

        $out = $app->giftcardservice->collect($res['order'], 'ADMIN');

        $this->assertTrue($out['ok']);
        $this->assertTrue($out['ready']);
        $this->assertCount(1, $out['cards']);
        $this->assertCount($before, MockGiftcardAdapter::$calls,
            'a delivered order must not call the vendor again');
    }

    /* ========================= money and refunds ======================== */

    public function testAVendorRejectionRefundsInFull()
    {
        list($app, $user) = $this->app();

        // '...0' at the mock vendor: out of stock.
        $res = $app->giftcardservice->purchase($user, $this->request(
            array('product' => 'STEAM-US-10')));

        $this->assertFalse($res['ok']);
        $this->assertSame('500000.00000000', $app->balance($user),
            'a card we could not buy costs the customer nothing');
        $this->assertSame('FAILED', $res['transaction']->status);
        $this->assertSame('FAILED', $res['order']->status);
        list($d, $c) = $app->ledger_is_balanced();
        $this->assertSame($d, $c);
    }

    public function testARejectedPurchaseStillLeavesAReceipt()
    {
        list($app, $user) = $this->app();

        $res = $app->giftcardservice->purchase($user, $this->request(
            array('product' => 'STEAM-US-10')));

        // The engine's failure result carries no transaction; the service has
        // to hand one back anyway, or the customer gets a red banner and no
        // way to see what happened to their money.
        $this->assertNotEmpty($res['transaction']);
        $this->assertNotEmpty($res['order']);
        $this->assertNotEmpty($res['order']->failure_reason);
    }

    public function testAnOrderThatNeverDeliversIsWrittenOffAndRefunded()
    {
        list($app, $user) = $this->app();
        $res = $app->giftcardservice->purchase($user, $this->request(
            array('product' => 'AMAZON-US-100')));

        $out = $app->giftcardservice->abandon($res['order'], 'CRON');

        $this->assertTrue($out['ok']);
        $this->assertSame('166000.00000000', $out['refunded']);
        $this->assertSame('FAILED', $out['order']->status);
        $this->assertSame('500000.00000000', $app->balance($user));
        $this->assertSame('FAILED',
            $app->Service_transaction_model->find_by_id($res['transaction']->id)->status);
    }

    /**
     * The rule that stops a customer keeping both the code and the money.
     * Once a card exists it is spendable, and the panel has no way to claw it
     * back — so a write-off must refuse rather than refund.
     */
    public function testAnOrderWithCodesCannotBeWrittenOff()
    {
        list($app, $user) = $this->app();
        $res = $app->giftcardservice->purchase($user, $this->request());

        $out = $app->giftcardservice->abandon($res['order'], 'ADMIN');

        $this->assertFalse($out['ok']);
        $this->assertSame('HAS_CODES', $out['code']);
        $this->assertSame('458000.00000000', $app->balance($user),
            'the customer keeps the card, so they do not also keep the money');
    }

    public function testAnAlreadySettledOrderCannotBeWrittenOffTwice()
    {
        list($app, $user) = $this->app();
        $res = $app->giftcardservice->purchase($user, $this->request(
            array('product' => 'AMAZON-US-100')));
        $app->giftcardservice->abandon($res['order'], 'CRON');

        $again = $app->giftcardservice->abandon(
            $app->Giftcard_order_model->find_by_id($res['order']->id), 'ADMIN');

        $this->assertFalse($again['ok']);
        $this->assertSame('NOT_OPEN', $again['code']);
        $this->assertSame('500000.00000000', $app->balance($user),
            'a second write-off must not pay the refund twice');
    }

    public function testAPurchaseIsRefusedWhenTheWalletIsShort()
    {
        list($app, $user) = $this->app('1000');

        $res = $app->giftcardservice->purchase($user, $this->request());

        $this->assertFalse($res['ok']);
        $this->assertSame('INSUFFICIENT_BALANCE', $res['code']);
        $this->assertSame('1000.00000000', $app->balance($user));
        $this->assertCount(0, MockGiftcardAdapter::$calls,
            'a customer who cannot pay must not reach the vendor');
    }

    public function testARetryWithTheSameIdempotencyKeyDoesNotBuyTwice()
    {
        list($app, $user) = $this->app();
        $key = 'gc:test:duplicate';

        $first  = $app->giftcardservice->purchase($user, $this->request(array('idempotency_key' => $key)));
        $second = $app->giftcardservice->purchase($user, $this->request(array('idempotency_key' => $key)));

        $this->assertTrue($first['ok']);
        $this->assertTrue($second['ok']);
        $this->assertSame($first['transaction']->id, $second['transaction']->id);
        $this->assertSame('458000.00000000', $app->balance($user),
            'a double submit charges once');
        $this->assertCount(1, MockGiftcardAdapter::$calls);
        // A duplicate must not re-collect either: the codes are already ours.
        $this->assertCount(1, $second['cards']);
        $this->assertSame(1, $app->Giftcard_code_model->count_for_order($second['order']->id));
    }

    public function testAVendorOutageRefundsAndRecordsTheReason()
    {
        list($app, $user) = $this->app();
        MockGiftcardAdapter::$force_error = 'The gift card vendor is unavailable';

        $res = $app->giftcardservice->purchase($user, $this->request());

        $this->assertFalse($res['ok']);
        $this->assertSame('500000.00000000', $app->balance($user));
        $this->assertStringContainsString('unavailable', (string)$res['order']->failure_reason);
    }

    /* ======================== catalogue guard rails ===================== */

    public function testAnUnpricedCardCannotBeBought()
    {
        list($app, $user) = $this->app();

        // Imported by a sync and never priced. Selling it would hand over a
        // real card for nothing.
        $res = $app->giftcardservice->purchase($user, $this->request(
            array('product' => 'AMAZON-GB-10')));

        $this->assertFalse($res['ok']);
        $this->assertSame('NO_PRICE', $res['code']);
        $this->assertCount(0, MockGiftcardAdapter::$calls);
    }

    public function testACustomAmountCardIsRefusedRatherThanGuessed()
    {
        list($app, $user) = $this->app();

        $res = $app->giftcardservice->purchase($user, $this->request(
            array('product' => 'AMAZON-US-RANGE')));

        $this->assertFalse($res['ok']);
        $this->assertSame('NOT_FIXED', $res['code'],
            'a RANGE card has no denomination until somebody names one');
        $this->assertSame('500000.00000000', $app->balance($user));
    }

    public function testASwitchedOffCardIsNotForSale()
    {
        list($app, $user) = $this->app();

        $res = $app->giftcardservice->purchase($user, $this->request(
            array('product' => 'STEAM-US-5')));

        $this->assertFalse($res['ok']);
        $this->assertSame('NO_PRODUCT', $res['code']);
    }

    public function testTheStorefrontHidesEverythingThatCannotBeBought()
    {
        list($app,) = $this->app();

        $codes = array_map(function ($p) { return $p->code; },
            $app->Giftcard_product_model->active());

        $this->assertContains('AMAZON-US-25', $codes);
        $this->assertNotContains('AMAZON-GB-10', $codes, 'unpriced');
        $this->assertNotContains('AMAZON-US-RANGE', $codes, 'no fixed denomination');
        $this->assertNotContains('STEAM-US-5', $codes, 'switched off');
    }

    public function testABrandWithNothingPricedIsNotOffered()
    {
        list($app,) = $this->app();

        $codes = array_map(function ($b) { return $b->code; },
            $app->Giftcard_brand_model->sellable());

        $this->assertContains('AMAZON', $codes);
        $this->assertNotContains('SWITCHED-OFF', $codes,
            'a brand that opens onto an empty list is worse than no brand');
    }

    public function testTheQuantityLimitIsThePerProductOne()
    {
        list($app, $user) = $this->app();

        // AMAZON-US-50 caps at 3 in the seed.
        $ok  = $app->giftcardservice->purchase($user, $this->request(
            array('product' => 'AMAZON-US-50', 'quantity' => 3)));
        $bad = $app->giftcardservice->purchase($user, $this->request(
            array('product' => 'AMAZON-US-50', 'quantity' => 4)));

        $this->assertTrue($ok['ok'], $ok['error'] ?? '');
        $this->assertFalse($bad['ok']);
        $this->assertSame('BAD_QUANTITY', $bad['code']);
    }

    public function testAZeroOrNegativeQuantityIsRefused()
    {
        list($app, $user) = $this->app();

        foreach (array(0, -1) as $q) {
            $res = $app->giftcardservice->purchase($user, $this->request(array('quantity' => $q)));
            $this->assertFalse($res['ok']);
            $this->assertSame('BAD_QUANTITY', $res['code']);
        }
        $this->assertSame('500000.00000000', $app->balance($user));
    }

    public function testAMalformedDeliveryEmailIsRefusedBeforeCharging()
    {
        list($app, $user) = $this->app();

        $res = $app->giftcardservice->purchase($user, $this->request(
            array('recipient_email' => 'not-an-address')));

        $this->assertFalse($res['ok']);
        $this->assertSame('BAD_EMAIL', $res['code']);
        $this->assertSame('500000.00000000', $app->balance($user));
    }

    public function testADeliveryEmailIsPassedToTheVendorAndRecorded()
    {
        list($app, $user) = $this->app();

        $res = $app->giftcardservice->purchase($user, $this->request(
            array('recipient_email' => 'friend@example.test')));

        $this->assertSame('friend@example.test', MockGiftcardAdapter::$calls[0]['recipient_email']);
        $this->assertSame('friend@example.test', $res['order']->recipient_email);
    }

    public function testAnUnknownProductIsRefused()
    {
        list($app, $user) = $this->app();

        $res = $app->giftcardservice->purchase($user, $this->request(
            array('product' => 'NO-SUCH-CARD')));

        $this->assertFalse($res['ok']);
        $this->assertSame('NO_PRODUCT', $res['code']);
    }

    /* ===================== the code is a bearer instrument =============== */

    /**
     * The single most important storage assertion in this file. Not "the
     * column is encrypted" — a reviewer can see that — but that the plaintext
     * card number appears in no column of any table after a complete,
     * successful purchase. A future convenience column, a metadata blob, a
     * failure_reason built from the vendor response, would each be caught here.
     */
    public function testThePlainCardNumberIsNowhereInTheDatabase()
    {
        list($app, $user) = $this->app();
        $res = $app->giftcardservice->purchase($user, $this->request());

        $plain = $app->giftcardservice->reveal($res['cards'][0], $user, 'CUSTOMER');
        $number = $plain['card']['card_number'];
        $this->assertNotEmpty($number);

        foreach (array('giftcard_codes', 'giftcard_orders', 'service_transactions',
                       'wallet_transactions', 'ledger_entries', 'audit_logs',
                       'provider_transactions', 'service_transaction_status_history') as $table) {
            foreach ($app->rows($table) as $row) {
                foreach ($row as $column => $value) {
                    if (!is_scalar($value)) continue;
                    $this->assertStringNotContainsString($number, (string)$value,
                        "the card number leaked into {$table}.{$column}");
                }
            }
        }
    }

    public function testOnlyTheMaskedTailIsStoredInTheClear()
    {
        list($app, $user) = $this->app();
        $res = $app->giftcardservice->purchase($user, $this->request());
        $card = $res['cards'][0];

        $plain = $app->giftcardservice->reveal($card, $user, 'CUSTOMER');
        $number = $plain['card']['card_number'];

        $this->assertSame(substr($number, -4), $card->card_last4);
        $this->assertNotSame($number, $card->card_number_encrypted);
        $this->assertNotSame($plain['card']['pin'], $card->pin_encrypted);
    }

    public function testRevealingACodeIsCountedAndAudited()
    {
        list($app, $user) = $this->app();
        $res = $app->giftcardservice->purchase($user, $this->request());

        $app->giftcardservice->reveal($res['cards'][0], $user, 'CUSTOMER');

        $order = $app->Giftcard_order_model->find_by_id($res['order']->id);
        $this->assertSame(1, (int)$order->reveal_count);
        $this->assertNotEmpty($order->last_revealed_at);
        $this->assertSame((int)$user->id, (int)$order->last_revealed_by);

        $card = $app->Giftcard_code_model->find_by_id($res['cards'][0]->id);
        $this->assertNotEmpty($card->revealed_at, 'the card itself records its first opening');

        $actions = array_map(function ($r) { return $r['action']; }, $app->rows('audit_logs'));
        $this->assertContains('giftcard.code.reveal', $actions);
    }

    public function testTheAuditEntryDoesNotBecomeASecondCopyOfTheCode()
    {
        list($app, $user) = $this->app();
        $res = $app->giftcardservice->purchase($user, $this->request());
        $plain = $app->giftcardservice->reveal($res['cards'][0], $user, 'CUSTOMER');

        foreach ($app->rows('audit_logs') as $row) {
            foreach ($row as $value) {
                if (!is_scalar($value)) continue;
                $this->assertStringNotContainsString(
                    $plain['card']['card_number'], (string)$value);
                $this->assertStringNotContainsString(
                    (string)$plain['card']['pin'], (string)$value);
            }
        }
    }

    public function testTheFirstRevealTimestampIsNotOverwrittenByLaterOnes()
    {
        list($app, $user) = $this->app();
        $res = $app->giftcardservice->purchase($user, $this->request());
        $card = $res['cards'][0];

        $app->giftcardservice->reveal($card, $user, 'CUSTOMER');
        $first = $app->Giftcard_code_model->find_by_id($card->id)->revealed_at;
        $app->db->where('id', $card->id)->update('giftcard_codes',
            array('revealed_at' => '2020-01-01 00:00:00'));
        $app->giftcardservice->reveal($app->Giftcard_code_model->find_by_id($card->id),
            $user, 'CUSTOMER');

        $this->assertSame('2020-01-01 00:00:00',
            $app->Giftcard_code_model->find_by_id($card->id)->revealed_at,
            'revealed_at records the first opening, not the latest');
        $this->assertNotEmpty($first);
        // The count still moves, so repeated access remains visible.
        $this->assertSame(2, (int)$app->Giftcard_order_model->find_by_id($res['order']->id)->reveal_count);
    }

    public function testAnUnreadableBlobIsReportedRatherThanRendered()
    {
        list($app, $user) = $this->app();
        $res = $app->giftcardservice->purchase($user, $this->request());
        $card = $res['cards'][0];
        $app->db->where('id', $card->id)->update('giftcard_codes',
            array('card_number_encrypted' => 'not-actually-ciphertext'));

        $out = $app->giftcardservice->reveal(
            $app->Giftcard_code_model->find_by_id($card->id), $user, 'CUSTOMER');

        // open(), not decrypt(): decrypt() hands its input back on failure,
        // which would render the blob as though it were the card.
        $this->assertFalse($out['ok']);
        $this->assertSame('UNREADABLE', $out['code']);
    }

    /**
     * Deliberately the inverse of the identity domain's retention rule. There
     * must be no scheduled deletion of a gift card code anywhere: it is the
     * product the customer bought, and a helpful sweep tidying away an unspent
     * card is indistinguishable from theft.
     */
    public function testNothingEverPurgesAGiftCardCode()
    {
        $model = file_get_contents(self::$root.'/application/models/Giftcard_code_model.php');
        $this->assertStringNotContainsString('->delete(', $model);
        $this->assertStringNotContainsString('purge', $model);

        $service = file_get_contents(self::$root.'/application/libraries/GiftcardService.php');
        $this->assertStringNotContainsString('purge_expired', $service);

        // The column itself, not the docblock explaining why it is absent.
        $migration = file_get_contents(self::$root.'/application/migrations/014_giftcards.php');
        preg_match('~CREATE TABLE IF NOT EXISTS giftcard_codes \((.*?)\) ENGINE~s', $migration, $m);
        $this->assertNotEmpty($m, 'giftcard_codes must be created by this migration');
        $this->assertStringNotContainsString('purged_at', $m[1],
            'a retention column on giftcard_codes would invite a sweep that deletes money');
    }

    /* ======================== the settlement sweep ======================= */

    public function testTheWorkerCollectsCodesThatArrivedLate()
    {
        list($app, $user) = $this->app();
        $app->library('CronWorkers');
        $res = $app->giftcardservice->purchase($user, $this->request(
            array('product' => 'AMAZON-US-100')));
        $this->becomes_ready($res['order']->provider_order_id, 1);

        $out = $app->cronworkers->giftcard_codes();

        $this->assertSame(1, $out['processed']);
        $this->assertSame(0, $out['failed']);
        $this->assertStringContainsString('1 delivered', $out['message']);
        $this->assertSame('DELIVERED',
            $app->Giftcard_order_model->find_by_id($res['order']->id)->status);
    }

    public function testTheWorkerLeavesAFreshUndeliveredOrderAlone()
    {
        list($app, $user) = $this->app();
        $app->library('CronWorkers');
        $res = $app->giftcardservice->purchase($user, $this->request(
            array('product' => 'AMAZON-US-100')));

        $out = $app->cronworkers->giftcard_codes();

        $this->assertSame(1, $out['processed']);
        $this->assertSame('PLACED',
            $app->Giftcard_order_model->find_by_id($res['order']->id)->status,
            'a card issued a minute from now is not a failure yet');
        $this->assertSame('334000.00000000', $app->balance($user));
    }

    public function testTheWorkerWritesOffAnOrderPastPatienceAndRefunds()
    {
        list($app, $user) = $this->app();
        $app->library('CronWorkers');
        $res = $app->giftcardservice->purchase($user, $this->request(
            array('product' => 'AMAZON-US-100')));

        // Old enough, and tried often enough, to be beyond hope.
        $app->db->where('id', $res['order']->id)->update('giftcard_orders', array(
            'placed_at'     => gmdate('Y-m-d H:i:s', time() - (6 * 3600)),
            'code_attempts' => 9,
        ));

        $out = $app->cronworkers->giftcard_codes();

        $this->assertGreaterThanOrEqual(1, $out['processed']);
        $this->assertStringContainsString('written off', $out['message']);
        $this->assertSame('FAILED',
            $app->Giftcard_order_model->find_by_id($res['order']->id)->status);
        $this->assertSame('500000.00000000', $app->balance($user),
            'the customer paid for a card that never came');
    }

    public function testAnOldOrderWithFewAttemptsIsStillGivenTheBenefitOfTheDoubt()
    {
        list($app, $user) = $this->app();
        $app->library('CronWorkers');
        $res = $app->giftcardservice->purchase($user, $this->request(
            array('product' => 'AMAZON-US-100')));
        $app->db->where('id', $res['order']->id)->update('giftcard_orders', array(
            'placed_at'     => gmdate('Y-m-d H:i:s', time() - (6 * 3600)),
            'code_attempts' => 1,
        ));

        $app->cronworkers->giftcard_codes();

        // Age alone is not enough: an order nobody has actually chased may
        // simply have been missed by a stopped worker.
        $this->assertSame('PLACED',
            $app->Giftcard_order_model->find_by_id($res['order']->id)->status);
    }

    public function testEveryAttemptIsCountedOnTheRow()
    {
        list($app, $user) = $this->app();
        $res = $app->giftcardservice->purchase($user, $this->request(
            array('product' => 'AMAZON-US-100')));
        $after_purchase = (int)$app->Giftcard_order_model->find_by_id($res['order']->id)->code_attempts;

        $app->giftcardservice->collect(
            $app->Giftcard_order_model->find_by_id($res['order']->id), 'CRON');
        $order = $app->Giftcard_order_model->find_by_id($res['order']->id);

        $this->assertSame($after_purchase + 1, (int)$order->code_attempts,
            'the attempt count is what bounds the retry, so it must move every time');
        $this->assertNotEmpty($order->last_attempt_at);
    }

    public function testTheWorkerIsAQuietNoOpWithNothingOutstanding()
    {
        list($app,) = $this->app();
        $app->library('CronWorkers');

        $out = $app->cronworkers->giftcard_codes();

        $this->assertSame(0, $out['processed']);
        $this->assertSame(0, $out['failed']);
        $this->assertSame('no gift card orders awaiting codes', $out['message']);
    }

    /* ====================== the Reloadly integration ===================== */

    private static function fixture($name)
    {
        $path = self::$root.'/tests/fixtures/reloadly/'.$name;
        if (!file_exists($path)) throw new RuntimeException('missing fixture '.$name);
        return file_get_contents($path);
    }

    private function provider(array $overrides = array())
    {
        return (object)array_merge(array(
            'id' => 21, 'public_id' => 'PROV0000000000000000000021', 'name' => 'Reloadly',
            'api_url' => ReloadlyAdapter::SANDBOX_URL,
            'api_key_encrypted' => '{"client_id":"test_client","client_secret":"test_secret"}',
            'api_type' => 'RELOADLY', 'status' => 'ACTIVE', 'currency' => 'NGN',
            'timeout_ms' => 30000,
            // A cached token, so the ordinary tests script one call rather
            // than two. The token tests override this.
            'retry_policy' => json_encode(array('reloadly' => array(
                'token' => 'cached-token',
                'token_expires_at' => 4102444800,
                'token_audience' => ReloadlyAdapter::SANDBOX_URL,
            ))),
        ), $overrides);
    }

    private function adapter(array $script, array $overrides = array())
    {
        $GLOBALS['__fake_ci'] = new GiftcardFakeCI();
        $http = new GiftcardFakeHttp($script);
        return array(new ReloadlyAdapter($this->provider($overrides), $http), $http);
    }

    private static function ok($body, $code = 200)
    {
        return array('http_code' => $code, 'body' => $body, 'request_id' => 'rid');
    }

    public function testTheVendorMediaTypeIsSentOnEveryCall()
    {
        list($adapter, $http) = $this->adapter(array(self::ok(self::fixture('order_placed.json'))));
        $adapter->order(array('product_id' => '11', 'quantity' => 1,
                              'unit_price' => '25.00', 'reference' => 'TX1'));

        $headers = $http->calls[0]['headers'];
        $this->assertContains('Accept: application/com.reloadly.giftcards-v1+json', $headers,
            'plain application/json is rejected by this API');
        $this->assertContains('Authorization: Bearer cached-token', $headers);
    }

    public function testTheOrderCarriesOurReferenceAsTheVendorIdempotencyKey()
    {
        list($adapter, $http) = $this->adapter(array(self::ok(self::fixture('order_placed.json'))));
        $adapter->order(array('product_id' => '11', 'quantity' => 2,
                              'unit_price' => '25.00', 'reference' => '01JMARVYSOCIALSTX'));

        $body = json_decode($http->calls[0]['body'], true);
        $this->assertSame('01JMARVYSOCIALSTX', $body['customIdentifier'],
            'a timeout on the way out must not become two purchases');
        $this->assertSame(11, $body['productId']);
        $this->assertSame(2, $body['quantity']);
        $this->assertEquals(25, $body['unitPrice'],
            'the vendor is quoted the card denomination in its own currency');
        $this->assertFalse($body['preOrder']);
    }

    /**
     * The order is accepted, not delivered — even though the vendor's own
     * status field says SUCCESSFUL. That field means "the order went through",
     * and reading it as "the customer has a code" is the mistake that settles
     * a transaction before anything was handed over.
     */
    public function testAnAcceptedOrderIsReportedAsPlacedNotDelivered()
    {
        list($adapter,) = $this->adapter(array(self::ok(self::fixture('order_placed.json'))));

        $res = $adapter->order(array('product_id' => '11', 'quantity' => 1,
                                     'unit_price' => '25.00', 'reference' => 'TX1'));

        $this->assertTrue($res['ok']);
        $this->assertSame('PLACED', $res['status']);
        $this->assertSame('1', $res['reference']);
    }

    public function testTheVendorCostIsTakenFromTheWalletMovement()
    {
        list($adapter,) = $this->adapter(array(self::ok(self::fixture('order_placed.json'))));

        $res = $adapter->order(array('product_id' => '11', 'quantity' => 1,
                                     'unit_price' => '25.00', 'reference' => 'TX1'));

        // balanceInfo.cost is what actually left the Reloadly wallet, fees
        // included — a better figure than the catalogue's estimate.
        $this->assertSame('34536.21000000', $res['cost']);
    }

    /**
     * The FX trap. If the Reloadly account is denominated in dollars and this
     * panel keeps its books in naira, reporting the vendor's number would land
     * a dollar figure in a naira column and show a 99% margin.
     */
    public function testNoCostIsReportedWhenTheVendorWalletIsInAnotherCurrency()
    {
        list($adapter,) = $this->adapter(
            array(self::ok(self::fixture('order_placed_foreign_wallet.json'))));

        $res = $adapter->order(array('product_id' => '11', 'quantity' => 1,
                                     'unit_price' => '25.00', 'reference' => 'TX9'));

        $this->assertTrue($res['ok']);
        $this->assertNull($res['cost'],
            'no figure at all beats a figure that is wrong by a factor of 1500');
    }

    public function testAnAcceptedOrderWithoutAReferenceIsTreatedAsAFailure()
    {
        list($adapter,) = $this->adapter(array(self::ok(self::fixture('order_no_reference.json'))));

        $res = $adapter->order(array('product_id' => '11', 'quantity' => 1,
                                     'unit_price' => '25.00', 'reference' => 'TX1'));

        // Without a transactionId the codes can never be collected, so an
        // "accepted" order we cannot follow up is worse than a rejection.
        $this->assertFalse($res['ok']);
        $this->assertStringContainsString('without returning a reference', $res['error']);
    }

    public function testASingleCardResponseIsReadAsOneCard()
    {
        list($adapter,) = $this->adapter(array(self::ok(self::fixture('cards_single.json'))));

        $res = $adapter->codes('1');

        $this->assertTrue($res['ok']);
        $this->assertTrue($res['ready']);
        $this->assertCount(1, $res['cards']);
        $this->assertSame('6120200345149064', $res['cards'][0]['card_number']);
        $this->assertSame('EFSDCEAFSD', $res['cards'][0]['pin']);
        $this->assertStringContainsString('redemption-code', $res['cards'][0]['redemption_url']);
    }

    public function testAMultiCardResponseIsReadAsManyCards()
    {
        list($adapter,) = $this->adapter(array(self::ok(self::fixture('cards_multiple.json'))));

        $res = $adapter->codes('2');

        $this->assertTrue($res['ready']);
        $this->assertCount(2, $res['cards']);
        $this->assertSame('6120200345149072', $res['cards'][1]['card_number']);
        $this->assertSame('2027-06-30', $res['cards'][0]['expires_on']);
    }

    public function testAPinOnlyBrandStillProducesACard()
    {
        list($adapter,) = $this->adapter(array(self::ok(self::fixture('cards_pin_only.json'))));

        $res = $adapter->codes('3');

        $this->assertTrue($res['ready']);
        $this->assertCount(1, $res['cards']);
        $this->assertSame('', $res['cards'][0]['card_number']);
        $this->assertSame('8N4K-2QW9-LM31', $res['cards'][0]['pin'],
            'plenty of brands issue a PIN and no card number');
    }

    /**
     * The most consequential single assertion about this vendor. A 404 on the
     * codes endpoint means "not issued yet", and reading it as an error would
     * refund a customer whose card is seconds away — while the vendor keeps
     * our money and the card.
     */
    public function testANotYetIssuedCardIsNotAnError()
    {
        list($adapter,) = $this->adapter(
            array(self::ok(self::fixture('cards_not_ready.json'), 404)));

        $res = $adapter->codes('4');

        $this->assertTrue($res['ok']);
        $this->assertFalse($res['ready']);
        $this->assertSame(array(), $res['cards']);
        $this->assertNull($res['error']);
    }

    public function testAnEmptyCardListIsAlsoJustNotYet()
    {
        list($adapter,) = $this->adapter(array(self::ok(self::fixture('cards_empty.json'))));

        $res = $adapter->codes('5');

        $this->assertTrue($res['ok']);
        $this->assertFalse($res['ready'],
            'marking an order delivered with nothing to deliver is the worse failure');
    }

    public function testCodesForAnOrderWithNoReferenceNeverCallTheVendor()
    {
        list($adapter, $http) = $this->adapter(array());

        $res = $adapter->codes('');

        $this->assertFalse($res['ok']);
        $this->assertSame(array(), $http->calls);
    }

    public function testVendorErrorsAreReportedWithSomethingActionable()
    {
        $cases = array(
            array('error_400_product.json',      400, 'rejected the order details'),
            array('error_400_denomination.json', 400, 'not a valid denomination'),
            array('error_402_balance.json',      402, 'wallet is out of funds'),
            array('error_429.json',              429, 'rate-limiting'),
        );
        foreach ($cases as $case) {
            list($fixture, $code, $needle) = $case;
            list($adapter,) = $this->adapter(array(self::ok(self::fixture($fixture), $code)));

            $res = $adapter->order(array('product_id' => '11', 'quantity' => 1,
                                         'unit_price' => '25.00', 'reference' => 'TX1'));

            $this->assertFalse($res['ok']);
            $this->assertStringContainsString($needle, $res['error'], $fixture);
        }
    }

    public function testAnErrorMessageNeverEchoesTheRequestPath()
    {
        list($adapter,) = $this->adapter(
            array(self::ok(self::fixture('error_400_product.json'), 400)));

        $res = $adapter->order(array('product_id' => '999', 'quantity' => 1,
                                     'unit_price' => '25.00', 'reference' => 'TX1'));

        // The envelope carries `path`, which echoes our request. Surfacing it
        // puts request detail into a message that ends up on a customer screen.
        $this->assertStringNotContainsString('/orders', $res['error']);
    }

    public function testAPermanentRejectionIsFlaggedAsNotWorthRetrying()
    {
        list($adapter,) = $this->adapter(
            array(self::ok(self::fixture('error_400_product.json'), 400)));
        $res = $adapter->order(array('product_id' => '999', 'quantity' => 1,
                                     'unit_price' => '25.00', 'reference' => 'TX1'));
        $this->assertTrue($res['permanent']);

        list($adapter2,) = $this->adapter(array(self::ok(self::fixture('error_429.json'), 429)));
        $res2 = $adapter2->order(array('product_id' => '11', 'quantity' => 1,
                                       'unit_price' => '25.00', 'reference' => 'TX2'));
        $this->assertFalse($res2['permanent'], 'a rate limit clears on its own');
    }

    public function testATransportFailureIsReportedWithoutTheUrl()
    {
        list($adapter,) = $this->adapter(array(
            array('http_code' => 0, 'body' => null, 'error' => 'Connection timed out'),
        ));

        $res = $adapter->order(array('product_id' => '11', 'quantity' => 1,
                                     'unit_price' => '25.00', 'reference' => 'TX1'));

        $this->assertFalse($res['ok']);
        $this->assertSame('Could not reach the gift card vendor', $res['error']);
    }

    /* --------------------------- the OAuth dance -------------------------- */

    public function testATokenIsMintedWhenNoneIsCached()
    {
        list($adapter, $http) = $this->adapter(array(
            self::ok(self::fixture('token.json')),
            self::ok(self::fixture('order_placed.json')),
        ), array('retry_policy' => null));

        $adapter->order(array('product_id' => '11', 'quantity' => 1,
                              'unit_price' => '25.00', 'reference' => 'TX1'));

        $this->assertCount(2, $http->calls);
        $this->assertStringContainsString('auth.reloadly.com', $http->calls[0]['url']);
        $body = json_decode($http->calls[0]['body'], true);
        $this->assertSame('client_credentials', $body['grant_type']);
        $this->assertSame('test_client', $body['client_id']);
        $this->assertSame('test_secret', $body['client_secret']);
    }

    /**
     * A token is scoped to one product *and* one environment. Minting a
     * production token for a sandbox provider row authenticates fine and then
     * spends real money on test clicks.
     */
    public function testTheTokenAudienceFollowsTheConfiguredEnvironment()
    {
        list($sandbox, $http1) = $this->adapter(array(
            self::ok(self::fixture('token.json')), self::ok(self::fixture('balance.json')),
        ), array('retry_policy' => null, 'api_url' => ReloadlyAdapter::SANDBOX_URL));
        $sandbox->balance();
        $this->assertSame(ReloadlyAdapter::SANDBOX_URL,
            json_decode($http1->calls[0]['body'], true)['audience']);

        list($live, $http2) = $this->adapter(array(
            self::ok(self::fixture('token.json')), self::ok(self::fixture('balance.json')),
        ), array('retry_policy' => null, 'api_url' => ReloadlyAdapter::BASE_URL));
        $live->balance();
        $this->assertSame(ReloadlyAdapter::BASE_URL,
            json_decode($http2->calls[0]['body'], true)['audience']);
    }

    public function testACachedTokenIsReusedRatherThanRefetched()
    {
        list($adapter, $http) = $this->adapter(array(self::ok(self::fixture('balance.json'))));

        $adapter->balance();

        $this->assertCount(1, $http->calls,
            'a 60-day token refetched per call would double every latency');
        $this->assertStringNotContainsString('auth.reloadly.com', $http->calls[0]['url']);
    }

    public function testAnExpiringTokenIsRefreshedEarly()
    {
        // Inside the skew window: technically still valid, but liable to
        // expire in the middle of a call that moves money.
        list($adapter, $http) = $this->adapter(array(
            self::ok(self::fixture('token.json')), self::ok(self::fixture('balance.json')),
        ), array('retry_policy' => json_encode(array('reloadly' => array(
            'token' => 'nearly-dead',
            'token_expires_at' => time() + 600,
            'token_audience' => ReloadlyAdapter::SANDBOX_URL,
        )))));

        $adapter->balance();

        $this->assertCount(2, $http->calls);
        $this->assertStringContainsString('auth.reloadly.com', $http->calls[0]['url']);
    }

    public function testATokenCachedForTheOtherEnvironmentIsDiscarded()
    {
        list($adapter, $http) = $this->adapter(array(
            self::ok(self::fixture('token.json')), self::ok(self::fixture('balance.json')),
        ), array(
            'api_url' => ReloadlyAdapter::SANDBOX_URL,
            'retry_policy' => json_encode(array('reloadly' => array(
                'token' => 'a-production-token',
                'token_expires_at' => 4102444800,
                'token_audience' => ReloadlyAdapter::BASE_URL,
            ))),
        ));

        $adapter->balance();

        $this->assertCount(2, $http->calls,
            'a live token on a sandbox row is worse than no token');
    }

    public function testAStaleTokenIsRefreshedOnceAfterA401()
    {
        list($adapter, $http) = $this->adapter(array(
            self::ok(self::fixture('error_401.json'), 401),
            self::ok(self::fixture('token.json')),
            self::ok(self::fixture('balance.json')),
        ));

        $res = $adapter->balance();

        $this->assertTrue($res['ok']);
        $this->assertCount(3, $http->calls);
        $this->assertStringContainsString('auth.reloadly.com', $http->calls[1]['url']);
    }

    public function testASecondUnauthorizedResponseIsNotRetriedForever()
    {
        list($adapter, $http) = $this->adapter(array(
            self::ok(self::fixture('error_401.json'), 401),
            self::ok(self::fixture('token.json')),
            self::ok(self::fixture('error_401.json'), 401),
        ));

        $res = $adapter->balance();

        $this->assertFalse($res['ok']);
        $this->assertCount(3, $http->calls,
            'a genuinely wrong secret must fail, not loop');
    }

    public function testAFailedAuthenticationIsReportedRatherThanThrown()
    {
        list($adapter,) = $this->adapter(array(self::ok(self::fixture('auth_401.json'), 401)),
            array('retry_policy' => null));

        $res = $adapter->order(array('product_id' => '11', 'quantity' => 1,
                                     'unit_price' => '25.00', 'reference' => 'TX1'));

        $this->assertFalse($res['ok']);
        $this->assertStringContainsString('authenticate', $res['error']);
    }

    /* --------------------------- catalogue import ------------------------- */

    public function testEachFixedDenominationBecomesItsOwnCatalogueRow()
    {
        list($adapter,) = $this->adapter(array(self::ok(self::fixture('products_us.json'))));

        $res = $adapter->products('US');

        $this->assertTrue($res['ok']);
        // Amazon $25 + $50 + Steam $20 + the RANGE row.
        $this->assertCount(4, $res['products']);
        $faces = array();
        foreach ($res['products'] as $p) $faces[] = $p['face_value'];
        $this->assertContains('25.00000000', $faces);
        $this->assertContains('50.00000000', $faces);
    }

    public function testTheVendorsOwnConversionIsPreferredToAnFxRate()
    {
        list($adapter,) = $this->adapter(array(self::ok(self::fixture('products_us.json'))));
        $res = $adapter->products('US');

        $costs = array();
        foreach ($res['products'] as $p) {
            if ($p['face_value'] !== null) $costs[$p['face_value']] = $p['cost'];
        }
        // fixedRecipientToSenderDenominationsMap is the vendor's own answer to
        // "what will this cost me in my account currency", already converted.
        $this->assertSame('39250.00000000', $costs['25.00000000']);
        $this->assertSame('78500.00000000', $costs['50.00000000']);
    }

    public function testTheDenominationMapIsReadInBothOfTheVendorsSpellings()
    {
        list($adapter,) = $this->adapter(array(self::ok(self::fixture('products_us.json'))));
        $res = $adapter->products('US');

        // Steam's map arrives as a list of single-key objects rather than an
        // object; the difference is invisible until a sync imports no prices.
        $steam = null;
        foreach ($res['products'] as $p) {
            if ($p['brand_name'] === 'Steam') $steam = $p;
        }
        $this->assertNotNull($steam);
        $this->assertSame('31400.00000000', $steam['cost']);
    }

    public function testARangeProductIsImportedWithItsBoundsAndNoFaceValue()
    {
        list($adapter,) = $this->adapter(array(self::ok(self::fixture('products_us.json'))));
        $res = $adapter->products('US');

        $range = null;
        foreach ($res['products'] as $p) {
            if ($p['denomination_type'] === 'RANGE') $range = $p;
        }
        $this->assertNotNull($range);
        $this->assertNull($range['face_value']);
        $this->assertSame('5.00000000', $range['min_face_value']);
        $this->assertSame('500.00000000', $range['max_face_value']);
    }

    /**
     * Every other currency column in the panel defaults to the base currency.
     * This one must not: it records what a *card* is worth to the person
     * redeeming it, so a default would turn a vendor's missing field into a
     * euro card silently sold as a dollar card.
     */
    public function testAProductWithNoStatedCurrencyIsSkippedRatherThanAssumedDollars()
    {
        list($adapter,) = $this->adapter(array(self::ok(json_encode(array(
            array(
                'productId' => 55, 'productName' => 'Mystery Card',
                'denominationType' => 'FIXED',
                'fixedRecipientDenominations' => array(25),
                'brand' => array('brandId' => 1, 'brandName' => 'Mystery'),
                'country' => array('isoName' => 'US'),
            ),
        )))));

        $res = $adapter->products('US');

        $this->assertTrue($res['ok']);
        $this->assertSame(array(), $res['products']);
    }

    public function testTheCatalogueImportRefusesARowWithNoCurrency()
    {
        $app = new IntegrationHarness();
        $app->seed_minimal();
        $app->seed_giftcards();
        $app->model(array('Giftcard_product_model'));

        $outcome = $app->Giftcard_product_model->upsert_from_provider(1, 1, array(
            'provider_product_id' => '99', 'name' => 'Mystery', 'brand_name' => 'Mystery',
            'country_code' => 'US', 'denomination_type' => 'FIXED',
            'face_value' => '25', 'cost' => '39000',
        ));

        $this->assertSame('unchanged', $outcome);
        $this->assertNull($app->Giftcard_product_model->find_by_code('MYSTERY-US-25'));
    }

    public function testNoCostIsImportedWhenTheAccountIsNotInTheBaseCurrency()
    {
        list($adapter,) = $this->adapter(
            array(self::ok(self::fixture('products_foreign_wallet.json'))));

        $res = $adapter->products('US');

        $this->assertSame('25.00000000', $res['products'][0]['face_value']);
        $this->assertNull($res['products'][0]['cost'],
            'a dollar figure in a naira column reads as a 99% discount');
    }

    public function testAPaginatedCatalogueIsUnwrapped()
    {
        list($adapter,) = $this->adapter(array(self::ok(self::fixture('products_paginated.json'))));

        $res = $adapter->products('GB');

        $this->assertTrue($res['ok']);
        $this->assertCount(1, $res['products']);
        $this->assertSame('Google Play', $res['products'][0]['brand_name']);
        $this->assertSame('GB', $res['products'][0]['country_code']);
    }

    public function testTheCatalogueCallIsScopedToTheRequestedCountry()
    {
        list($adapter, $http) = $this->adapter(array(self::ok(self::fixture('products_us.json'))));
        $adapter->products('GB');

        $this->assertStringContainsString('/countries/GB/products', $http->calls[0]['url']);
    }

    public function testTheBalanceIsReadWithItsCurrency()
    {
        list($adapter,) = $this->adapter(array(self::ok(self::fixture('balance.json'))));

        $res = $adapter->balance();

        $this->assertTrue($res['ok']);
        $this->assertSame('62580.36000000', $res['balance']);
        $this->assertSame('NGN', $res['currency']);
    }

    public function testTheOrderStatusLookupNormalisesTheVendorsListShape()
    {
        list($adapter,) = $this->adapter(array(self::ok(self::fixture('transaction.json'))));

        $res = $adapter->order_status('1');

        $this->assertTrue($res['ok']);
        $this->assertSame('PLACED', $res['status']);
        $this->assertSame('1', $res['reference']);
    }

    /* ==================== catalogue sync into the panel =================== */

    public function testASyncImportsNewDenominationsUnpricedAndInactive()
    {
        $app = new IntegrationHarness();
        $app->seed_minimal();
        $app->model(array('Giftcard_brand_model', 'Giftcard_product_model'));

        $rows = json_decode(self::fixture('products_us.json'), true);
        $brand_id = $app->Giftcard_brand_model->upsert_from_provider(array(
            'brand_name' => 'Amazon', 'brand_id' => '2',
            'redeem_instructions' => 'Go to amazon.com/redeem.',
        ));
        $outcome = $app->Giftcard_product_model->upsert_from_provider(1, $brand_id, array(
            'provider_product_id' => '11', 'name' => 'Amazon US', 'brand_name' => 'Amazon',
            'country_code' => 'US', 'denomination_type' => 'FIXED',
            'recipient_currency' => 'USD', 'face_value' => '25', 'cost' => '39250',
        ));

        $this->assertSame('inserted', $outcome);
        $product = $app->Giftcard_product_model->find_by_code('AMAZON-US-25');
        $this->assertNull($product->price, 'a sync never invents a selling price');
        $this->assertSame(0, (int)$product->is_active);
        $this->assertSame('39250.00000000', $product->provider_cost);
        $this->assertNotEmpty($rows);
    }

    /**
     * The rule that keeps a nightly sync from repricing the shop. Gift card
     * cost moves with the FX rate; if a sync could write `price`, the shop
     * would chase the naira and an operator's considered margin would vanish
     * overnight.
     */
    public function testASyncRefreshesCostButNeverTouchesOurPrice()
    {
        $app = new IntegrationHarness();
        $app->seed_minimal();
        $app->seed_giftcards();
        $app->model(array('Giftcard_product_model'));
        $before = $app->Giftcard_product_model->find_by_code('AMAZON-US-25');

        $outcome = $app->Giftcard_product_model->upsert_from_provider(
            (int)$before->provider_id, (int)$before->brand_id, array(
                'provider_product_id' => '11', 'name' => 'Amazon US', 'brand_name' => 'Amazon',
                'country_code' => 'US', 'denomination_type' => 'FIXED',
                'recipient_currency' => 'USD', 'face_value' => '25', 'cost' => '44000',
            ));

        $after = $app->Giftcard_product_model->find_by_code('AMAZON-US-25');
        $this->assertSame('updated', $outcome);
        $this->assertSame('44000.00000000', $after->provider_cost);
        $this->assertSame($before->price, $after->price);
        $this->assertSame((int)$before->is_active, (int)$after->is_active,
            'whether we sell something is an operator decision, not the vendor\'s');
    }

    public function testASyncDoesNotReactivateABrandAnOperatorSwitchedOff()
    {
        $app = new IntegrationHarness();
        $app->seed_minimal();
        $app->seed_giftcards();
        $app->model(array('Giftcard_brand_model'));

        $app->Giftcard_brand_model->upsert_from_provider(array(
            'brand_name' => 'Switched Off', 'brand_id' => '77',
            'redeem_instructions' => 'Refreshed by the vendor.',
        ));

        $brand = $app->Giftcard_brand_model->find_by_code('SWITCHED-OFF');
        $this->assertSame(0, (int)$brand->is_active);
        $this->assertSame('Refreshed by the vendor.', $brand->redeem_instructions,
            'the vendor still owns its own copy');
    }

    /* ========================= wiring and gates ========================== */

    private function admin_src()
    {
        return file_get_contents(self::$root.'/application/controllers/admin/Giftcards.php');
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

    public function testTheScreensExist()
    {
        $this->assertFileExists(self::$root.'/application/controllers/admin/Giftcards.php',
            'giftcard permissions are seeded, so the screens that use them must exist');
        $this->assertFileExists(self::$root.'/application/views/admin/giftcards/index.php');
        $this->assertFileExists(self::$root.'/application/views/admin/giftcards/detail.php');
        $this->assertFileExists(self::$root.'/application/controllers/dashboard/Giftcards.php');
        $this->assertFileExists(self::$root.'/application/views/dashboard/giftcards/index.php');
        $this->assertFileExists(self::$root.'/application/views/dashboard/giftcards/detail.php');
    }

    public function testEveryAdminMutationIsPostOnlyAndAudited()
    {
        $src = $this->admin_src();
        $this->assertStringContainsString("method(true) !== 'POST'", $src);
        foreach (array('giftcard.collected', 'giftcard.abandoned', 'giftcard.refunded') as $action) {
            $this->assertStringContainsString($action, $src,
                'every state change has to leave an audit entry');
        }
    }

    public function testTheAdminScreenIsPermissionGated()
    {
        $src = $this->admin_src();
        $this->assertStringContainsString("require_perm('giftcards.view')", $src);
        $this->assertStringContainsString("'giftcards.reveal'", $src);
        $this->assertStringContainsString("'giftcards.refund'", $src);

        $matrix = self::seeder()::role_matrix();
        $this->assertContains('giftcards.view', $matrix['STAFF']);
        $this->assertNotContains('giftcards.reveal', $matrix['STAFF'],
            'a gift card code is money; reading one is not a support task');
        $this->assertNotContains('giftcards.refund', $matrix['STAFF']);
        $this->assertContains('giftcards.reveal', $matrix['ADMIN']);
    }

    public function testTheGiftcardPermissionsAreSeeded()
    {
        $catalog = self::seeder()::permission_catalog();
        $this->assertArrayHasKey('giftcards', $catalog);
        foreach (array('giftcards.view','giftcards.manage','giftcards.refund','giftcards.reveal') as $perm) {
            $this->assertContains($perm, $catalog['giftcards']);
        }
    }

    public function testNoControllerMovesMoneyOrDecryptsDirectly()
    {
        foreach (array('application/controllers/admin/Giftcards.php',
                       'application/controllers/dashboard/Giftcards.php') as $file) {
            $src = file_get_contents(self::$root.'/'.$file);
            $this->assertStringNotContainsString('ledgerservice->', $src);
            $this->assertStringNotContainsString('encryptionservice->', $src);
            $this->assertStringNotContainsString("update('wallets'", $src);
            $this->assertStringNotContainsString("update('giftcard_codes'", $src);
        }
    }

    public function testTheCustomerControllerOnlyEverReachesItsOwnRows()
    {
        $src = file_get_contents(self::$root.'/application/controllers/dashboard/Giftcards.php');
        $this->assertStringContainsString('find_public_for_user', $src);
        $this->assertStringNotContainsString('admin_find', $src);
        $this->assertStringContainsString('find_public_for_order', $src,
            'a card is fetched through its order, so one customer cannot name another\'s card');
    }

    public function testTheAdapterIsRegisteredInTheOneRegistry()
    {
        require_once self::$root.'/application/libraries/Provider_manager.php';
        $types = Provider_manager::supported_types(Provider_manager::FAMILY_GIFTCARD);
        $this->assertContains('RELOADLY', $types);
        $this->assertContains('MOCK_GIFTCARD', $types);
        $this->assertContains(Provider_manager::FAMILY_GIFTCARD, Provider_manager::families());
    }

    public function testTheSyncServiceRoutesGiftcardVendorsToTheirOwnFamily()
    {
        require_once self::$root.'/application/libraries/ProviderSyncService.php';
        $this->assertSame(Provider_manager::FAMILY_GIFTCARD,
            ProviderSyncService::family((object)array('api_type' => 'RELOADLY')));
        $this->assertContains('RELOADLY', ProviderSyncService::supported_types(),
            'the admin create form reads this list, so an unregistered type cannot be added');
    }

    public function testTheSweepIsScheduledEverywhereItHasToBe()
    {
        $config = file_get_contents(self::$root.'/application/config/marvy.php');
        $this->assertMatchesRegularExpression("~'giftcard_codes'\s*=>~", $config);

        $cron = file_get_contents(self::$root.'/application/controllers/Cron.php');
        $this->assertStringContainsString('public function giftcard_codes()', $cron);
        // Job registration moved from Cron.php's own list to the shared
        // registry (one map for the CLI and the admin "Run now" button), so
        // "is the sweep wired" is asked where the list now lives.
        $registry = file_get_contents(self::$root.'/application/libraries/CronRegistry.php');
        $this->assertStringContainsString("'giftcard_codes',", $registry);

        $crontab = file_get_contents(self::$root.'/cron/crontab.example');
        $this->assertStringContainsString('cron giftcard_codes', $crontab);
    }

    public function testTheMigrationIsRegisteredAndDeclaresItsTables()
    {
        require_once self::$root.'/application/migrations/014_giftcards.php';
        $this->assertSame(
            array('giftcard_brands', 'giftcard_products', 'giftcard_orders', 'giftcard_codes'),
            Migration_Giftcards::tables());

        $config = file_get_contents(self::$root.'/application/config/migration.php');
        preg_match("/migration_version'\]\s*=\s*(\d+)/", $config, $m);
        $this->assertGreaterThanOrEqual(14, (int)($m[1] ?? 0),
            'later domains may add migrations, but the gift-card migration must stay registered');
    }

    public function testTheEnvExampleDocumentsTheVendor()
    {
        $env = file_get_contents(self::$root.'/.env.example');
        foreach (array('RELOADLY_CLIENT_ID', 'RELOADLY_CLIENT_SECRET',
                       'RELOADLY_BASE_URL', 'GIFTCARD_COUNTRIES') as $key) {
            $this->assertStringContainsString($key, $env);
        }
        $this->assertStringContainsString('giftcards-sandbox.reloadly.com', $env,
            'the shipped default must be the sandbox, not the live wallet');
    }

    public function testTheNavIconExists()
    {
        $icons = file_get_contents(self::$root.'/application/views/partials/icon.php');
        $this->assertStringContainsString("'gift-card'", $icons);
        $layout = ShellSource::app(self::$root);
        $this->assertStringContainsString('admin/giftcards', $layout);
        $this->assertStringContainsString('dashboard/giftcards', $layout);
    }

    public function testTheAdminListNeverJoinsTheCodesTable()
    {
        $src = file_get_contents(self::$root.'/application/models/Service_transaction_model.php');
        // The join list, not the comment that explains the omission.
        preg_match_all('~->join\(\s*.([\w]+).~', $src, $m);
        $this->assertNotContains('giftcard_codes', $m[1],
            'a queue of 25 rows must not drag 25 encrypted bearer instruments through the app');
        $this->assertContains('giftcard_orders', $m[1]);
    }

    /* ------------------------------ helpers ------------------------------ */

    /**
     * Make the mock vendor willing to hand over codes for a reference it
     * originally accepted without issuing anything.
     *
     * Reaches into the adapter's private state on purpose: the alternative is
     * a public setter that exists only for tests and could be called by
     * production code by accident.
     */
    private function becomes_ready($reference, $quantity)
    {
        $prop = new ReflectionProperty('MockGiftcardAdapter', 'ready');
        $prop->setAccessible(true);
        $ready = $prop->getValue();
        $ready[(string)$reference] = array('quantity' => $quantity, 'product_id' => 'late');
        $prop->setValue(null, $ready);
    }
}

/* --------------------------- doubles for the adapter ---------------------- */

/**
 * Scripted HTTP for the Reloadly adapter.
 *
 * Records the body as well as the URL, because half of what these tests assert
 * is what was *sent* — the idempotency key, the audience, the denomination.
 * Throws on an unscripted call so a test that makes an unexpected request — an
 * extra token fetch, a retry — fails loudly instead of silently reusing a
 * response.
 */
class GiftcardFakeHttp
{
    public $calls = array();
    private $script;

    public function __construct(array $script) { $this->script = $script; }

    public function get($url, $headers = array(), $options = array())
    {
        return $this->record('GET', $url, null, $headers, $options);
    }

    public function post($url, $data = null, $headers = array(), $options = array())
    {
        return $this->record('POST', $url, $data, $headers, $options);
    }

    private function record($method, $url, $body, $headers, $options)
    {
        $this->calls[] = array(
            'method' => $method, 'url' => $url,
            'path' => parse_url($url, PHP_URL_PATH),
            'body' => $body, 'headers' => $headers, 'options' => $options,
        );
        if (!$this->script) {
            throw new RuntimeException('GiftcardFakeHttp: unscripted '.$method.' '
                .parse_url($url, PHP_URL_PATH));
        }
        return array_shift($this->script);
    }
}

/** Minimal container for the adapter's own get_instance() credential read. */
#[AllowDynamicProperties]
class GiftcardFakeCI
{
    public $load;
    public function __construct()
    {
        $this->load = new GiftcardFakeLoader();
        $this->encryptionservice = new GiftcardPassthroughEncryption();
    }
}

class GiftcardFakeLoader
{
    public function library($n, $p = null, $o = null) { return $this; }
    public function model($n, $a = null, $d = false) { return $this; }
    public function helper($n = '') { return $this; }
}

class GiftcardPassthroughEncryption
{
    public function encrypt($plain) { return 'enc:'.base64_encode((string)$plain); }
    public function decrypt($blob)
    {
        return strpos((string)$blob, 'enc:') === 0
            ? base64_decode(substr((string)$blob, 4)) : (string)$blob;
    }
}
