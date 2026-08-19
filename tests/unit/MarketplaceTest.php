<?php
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__).'/_support/FakeDb.php';
require_once dirname(__DIR__).'/_support/IntegrationHarness.php';

/** Marketplace vertical-slice integration and wiring gates. */
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
            eval('function get_instance(){ return $GLOBALS["__fake_ci"]; }');
        }
        if (!function_exists('log_message')) eval('function log_message($l,$m){}');
        require_once self::$root.'/application/core/MY_Model.php';
        require_once self::$root.'/application/helpers/windels_helper.php';
    }

    /** Approved seller, active finite-stock listing, and a funded buyer. */
    private function app($stock = 5)
    {
        $app = new IntegrationHarness();
        $app->seed_minimal();
        $buyer = $app->register('market_buyer', 'market-buyer@x.test');
        $seller = $app->register('market_seller', 'market-seller@x.test');
        $admin = $app->register('market_admin', 'market-admin@x.test', 'Str0ng!pass1', 'ADMIN');
        $app->credit($buyer, '10000.00000000');
        $now = gmdate('Y-m-d H:i:s');
        $app->db->insert('marketplace_sellers', array(
            'public_id' => 'MPS00000000000000000000001',
            'user_id' => $seller->id,
            'display_name' => 'Verified Seller',
            'status' => 'APPROVED',
            'approved_at' => $now,
            'approved_by' => $admin->id,
            'created_at' => $now, 'updated_at' => $now,
        ));
        $seller_profile_id = $app->db->insert_id();
        $app->db->insert('marketplace_listings', array(
            'public_id' => 'MPL00000000000000000000001',
            'seller_id' => $seller_profile_id,
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
                          'Marketplace_seller_model', 'Service_transaction_model',
                          'Audit_log_model', 'Wallet_model'));
        return array($app, $buyer, $seller, $admin);
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

    public function testPurchaseChargesOnceFreezesFeeAndOpensEscrow()
    {
        list($app, $buyer) = $this->app();
        $res = $this->purchase($app, $buyer);

        $this->assertTrue($res['ok'], $res['error'] ?? '');
        $this->assertSame('PROCESSING', $res['transaction']->status);
        $this->assertSame('PAID', $res['order']->status);
        $this->assertSame('2000.00000000', $res['order']->gross_amount);
        $this->assertSame('200.00000000', $res['order']->fee_amount);
        $this->assertSame('1800.00000000', $res['order']->seller_amount);
        $this->assertSame('8000.00000000', $app->balance($buyer));
        $listing = $app->Marketplace_listing_model->find_id($res['order']->listing_id);
        $this->assertSame(3, (int)$listing->stock);
        $this->assertCount(1, $app->rows('marketplace_orders'));
        $this->assertCount(1, $app->rows('marketplace_order_events'));
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

    public function testEncryptedDeliveryAcceptAndIdempotentSellerPayout()
    {
        list($app, $buyer, $seller) = $this->app();
        $bought = $this->purchase($app, $buyer, array('quantity' => 1));
        $secret = 'License: LIC-SECRET-123 and private download instructions';

        $delivered = $app->marketplaceservice->deliver($seller, $bought['order']->public_id, $secret);
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
        $this->assertSame('900.00000000', $app->balance($seller));
        $tx = $app->Service_transaction_model->find_by_id($stored->service_transaction_id);
        $this->assertSame('SUCCESSFUL', $tx->status);

        $again = $app->marketplaceservice->accept($buyer, $stored->public_id);
        $this->assertTrue($again['ok']);
        $this->assertTrue($again['duplicate']);
        $this->assertSame('900.00000000', $app->balance($seller));
        list($debits, $credits) = $app->ledger_is_balanced();
        $this->assertSame($debits, $credits);
    }

    public function testDisputeStopsAutoReleaseAndAdminRefundsExactlyOnce()
    {
        list($app, $buyer, $seller, $admin) = $this->app();
        $bought = $this->purchase($app, $buyer, array('quantity' => 1));
        $app->marketplaceservice->deliver($seller, $bought['order']->public_id, 'Delivered but disputed payload');
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
        $this->assertSame('0.00000000', $app->balance($seller));

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
    }

    public function testFailedSellerPayoutRollsBackEscrowAndCanBeRetried()
    {
        list($app, $buyer, $seller) = $this->app();
        $bought = $this->purchase($app, $buyer, array('quantity' => 1));
        $app->marketplaceservice->deliver($seller, $bought['order']->public_id, 'Valid delivery payload');
        $order = $app->Marketplace_order_model->find_id($bought['order']->id);
        $real_ledger = $app->ledgerservice;
        $app->ledgerservice = new class {
            public function credit() { return array('ok' => false, 'error' => 'simulated outage'); }
        };

        $failed = $app->marketplaceservice->release($order, 'BUYER', $buyer->id);
        $this->assertFalse($failed['ok']);
        $this->assertSame('PAYOUT_FAILED', $failed['code']);
        $this->assertSame('DELIVERED', $app->Marketplace_order_model->find_id($order->id)->status);
        $this->assertSame('PROCESSING', $app->Service_transaction_model
            ->find_by_id($order->service_transaction_id)->status);
        $this->assertSame('0.00000000', $app->balance($seller));

        $app->ledgerservice = $real_ledger;
        $retried = $app->marketplaceservice->release(
            $app->Marketplace_order_model->find_id($order->id), 'BUYER', $buyer->id
        );
        $this->assertTrue($retried['ok'], $retried['error'] ?? '');
        $this->assertSame('COMPLETED', $retried['order']->status);
        $this->assertSame('900.00000000', $app->balance($seller));
        $payouts = array_values(array_filter($app->rows('wallet_transactions'), function ($row) {
            return $row['type'] === 'MARKETPLACE_PAYOUT';
        }));
        $this->assertCount(1, $payouts);
        $this->assertSame('MARKETPLACE_ORDER', $payouts[0]['reference_type']);
        $this->assertSame((string)$order->id, (string)$payouts[0]['reference_id']);
        $this->assertSame('marketplace:'.$order->id.':payout', $payouts[0]['idempotency_key']);
    }

    public function testFailedBuyerRefundRollsBackEscrowAndCanBeRetried()
    {
        list($app, $buyer, $seller, $admin) = $this->app();
        $bought = $this->purchase($app, $buyer, array('quantity' => 1));
        $app->marketplaceservice->deliver($seller, $bought['order']->public_id, 'Valid delivery payload');
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

    public function testStaleReleaseCannotPayAfterRefundWinsResolution()
    {
        list($app, $buyer, $seller, $admin) = $this->app();
        $bought = $this->purchase($app, $buyer, array('quantity' => 1));
        $app->marketplaceservice->deliver($seller, $bought['order']->public_id, 'Valid delivery payload');
        $refund_copy = $app->Marketplace_order_model->find_id($bought['order']->id);
        $release_copy = clone $refund_copy;

        $refunded = $app->marketplaceservice->refund($refund_copy, $admin->id, 'Buyer claim upheld');
        $late_release = $app->marketplaceservice->release($release_copy, 'ADMIN', $admin->id);
        $this->assertTrue($refunded['ok']);
        $this->assertFalse($late_release['ok']);
        $this->assertSame('CONFLICT', $late_release['code']);
        $this->assertSame('REFUNDED', $app->Marketplace_order_model->find_id($refund_copy->id)->status);
        $this->assertSame('10000.00000000', $app->balance($buyer));
        $this->assertSame('0.00000000', $app->balance($seller));
        $this->assertEmpty(array_filter($app->rows('wallet_transactions'), function ($row) {
            return $row['type'] === 'MARKETPLACE_PAYOUT';
        }));
    }

    public function testCronReleasesOnlyDueDeliveredOrdersAndCanBeRetried()
    {
        list($app, $buyer, $seller) = $this->app();
        $bought = $this->purchase($app, $buyer, array('quantity' => 1));
        $app->marketplaceservice->deliver($seller, $bought['order']->public_id, 'Valid digital delivery');
        $app->Marketplace_order_model->update_fields($bought['order']->id, array(
            'release_due_at' => gmdate('Y-m-d H:i:s', time() - 60),
        ));
        $app->library('CronWorkers');

        $first = $app->cronworkers->marketplace_release(100);
        $second = $app->cronworkers->marketplace_release(100);
        $this->assertSame(1, $first['processed']);
        $this->assertSame(0, $first['failed']);
        $this->assertSame(0, $second['processed']);
        $this->assertSame('900.00000000', $app->balance($seller));
    }

    public function testUnrelatedUsersCannotDeliverOrRevealFulfilment()
    {
        list($app, $buyer, $seller) = $this->app();
        $stranger = $app->register('stranger', 'stranger@x.test');
        $bought = $this->purchase($app, $buyer, array('quantity' => 1));
        $this->assertFalse($app->marketplaceservice
            ->deliver($stranger, $bought['order']->public_id, 'stolen')['ok']);
        $app->marketplaceservice->deliver($seller, $bought['order']->public_id, 'private payload');
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

    public function testOnlyStaffCanHoldThePlatformSellerProfile()
    {
        list($app, $buyer) = $this->app();
        // A customer calling the service directly — bypassing every route —
        // still cannot mint a seller profile.
        $customer = $app->marketplaceservice->apply_seller($buyer, array(
            'display_name' => 'Independent Store',
            'bio' => 'A customer attempt to become a seller.',
        ));
        $this->assertFalse($customer['ok']);
        $this->assertSame('CUSTOMERS_CANNOT_SELL', $customer['code']);
        // Only the fixture seller exists; nothing was created.
        $this->assertCount(1, $app->rows('marketplace_sellers'));

        // Staff are auto-approved as the platform's own storefront, stamped
        // with their own id and no identity requirement.
        $staff = $app->register('storefront_ops', 'storefront-ops@x.test', 'Str0ng!pass1', 'ADMIN');
        $res = $app->marketplaceservice->apply_seller($staff, array(
            'display_name' => 'WINDELS Store',
            'bio' => 'Official platform storefront.',
        ));
        $this->assertTrue($res['ok'], $res['error'] ?? '');
        $this->assertSame('APPROVED', $res['seller']->status);
        $this->assertSame((int)$staff->id, (int)$res['seller']->approved_by);
        $this->assertNull($res['seller']->identity_check_id);

        // Source-level: the customer dashboard exposes no seller surface at
        // all — no application, no listing editor, no fulfilment endpoint.
        $controller = file_get_contents(self::$root.'/application/controllers/dashboard/Marketplace.php');
        foreach (array('apply', 'save_listing', 'listing_status', 'function deliver', 'seller(') as $needle) {
            $this->assertStringNotContainsString($needle, $controller,
                'customer dashboard controller must not expose seller surface: '.$needle);
        }
        $routes = file_get_contents(self::$root.'/application/config/routes.php');
        foreach (array('dashboard/marketplace/apply', 'dashboard/marketplace/seller',
                       'dashboard/marketplace/listings', 'dashboard/marketplace/save_listing',
                       'dashboard/marketplace/deliver') as $needle) {
            $this->assertStringNotContainsString($needle, $routes,
                'customer seller route must not exist: '.$needle);
        }
        $this->assertFileDoesNotExist(self::$root.'/application/views/dashboard/marketplace/seller.php');
    }

    public function testStaffListingsPublishImmediatelyWithManagedCategoriesAndPromos()
    {
        list($app) = $this->app();
        $staff = $app->register('catalogue_admin', 'catalogue-admin@x.test', 'Str0ng!pass1', 'ADMIN');
        $apply = $app->marketplaceservice->apply_seller($staff, array(
            'display_name' => 'WINDELS Store',
            'bio' => 'Official platform storefront.',
        ));
        $this->assertTrue($apply['ok'], $apply['error'] ?? '');

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

        $base = array(
            'title' => 'Another premium product',
            'category' => 'DIGITAL_GOODS',
            'description' => 'A long enough description to pass the validation floor.',
            'price' => '1000',
        );
        // Customers cannot create listings even through the service directly.
        $buyer = $app->register('listing_intruder', 'listing-intruder@x.test');
        $this->assertFalse($app->marketplaceservice->save_listing($buyer, $base)['ok']);

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
        list($app, $buyer, $seller, $admin) = $this->app();
        $bought = $this->purchase($app, $buyer, array('quantity' => 1));

        // The operator flag is explicit and never inferred: without it, even
        // an admin hits the own-order guard.
        $this->assertFalse($app->marketplaceservice
            ->deliver($admin, $bought['order']->public_id, 'admin payload', false)['ok']);

        $res = $app->marketplaceservice->deliver(
            $admin, $bought['order']->public_id, 'Operator-delivered secret payload', true
        );
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
        list($app, $buyer, $seller) = $this->app();
        $bought = $this->purchase($app, $buyer, array('quantity' => 1));
        $app->marketplaceservice->deliver($seller, $bought['order']->public_id, 'Sensitive delivery payload');
        $orders = $app->Marketplace_order_model->for_user($buyer->id, 'BUYER', 25, 0);
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

    public function testModerationControlsSellerAndListingAvailability()
    {
        list($app, $buyer, $seller, $admin) = $this->app();
        $listing = $app->Marketplace_listing_model->find_public('MPL00000000000000000000001');
        $res = $app->marketplaceservice->moderate_seller(
            'MPS00000000000000000000001', 'SUSPENDED', $admin->id, 'Manual compliance hold'
        );
        $this->assertTrue($res['ok']);
        $this->assertSame('PAUSED', $app->Marketplace_listing_model->find_id($listing->id)->status);
        $this->assertFalse($this->purchase($app, $buyer, array('idempotency_key'=>'after-suspend'))['ok']);
    }

    /* ========================== schema / wiring ========================== */

    public function testMigrationProtectsEscrowAndFulfilmentShape()
    {
        if (!class_exists('CI_Migration')) eval('class CI_Migration {}');
        require_once self::$root.'/application/migrations/015_marketplace.php';
        $this->assertSame(array(
            'marketplace_sellers', 'marketplace_listings',
            'marketplace_orders', 'marketplace_order_events'
        ), Migration_Marketplace::tables());
        $sql = implode("\n", Migration_Marketplace::statements());
        foreach (array('delivery_encrypted MEDIUMTEXT', 'service_transaction_id BIGINT UNSIGNED NOT NULL UNIQUE',
                       'payout_wallet_transaction_id', 'release_due_at', 'identity_check_id') as $needle) {
            $this->assertStringContainsString($needle, $sql);
        }
        $this->assertStringNotContainsString('delivery TEXT', $sql);
    }

    public function testSecuritySensitiveMutationsArePermissionedPostOnlyAndAudited()
    {
        $admin = file_get_contents(self::$root.'/application/controllers/admin/Marketplace.php');
        foreach (array("require_perm('marketplace.view')", "require_perm('marketplace.manage')",
                       "require_perm('marketplace.resolve')", "require_perm('marketplace.reveal')",
                       "require_perm('marketplace.moderate_sellers')",
                       "require_perm('marketplace.moderate_listings')") as $gate) {
            $this->assertStringContainsString($gate, $admin);
        }
        $this->assertStringContainsString('$this->post_only();', $admin);
        $service = file_get_contents(self::$root.'/application/libraries/MarketplaceService.php');
        $this->assertStringContainsString('encryptionservice->open(', $service);
        $this->assertStringContainsString("'marketplace.delivery.reveal'", $service);
        $this->assertStringContainsString('transactionengine->transition(', $service);
        $this->assertStringContainsString('ledgerservice->credit(', $service);
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
                       'marketplace_fee_percent', 'marketplace_auto_release_hours') as $needle) {
            $this->assertStringContainsString($needle, $seed);
        }
        // Customers cannot opt out of identity checks to become sellers —
        // they cannot become sellers at all, so the setting is gone.
        $this->assertStringNotContainsString('marketplace_require_verified_identity', $seed);
        $cron = file_get_contents(self::$root.'/application/controllers/Cron.php');
        $workers = file_get_contents(self::$root.'/application/libraries/CronWorkers.php');
        $crontab = file_get_contents(self::$root.'/cron/crontab.example');
        $this->assertStringContainsString('public function marketplace_release()', $cron);
        $this->assertStringContainsString('public function marketplace_release($limit = 100)', $workers);
        $this->assertStringContainsString('cron marketplace_release', $crontab);
    }

    public function testMigrationAndGeneratedSchemaAreCurrent()
    {
        if (!class_exists('CI_Migration')) eval('class CI_Migration {}');
        require_once self::$root.'/application/migrations/015_marketplace.php';
        $config = file_get_contents(self::$root.'/application/config/migration.php');
        $this->assertStringContainsString("\$config['migration_version'] = 17;", $config);
        $schema = file_get_contents(self::$root.'/docs/database.sql');
        $this->assertStringContainsString('-- migration 015_marketplace', $schema);
        foreach (Migration_Marketplace::tables() as $table) {
            $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS '.$table.' (', $schema);
        }
    }
}
