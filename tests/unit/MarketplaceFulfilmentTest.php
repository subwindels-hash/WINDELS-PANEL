<?php
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__).'/_support/FakeDb.php';
require_once dirname(__DIR__).'/_support/IntegrationHarness.php';

/**
 * Marketplace fulfilment: escrow, refunds and what the buyer keeps.
 *
 * Two defects at the seams between modules, which is where this build keeps
 * putting them:
 *
 *  1. **The stuck-purchase sweep did not know what escrow is.** A marketplace
 *     purchase sits in PROCESSING for the whole inspection window — 72 hours
 *     by default, up to 30 days — because that is what escrow *is*. The
 *     recovery sweep added for the vendor-abandonment case treats any
 *     in-flight purchase older than 24 hours as abandoned, so it would have
 *     refunded buyers of goods that had already shipped, left the order at
 *     DELIVERED, left the stock decremented, and then broken the release
 *     worker when it tried to settle a transaction that was already terminal.
 *
 *  2. **A refunded digital order kept its download.** `ShopDeliveryService`
 *     has had `revoke()` since it shipped, wired to an admin button and to
 *     nothing else. Refunding a digital purchase — a dispute resolved in the
 *     buyer's favour, say — returned the money and left the file in the
 *     buyer's "My Downloads" for ever. They kept the product and the payment.
 */
