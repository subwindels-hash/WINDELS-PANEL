<?php
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__).'/_support/FakeDb.php';
require_once dirname(__DIR__).'/_support/IntegrationHarness.php';

/**
 * Physical marketplace orders use the same wallet/escrow engine as digital
 * orders, but cannot enter it without an owned address, an active base-currency
 * method and a complete physical product row.
 */
class PhysicalShippingTest extends TestCase
{
    private static $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(dirname(__DIR__));
        if (!defined('BASEPATH')) define('BASEPATH', self::$root.'/system/');
        if (!class_exists('CI_Model')) eval('#[AllowDynamicProperties] class CI_Model { public $db; }');
        if (!function_exists('get_instance')) eval('function &get_instance(){ return $GLOBALS["__fake_ci"]; }');
        if (!function_exists('log_message')) eval('function log_message($l,$m){}');
        require_once self::$root.'/application/core/MY_Model.php';
        require_once self::$root.'/application/helpers/marvy_helper.php';
    }

    private function app($balance = '50000')
    {
        $app = new IntegrationHarness();
        $app->seed_minimal();
        $buyer = $app->register('shipbuyer', 'shipbuyer@x.test');
        $app->credit($buyer, $balance);
        $app->library(array('LedgerService', 'TransactionEngine', 'MarketplaceService',
                            'CartService', 'ShopCheckoutService', 'ShopShippingService'));
        $app->model(array('Service_transaction_model', 'Marketplace_order_model',
                          'Marketplace_listing_model', 'Physical_product_model',
                          'Shipping_address_model', 'Shipping_method_model',
                          'Shop_order_shipment_model', 'Wallet_model', 'Setting_model'));

        $now = gmdate('Y-m-d H:i:s');
        $app->db->insert('shipping_methods', array(
            'public_id' => 'SHP'.str_pad((string)random_int(1, 999999), 23, '0', STR_PAD_LEFT),
            'name' => 'Standard', 'carrier' => 'Acme', 'price' => '250.00000000',
            'currency' => 'NGN', 'estimated_days_min' => 2, 'estimated_days_max' => 5,
            'is_active' => 1, 'sorting' => 0, 'created_at' => $now, 'updated_at' => $now,
        ));
        $method = $app->db->where('id', $app->db->insert_id())->get('shipping_methods')->row();
        $app->db->insert('shipping_addresses', array(
            'public_id' => 'SAD'.str_pad((string)random_int(1, 999999), 23, '0', STR_PAD_LEFT),
            'user_id' => $buyer->id, 'full_name' => 'Ship Buyer', 'phone' => '08000000000',
            'line1' => '1 Test Street', 'line2' => null, 'city' => 'Abuja', 'state' => 'FCT',
            'postal_code' => '900001', 'country_code' => 'NG', 'is_default' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ));
        $address = $app->db->where('id', $app->db->insert_id())->get('shipping_addresses')->row();
        $app->__physical_checkout = array('address' => $address, 'method' => $method);
        return array($app, $buyer);
    }

    private function listing($app, $title = 'Physical mug', $price = '1000.00000000')
    {
        $now = gmdate('Y-m-d H:i:s');
        $app->db->insert('marketplace_listings', array(
            'public_id' => 'MPS'.str_pad((string)random_int(1, 999999), 23, '0', STR_PAD_LEFT),
            'category' => 'DIGITAL_GOODS', 'title' => $title,
            'description' => 'A physical product with complete package details.',
            'product_type' => 'PHYSICAL', 'price' => $price, 'currency' => 'NGN',
            'promo_price' => null, 'is_featured' => 0, 'image' => null, 'stock' => 5,
            'delivery_days' => 3, 'status' => 'ACTIVE', 'created_at' => $now, 'updated_at' => $now,
        ));
        $listing = $app->db->where('id', $app->db->insert_id())->get('marketplace_listings')->row();
        $app->db->insert('physical_products', array(
            'public_id' => 'PPS'.str_pad((string)random_int(1, 999999), 23, '0', STR_PAD_LEFT),
            'listing_id' => $listing->id, 'sku' => 'SKU-'.random_int(1, 999999),
            'weight_grams' => 350, 'length_cm' => '12.00', 'width_cm' => '10.00',
            'height_cm' => '10.00', 'requires_shipping' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ));
        return $listing;
    }

    private function purchase_input($app, $listing, $buyer, $extra = array())
    {
        return array_merge(array(
            'listing' => $listing->public_id, 'quantity' => 1,
            'shipping_address_id' => $app->__physical_checkout['address']->id,
            'shipping_method_id' => $app->__physical_checkout['method']->id,
        ), $extra);
    }

    private function order($app, $purchase)
    {
        return $app->db->where('public_id', $purchase['order']->public_id)
            ->get('marketplace_orders')->row();
    }

    public function testPhysicalPurchaseRequiresServerValidatedContext()
    {
        list($app, $buyer) = $this->app();
        $listing = $this->listing($app);
        $before = $app->balance($buyer);

        $missing = $app->marketplaceservice->purchase($buyer, array(
            'listing' => $listing->public_id, 'quantity' => 1,
        ));
        $this->assertFalse($missing['ok']);
        $this->assertSame('SHIPPING_REQUIRED', $missing['code']);
        $this->assertSame($before, $app->balance($buyer));

        $tampered = $app->marketplaceservice->purchase($buyer, $this->purchase_input(
            $app, $listing, $buyer, array('shipping_method_id' => 999999)
        ));
        $this->assertFalse($tampered['ok']);
        $this->assertSame('BAD_SHIPPING_METHOD', $tampered['code']);
        $this->assertSame($before, $app->balance($buyer));

        $posted_false = $app->marketplaceservice->purchase($buyer, $this->purchase_input(
            $app, $listing, $buyer, array('shipping_charge' => false)
        ));
        $this->assertTrue($posted_false['ok'], $posted_false['error'] ?? '');
        $this->assertSame('1250.00000000', (string)$posted_false['order']->gross_amount);
    }

    public function testPhysicalPurchaseChargesTheActiveMethodAndCreatesShipment()
    {
        list($app, $buyer) = $this->app();
        $listing = $this->listing($app);
        $before = $app->balance($buyer);
        $res = $app->marketplaceservice->purchase($buyer, $this->purchase_input($app, $listing, $buyer));

        $this->assertTrue($res['ok'], $res['error'] ?? '');
        $order = $this->order($app, $res);
        $this->assertSame('1250.00000000', (string)$order->gross_amount);
        $this->assertSame('250.00000000', (string)$order->shipping_cost);
        $this->assertSame('48750.00000000', $app->balance($buyer));
        $shipment = $app->db->where('marketplace_order_id', $order->id)
            ->get('shop_order_shipments')->row();
        $this->assertNotNull($shipment);
        $this->assertSame('PENDING', $shipment->status);
        $this->assertSame('250.00000000', (string)$shipment->shipping_cost);
        $this->assertSame($before, bcadd($app->balance($buyer), '1250.00000000', 8));
    }

    public function testCheckoutAllocatesShippingExactlyOnceAcrossPhysicalLines()
    {
        list($app, $buyer) = $this->app();
        $one = $this->listing($app, 'First physical item');
        $two = $this->listing($app, 'Second physical item');
        $this->assertTrue($app->cartservice->add($buyer->id, $one->public_id, 1)['ok']);
        $this->assertTrue($app->cartservice->add($buyer->id, $two->public_id, 1)['ok']);

        $quote = $app->shopcheckoutservice->quote($buyer->id, array(
            'shipping_method' => $app->__physical_checkout['method']->public_id,
        ));
        $this->assertTrue($quote['ok'], $quote['error'] ?? '');
        $this->assertSame('2250.00000000', $quote['view']['total']);
        $res = $app->shopcheckoutservice->checkout($buyer, array(
            'shipping_method' => $app->__physical_checkout['method']->public_id,
            'shipping_address_id' => $app->__physical_checkout['address']->public_id,
            'idempotency_key' => 'physical-checkout-1',
        ));
        $this->assertTrue($res['ok'], $res['error'] ?? '');
        $this->assertCount(2, $res['orders']);
        $this->assertSame('2250.00000000', bcsub('50000.00000000', $app->balance($buyer), 8));
        $fees = array();
        foreach ($res['orders'] as $placed) $fees[] = (string)$placed->shipping_cost;
        sort($fees);
        $this->assertSame(array('0.00000000', '250.00000000'), $fees);
    }

    public function testCheckoutCompensatesAnEarlierLineWhenALaterChargeFails()
    {
        list($app, $buyer) = $this->app('1500');
        $one = $this->listing($app, 'First physical item');
        $two = $this->listing($app, 'Second physical item');
        $app->cartservice->add($buyer->id, $one->public_id, 1);
        $app->cartservice->add($buyer->id, $two->public_id, 1);

        $res = $app->shopcheckoutservice->checkout($buyer, array(
            'shipping_method' => $app->__physical_checkout['method']->public_id,
            'shipping_address_id' => $app->__physical_checkout['address']->public_id,
            'idempotency_key' => 'physical-checkout-rollback',
        ));
        $this->assertFalse($res['ok']);
        $this->assertSame('1500.00000000', $app->balance($buyer));
        $this->assertSame(2, $app->cartservice->count_for($buyer->id), 'the cart remains retryable');
        $orders = $app->db->get('marketplace_orders')->result();
        $this->assertCount(1, $orders);
        $this->assertSame('REFUNDED', $orders[0]->status);

        // A retry with a fresh idempotency root can now buy both lines after
        // the customer adds funds, rather than treating the compensated prefix
        // as a successful checkout.
        $app->credit($buyer, '1000');
        $retry = $app->shopcheckoutservice->checkout($buyer, array(
            'shipping_method' => $app->__physical_checkout['method']->public_id,
            'shipping_address_id' => $app->__physical_checkout['address']->public_id,
            'idempotency_key' => 'physical-checkout-rollback-retry',
        ));
        $this->assertTrue($retry['ok'], $retry['error'] ?? '');
        $this->assertSame(0, $app->cartservice->count_for($buyer->id));
    }

    public function testShipmentLifecycleUpdatesEscrowAndFreezesReturnedParcels()
    {
        list($app, $buyer) = $this->app();
        $listing = $this->listing($app);
        $purchase = $app->marketplaceservice->purchase($buyer, $this->purchase_input($app, $listing, $buyer));
        $order = $this->order($app, $purchase);
        $shipment = $app->db->where('marketplace_order_id', $order->id)->get('shop_order_shipments')->row();

        $missing_tracking = $app->shopshippingservice->update($shipment->public_id, array('status' => 'SHIPPED'), 7);
        $this->assertFalse($missing_tracking['ok']);
        $this->assertSame('TRACKING_REQUIRED', $missing_tracking['code']);

        $shipped = $app->shopshippingservice->update($shipment->public_id, array(
            'status' => 'SHIPPED', 'carrier' => 'Acme', 'tracking_number' => 'ABC123',
            'tracking_url' => 'https://carrier.example/ABC123',
        ), 7);
        $this->assertTrue($shipped['ok'], $shipped['error'] ?? '');
        $delivered = $app->shopshippingservice->update($shipment->public_id, array(
            'status' => 'DELIVERED', 'carrier' => 'Acme', 'tracking_number' => 'ABC123',
        ), 7);
        $this->assertTrue($delivered['ok'], $delivered['error'] ?? '');
        $this->assertSame('DELIVERED', $app->db->where('id', $order->id)->get('marketplace_orders')->row()->status);

        $returned = $app->shopshippingservice->update($shipment->public_id, array('status' => 'RETURNED'), 7);
        $this->assertTrue($returned['ok'], $returned['error'] ?? '');
        $this->assertSame('DISPUTED', $app->db->where('id', $order->id)->get('marketplace_orders')->row()->status);
        $this->assertNull($app->db->where('id', $order->id)->get('marketplace_orders')->row()->release_due_at);
    }
}
