<?php
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__).'/_support/FakeDb.php';
require_once dirname(__DIR__).'/_support/IntegrationHarness.php';

/**
 * Marketplace vertical-slice integration and wiring gates.
 *
 * Every flow here runs the platform-single-seller shape: no vendor entity, no
 * payout rail, gross == platform revenue, escrow closes by completion or
 * refund — never by paying anyone out.
 */
class MarketplaceTest extends TestCase
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

    /** Active finite-stock platform listing and a funded buyer. */
    private function app($stock = 5)
    {
        $app = new IntegrationHarness();
        $app->seed_minimal();
        $buyer = $app->register('market_buyer', 'market-buyer@x.test');
        $admin = $app->register('market_admin', 'market-admin@x.test', 'Str0ng!pass1', 'ADMIN');
        $app->credit($buyer, '10000.00000000');
        $now = gmdate('Y-m-d H:i:s');
        $app->db->insert('marketplace_listings', array(
            'public_id' => 'MPL00000000000000000000001',
            'title' => 'Premium digital resource',
            'category' => 'DIGITAL_GOODS',
            'description' => 'A complete digital resource with secure fulfilment instructions.',
            'price' => '1000.00000000',
            'stock' => $stock,
            'delivery_days' => 1,
            'status' => 'ACTIVE',
            'approved_at' => $now,
            'approved_by' => $admin->id,
            'created_at' => $now, 'updated_at' => $now,
        ));
        $app->library('MarketplaceService');
        $app->model(array('Marketplace_order_model', 'Marketplace_listing_model',
                          'Service_transaction_model',
                          'Audit_log_model', 'Wallet_model'));
        return array($app, $buyer, $admin);
    }

    private function purchase($app, $buyer, array $overrides = array())
    {
        return $app->marketplaceservice->purchase($buyer, array_merge(array(
            'listing' => 'MPL00000000000000000000001',
            'quantity' => 2,
            'idempotency_key' => 'marketplace-test-purchase',
            'source' => 'WEB',
        ), $overrides));
    }

    /** Operator fulfils on the platform's behalf (admin console flag). */
    private function deliver($app, $admin, $public_id, $payload)
    {
        return $app->marketplaceservice->deliver($admin, $public_id, $payload, true);
    }

    public function testPurchaseChargesOnceAndOpensEscrow()
    {
        list($app, $buyer) = $this->app();
        $res = $this->purchase($app, $buyer);

        $this->assertTrue($res['ok'], $res['error'] ?? '');
        $this->assertSame('PROCESSING', $res['transaction']->status);
        $this->assertSame('PAID', $res['order']->status);
        $this->assertSame('2000.00000000', $res['order']->gross_amount);
        // The platform keeps the whole charge: no vendor split columns exist.
        foreach (array('seller_id', 'seller_amount', 'fee_amount',
                       'payout_wallet_transaction_id') as $gone) {
            $this->assertFalse(property_exists($res['order'], $gone),
                'orders must not carry vendor field '.$gone);
        }
        $this->assertSame('8000.00000000', $app->balance($buyer));
        $listing = $app->Marketplace_listing_model->find_id($res['order']->listing_id);
        $this->assertSame(3, (int)$listing->stock);
        $this->assertCount(1, $app->rows('marketplace_orders'));
        $this->assertCount(1, $app->rows('marketplace_order_events'));
        // No supplier cost is frozen onto the sale's ledger record.
        $tx = $app->Service_transaction_model->find_by_id($res['order']->service_transaction_id);
        $this->assertTrue($tx->provider_cost === null || $tx->provider_cost === '0.00000000');
        list($debits, $credits) = $app->ledger_is_balanced();
        $this->assertSame($debits, $credits);
    }

    public function testPurchaseIdempotencyDoesNotDoubleChargeOrConsumeStock()
    {
        list($app, $buyer) = $this->app();
        $first = $this->purchase($app, $buyer);
        $second = $this->purchase($app, $buyer);

        $this->assertTrue($first['ok']);
        $this->assertTrue($second['ok']);
        $this->assertTrue($second['duplicate']);
        $this->assertSame($first['order']->id, $second['order']->id);
        $this->assertSame('8000.00000000', $app->balance($buyer));
        $this->assertSame(3, (int)$app->Marketplace_listing_model
            ->find_id($first['order']->listing_id)->stock);
        $this->assertCount(1, $app->rows('marketplace_orders'));
    }

    public function testEncryptedDeliveryAcceptAndIdempotentCompletion()
    {
        list($app, $buyer, $admin) = $this->app();
        $bought = $this->purchase($app, $buyer, array('quantity' => 1));
        $secret = 'License: LIC-SECRET-123 and private download instructions';

        $delivered = $this->deliver($app, $admin, $bought['order']->public_id, $secret);
        $this->assertTrue($delivered['ok'], $delivered['error'] ?? '');
        $stored = $app->Marketplace_order_model->find_id($bought['order']->id);
        $this->assertSame('DELIVERED', $stored->status);
        $this->assertNotSame($secret, $stored->delivery_encrypted);
        $this->assertStringNotContainsString('LIC-SECRET', $stored->delivery_encrypted);

        $revealed = $app->marketplaceservice->reveal($buyer, $stored->public_id, false);
        $this->assertTrue($revealed['ok']);
        $this->assertSame($secret, $revealed['delivery']);
        $this->assertNotEmpty(array_filter($app->rows('audit_logs'), function ($row) {
            return $row['action'] === 'marketplace.delivery.reveal';
        }));

        $accepted = $app->marketplaceservice->accept($buyer, $stored->public_id);
        $this->assertTrue($accepted['ok'], $accepted['error'] ?? '');
        $this->assertSame('COMPLETED', $accepted['order']->status);
        // Completing the sale moves NO money: the charge settled at purchase.
        $this->assertSame('9000.00000000', $app->balance($buyer));
        $tx = $app->Service_transaction_model->find_by_id($stored->service_transaction_id);
        $this->assertSame('SUCCESSFUL', $tx->status);
        $this->assertEmpty(array_filter($app->rows('wallet_transactions'), function ($row) {
            return strpos($row['type'], 'MARKETPLACE_PAYOUT') !== false;
        }), 'a marketplace completion must never create a payout transaction');

        $again = $app->marketplaceservice->accept($buyer, $stored->public_id);
        $this->assertTrue($again['ok']);
        $this->assertTrue($again['duplicate']);
        list($debits, $credits) = $app->ledger_is_balanced();
        $this->assertSame($debits, $credits);
    }

    public function testDisputeStopsAutoReleaseAndAdminRefundsExactlyOnce()
    {
        list($app, $buyer, $admin) = $this->app();
        $bought = $this->purchase($app, $buyer, array('quantity' => 1));
        $this->deliver($app, $admin, $bought['order']->public_id, 'Delivered but disputed payload');
        $order = $app->Marketplace_order_model->find_id($bought['order']->id);
        $app->Marketplace_order_model->update_fields($order->id, array(
            'release_due_at' => gmdate('Y-m-d H:i:s', time() - 60),
        ));

        $dispute = $app->marketplaceservice->dispute(
            $buyer, $order->public_id, 'The delivered resource does not match the listing.'
        );
        $this->assertTrue($dispute['ok'], $dispute['error'] ?? '');
        $this->assertNull($dispute['order']->release_due_at);

        $app->library('CronWorkers');
        $sweep = $app->cronworkers->marketplace_release(100);
        $this->assertSame(0, $sweep['processed']);

        $refund = $app->marketplaceservice->refund(
            $app->Marketplace_order_model->find_id($order->id), $admin->id,
            'Buyer evidence confirmed that the fulfilment was not as described.'
        );
        $this->assertTrue($refund['ok'], $refund['error'] ?? '');
        $this->assertSame('REFUNDED', $refund['order']->status);
        $this->assertSame('10000.00000000', $app->balance($buyer));
        $this->assertSame(5, (int)$app->Marketplace_listing_model
            ->find_id($order->listing_id)->stock);

        $again = $app->marketplaceservice->refund($refund['order'], $admin->id, 'retry');
        $this->assertFalse($again['ok']);
        $this->assertSame('10000.00000000', $app->balance($buyer));
        list($debits, $credits) = $app->ledger_is_balanced();
        $this->assertSame($debits, $credits);
    }

    public function testFailedBuyerRefundRollsBackEscrowAndCanBeRetried()
    {
        list($app, $buyer, $admin) = $this->app();
        $bought = $this->purchase($app, $buyer, array('quantity' => 1));
        $this->deliver($app, $admin, $bought['order']->public_id, 'Valid delivery payload');
        $order = $app->Marketplace_order_model->find_id($bought['order']->id);
        $real_ledger = $app->ledgerservice;
        $app->ledgerservice = new class {
            public function refund() { return array('ok' => false, 'error' => 'simulated outage'); }
        };

        $failed = $app->marketplaceservice->refund($order, $admin->id, 'Confirmed issue');
        $this->assertFalse($failed['ok']);
        $this->assertSame('REFUND_FAILED', $failed['code']);
        $this->assertSame('DELIVERED', $app->Marketplace_order_model->find_id($order->id)->status);
        $this->assertSame('PROCESSING', $app->Service_transaction_model
            ->find_by_id($order->service_transaction_id)->status);
        $this->assertSame('9000.00000000', $app->balance($buyer));
        $this->assertSame(4, (int)$app->Marketplace_listing_model->find_id($order->listing_id)->stock);

        $app->ledgerservice = $real_ledger;
        $retried = $app->marketplaceservice->refund(
            $app->Marketplace_order_model->find_id($order->id), $admin->id, 'Confirmed issue'
        );
        $this->assertTrue($retried['ok'], $retried['error'] ?? '');
        $this->assertSame('REFUNDED', $retried['order']->status);
        $this->assertSame('10000.00000000', $app->balance($buyer));
        $this->assertSame(5, (int)$app->Marketplace_listing_model->find_id($order->listing_id)->stock);
    }

    public function testStaleReleaseCannotCompleteAfterRefundWinsResolution()
    {
        list($app, $buyer, $admin) = $this->app();
        $bought = $this->purchase($app, $buyer, array('quantity' => 1));
        $this->deliver($app, $admin, $bought['order']->public_id, 'Valid delivery payload');
        $refund_copy = $app->Marketplace_order_model->find_id($bought['order']->id);
        $release_copy = clone $refund_copy;

        $refunded = $app->marketplaceservice->refund($refund_copy, $admin->id, 'Buyer claim upheld');
        $late_release = $app->marketplaceservice->release($release_copy, 'ADMIN', $admin->id);
        $this->assertTrue($refunded['ok']);
        $this->assertFalse($late_release['ok']);
        $this->assertSame('CONFLICT', $late_release['code']);
        $this->assertSame('REFUNDED', $app->Marketplace_order_model->find_id($refund_copy->id)->status);
        $this->assertSame('10000.00000000', $app->balance($buyer));
        list($debits, $credits) = $app->ledger_is_balanced();
        $this->assertSame($debits, $credits);
    }

    public function testCronReleasesOnlyDueDeliveredOrdersAndCanBeRetried()
    {
        list($app, $buyer, $admin) = $this->app();
        $bought = $this->purchase($app, $buyer, array('quantity' => 1));
        $this->deliver($app, $admin, $bought['order']->public_id, 'Valid digital delivery');
        $app->Marketplace_order_model->update_fields($bought['order']->id, array(
            'release_due_at' => gmdate('Y-m-d H:i:s', time() - 60),
        ));
        $app->library('CronWorkers');

        $first = $app->cronworkers->marketplace_release(100);
        $second = $app->cronworkers->marketplace_release(100);
        $this->assertSame(1, $first['processed']);
        $this->assertSame(0, $first['failed']);
        $this->assertSame(0, $second['processed']);
        $done = $app->Marketplace_order_model->find_id($bought['order']->id);
        $this->assertSame('COMPLETED', $done->status);
        // The cron path also moves no money — completion is a status, not a credit.
        $this->assertSame('9000.00000000', $app->balance($buyer));
        $this->assertSame('SUCCESSFUL', $app->Service_transaction_model
            ->find_by_id($done->service_transaction_id)->status);
    }

    public function testUnrelatedUsersCannotDeliverOrRevealFulfilment()
    {
        list($app, $buyer, $admin) = $this->app();
        $stranger = $app->register('stranger', 'stranger@x.test');
        $bought = $this->purchase($app, $buyer, array('quantity' => 1));
        $this->assertFalse($app->marketplaceservice
            ->deliver($stranger, $bought['order']->public_id, 'stolen', false)['ok']);
        // Even the buyer cannot deliver: fulfilment is platform-side only.
        $this->assertFalse($app->marketplaceservice
            ->deliver($buyer, $bought['order']->public_id, 'mine', false)['ok']);
        $this->deliver($app, $admin, $bought['order']->public_id, 'private payload');
        $this->assertFalse($app->marketplaceservice
            ->reveal($stranger, $bought['order']->public_id, false)['ok']);
    }

    public function testFailedCheckoutClosesPendingEscrowOrder()
    {
        list($app, $buyer) = $this->app();
        $app->ledgerservice = new class {
            public function charge() { return array('ok' => false, 'error' => 'simulated charge failure'); }
        };

        $failed = $this->purchase($app, $buyer);
        $this->assertFalse($failed['ok']);
        $this->assertSame('CANCELLED', $failed['order']->status);
        $this->assertSame('10000.00000000', $app->balance($buyer));
        $this->assertSame(5, (int)$app->Marketplace_listing_model
            ->find_id($failed['order']->listing_id)->stock);
        $events = $app->Marketplace_order_model->events($failed['order']->id);
        $this->assertSame('CANCELLED', end($events)->event_type);
    }

    public function testThereIsNoVendorFeatureAnywhere()
    {
        list($app) = $this->app();
        $service = $app->marketplaceservice;

        // Service-level: the methods that minted or moderated vendors are gone.
        foreach (array('apply_seller', 'moderate_seller') as $method) {
            $this->assertFalse(method_exists($service, $method),
                'MarketplaceService must not expose vendor method '.$method);
        }
        $service_src = file_get_contents(self::$root.'/application/libraries/MarketplaceService.php');
        foreach (array('apply_seller', 'moderate_seller', 'seller_id', 'seller_amount',
                       'fee_percent', 'MARKETPLACE_PAYOUT', 'payout_wallet_transaction_id',
                       'Marketplace_seller_model') as $needle) {
            $this->assertStringNotContainsString($needle, $service_src,
                'vendor remnant in MarketplaceService: '.$needle);
        }

        // The seller model file does not exist.
        $this->assertFileDoesNotExist(self::$root.'/application/models/Marketplace_seller_model.php');

        // No route can reach a vendor concept; the removed admin sellers
        // moderation route must answer 404 (no wildcard catches it).
        $routes = file_get_contents(self::$root.'/application/config/routes.php');
        foreach (array('marketplace/sellers', 'marketplace/apply', 'dashboard/marketplace/listings/new',
                       'apply_seller', 'moderate_seller') as $needle) {
            $this->assertStringNotContainsString($needle, $routes,
                'vendor route must not exist: '.$needle);
        }

        // The admin console has no vendor surface and cannot mint or moderate one.
        $admin = file_get_contents(self::$root.'/application/controllers/admin/Marketplace.php');
        foreach (array('apply_seller', 'moderate_seller', 'ensure_platform_seller',
                       'Marketplace_seller_model', 'tab=sellers') as $needle) {
            $this->assertStringNotContainsString($needle, $admin,
                'admin vendor remnant: '.$needle);
        }

        // Views carry no vendor columns/tabs on either side of the console.
        foreach (glob(self::$root.'/application/views/admin/marketplace/*.php') as $view) {
            $src = file_get_contents($view);
            foreach (array('seller_username', 'seller_name', 'tab=sellers', "tab === 'sellers'",
                           'seller_id', 'fee_amount', 'seller_amount') as $needle) {
                $this->assertStringNotContainsString($needle, $src, basename($view).' vendor remnant: '.$needle);
            }
        }
        foreach (glob(self::$root.'/application/views/dashboard/marketplace/*.php') as $view) {
            $src = file_get_contents($view);
            foreach (array('seller_username', 'seller_name', 'seller_user_id',
                           'counterparty_name') as $needle) {
                $this->assertStringNotContainsString($needle, $src, basename($view).' vendor remnant: '.$needle);
            }
        }

        // RBAC surface: the vendor-moderation permission is not seeded or granted.
        $seed = file_get_contents(self::$root.'/application/seeds/Core_seeder.php');
        $this->assertStringNotContainsString('marketplace.moderate_sellers', $seed);
        $this->assertStringNotContainsString('marketplace_fee_percent', $seed);
        $settings = file_get_contents(self::$root.'/application/libraries/SettingsService.php');
        $this->assertStringNotContainsString('marketplace_fee_percent', $settings);
        $this->assertStringContainsString('marketplace_auto_release_hours', $settings);
    }

    public function testStaffListingsPublishImmediatelyWithManagedCategoriesAndPromos()
    {
        list($app) = $this->app();
        $staff = $app->register('catalogue_admin', 'catalogue-admin@x.test', 'Str0ng!pass1', 'ADMIN');

        // A staff save goes straight to the shelf, stamped with the operator.
        $res = $app->marketplaceservice->save_listing($staff, array(
            'title' => 'Netflix Premium 12 months',
            'category' => 'DIGITAL_GOODS',
            'description' => 'Official 12-month premium subscription, delivered securely.',
            'price' => '5000',
            'promo_price' => '4000',
            'stock' => '10',
            'delivery_days' => 1,
            'product_type' => 'DIGITAL',
            'is_featured' => true,
        ));
        $this->assertTrue($res['ok'], $res['error'] ?? '');
        $this->assertSame('ACTIVE', $res['listing']->status);
        $this->assertSame((int)$staff->id, (int)$res['listing']->approved_by);
        $this->assertSame('4000.00000000', $res['listing']->promo_price);
        $this->assertSame(1, (int)$res['listing']->is_featured);
        // Platform-owned: no vendor reference is possible on a listing.
        $this->assertFalse(property_exists($res['listing'], 'seller_id'));

        // The sell path is gate-kept by the controller permission, never by
        // data: pin that save_listing sits behind require_perm('marketplace.manage').
        $controller = file_get_contents(self::$root.'/application/controllers/admin/Marketplace.php');
        $save_body = substr($controller, strpos($controller, 'public function save_listing('));
        $this->assertLessThan(strpos($save_body, 'save_listing($this->current_user'),
            strpos($save_body, "require_perm('marketplace.manage')"),
            'save_listing must be permission-gated before it touches the service');

        $base = array(
            'title' => 'Another premium product',
            'category' => 'DIGITAL_GOODS',
            'description' => 'A long enough description to pass the validation floor.',
            'price' => '1000',
        );
        // Categories are managed rows: inventing one fails.
        $bad_category = $app->marketplaceservice->save_listing($staff, array_merge($base, array(
            'category' => 'NOT_A_CATEGORY',
        )));
        $this->assertFalse($bad_category['ok']);
        $this->assertSame('BAD_CATEGORY', $bad_category['code']);

        // A promo must genuinely undercut the list price.
        $promo_too_high = $app->marketplaceservice->save_listing($staff, array_merge($base, array(
            'promo_price' => '1000',
        )));
        $this->assertFalse($promo_too_high['ok']);
        $this->assertSame('BAD_PROMO', $promo_too_high['code']);
        $promo_free = $app->marketplaceservice->save_listing($staff, array_merge($base, array(
            'promo_price' => '0',
        )));
        $this->assertFalse($promo_free['ok']);
        $this->assertSame('BAD_PROMO', $promo_free['code']);
    }

    public function testPurchaseChargesThePromoPriceOnlyWhenItUndercuts()
    {
        list($app, $buyer) = $this->app();
        // Live promotion: buyers are charged the promo price, server-side.
        $listing = $app->Marketplace_listing_model->find_public('MPL00000000000000000000001');
        $app->Marketplace_listing_model->update_fields($listing->id, array('promo_price' => '750.00000000'));
        $res = $this->purchase($app, $buyer);
        $this->assertTrue($res['ok'], $res['error'] ?? '');
        $this->assertSame('750.00000000', $res['order']->unit_price);
        $this->assertSame('1500.00000000', $res['order']->gross_amount);
        $this->assertSame('8500.00000000', $app->balance($buyer));

        // A promo that is NOT below list price can never raise the charge.
        $app->Marketplace_listing_model->update_fields($listing->id, array('promo_price' => '1200.00000000'));
        $res2 = $this->purchase($app, $buyer, array('idempotency_key' => 'promo-above-list'));
        $this->assertTrue($res2['ok'], $res2['error'] ?? '');
        $this->assertSame('1000.00000000', $res2['order']->unit_price);
        $this->assertSame('2000.00000000', $res2['order']->gross_amount);
        $this->assertSame('6500.00000000', $app->balance($buyer));
    }

    public function testAdminFulfilsOnThePlatformsBehalfButPrivilegeIsNeverInferred()
    {
        list($app, $buyer, $admin) = $this->app();
        $bought = $this->purchase($app, $buyer, array('quantity' => 1));

        // The operator flag is explicit and never inferred: without it, even
        // an admin hits the guard — there is no vendor to be.
        $this->assertFalse($app->marketplaceservice
            ->deliver($admin, $bought['order']->public_id, 'admin payload', false)['ok']);

        $res = $this->deliver($app, $admin, $bought['order']->public_id, 'Operator-delivered secret payload');
        $this->assertTrue($res['ok'], $res['error'] ?? '');
        $this->assertSame('DELIVERED', $res['order']->status);

        // The flag is the privilege, so the ONLY caller that may pass true is
        // the permission-gated admin controller. Pin that down at the source:
        // every as_admin=true call site sits behind require_perm.
        $admin_controller = file_get_contents(self::$root.'/application/controllers/admin/Marketplace.php');
        $this->assertStringContainsString("require_perm('marketplace.manage');", $admin_controller);
        $deliver_body = substr($admin_controller, strpos($admin_controller, 'public function deliver('));
        $this->assertLessThan(strpos($deliver_body, ', true)'),
            strpos($deliver_body, "require_perm"),
            'the as_admin flag must be raised only after require_perm()');
        $this->assertStringContainsString('public function deliver(', $admin_controller);
        $dashboard = file_get_contents(self::$root.'/application/controllers/dashboard/Marketplace.php');
        $this->assertStringNotContainsString('deliver(', $dashboard,
            'the customer dashboard must not fulfil anything at all');
    }

    public function testOrderQueuesExcludeEncryptedFulfilmentProjection()
    {
        list($app, $buyer, $admin) = $this->app();
        $bought = $this->purchase($app, $buyer, array('quantity' => 1));
        $this->deliver($app, $admin, $bought['order']->public_id, 'Sensitive delivery payload');
        $orders = $app->Marketplace_order_model->for_user($buyer->id, 25, 0);
        $admin_orders = $app->Marketplace_order_model->admin_search(array(), 25, 0);
        $app->Marketplace_order_model->update_fields($bought['order']->id, array(
            'release_due_at' => '2000-01-01 00:00:00',
        ));
        $worker_orders = $app->Marketplace_order_model->due_for_release(25);

        $this->assertCount(1, $orders);
        $this->assertCount(1, $admin_orders);
        $this->assertCount(1, $worker_orders);
        $this->assertFalse(property_exists($orders[0], 'delivery_encrypted'));
        $this->assertFalse(property_exists($admin_orders[0], 'delivery_encrypted'));
        $this->assertFalse(property_exists($worker_orders[0], 'delivery_encrypted'));
        $source = file_get_contents(self::$root.'/application/models/Marketplace_order_model.php');
        $this->assertStringContainsString('private function list_projection()', $source);
        $this->assertStringContainsString('deliberately excludes delivery_encrypted', $source);
    }

    public function testModerationControlsListingAvailability()
    {
        list($app, $buyer, $admin) = $this->app();
        $listing = $app->Marketplace_listing_model->find_public('MPL00000000000000000000001');
        $res = $app->marketplaceservice->moderate_listing(
            'MPL00000000000000000000001', 'PAUSED', $admin->id, 'Manual compliance hold'
        );
        $this->assertTrue($res['ok']);
        $this->assertSame('PAUSED', $app->Marketplace_listing_model->find_id($listing->id)->status);
        $this->assertFalse($this->purchase($app, $buyer, array('idempotency_key'=>'after-pause'))['ok']);
    }

    /* ========================== schema / wiring ========================== */

    public function testMigrationHasNoVendorShape()
    {
        if (!class_exists('CI_Migration')) eval('class CI_Migration { public $db; }');
        require_once self::$root.'/application/migrations/015_marketplace.php';
        $this->assertSame(array(
            'marketplace_listings',
            'marketplace_orders', 'marketplace_order_events'
        ), Migration_Marketplace::tables());
        $sql = implode("\n", Migration_Marketplace::statements());
        foreach (array('delivery_encrypted MEDIUMTEXT', 'service_transaction_id BIGINT UNSIGNED NOT NULL UNIQUE',
                       'release_due_at') as $needle) {
            $this->assertStringContainsString($needle, $sql);
        }
        foreach (array('marketplace_sellers', 'seller_id', 'seller_amount', 'fee_amount',
                       'payout_wallet_transaction_id', 'identity_check_id', 'delivery TEXT') as $needle) {
            $this->assertStringNotContainsString($needle, $sql,
                'vendor remnant in migration 015: '.$needle);
        }
    }

    public function testMigration019RetiresVendorDataOnUpgrades()
    {
        if (!class_exists('CI_Migration')) eval('class CI_Migration { public $db; }');
        require_once self::$root.'/application/migrations/019_remove_marketplace_vendors.php';
        // Contract: it creates nothing and drops exactly the vendor table.
        $this->assertSame(array(), Migration_Remove_marketplace_vendors::statements());
        $this->assertSame(array(), Migration_Remove_marketplace_vendors::tables());
        $this->assertSame(array('marketplace_sellers'),
            Migration_Remove_marketplace_vendors::dropped_tables());

        $src = file_get_contents(self::$root.'/application/migrations/019_remove_marketplace_vendors.php');
        // FK-then-column order, resolved through information_schema so a
        // renamed constraint on a live install still upgrades.
        $this->assertStringContainsString('information_schema.KEY_COLUMN_USAGE', $src);
        $this->assertStringContainsString('DROP FOREIGN KEY', $src);
        foreach (array("'seller_id'", "'fee_amount'", "'seller_amount'",
                       "'payout_wallet_transaction_id'", 'DROP TABLE IF EXISTS marketplace_sellers',
                       "'marketplace.moderate_sellers'", "'marketplace_fee_percent'") as $needle) {
            $this->assertStringContainsString($needle, $src);
        }
        // The upgrade is column/table/permission/settings-only: order and
        // listing HISTORY is preserved by design.
        $this->assertStringNotContainsString('DROP TABLE IF EXISTS marketplace_orders', $src);
        $this->assertStringNotContainsString('DELETE FROM marketplace_orders', $src);
    }

    public function testMigration019RehearsesAgainstLegacyAndFreshShapes()
    {
        if (!class_exists('CI_Migration')) eval('class CI_Migration { public $db; }');
        require_once self::$root.'/application/migrations/019_remove_marketplace_vendors.php';

        // Legacy shape: every vendor column/index/table exists.
        $legacy = new Marketplace019FakeDb(true);
        $mig = new Migration_Remove_marketplace_vendors();
        $mig->db = $legacy;
        $mig->up();
        $raw = implode("\n", $legacy->raw);

        // FK detachment is looked up, not assumed by name.
        $this->assertStringContainsString('information_schema.KEY_COLUMN_USAGE', implode("\n", $legacy->meta_reads));
        // FK drops precede their column drops; the table drop comes last.
        $fk_pos = strpos($raw, 'DROP FOREIGN KEY');
        $col_pos = strpos($raw, 'DROP COLUMN');
        $table_pos = strpos($raw, 'DROP TABLE IF EXISTS marketplace_sellers');
        $this->assertNotFalse($fk_pos); $this->assertNotFalse($col_pos); $this->assertNotFalse($table_pos);
        $this->assertLessThan($col_pos, $fk_pos, 'FKs must be detached before columns drop');
        $this->assertLessThan($table_pos, $col_pos, 'columns retire before the vendor table drops');
        foreach (array('seller_id', 'fee_amount', 'seller_amount', 'payout_wallet_transaction_id') as $col) {
            $this->assertStringContainsString('DROP COLUMN `'.$col.'`', $raw);
        }
        $this->assertStringContainsString('DROP INDEX `idx_mporder_seller`', $raw);
        $this->assertStringContainsString('DROP INDEX `idx_mplisting_seller`', $raw);
        $this->assertStringContainsString("'marketplace.moderate_sellers'", $raw);
        $this->assertStringContainsString("'marketplace_fee_percent'", $raw);
        // History tables are untouched by the retirement.
        $this->assertStringNotContainsString('marketplace_orders` DROP TABLE', $raw);
        $this->assertStringNotContainsString('DELETE FROM marketplace_orders', $raw);
        $this->assertStringNotContainsString('DELETE FROM marketplace_listings', $raw);

        // Fresh shape: nothing exists — runtime DDL is skipped, cleanup is a no-op match.
        $fresh = new Marketplace019FakeDb(false);
        $mig2 = new Migration_Remove_marketplace_vendors();
        $mig2->db = $fresh;
        $mig2->up();
        $fresh_raw = implode("\n", $fresh->raw);
        $this->assertStringNotContainsString('DROP COLUMN', $fresh_raw);
        $this->assertStringNotContainsString('DROP FOREIGN KEY', $fresh_raw);
        // DROP TABLE IF EXISTS is unconditional and safe on a fresh install.
        $this->assertStringContainsString('DROP TABLE IF EXISTS marketplace_sellers', $fresh_raw);
    }

    public function testSecuritySensitiveMutationsArePermissionedPostOnlyAndAudited()
    {
        $admin = file_get_contents(self::$root.'/application/controllers/admin/Marketplace.php');
        foreach (array("require_perm('marketplace.view')", "require_perm('marketplace.manage')",
                       "require_perm('marketplace.resolve')", "require_perm('marketplace.reveal')",
                       "require_perm('marketplace.moderate_listings')") as $gate) {
            $this->assertStringContainsString($gate, $admin);
        }
        $this->assertStringContainsString('$this->post_only();', $admin);
        $service = file_get_contents(self::$root.'/application/libraries/MarketplaceService.php');
        $this->assertStringContainsString('encryptionservice->open(', $service);
        $this->assertStringContainsString("'marketplace.delivery.reveal'", $service);
        $this->assertStringContainsString('transactionengine->transition(', $service);
        $this->assertStringNotContainsString('ledgerservice->credit(', $service,
            'escrow release must never credit anyone — gross is platform revenue at purchase');
        $this->assertStringNotContainsString('->decrypt(', $service,
            'corrupt ciphertext must never fall back to being rendered');
    }

    public function testRoutesNavigationPermissionsAndCronAreRegistered()
    {
        $routes = file_get_contents(self::$root.'/application/config/routes.php');
        foreach (array('dashboard/marketplace', 'dashboard/marketplace/orders/(:any)/dispute',
                       'admin/marketplace/orders/(:any)/resolve') as $route) {
            $this->assertStringContainsString($route, $routes);
        }
        $layout = file_get_contents(self::$root.'/application/views/layouts/app.php');
        $this->assertStringContainsString("'dashboard/marketplace'", $layout);
        $this->assertStringContainsString("'admin/marketplace'", $layout);

        $seed = file_get_contents(self::$root.'/application/seeds/Core_seeder.php');
        foreach (array('marketplace.view', 'marketplace.resolve', 'marketplace.reveal',
                       'marketplace.manage', 'seed_marketplace_categories',
                       'marketplace_auto_release_hours') as $needle) {
            $this->assertStringContainsString($needle, $seed);
        }
        // Customers cannot opt out of identity checks to become vendors —
        // they cannot become vendors at all, so both settings are gone.
        $this->assertStringNotContainsString('marketplace_require_verified_identity', $seed);
        $this->assertStringNotContainsString('marketplace.moderate_sellers', $seed);
        $cron = file_get_contents(self::$root.'/application/controllers/Cron.php');
        $workers = file_get_contents(self::$root.'/application/libraries/CronWorkers.php');
        $crontab = file_get_contents(self::$root.'/cron/crontab.example');
        $this->assertStringContainsString('public function marketplace_release()', $cron);
        $this->assertStringContainsString('public function marketplace_release($limit = 100)', $workers);
        $this->assertStringContainsString('cron marketplace_release', $crontab);
    }

    public function testMigrationAndGeneratedSchemaAreCurrent()
    {
        if (!class_exists('CI_Migration')) eval('class CI_Migration { public $db; }');
        require_once self::$root.'/application/migrations/015_marketplace.php';
        $config = file_get_contents(self::$root.'/application/config/migration.php');
        $this->assertStringContainsString("\$config['migration_version'] = 19;", $config);
        $schema = file_get_contents(self::$root.'/docs/database.sql');
        $this->assertStringContainsString('-- migration 015_marketplace', $schema);
        foreach (Migration_Marketplace::tables() as $table) {
            $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS '.$table.' (', $schema);
        }
        // The retired vendor table must NOT be in the canonical schema, and
        // the retirement migration must be registered.
        $this->assertStringNotContainsString('CREATE TABLE IF NOT EXISTS marketplace_sellers', $schema);
        $this->assertStringContainsString('-- migration 019_remove_marketplace_vendors', $schema);
    }
}