class MarketplaceFulfilmentTest extends TestCase
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
        $buyer = $app->register('buyer', 'buyer@x.test');
        $app->credit($buyer, $balance);
        $app->library(array('LedgerService', 'TransactionEngine', 'MarketplaceService',
                            'ShopDeliveryService', 'CronWorkers'));
        $app->model(array('Service_transaction_model', 'Marketplace_order_model',
                          'Marketplace_listing_model', 'Digital_delivery_model',
                          'Digital_product_model', 'Wallet_model', 'Setting_model',
                          'Service_transaction_status_history_model'));
        return array($app, $buyer);
    }

    /** A listing the platform sells, optionally with a file behind it. */
    private function listing($app, $digital = false, $stock = 5)
    {
        $now = gmdate('Y-m-d H:i:s');
        $app->db->insert('marketplace_listings', array(
            'public_id' => 'MLS'.str_pad((string)random_int(1, 999999), 23, '0', STR_PAD_LEFT),
            'category' => 'DIGITAL_GOODS',
            'title' => $digital ? 'An ebook' : 'A mug',
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
                'public_id' => 'DGP'.str_pad((string)random_int(1, 999999), 23, '0', STR_PAD_LEFT),
                'listing_id' => $listing->id,
                'storage_key' => 'storage/digital/book.pdf',
                'original_filename' => 'book.pdf',
                'mime_type' => 'application/pdf', 'size_bytes' => 2048,
                'download_limit' => 5, 'link_ttl_hours' => 24,
                'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s'),
            ));
        }
        return $listing;
    }

    private function buy($app, $buyer, $listing, $quantity = 1)
    {
        return $app->marketplaceservice->purchase($buyer, array(
            'listing' => $listing->public_id, 'quantity' => $quantity,
        ));
    }

    private function order_of($app, $public_id)
    {
        return $app->db->where('public_id', $public_id)->get('marketplace_orders')->row();
    }

    private function age_transaction($app, $order, $expression)
    {
        $app->db->where('id', $order->service_transaction_id)->update('service_transactions',
            array('created_at' => gmdate('Y-m-d H:i:s', strtotime($expression))));
    }

    /* =============== escrow is not the same thing as "stuck" ============= */

    public function testEscrowIsNotSweptAwayWhileTheInspectionWindowIsOpen()
    {
        list($app, $buyer) = $this->app();
        $listing = $this->listing($app);
        $bought = $this->buy($app, $buyer, $listing);
        $this->assertTrue($bought['ok'], $bought['error'] ?? '');
        $order = $this->order_of($app, $bought['order']->public_id);
        $balance = $app->balance($buyer);

        // Two days in: past the generic 24-hour backstop, inside the 72-hour
        // escrow window the buyer agreed to.
        $this->age_transaction($app, $order, '-48 hours');
        $summary = $app->cronworkers->service_recovery();

        $fresh = $this->order_of($app, $order->public_id);
        $this->assertSame('PAID', $fresh->status,
            'refunding an order still in escrow would pay back a buyer whose goods are on their way');
        $this->assertSame($balance, $app->balance($buyer), 'and no money may move');
        $this->assertSame(1, $summary['skipped']);
    }

    /**
     * Once the release worker has plainly stopped running, the buyer must not
     * be left funding an order nobody will ever settle.
     */
    public function testAnEscrowNobodyEverReleasedIsEventuallyRefunded()
    {
        list($app, $buyer) = $this->app();
        $listing = $this->listing($app, false, 5);
        $bought = $this->buy($app, $buyer, $listing);
        $order = $this->order_of($app, $bought['order']->public_id);
        $before = $app->balance($buyer);

        $this->age_transaction($app, $order, '-30 days');
        $app->cronworkers->service_recovery();

        $fresh = $this->order_of($app, $order->public_id);
        $this->assertSame('REFUNDED', $fresh->status);
        $this->assertSame(bcadd($before, (string)$order->gross_amount, 8), $app->balance($buyer));
    }

    /** And the escrow row, the stock and the ledger must move together. */
    public function testAnAbandonedEscrowPutsTheStockBack()
    {
        list($app, $buyer) = $this->app();
        $listing = $this->listing($app, false, 5);
        $this->buy($app, $buyer, $listing);
        $after_purchase = (int)$app->db->where('id', $listing->id)
            ->get('marketplace_listings')->row()->stock;
        $this->assertSame(4, $after_purchase, 'the purchase took a unit');

        $order = $app->db->where('listing_id', $listing->id)->get('marketplace_orders')->row();
        $this->age_transaction($app, $order, '-30 days');
        $app->cronworkers->service_recovery();

        $this->assertSame(5, (int)$app->db->where('id', $listing->id)
            ->get('marketplace_listings')->row()->stock,
            'a refunded order that kept its stock reserved sells one fewer of everything, for ever');
    }

    /* ================= a refund takes the goods back too ================= */

    public function testRefundingADigitalOrderRevokesTheDownload()
    {
        list($app, $buyer) = $this->app();
        $listing = $this->listing($app, true);
        $bought = $this->buy($app, $buyer, $listing);
        $this->assertTrue($bought['ok'], $bought['error'] ?? '');
        $order = $this->order_of($app, $bought['order']->public_id);

        $delivery = $app->db->where('marketplace_order_id', $order->id)
            ->get('digital_deliveries')->row();
        $this->assertNotNull($delivery, 'buying a digital listing grants the download');
        $this->assertSame(0, (int)$delivery->revoked);

        $app->marketplaceservice->refund($order, null, 'Dispute resolved for the buyer');

        $after = $app->db->where('id', $delivery->id)->get('digital_deliveries')->row();
        $this->assertSame(1, (int)$after->revoked,
            'the buyer kept both the file and the money before this');
        $this->assertNotEmpty($after->revoked_reason);
    }

    public function testARefundOnAPhysicalOrderHasNoDownloadToRevokeAndStillSucceeds()
    {
        list($app, $buyer) = $this->app();
        $listing = $this->listing($app, false);
        $bought = $this->buy($app, $buyer, $listing);
        $order = $this->order_of($app, $bought['order']->public_id);
        $before = $app->balance($buyer);

        $res = $app->marketplaceservice->refund($order, null, 'Never arrived');

        $this->assertTrue($res['ok'], $res['error'] ?? '');
        $this->assertSame(bcadd($before, (string)$order->gross_amount, 8), $app->balance($buyer));
    }

    /** A released order is a completed sale; refunding it is a different act. */
    public function testAReleasedOrderIsNotRefundedByTheSweep()
    {
        list($app, $buyer) = $this->app();
        $listing = $this->listing($app);
        $bought = $this->buy($app, $buyer, $listing);
        $order = $this->order_of($app, $bought['order']->public_id);
        $app->marketplaceservice->deliver(null, $order->public_id, 'Tracking: ABC123', true);
        $released = $app->marketplaceservice->release(
            $this->order_of($app, $order->public_id), 'ADMIN', null);
        $this->assertTrue($released['ok'], $released['error'] ?? '');
        $balance = $app->balance($buyer);

        $this->age_transaction($app, $order, '-60 days');
        $app->cronworkers->service_recovery();

        $this->assertSame($balance, $app->balance($buyer),
            'a completed sale is not an abandoned one, however old');
        $this->assertSame('COMPLETED', $this->order_of($app, $order->public_id)->status);
    }

    /* ========================= the wiring is real ======================== */

    public function testTheSweepDelegatesMarketplaceToTheServiceThatOwnsEscrow()
    {
        $src = file_get_contents(self::$root.'/application/libraries/CronWorkers.php');
        $this->assertStringContainsString('marketplaceservice->refund', $src,
            'a bare ledger reversal leaves the order and the stock behind');
        $this->assertStringContainsString('marketplace_auto_release_hours', $src,
            'the escrow window has to be read, not assumed');

        $marketplace = file_get_contents(self::$root.'/application/libraries/MarketplaceService.php');
        $this->assertStringContainsString('revoke_for_order', $marketplace,
            'a refund must take the goods back as well as return the money');
    }
}
