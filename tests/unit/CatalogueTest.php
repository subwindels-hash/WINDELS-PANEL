<?php
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__).'/_support/FakeDb.php';
require_once dirname(__DIR__).'/_support/IntegrationHarness.php';

/**
 * Catalogue pricing and shelf control (Session 29).
 *
 * The audit's last open gap. Every catalogue in the panel imports the same
 * way on purpose — a vendor sync writes `is_active = 0, price = NULL`, because
 * the vendor knows its own cost and nothing about our margin — and that rule
 * had no counterpart. There was no supported way to set the price it refuses
 * to invent, so putting a product on sale meant hand-written UPDATEs against
 * production, with no validation, no audit entry and nothing stopping a
 * second active airtime row from silently taking over a network's rates.
 *
 * The behavioural half runs the real CatalogueService and the real product
 * models against the migration-derived schema, then buys through the real
 * VtuService/NumberService to prove the pricing decision actually reaches the
 * checkout — a price that saves but does not sell is the whole failure this
 * screen exists to prevent. The source-level half pins the admin-surface
 * guarantees the other domains pin: POST-only, permissioned, audited.
 */
class CatalogueTest extends TestCase
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
        require_once self::$root.'/application/libraries/MockVtuAdapter.php';
        require_once self::$root.'/application/libraries/MockNumberAdapter.php';
    }

    protected function setUp(): void
    {
        if (class_exists('MockNumberAdapter')) MockNumberAdapter::reset();
    }

    /** A world with all four catalogues seeded and a funded customer. */
    private function app($balance = '100000')
    {
        $app = new IntegrationHarness();
        $app->seed_minimal();
        $app->seed_vtu();
        $app->seed_numbers();
        $app->seed_identity();
        $app->seed_giftcards();
        $user = $app->register('cat_user', 'cat@x.test');
        $app->credit($user, $balance);
        $app->library('CatalogueService');
        $app->model(array(
            'Vtu_product_model', 'Vtu_network_model',
            'Number_product_model', 'Number_country_model', 'Number_service_model',
            'Identity_product_model', 'Giftcard_product_model', 'Giftcard_brand_model',
            'Provider_model',
        ));
        return array($app, $user);
    }

    /** The row a sync would have left behind: imported, unpriced, switched off. */
    private function imported_vtu_row($app)
    {
        $network = $app->Vtu_network_model->find_by_code('MTND');
        $id = $app->Vtu_product_model->create(array(
            'network_id' => $network->id, 'service_type' => 'DATA',
            'code' => 'MTN-5GB', 'provider_code' => 'mtn-5gb',
            'name' => 'MTN 5GB (30 days)', 'price' => null,
            'provider_cost' => '2400.00000000', 'is_active' => 0, 'sorting' => 9,
        ));
        return $app->Vtu_product_model->find_by_id($id);
    }

    /* ==================== the gap this screen closes ==================== */

    /**
     * The whole point, end to end: a synced row is unbuyable, pricing it and
     * switching it on makes it buyable, and the customer is charged the price
     * that was typed in.
     *
     * Asserting the save alone would pass with a service that writes to a
     * column nothing reads.
     */
    public function testPricingASyncedRowIsWhatMakesItBuyable()
    {
        list($app, $user) = $this->app();
        $app->library(array('TransactionEngine', 'VtuService'));
        $product = $this->imported_vtu_row($app);

        // As imported: the buying path refuses it.
        $before = $app->vtuservice->data($user, array(
            'network' => 'MTND', 'product' => 'MTN-5GB', 'msisdn' => '08031234567'));
        $this->assertFalse($before['ok']);
        $this->assertSame('NO_PRODUCT', $before['code'],
            'an inactive row must not be reachable by code');

        $res = $app->catalogueservice->save('vtu', $product, array(
            'network_id' => $product->network_id, 'name' => $product->name,
            'code' => $product->code, 'provider_code' => 'mtn-5gb',
            'price' => '3000', 'provider_cost' => '2400', 'is_active' => '1',
        ));
        $this->assertTrue($res['ok'], $res['error'] ?? '');

        $opening = $app->balance($user);
        $after = $app->vtuservice->data($user, array(
            'network' => 'MTND', 'product' => 'MTN-5GB', 'msisdn' => '08031234567'));
        $this->assertTrue($after['ok'], $after['error'] ?? '');
        $this->assertSame('3000.00000000', $after['transaction']->amount,
            'the customer must be charged the price the operator typed');
        $this->assertSame(
            bcsub($opening, '3000.00000000', 8), $app->balance($user));
        list($debits, $credits) = $app->ledger_is_balanced();
        $this->assertSame($debits, $credits);
    }

    /**
     * The rule the sync depends on, from the other side: activating an
     * unpriced row is refused rather than producing a product that fails at
     * the checkout.
     *
     * A free sale would be worse than an error — VtuService returns NO_PRICE,
     * so the customer meets a broken product instead of a missing one.
     */
    public function testAProductCannotGoOnSaleWithoutAPrice()
    {
        list($app,) = $this->app();
        $product = $this->imported_vtu_row($app);

        $res = $app->catalogueservice->save('vtu', $product, array(
            'network_id' => $product->network_id, 'name' => $product->name,
            'code' => $product->code, 'price' => '', 'is_active' => '1',
        ));
        $this->assertFalse($res['ok']);
        $this->assertSame('NOT_SELLABLE', $res['code']);
        $this->assertStringContainsString('price', strtolower($res['error']));

        $stored = $app->Vtu_product_model->find_by_id($product->id);
        $this->assertSame('0', (string)$stored->is_active,
            'a refused activation must not half-apply');
    }

    /** The same rule via the one-click switch, which is the path operators use. */
    public function testTheOnSaleSwitchAlsoRefusesAnUnpricedRow()
    {
        list($app,) = $this->app();
        $product = $this->imported_vtu_row($app);

        $res = $app->catalogueservice->set_status('vtu', $product, true);
        $this->assertFalse($res['ok']);
        $this->assertSame('NOT_SELLABLE', $res['code']);
        $this->assertSame('0',
            (string)$app->Vtu_product_model->find_by_id($product->id)->is_active);
    }

    /**
     * Switching *off* is never blocked.
     *
     * It is the emergency brake: any rule that could refuse it would be a rule
     * that keeps a mispriced product on sale while someone argues with a form.
     */
    public function testAProductCanAlwaysBeTakenOffSale()
    {
        list($app,) = $this->app();
        // A row that would fail every activation rule: no price at all.
        $network = $app->Vtu_network_model->find_by_code('MTND');
        $id = $app->Vtu_product_model->create(array(
            'network_id' => $network->id, 'service_type' => 'DATA',
            'code' => 'BROKEN', 'name' => 'Somehow live', 'price' => null,
            'is_active' => 1,
        ));
        $row = $app->Vtu_product_model->find_by_id($id);

        $res = $app->catalogueservice->set_status('vtu', $row, false);
        $this->assertTrue($res['ok'], $res['error'] ?? '');
        $this->assertSame('0', (string)$app->Vtu_product_model->find_by_id($id)->is_active);
    }

    /* ===================== the one-live-row invariants =================== */

    /**
     * VtuService::variable_product() takes active_for(...)[0]. A second active
     * airtime row for one network therefore does not error — it silently
     * decides the discount and the amount limits by sort order, which is the
     * kind of bug that is only ever found in a margin report.
     */
    public function testASecondVariableAmountProductPerNetworkIsRefused()
    {
        list($app,) = $this->app();
        $network = $app->Vtu_network_model->find_by_code('MTN');
        $id = $app->Vtu_product_model->create(array(
            'network_id' => $network->id, 'service_type' => 'AIRTIME',
            'code' => 'MTN-AIRTIME-PROMO', 'name' => 'MTN Airtime promo',
            'discount_percent' => '9.0000', 'is_active' => 0,
        ));
        $rival = $app->Vtu_product_model->find_by_id($id);

        $res = $app->catalogueservice->set_status('vtu', $rival, true);
        $this->assertFalse($res['ok']);
        $this->assertSame('NOT_SELLABLE', $res['code']);
        $this->assertStringContainsString('MTN Airtime', $res['error'],
            'the message must name the row already on sale, or the operator cannot act on it');

        // And the original still decides the price.
        $app->library(array('TransactionEngine', 'VtuService'));
        $user = $app->register('air_user', 'air@x.test');
        $app->credit($user, '5000');
        $buy = $app->vtuservice->airtime($user, array(
            'network' => 'MTN', 'msisdn' => '08031234567', 'amount' => '1000'));
        $this->assertTrue($buy['ok'], $buy['error'] ?? '');
        // The seeded row is 2%, the rejected rival 9%.
        $this->assertSame('980.00000000', $buy['transaction']->amount);
    }

    /** Editing the row that is already live is not "a second row". */
    public function testTheLiveVariableProductCanStillBeEdited()
    {
        list($app,) = $this->app();
        $network = $app->Vtu_network_model->find_by_code('MTN');
        $live = $app->Vtu_product_model->find_by_code($network->id, 'AIRTIME', 'MTN-AIRTIME');
        $this->assertNotNull($live);

        $res = $app->catalogueservice->save('vtu', $live, array(
            'network_id' => $network->id, 'name' => $live->name, 'code' => $live->code,
            'discount_percent' => '3.5', 'min_amount' => '100', 'max_amount' => '20000',
            'is_active' => '1',
        ));
        $this->assertTrue($res['ok'], $res['error'] ?? '');
        $this->assertSame('3.5000',
            $app->Vtu_product_model->find_by_id($live->id)->discount_percent);
    }

    /**
     * Numbers have the same shape of trap: NumberService resolves through
     * find_for_pair(), which takes the first active row for a (country,
     * service) pair.
     */
    public function testASecondActiveNumberProductForAPairIsRefused()
    {
        list($app,) = $this->app();
        $country = $app->Number_country_model->find_by_code('NG');
        $service = $app->Number_service_model->find_by_code('WHATSAPP');

        $res = $app->catalogueservice->save('numbers', null, array(
            'country_id' => $country->id, 'service_id' => $service->id,
            'code' => 'NG-WHATSAPP-ALT', 'price' => '600', 'provider_cost' => '250',
            'is_active' => '1',
        ));
        $this->assertFalse($res['ok']);
        $this->assertSame('NOT_SELLABLE', $res['code']);

        // Nothing was written: a refused create must not leave a row behind.
        $this->assertNull($app->Number_product_model->find_by_code(
            $country->id, $service->id, 'NG-WHATSAPP-ALT'));
    }

    /** The same product may be created off sale — that is how you stage one. */
    public function testASecondNumberProductMayBeCreatedOffSale()
    {
        list($app,) = $this->app();
        $country = $app->Number_country_model->find_by_code('NG');
        $service = $app->Number_service_model->find_by_code('WHATSAPP');

        $res = $app->catalogueservice->save('numbers', null, array(
            'country_id' => $country->id, 'service_id' => $service->id,
            'code' => 'NG-WHATSAPP-ALT', 'price' => '600', 'is_active' => '0',
        ));
        $this->assertTrue($res['ok'], $res['error'] ?? '');
        $this->assertSame('0', (string)$res['product']->is_active);
    }

    /* ========================== money handling ========================== */

    /**
     * Prices are money, and money in this codebase is a DECIMAL string with
     * eight places. A float that reaches the column truncates a kobo per sale.
     */
    public function testPricesAreStoredAsEightPlaceDecimalStrings()
    {
        list($app,) = $this->app();
        $product = $this->imported_vtu_row($app);

        $app->catalogueservice->save('vtu', $product, array(
            'network_id' => $product->network_id, 'name' => $product->name,
            'code' => $product->code, 'price' => '2999.99', 'provider_cost' => '2400.5',
        ));
        $stored = $app->Vtu_product_model->find_by_id($product->id);
        $this->assertSame('2999.99000000', $stored->price);
        $this->assertSame('2400.50000000', $stored->provider_cost);
    }

    /** Blank is NULL, not zero: "not priced yet" and "free" are different. */
    public function testABlankPriceClearsRatherThanZeroing()
    {
        list($app,) = $this->app();
        $product = $this->imported_vtu_row($app);
        $app->Vtu_product_model->update_fields($product->id, array('price' => '1000.00000000'));

        $app->catalogueservice->save('vtu', $app->Vtu_product_model->find_by_id($product->id), array(
            'network_id' => $product->network_id, 'name' => $product->name,
            'code' => $product->code, 'price' => '',
        ));
        $this->assertNull($app->Vtu_product_model->find_by_id($product->id)->price,
            'a cleared price must be NULL — a zero price is a product sold for nothing');
    }

    public function testNonNumericAndNegativePricesAreRefused()
    {
        list($app,) = $this->app();
        $product = $this->imported_vtu_row($app);

        foreach (array('free', '-100') as $bad) {
            $res = $app->catalogueservice->save('vtu', $product, array(
                'network_id' => $product->network_id, 'name' => $product->name,
                'code' => $product->code, 'price' => $bad,
            ));
            $this->assertFalse($res['ok'], "price '{$bad}' must be refused");
            $this->assertSame('INVALID', $res['code']);
        }
    }

    /**
     * Selling below cost is allowed and warned about.
     *
     * It is a legitimate loss-leader and an illegitimate fat finger, and only
     * the operator knows which — but shipping it silently is how a panel sells
     * ₦42,000 gift cards for ₦4,200 all weekend.
     */
    public function testSellingBelowCostIsAllowedButWarnedAbout()
    {
        list($app,) = $this->app();
        $product = $this->imported_vtu_row($app);

        $res = $app->catalogueservice->save('vtu', $product, array(
            'network_id' => $product->network_id, 'name' => $product->name,
            'code' => $product->code, 'price' => '2000', 'provider_cost' => '2400',
            'is_active' => '1',
        ));
        $this->assertTrue($res['ok'], $res['error'] ?? '');
        $this->assertNotEmpty($res['warnings'], 'a below-cost price must be flagged');
        $this->assertStringContainsString('below the vendor cost', implode(' ', $res['warnings']));
        $this->assertSame('1', (string)$app->Vtu_product_model->find_by_id($product->id)->is_active,
            'a warning is not a refusal');
    }

    /** A discount over 100% would floor to zero — a free top-up, not a sale. */
    public function testAnImpossibleDiscountIsRefused()
    {
        list($app,) = $this->app();
        $network = $app->Vtu_network_model->find_by_code('MTN');
        $live = $app->Vtu_product_model->find_by_code($network->id, 'AIRTIME', 'MTN-AIRTIME');

        foreach (array('120', '-5') as $bad) {
            $res = $app->catalogueservice->save('vtu', $live, array(
                'network_id' => $network->id, 'name' => $live->name, 'code' => $live->code,
                'discount_percent' => $bad,
            ));
            $this->assertFalse($res['ok'], "discount '{$bad}' must be refused");
        }
    }

    public function testAnInvertedAmountRangeIsRefused()
    {
        list($app,) = $this->app();
        $network = $app->Vtu_network_model->find_by_code('MTN');
        $live = $app->Vtu_product_model->find_by_code($network->id, 'AIRTIME', 'MTN-AIRTIME');

        $res = $app->catalogueservice->save('vtu', $live, array(
            'network_id' => $network->id, 'name' => $live->name, 'code' => $live->code,
            'min_amount' => '5000', 'max_amount' => '100',
        ));
        $this->assertFalse($res['ok']);
        $this->assertStringContainsString('minimum', strtolower($res['error']));
    }

    /**
     * A variable-amount product must not carry a row price.
     *
     * Nothing reads it — the customer names the amount — so a stored price
     * would be an authoritative-looking number that never applies, which is
     * how an operator "fixes" a margin by editing a field with no effect.
     */
    public function testAVariableAmountProductNeverStoresARowPrice()
    {
        list($app,) = $this->app();
        $network = $app->Vtu_network_model->find_by_code('MTN');
        $live = $app->Vtu_product_model->find_by_code($network->id, 'AIRTIME', 'MTN-AIRTIME');

        $app->catalogueservice->save('vtu', $live, array(
            'network_id' => $network->id, 'name' => $live->name, 'code' => $live->code,
            'price' => '999', 'discount_percent' => '2',
        ));
        $this->assertNull($app->Vtu_product_model->find_by_id($live->id)->price);
    }

    /* ============================ the grid ============================== */

    /**
     * The admin grid must show what customers cannot see — that is its job.
     * A grid built on the customer-facing active() query would hide exactly
     * the rows an operator came to fix.
     */
    public function testTheGridShowsInactiveAndUnpricedRows()
    {
        list($app,) = $this->app();
        $this->imported_vtu_row($app);

        $all = $app->catalogueservice->grid('vtu', array(), 100, 0);
        $codes = array_map(function ($r) { return $r->code; }, $all['rows']);
        $this->assertContains('MTN-5GB', $codes);

        $unpriced = $app->catalogueservice->grid('vtu', array('pricing' => 'unpriced'), 100, 0);
        foreach ($unpriced['rows'] as $r) $this->assertNull($r->price);
        $this->assertContains('MTN-5GB',
            array_map(function ($r) { return $r->code; }, $unpriced['rows']));

        $off = $app->catalogueservice->grid('vtu', array('status' => 'inactive'), 100, 0);
        foreach ($off['rows'] as $r) $this->assertSame('0', (string)$r->is_active);
    }

    /** Every domain answers the same grid call — the screen is generic. */
    public function testEveryDomainHasAGridAndACount()
    {
        list($app,) = $this->app();
        foreach (array_keys(CatalogueService::domains()) as $domain) {
            $grid = $app->catalogueservice->grid($domain, array(), 25, 0);
            $this->assertIsArray($grid['rows'], $domain.' must return rows');
            $this->assertGreaterThanOrEqual(0, $grid['total']);
            $this->assertSame($grid['total'], $app->catalogueservice->model($domain)->admin_count(array()));
        }
    }

    /**
     * The count and the page must be built from the same WHERE clause, or the
     * pager promises pages that render empty.
     */
    public function testTheCountAgreesWithTheFilteredPage()
    {
        list($app,) = $this->app();
        $this->imported_vtu_row($app);
        $filters = array('pricing' => 'unpriced');

        $grid = $app->catalogueservice->grid('vtu', $filters, 100, 0);
        $this->assertSame(count($grid['rows']), $grid['total']);
    }

    public function testTheGridIsPaged()
    {
        list($app,) = $this->app();
        $first = $app->catalogueservice->grid('vtu', array(), 2, 0);
        $second = $app->catalogueservice->grid('vtu', array(), 2, 2);

        $this->assertCount(2, $first['rows']);
        $this->assertGreaterThan(2, $first['total'], 'the seed must have more rows than one page');
        $this->assertNotEquals($first['rows'][0]->code, $second['rows'][0]->code ?? null);
    }

    /* ======================= per-domain specifics ======================= */

    /**
     * A gift card's face value is in the card's own currency, and the panel
     * charges in naira. Defaulting the currency would import a dollar card
     * and sell it as a naira one — migration 014 refuses to default it, and
     * so does this form.
     */
    public function testAGiftCardMustNameItsOwnCurrency()
    {
        list($app,) = $this->app();
        $brand = $app->Giftcard_brand_model->find_by_code('AMAZON');

        $res = $app->catalogueservice->save('giftcards', null, array(
            'brand_id' => $brand->id, 'name' => 'Amazon US $30',
            'denomination_type' => 'FIXED', 'face_value' => '30',
            'price' => '50000', 'recipient_currency' => '',
        ));
        $this->assertFalse($res['ok']);
        $this->assertStringContainsString('currency', strtolower($res['error']));
    }

    public function testAFixedGiftCardNeedsAFaceValueBeforeItGoesOnSale()
    {
        list($app,) = $this->app();
        $brand = $app->Giftcard_brand_model->find_by_code('AMAZON');

        $res = $app->catalogueservice->save('giftcards', null, array(
            'brand_id' => $brand->id, 'name' => 'Amazon mystery',
            'denomination_type' => 'FIXED', 'recipient_currency' => 'USD',
            'price' => '50000', 'is_active' => '1',
        ));
        $this->assertFalse($res['ok']);
        $this->assertStringContainsString('face value', strtolower($res['error']));
    }

    /**
     * A card under a switched-off brand is invisible however it is priced,
     * because Giftcard_brand_model::sellable() joins on the brand. Saying so
     * is the difference between "this is off" and "this is broken".
     */
    public function testACardUnderASwitchedOffBrandWarnsThatItStaysHidden()
    {
        list($app,) = $this->app();
        $brand = $app->Giftcard_brand_model->find_by_code('SWITCHED-OFF');

        $res = $app->catalogueservice->save('giftcards', null, array(
            'brand_id' => $brand->id, 'name' => 'Hidden card',
            'denomination_type' => 'FIXED', 'recipient_currency' => 'USD',
            'face_value' => '10', 'price' => '17000', 'is_active' => '1',
        ));
        $this->assertTrue($res['ok'], $res['error'] ?? '');
        $this->assertStringContainsString('brand is switched off', implode(' ', $res['warnings']));
    }

    /** Dojah has no BVN-by-phone endpoint; such a row would always 404 — billed. */
    public function testBvnByPhoneIsRefused()
    {
        list($app,) = $this->app();

        $res = $app->catalogueservice->save('identity', null, array(
            'name' => 'BVN by phone', 'id_type' => 'BVN', 'lookup_field' => 'PHONE',
            'price' => '300',
        ));
        $this->assertFalse($res['ok']);
        $this->assertStringContainsString('phone', strtolower($res['error']));
    }

    /**
     * Pricing an identity check makes it appear in the customer catalogue.
     * The seed ships NIN_UNPRICED precisely so this path has something real
     * to fix.
     */
    public function testPricingAnIdentityCheckPutsItInTheCustomerCatalogue()
    {
        list($app,) = $this->app();
        $product = $app->Identity_product_model->find_by_code('NIN_UNPRICED');

        $codes = array_map(function ($p) { return $p->code; }, $app->Identity_product_model->active());
        $this->assertNotContains('NIN_UNPRICED', $codes);

        $res = $app->catalogueservice->save('identity', $product, array(
            'name' => $product->name, 'code' => $product->code,
            'id_type' => 'NIN', 'lookup_field' => 'IDENTIFIER',
            'provider_code' => 'kyc/nin/advance', 'price' => '450',
            'provider_cost' => '220', 'is_active' => '1',
        ));
        $this->assertTrue($res['ok'], $res['error'] ?? '');

        $codes = array_map(function ($p) { return $p->code; }, $app->Identity_product_model->active());
        $this->assertContains('NIN_UNPRICED', $codes);
    }

    /**
     * A number's stock is a snapshot from the last sync, so a zero does not
     * block the save — but NumberService refuses to reserve against it, and an
     * operator who is not told will read that as the panel being broken.
     */
    public function testActivatingAnOutOfStockNumberWarnsRatherThanRefusing()
    {
        list($app,) = $this->app();
        $country = $app->Number_country_model->find_by_code('GB');
        $service = $app->Number_service_model->find_by_code('TELEGRAM');

        $res = $app->catalogueservice->save('numbers', null, array(
            'country_id' => $country->id, 'service_id' => $service->id,
            'code' => 'GB-TELEGRAM', 'price' => '950', 'provider_cost' => '600',
            'stock' => '0', 'is_active' => '1',
        ));
        $this->assertTrue($res['ok'], $res['error'] ?? '');
        $this->assertStringContainsString('Stock is zero', implode(' ', $res['warnings']));
    }

    /**
     * Pricing a number and switching it on must make a real reservation
     * possible, charged at the new price.
     */
    public function testAPricedNumberBecomesRentable()
    {
        list($app, $user) = $this->app();
        $app->library(array('TransactionEngine', 'NumberService'));
        $country = $app->Number_country_model->find_by_code('GB');
        $service = $app->Number_service_model->find_by_code('TELEGRAM');

        $blocked = $app->numberservice->reserve($user,
            array('country' => 'GB', 'service' => 'TELEGRAM'));
        $this->assertFalse($blocked['ok']);
        $this->assertSame('NO_PRODUCT', $blocked['code']);

        $app->catalogueservice->save('numbers', null, array(
            'country_id' => $country->id, 'service_id' => $service->id,
            'code' => 'GB-TELEGRAM', 'price' => '950', 'provider_cost' => '600',
            'provider_country' => 'england', 'provider_product' => 'telegram',
            'stock' => '25', 'is_active' => '1',
        ));

        $res = $app->numberservice->reserve($user,
            array('country' => 'GB', 'service' => 'TELEGRAM'));
        $this->assertTrue($res['ok'], $res['error'] ?? '');
        $this->assertSame('950.00000000', $res['transaction']->amount);
        list($debits, $credits) = $app->ledger_is_balanced();
        $this->assertSame($debits, $credits);
    }

    /* ====================== provider and code rules ===================== */

    /**
     * A provider that cannot serve this domain is refused at the form, not at
     * the first purchase. Assigning an SMM panel to a data bundle produces a
     * product that charges the customer and then cannot be dispatched.
     */
    public function testAProviderThatCannotServeTheDomainIsRefused()
    {
        list($app,) = $this->app();
        $product = $this->imported_vtu_row($app);
        // Provider 1 from seed_minimal() is STANDARD_SMM.
        $smm = $app->Provider_model->find_by_id(1);
        $this->assertSame('STANDARD_SMM', $smm->api_type);

        $res = $app->catalogueservice->save('vtu', $product, array(
            'network_id' => $product->network_id, 'name' => $product->name,
            'code' => $product->code, 'price' => '3000', 'provider_id' => (string)$smm->id,
        ));
        $this->assertFalse($res['ok']);
        $this->assertStringContainsString('API type', $res['error']);
    }

    /** The picker only ever offers providers this build can actually drive. */
    public function testTheProviderPickerIsScopedToTheDomain()
    {
        list($app,) = $this->app();
        foreach (array('vtu' => 'MOCK', 'numbers' => 'MOCK_NUMBER',
                       'identity' => 'MOCK_IDENTITY', 'giftcards' => 'MOCK_GIFTCARD') as $domain => $type) {
            $types = array_map(function ($p) { return $p->api_type; },
                $app->catalogueservice->providers_for($domain));
            $this->assertContains($type, $types, $domain.' must offer its own vendor');
            $this->assertNotContains('STANDARD_SMM', $types,
                $domain.' must not offer an SMM panel');
        }
    }

    /** A clash is reported by name, not left to the database's UNIQUE error. */
    public function testADuplicateCodeIsRefusedWithAUsefulMessage()
    {
        list($app,) = $this->app();
        $network = $app->Vtu_network_model->find_by_code('MTND');

        $res = $app->catalogueservice->save('vtu', null, array(
            'network_id' => $network->id, 'name' => 'Another 1GB',
            'code' => 'MTN-1GB', 'price' => '350',
        ));
        $this->assertFalse($res['ok']);
        $this->assertSame('DUPLICATE', $res['code']);
        $this->assertStringContainsString('MTN 1GB', $res['error']);
    }

    /** Codes are normalised the same way the vendor syncs normalise them. */
    public function testCodesAreNormalisedLikeTheSyncDoes()
    {
        list($app,) = $this->app();
        $network = $app->Vtu_network_model->find_by_code('MTND');

        $res = $app->catalogueservice->save('vtu', null, array(
            'network_id' => $network->id, 'name' => 'MTN 2GB',
            'code' => 'mtn 2gb weekly!', 'price' => '900',
        ));
        $this->assertTrue($res['ok'], $res['error'] ?? '');
        $this->assertSame('MTN-2GB-WEEKLY-', $res['product']->code);
    }

    /**
     * The service type is the network's, not the form's. VtuService refuses a
     * product whose type does not match its network, so an independently
     * chosen type only ever creates a row nobody can buy.
     */
    public function testTheServiceTypeFollowsTheNetwork()
    {
        list($app,) = $this->app();
        $cable = $app->Vtu_network_model->find_by_code('DSTV');

        $res = $app->catalogueservice->save('vtu', null, array(
            'network_id' => $cable->id, 'service_type' => 'AIRTIME',
            'name' => 'DSTV Yanga', 'code' => 'DSTV-YANGA', 'price' => '4500',
        ));
        $this->assertTrue($res['ok'], $res['error'] ?? '');
        $this->assertSame('CABLE', $res['product']->service_type);
    }

    public function testAnUnknownDomainIsRefusedRatherThanGuessed()
    {
        list($app,) = $this->app();
        $res = $app->catalogueservice->save('wallets', null, array('name' => 'x'));
        $this->assertFalse($res['ok']);
        $this->assertSame('NO_DOMAIN', $res['code']);
        $this->assertFalse(CatalogueService::is_domain('wallets'));
        $this->assertFalse(CatalogueService::is_domain(''));
    }

    /* ========================= the admin surface ======================== */

    private function controller()
    {
        return file_get_contents(self::$root.'/application/controllers/admin/Catalogue.php');
    }

    public function testTheAdminScreenExists()
    {
        $this->assertFileExists(self::$root.'/application/controllers/admin/Catalogue.php',
            'pricing.manage is seeded, so the screen that uses it must exist');
        foreach (array('index','edit','_form') as $view) {
            $this->assertFileExists(self::$root.'/application/views/admin/catalogue/'.$view.'.php');
        }
    }

    public function testEveryMutationIsPostOnlyAndGuarded()
    {
        $src = $this->controller();
        foreach (array('domain','edit','create','update','status') as $action) {
            $this->assertStringContainsString('function '.$action.'(', $src,
                "admin/Catalogue.php must define {$action}()");
        }
        $this->assertStringContainsString("method(true) !== 'POST') show_404()", $src,
            'admin/Catalogue.php must reject non-POST mutations');
        $this->assertSame(3, substr_count($src, '$this->guard('),
            'create, update and status must each go through guard()');
    }

    /**
     * Reading the catalogue and changing a price are different jobs. STAFF can
     * see what is on sale; setting the price is `pricing.manage`, which STAFF
     * does not have — a price is money.
     */
    public function testChangingAPriceNeedsItsOwnPermission()
    {
        $src = $this->controller();
        $this->assertStringContainsString("require_perm('services.view')", $src);
        $this->assertStringContainsString("'pricing.manage'", $src);

        $seeder = $this->seeder();
        $matrix = $seeder::role_matrix();
        $this->assertContains('services.view', $matrix['STAFF']);
        $this->assertNotContains('pricing.manage', $matrix['STAFF'],
            'support answering tickets must not be able to reprice the shop');
        $this->assertContains('pricing.manage', $matrix['ADMIN']);

        $catalog = $seeder::permission_catalog();
        $found = false;
        foreach ($catalog as $group) {
            if (in_array('pricing.manage', $group, true)) $found = true;
        }
        $this->assertTrue($found, 'pricing.manage must be in the permission catalogue');
    }

    /** A price change nobody can attribute is a price change nobody owns. */
    public function testEveryChangeIsAuditLogged()
    {
        $src = $this->controller();
        $this->assertStringContainsString('Audit_log_model', $src);
        $this->assertSame(3, substr_count($src, '$this->audit('),
            'create, update and status must each record what they did');
        // The whole row, before and after: "who dropped the price on Steam
        // cards" has to be answerable months later.
        $this->assertStringContainsString('get_object_vars', $src);
    }

    public function testTheControllerHoldsNoPricingRules()
    {
        $src = $this->controller();
        // The rules live in CatalogueService so they cannot differ per domain.
        $this->assertStringContainsString('catalogueservice->', $src);
        foreach (array('->update(', '->insert(', 'discount_percent', 'price\' =>') as $needle) {
            $this->assertStringNotContainsString($needle, $src,
                'admin/Catalogue.php must delegate, not re-implement: '.$needle);
        }
    }

    public function testTheControllerNeverMovesMoney()
    {
        $src = $this->controller();
        foreach (array('ledgerservice->', "update('wallets'", "insert('wallet_transactions'") as $needle) {
            $this->assertStringNotContainsString($needle, $src);
        }
    }

    public function testTheGridIsBounded()
    {
        $src = $this->controller();
        $this->assertStringContainsString('const PER_PAGE', $src,
            'an unbounded catalogue grid would SELECT the whole price list');
    }

    public function testTheRoutesAndNavigationAreRegistered()
    {
        $routes = file_get_contents(self::$root.'/application/config/routes.php');
        $this->assertStringContainsString("\$route['admin/catalogue']", $routes);

        // CI3 matches in order: an action route after the (:any) detail route
        // would be swallowed by it.
        $detail = strpos($routes, "\$route['admin/catalogue/(:any)/(:any)']");
        $this->assertNotFalse($detail);
        foreach (array('update', 'status') as $action) {
            $pos = strpos($routes, "\$route['admin/catalogue/(:any)/(:any)/{$action}']");
            $this->assertNotFalse($pos, "the {$action} route is missing");
            $this->assertLessThan($detail, $pos,
                "the {$action} route must precede the (:any)/(:any) detail route");
        }
        $grid = strpos($routes, "\$route['admin/catalogue/(:any)']");
        $this->assertLessThan($grid, strpos($routes, "\$route['admin/catalogue/(:any)/create']"),
            'the create route must precede the single-segment grid route');

        $layout = file_get_contents(self::$root.'/application/views/layouts/app.php');
        $this->assertStringContainsString('admin/catalogue', $layout);

        // The SMM catalogue and the four-domain catalogue are distinct admin
        // surfaces. Now that the service editor exists, both links must remain.
        $this->assertStringContainsString("array('admin/services',", $layout);
        $this->assertFileExists(self::$root.'/application/controllers/admin/Services.php');
    }

    /** An icon the whitelist does not define renders an empty box. */
    public function testTheNavIconExists()
    {
        $icons = file_get_contents(self::$root.'/application/views/partials/icon.php');
        $this->assertStringContainsString("'package'", $icons);
    }

    /**
     * The layout only rendered success and error flashes, so a warning set by
     * a controller was written to the session and silently dropped.
     */
    public function testTheLayoutRendersTheWarningFlash()
    {
        $layout = file_get_contents(self::$root.'/application/views/layouts/app.php');
        $this->assertStringContainsString("flashdata('warning')", $layout);
        $this->assertStringContainsString('alert-warning', $layout);
    }

    public function testTheViewsCarryCsrfAndNeverRenderCredentials()
    {
        foreach (glob(self::$root.'/application/views/admin/catalogue/*.php') as $file) {
            $src = file_get_contents($file);
            if (strpos($src, 'method="post"') !== false) {
                $this->assertStringContainsString('get_csrf_token_name()', $src,
                    basename($file).' has a POST form without a CSRF token');
            }
            $this->assertStringNotContainsString('api_key', $src,
                basename($file).' must not render provider credentials');
        }
    }

    /**
     * The grid renders a row per product and must not query per row — a price
     * list is the one admin screen guaranteed to have hundreds of rows.
     */
    public function testTheGridDoesNotQueryPerRow()
    {
        $view = file_get_contents(self::$root.'/application/views/admin/catalogue/index.php');
        $this->assertStringNotContainsString('_model->', $view,
            'the view must render what the controller passed it');

        // The parent names come from a join, not a lookup per row.
        $model = file_get_contents(self::$root.'/application/models/Vtu_product_model.php');
        $this->assertStringContainsString('network_name', $model);
    }

    /**
     * The catalogue is read-and-write reference data, but never a money table:
     * a screen that could adjust a wallet from a price form would be a way to
     * mint balance.
     */
    public function testTheServiceOnlyTouchesCatalogueTables()
    {
        $src = file_get_contents(self::$root.'/application/libraries/CatalogueService.php');
        foreach (array('wallets', 'ledger', 'service_transactions') as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase($forbidden, $src,
                'CatalogueService must not touch '.$forbidden);
        }
    }

    private function seeder()
    {
        if (!class_exists('Core_seeder')) {
            require_once self::$root.'/application/libraries/Seeder.php';
            require_once self::$root.'/application/seeds/Core_seeder.php';
        }
        return 'Core_seeder';
    }
}
