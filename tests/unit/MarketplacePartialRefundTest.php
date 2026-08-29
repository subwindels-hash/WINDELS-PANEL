<?php
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__).'/_support/FakeDb.php';
require_once dirname(__DIR__).'/_support/IntegrationHarness.php';

/**
 * Partial marketplace refunds (module 23).
 *
 * Escrow shipped all-or-nothing, and module 11 recorded that as a deliberate
 * default. It stops being right the moment a real dispute arrives: two dead
 * keys in a five-licence bundle, a delivery that arrived damaged but usable,
 * an agreed discount after a late shipment. Staff had two options, and both
 * were wrong:
 *
 *   - refund the whole order, giving away the three licences that worked;
 *   - pay the customer with a wallet adjustment, which settles the dispute and
 *     leaves the order claiming it was paid in full — so every revenue figure
 *     overstates by exactly the amount that was returned.
 *
 * These tests pin the money rules first (never refund more than is left, never
 * pay twice, always tell the reporting tables) and the product rules second
 * (a part refund is compensation, not a reversal: the buyer keeps their goods
 * and the shelf only gets back the units actually returned).
 */
class MarketplacePartialRefundTest extends TestCase
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
    }

    private function app($balance = '50000')
    {
        $app = new IntegrationHarness();
        $app->seed_minimal();
        $buyer = $app->register('pbuyer', 'pbuyer@x.test');
        $app->credit($buyer, $balance);
        $app->library(array('LedgerService', 'TransactionEngine', 'MarketplaceService',
                            'ShopDeliveryService', 'ShopShippingService'));
        $app->model(array('Service_transaction_model', 'Marketplace_order_model',
                          'Marketplace_listing_model', 'Digital_delivery_model',
                          'Digital_product_model', 'Physical_product_model',
                          'Shipping_address_model', 'Shipping_method_model',
                          'Shop_order_shipment_model', 'Wallet_model', 'Setting_model',
                          'Service_transaction_status_history_model'));
        $app->db->insert('shipping_methods', array(
            'public_id' => 'SHP'.str_pad((string)random_int(1, 999999), 23, '0', STR_PAD_LEFT),
            'name' => 'Standard', 'carrier' => 'Acme', 'price' => '250.00000000',
            'currency' => 'NGN', 'estimated_days_min' => 2, 'estimated_days_max' => 5,
            'is_active' => 1, 'sorting' => 0, 'created_at' => gmdate('Y-m-d H:i:s'),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ));
        $method = $app->db->where('id', $app->db->insert_id())->get('shipping_methods')->row();
        $app->db->insert('shipping_addresses', array(
            'public_id' => 'SAD'.str_pad((string)random_int(1, 999999), 23, '0', STR_PAD_LEFT),
            'user_id' => $buyer->id, 'full_name' => 'Buyer', 'phone' => '08000000000',
            'line1' => '1 Test Street', 'line2' => null, 'city' => 'Abuja', 'state' => 'FCT',
            'postal_code' => '900001', 'country_code' => 'NG', 'is_default' => 1,
            'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s'),
        ));
        $address = $app->db->where('id', $app->db->insert_id())->get('shipping_addresses')->row();
        $app->__physical_checkout = array('address' => $address, 'method' => $method);
        return array($app, $buyer);
    }

    private function listing($app, $digital = false, $stock = 5)
    {
        $now = gmdate('Y-m-d H:i:s');
        $app->db->insert('marketplace_listings', array(
            'public_id' => 'MLP'.str_pad((string)random_int(1, 999999), 23, '0', STR_PAD_LEFT),
            'category' => 'DIGITAL_GOODS',
            'title' => $digital ? 'A licence bundle' : 'A mug',
            'description' => 'Something to buy.',
            'product_type' => $digital ? 'DIGITAL' : 'PHYSICAL',
            'price' => '1000.00000000', 'currency' => 'NGN',
            'promo_price' => null, 'is_featured' => 0, 'image' => null,
            'stock' => $stock, 'delivery_days' => 1, 'status' => 'ACTIVE',
            'created_at' => $now, 'updated_at' => $now,
        ));
        $listing = $app->db->where('id', $app->db->insert_id())->get('marketplace_listings')->row();

        if ($digital) {
            $app->db->insert('digital_products', array(
                'public_id' => 'DGL'.str_pad((string)random_int(1, 999999), 23, '0', STR_PAD_LEFT),
                'listing_id' => $listing->id,
                'storage_key' => 'digital_products/bundle.pdf',
                'original_filename' => 'bundle.pdf',
                'mime_type' => 'application/pdf', 'size_bytes' => 2048,
                'download_limit' => 5, 'link_ttl_hours' => 24,
                'created_at' => $now, 'updated_at' => $now,
            ));
        } else {
            $app->db->insert('physical_products', array(
                'public_id' => 'PPR'.str_pad((string)random_int(1, 999999), 23, '0', STR_PAD_LEFT),
                'listing_id' => $listing->id, 'sku' => 'MUG-'.random_int(1, 999999),
                'weight_grams' => 350, 'length_cm' => '12.00', 'width_cm' => '10.00',
                'height_cm' => '10.00', 'requires_shipping' => 1,
                'created_at' => $now, 'updated_at' => $now,
            ));
        }
        return $listing;
    }

    private function buy($app, $buyer, $listing, $quantity = 1)
    {
        $input = array('listing' => $listing->public_id, 'quantity' => $quantity);
        if (strtoupper((string)$listing->product_type) === 'PHYSICAL') {
            $input['shipping_address_id'] = $app->__physical_checkout['address']->id;
            $input['shipping_method_id'] = $app->__physical_checkout['method']->id;
        }
        return $app->marketplaceservice->purchase($buyer, $input);
    }

    private function deliver_physical($app, $order)
    {
        $shipment = $app->db->where('marketplace_order_id', $order->id)
            ->get('shop_order_shipments')->row();
        $this->assertNotNull($shipment);
        $this->assertTrue($app->shopshippingservice->update($shipment->public_id, array(
            'status' => 'SHIPPED', 'carrier' => 'Acme', 'tracking_number' => 'ABC123',
        ), null)['ok']);
        return $app->shopshippingservice->update($shipment->public_id, array(
            'status' => 'DELIVERED', 'carrier' => 'Acme', 'tracking_number' => 'ABC123',
        ), null);
    }

    private function order_of($app, $public_id)
    {
        return $app->db->where('public_id', $public_id)->get('marketplace_orders')->row();
    }

    private function balance($app, $buyer)
    {
        return $app->db->where('user_id', $buyer->id)->get('wallets')->row()->balance;
    }

    private function stock($app, $listing)
    {
        return (int)$app->db->where('id', $listing->id)->get('marketplace_listings')->row()->stock;
    }

    /* ========================= the money it returns ====================== */

    /**
     * The case the whole module exists for: five licences, two dead. The buyer
     * gets two-fifths back and keeps the three that work.
     */
    public function testTwoOfFiveLicencesAreRefundedAndTheRestStand()
    {
        list($app, $buyer) = $this->app();
        $listing = $this->listing($app, false, 5);
        $bought  = $this->buy($app, $buyer, $listing, 5);
        $order   = $this->order_of($app, $bought['order']->public_id);
        $after_purchase = $this->balance($app, $buyer);

        $res = $app->marketplaceservice->refund_partial($order, '2000', null, 'Two keys were dead', 0);

        $this->assertTrue($res['ok'], $res['error'] ?? '');
        $this->assertSame('2000.00000000', $res['refunded']);
        $this->assertSame('3250.00000000', $res['remaining'], 'three licences and the quoted shipping fee are still paid for');

        $row = $this->order_of($app, $order->public_id);
        $this->assertSame('PARTIALLY_REFUNDED', $row->status,
            'leaving it DELIVERED would hide the refund on every list screen');
        $this->assertSame('2000.00000000', (string)$row->refunded_amount);
        $this->assertSame(0, (int)$row->refunded_quantity, 'no units were returned to the shelf');
        $this->assertSame('2000.00000000',
            bcsub($this->balance($app, $buyer), $after_purchase, 8),
            'the buyer is actually paid');
    }

    /**
     * Analytics read `service_transactions`, so a refund invisible there
     * leaves net revenue overstated for ever — which is exactly the failure
     * the wallet-adjustment workaround produced.
     */
    public function testTheRefundIsVisibleToTheRevenueTables()
    {
        list($app, $buyer) = $this->app();
        $listing = $this->listing($app, false, 5);
        $bought  = $this->buy($app, $buyer, $listing, 3);
        $order   = $this->order_of($app, $bought['order']->public_id);

        $app->marketplaceservice->refund_partial($order, '750', null, 'Arrived scratched', 0);

        $tx = $app->db->where('id', $order->service_transaction_id)->get('service_transactions')->row();
        $this->assertSame('750.00000000', (string)$tx->refunded_amount);
    }

    /* ============================ the ceilings =========================== */

    public function testItRefusesToRefundMoreThanIsLeft()
    {
        list($app, $buyer) = $this->app();
        $listing = $this->listing($app, false, 5);
        $order   = $this->order_of($app, $this->buy($app, $buyer, $listing, 2)['order']->public_id);
        $before  = $this->balance($app, $buyer);

        $res = $app->marketplaceservice->refund_partial($order, '5000', null, 'fat finger', 0);

        $this->assertFalse($res['ok']);
        $this->assertSame('OVER_REFUND', $res['code']);
        $this->assertSame($before, $this->balance($app, $buyer), 'nothing may move on a refusal');
        $this->assertStringContainsString('2250.00000000', $res['error'],
            'the operator is told what IS refundable rather than guessing again');
    }

    /** Two partials must not add up to more than the order. */
    public function testTheCeilingIsCumulative()
    {
        list($app, $buyer) = $this->app();
        $listing = $this->listing($app, false, 5);
        $order   = $this->order_of($app, $this->buy($app, $buyer, $listing, 3)['order']->public_id);

        $this->assertTrue($app->marketplaceservice->refund_partial(
            $order, '1000', null, 'first adjustment', 0)['ok']);

        $order = $this->order_of($app, $order->public_id);
        $res = $app->marketplaceservice->refund_partial($order, '2500', null, 'second adjustment', 0);

        $this->assertFalse($res['ok']);
        $this->assertSame('OVER_REFUND', $res['code']);
        $this->assertSame('1000.00000000', (string)$this->order_of($app, $order->public_id)->refunded_amount);
    }

    public function testZeroAndNegativeAmountsAreRefused()
    {
        list($app, $buyer) = $this->app();
        $listing = $this->listing($app, false, 5);
        $order   = $this->order_of($app, $this->buy($app, $buyer, $listing, 1)['order']->public_id);

        foreach (array('0', '-100', '') as $amount) {
            $res = $app->marketplaceservice->refund_partial($order, $amount, null, 'nope', 0);
            $this->assertFalse($res['ok'], 'refused: "'.$amount.'"');
            $this->assertSame('BAD_AMOUNT', $res['code']);
        }
    }

    /** Restocking more units than were bought would invent inventory. */
    public function testItRefusesToRestockMoreUnitsThanExist()
    {
        list($app, $buyer) = $this->app();
        $listing = $this->listing($app, false, 5);
        $order   = $this->order_of($app, $this->buy($app, $buyer, $listing, 2)['order']->public_id);
        $stock   = $this->stock($app, $listing);

        $res = $app->marketplaceservice->refund_partial($order, '500', null, 'one back', 9);

        $this->assertFalse($res['ok']);
        $this->assertSame('OVER_RESTOCK', $res['code']);
        $this->assertSame($stock, $this->stock($app, $listing));
    }

    /** Once the money is the platform's, this is not the tool. */
    public function testReleasedEscrowCannotBePartRefunded()
    {
        list($app, $buyer) = $this->app();
        $listing = $this->listing($app, false, 5);
        $order   = $this->order_of($app, $this->buy($app, $buyer, $listing, 1)['order']->public_id);
        $this->assertTrue($this->deliver_physical($app, $order)['ok']);
        $app->marketplaceservice->release($this->order_of($app, $order->public_id), 'ADMIN', null);

        $res = $app->marketplaceservice->refund_partial(
            $this->order_of($app, $order->public_id), '100', null, 'goodwill', 0);

        $this->assertFalse($res['ok']);
        $this->assertSame('ALREADY_RELEASED', $res['code']);
        $this->assertStringContainsString('wallet adjustment', $res['error'],
            'the operator is pointed at the tool that does have an audit trail for this');
    }

    /* ===================== goods, stock and revocation =================== */

    /**
     * A part refund is compensation, not a reversal. Revoking the download
     * would take back the thing the buyer still (mostly) paid for.
     */
    public function testAPartialRefundDoesNotRevokeTheDownload()
    {
        list($app, $buyer) = $this->app();
        $listing = $this->listing($app, true, 5);
        $order   = $this->order_of($app, $this->buy($app, $buyer, $listing, 1)['order']->public_id);

        $app->marketplaceservice->refund_partial($order, '300', null, 'One chapter missing', 0);

        $delivery = $app->db->where('marketplace_order_id', $order->id)
            ->get('digital_deliveries')->row();
        $this->assertNotNull($delivery);
        $this->assertSame(0, (int)$delivery->revoked, 'the buyer keeps what they part-paid for');
    }

    /** Refunding the last of the money is a full refund, with everything that means. */
    public function testRefundingTheRemainderClosesTheOrderProperly()
    {
        list($app, $buyer) = $this->app();
        $listing = $this->listing($app, true, 5);
        $order   = $this->order_of($app, $this->buy($app, $buyer, $listing, 1)['order']->public_id);

        $app->marketplaceservice->refund_partial($order, '400', null, 'partial first', 0);
        $order = $this->order_of($app, $order->public_id);
        $res = $app->marketplaceservice->refund_partial($order, '600', null, 'and the rest', 1);

        $this->assertTrue($res['ok'], $res['error'] ?? '');
        $row = $this->order_of($app, $order->public_id);
        $this->assertSame('REFUNDED', $row->status);
        $this->assertSame('1000.00000000', (string)$row->refunded_amount,
            'the two refunds total the order, not twice the order');

        $delivery = $app->db->where('marketplace_order_id', $order->id)
            ->get('digital_deliveries')->row();
        $this->assertSame(1, (int)$delivery->revoked,
            'a fully refunded buyer does not keep the file');
    }

    /**
     * The wallet must not be paid twice for the same total. A full refund
     * after a partial one returns only what is left.
     */
    public function testTheBuyerIsNeverPaidMoreThanTheyPaid()
    {
        list($app, $buyer) = $this->app();
        $listing = $this->listing($app, false, 5);
        $before  = $this->balance($app, $buyer);
        $order   = $this->order_of($app, $this->buy($app, $buyer, $listing, 4)['order']->public_id);

        $app->marketplaceservice->refund_partial($order, '1500', null, 'damaged', 0);
        $app->marketplaceservice->refund($this->order_of($app, $order->public_id), null, 'the rest');

        $this->assertSame($before, $this->balance($app, $buyer),
            'the buyer ends up exactly where they started, not better off');
        $this->assertSame('4250.00000000',
            (string)$this->order_of($app, $order->public_id)->refunded_amount);
    }

    /** Units already returned are not returned again by the closing refund. */
    public function testStockIsRestoredOnceAndOnlyOnce()
    {
        list($app, $buyer) = $this->app();
        $listing = $this->listing($app, false, 5);
        $order   = $this->order_of($app, $this->buy($app, $buyer, $listing, 4)['order']->public_id);
        $this->assertSame(1, $this->stock($app, $listing), 'four of five went out');

        $app->marketplaceservice->refund_partial($order, '1000', null, 'one came back', 1);
        $this->assertSame(2, $this->stock($app, $listing));

        $app->marketplaceservice->refund($this->order_of($app, $order->public_id), null, 'all back');
        $this->assertSame(5, $this->stock($app, $listing),
            'the closing refund returns the three still out, not all four again');
    }

    /* ============================== the trail ============================ */

    public function testEveryPartialRefundIsAuditedAndOnTheOrderTimeline()
    {
        list($app, $buyer) = $this->app();
        $listing = $this->listing($app, false, 5);
        $order   = $this->order_of($app, $this->buy($app, $buyer, $listing, 2)['order']->public_id);

        $app->marketplaceservice->refund_partial($order, '500', null, 'Chipped handle', 0);

        $actions = array();
        foreach ($app->db->get('audit_logs')->result() as $row) $actions[] = $row->action;
        $this->assertContains('marketplace.order.partial_refund', $actions);

        $events = $app->db->where('order_id', $order->id)->get('marketplace_order_events')->result();
        $notes = '';
        foreach ($events as $e) $notes .= (string)$e->note;
        $this->assertStringContainsString('Chipped handle', $notes,
            'the buyer-visible timeline has to say why they were part-refunded');
    }

    public function testRefundableReportsWhatIsLeft()
    {
        list($app, $buyer) = $this->app();
        $listing = $this->listing($app, false, 5);
        $order   = $this->order_of($app, $this->buy($app, $buyer, $listing, 3)['order']->public_id);

        $this->assertSame('3250.00000000', $app->marketplaceservice->refundable($order));
        $app->marketplaceservice->refund_partial($order, '1200', null, 'adjustment', 0);
        $this->assertSame('2050.00000000',
            $app->marketplaceservice->refundable($this->order_of($app, $order->public_id)));
    }

    /* ============================= the wiring ============================ */

    public function testTheAdminScreenOffersItWithAnAmountAndAConsequence()
    {
        $view = file_get_contents(self::$root.'/application/views/admin/marketplace/order.php');
        $this->assertStringContainsString('PARTIAL_REFUND', $view);
        $this->assertStringContainsString('name="amount"', $view);
        $this->assertStringContainsString('name="restock"', $view);
        $this->assertStringContainsString('Refundable now', $view,
            'staff must see the ceiling before they type an amount');
        $this->assertStringContainsString('keep what they part-paid for', $view);

        $ctrl = file_get_contents(self::$root.'/application/controllers/admin/Marketplace.php');
        $this->assertStringContainsString('refund_partial', $ctrl);
        $this->assertStringContainsString("require_perm('marketplace.resolve')", $ctrl,
            'returning money needs the same grant as resolving a dispute');
    }

    public function testTheMigrationRecordsWhatWasReturned()
    {
        $src = file_get_contents(self::$root.'/application/migrations/032_marketplace_partial_refunds.php');
        $this->assertStringContainsString('ADD COLUMN refunded_amount', $src);
        $this->assertStringContainsString('ADD COLUMN refunded_quantity', $src);
        $this->assertStringContainsString("WHERE status = 'REFUNDED'", $src,
            'orders refunded before this migration must not read as never refunded');

        $config = file_get_contents(self::$root.'/application/config/migration.php');
        preg_match("/migration_version'\]\s*=\s*(\d+)/", $config, $m);
        $this->assertGreaterThanOrEqual(32, (int)($m[1] ?? 0));
    }
}