/* -------------------------------- doubles ------------------------------- */

/**
 * Minimal CI db-driver stand-in for migration rehearsals. information_schema
 * probes are answered from the $legacy flag; everything the migration mutates
 * is captured in order for assertions.
 */
class Marketplace019FakeDb {
    public $raw = array();
    public $meta_reads = array();
    private $legacy;
    public function __construct($legacy) { $this->legacy = $legacy; }
    public function query($sql, $binds = array()) {
        if (strpos($sql, 'information_schema') !== false) {
            $this->meta_reads[] = $sql;
            $n = $this->legacy ? 1 : 0;
            $is_fk = strpos($sql, 'KEY_COLUMN_USAGE') !== false
                  && strpos($sql, 'REFERENCED_TABLE_NAME') !== false;
            if ($is_fk) {
                $rows = $this->legacy
                    ? array((object)array('name' => 'fk_old_vendor'))
                    : array();
                return new Marketplace019FakeResult(null, $rows);
            }
            return new Marketplace019FakeResult(array('n' => $n), null);
        }
        $this->raw[] = $sql;
        return true;
    }
}
class Marketplace019FakeResult {
    private $row; private $rows;
    public function __construct($row, $rows) {
        $this->row = $row ? (object)$row : null;
        $this->rows = $rows === null ? array() : $rows;
    }
    public function row() { return $this->row; }
    public function result() { return $this->rows; }
}
