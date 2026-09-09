<?php
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__).'/_support/FakeDb.php';
require_once dirname(__DIR__).'/_support/IntegrationHarness.php';

/**
 * Deleting a provider — and only what belonged to it.
 *
 * A provider row used to be immortal: the schema's foreign keys (CASCADE on
 * the mirror tables, SET NULL on history) were defined in migration 004, but
 * no screen ever offered the delete, so the only way out was SQL. These tests
 * pin the delete's exact blast radius, which is the whole safety story of the
 * feature:
 *
 *   - gone: the provider row, its synced catalogue, its logs, its
 *     provider_orders mapping rows — the mirrors of a dead upstream account;
 *   - kept, unlinked: panel services (still sellable, auto-price-sync off
 *     since there is nothing to sync from) and past orders (history intact,
 *     provider link removed);
 *   - reported: the counts the confirmation and the audit row rely on.
 */
class ProviderDeleteTest extends TestCase
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

    private function app()
    {
        $app = new IntegrationHarness();
        $app->seed_minimal();
        $app->model(array('User_model', 'Provider_model', 'Provider_service_model'));
        // The harness pre-registers a ProviderSyncService fake for adapter
        // calls; the delete under test is the real one.
        require_once self::$root.'/application/libraries/ProviderSyncService.php';
        $app->real_providersync = new ProviderSyncService();
        return $app;
    }

    /** A provider with a synced catalogue, two imported panel services and a live order. */
    private function world($app)
    {
        $user = $app->register('buyer', 'buyer@x.test');
        $now = gmdate('Y-m-d H:i:s');

        $app->db->insert('providers', array(
            'public_id' => 'PRV000000000000000000000DEL',
            'name' => 'Doomed Panel', 'api_type' => 'STANDARDSMM',
            'api_url' => 'https://doomed.example/api', 'status' => 'ACTIVE',
            'api_key_encrypted' => 'encrypted-by-test',
            'rate_multiplier' => '1.00000000', 'markup' => '0.00000000',
            'currency' => 'USD', 'timeout_ms' => 15000,
            'sync_interval_minutes' => 60, 'health_status' => 'UNKNOWN',
            'created_at' => $now, 'updated_at' => $now,
        ));
        $provider = $app->db->where('public_id', 'PRV000000000000000000000DEL')->get('providers')->row();

        foreach (array('201', '202') as $sid) {
            $app->db->insert('provider_services', array(
                'provider_id' => $provider->id, 'provider_service_id' => $sid,
                'name' => 'Service '.$sid, 'category' => 'Instagram',
                'rate' => '1.00000000', 'min_quantity' => 100, 'max_quantity' => 10000,
                'service_type' => 'DEFAULT', 'cancel_supported' => 0, 'refill_supported' => 0,
                'dripfeed_supported' => 0, 'last_synced_at' => $now,
            ));
        }

        // One panel service linked and following the provider's pricing;
        // one hand-priced panel service that happens to share the provider.
        foreach (array(true, false) as $auto) {
            $app->db->insert('services', array(
                'public_id' => 'SVC'.str_pad($sid = ($auto ? 'AUTO' : 'HAND'), 23, '0'),
                'name' => 'Imported '.($auto ? 'auto' : 'hand'),
                'slug' => 'imported-'.($auto ? 'auto' : 'hand'),
                'category_id' => 1, 'service_type' => 'DEFAULT',
                'rate' => '2.00000000', 'provider_rate' => '1.00000000',
                'provider_id' => $provider->id,
                'provider_service_id' => $auto ? '201' : '202',
                'auto_price_sync' => $auto ? 1 : 0,
                'min_quantity' => 100, 'max_quantity' => 10000, 'increment_step' => 1,
                'status' => 'ACTIVE', 'created_at' => $now, 'updated_at' => $now,
            ));
        }
        $app->db->insert('orders', array(
            'public_id' => 'ORD0000000000000000000DEL1', 'user_id' => $user->id,
            'service_id' => 1, 'provider_id' => $provider->id, 'status' => 'PROCESSING',
            'quantity' => 100, 'charge' => '2.00000000', 'rate_at_order' => '2.00000000',
            'provider_charge' => '1.00000000', 'refunded_amount' => '0.00000000',
            'currency' => 'NGN', 'link' => 'https://x.test/a', 'source' => 'WEB',
            'created_at' => $now,
        ));

        return array($provider, $user);
    }

    public function testDeleteRemovesTheMirrorsAndUnlinksTheRest()
    {
        $app = $this->app();
        list($provider, $user) = $this->world($app);

        $res = $app->real_providersync->delete_provider($provider);
        $this->assertNotEmpty($res['ok'], $res['error'] ?? '');
        $this->assertSame(array('synced_services' => 2, 'panel_services' => 2, 'orders' => 1),
            $res['counts'], 'the counts the confirmation and audit rely on');

        // The provider itself, and every mirror of its upstream account.
        // (seed_minimal plants its own demo provider, which must survive.)
        $this->assertSame(array(),
            array_filter($app->rows('providers'),
                function ($p) { return $p['public_id'] === 'PRV000000000000000000000DEL'; }));
        $this->assertSame(array(),
            array_filter($app->rows('provider_services'),
                function ($s) use ($provider) { return (int)$s['provider_id'] === (int)$provider->id; }));

        // Panel services survive, unlinked, at their own price — the one that
        // followed the provider stops following. (The seeded demo service
        // belongs to the seeded demo provider and is not our concern.)
        $services = array_values(array_filter($app->rows('services'),
            function ($s) { return in_array($s['public_id'],
                array('SVCAUTO0000000000000000000', 'SVCHAND0000000000000000000'), true); }));
        $this->assertCount(2, $services, 'panel services are never deleted with the provider');
        foreach ($services as $s) {
            $this->assertNull($s['provider_id']);
            $this->assertSame(0, (int)$s['auto_price_sync'],
                'auto-price-sync stops with the provider it followed');
            $this->assertSame('2.00000000', $s['rate'], 'the selling rate is untouched');
        }

        // History survives; only the provider link is removed.
        $orders = $app->rows('orders');
        $this->assertCount(1, $orders);
        $this->assertNull($orders[0]['provider_id']);
        $this->assertSame('PROCESSING', $orders[0]['status']);
    }

    public function testDeleteRefusesAnUnknownProvider()
    {
        $app = $this->app();
        $res = $app->real_providersync->delete_provider(null);
        $this->assertEmpty($res['ok']);
        $this->assertSame('Unknown provider.', $res['error']);
    }

    public function testTheRouteIsPostOnlyAndGatedOnProvidersManage()
    {
        $routes = file_get_contents(self::$root.'/application/config/routes.php');
        $this->assertStringContainsString(
            "\$route['admin/providers/(:any)/delete'] = 'admin/providers/delete/\$1';", $routes,
            'the delete route must exist before the public-id catch-all');

        $src = file_get_contents(self::$root.'/application/controllers/admin/Providers.php');
        $this->assertStringContainsString("guard_post('providers.manage')", $src,
            'the delete must be POST-only behind providers.manage');
        $this->assertStringContainsString("'provider.deleted'", $src,
            'the delete must be audited');
    }
}
