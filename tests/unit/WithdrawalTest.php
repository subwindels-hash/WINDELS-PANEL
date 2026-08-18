<?php
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__).'/_support/FakeDb.php';
require_once dirname(__DIR__).'/_support/IntegrationHarness.php';

/** Withdrawal reservation, settlement, privacy, and wiring gates. */
class WithdrawalTest extends TestCase
{
    private static $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(dirname(__DIR__));
        if (!defined('BASEPATH')) define('BASEPATH', self::$root.'/system/');
        if (!class_exists('CI_Model')) eval('#[AllowDynamicProperties] class CI_Model { public $db; }');
        if (!function_exists('get_instance')) eval('function get_instance(){ return $GLOBALS["__fake_ci"]; }');
        if (!function_exists('log_message')) eval('function log_message($l,$m){}');
        require_once self::$root.'/application/core/MY_Model.php';
        require_once self::$root.'/application/helpers/windels_helper.php';
    }

    private function app($balance = '20000.00000000')
    {
        $app = new IntegrationHarness();
        $app->seed_minimal();
        $customer = $app->register('withdraw_user', 'withdraw@x.test');
        $admin = $app->register('withdraw_admin', 'withdraw-admin@x.test', 'Str0ng!pass1', 'ADMIN');
        $stranger = $app->register('withdraw_other', 'withdraw-other@x.test');
        if (bccomp($balance, '0', 8) > 0) $app->credit($customer, $balance);
        $app->library('WithdrawalService');
        // Setting_model memoizes per web request; each harness instance is a
        // separate request/database and must not inherit the prior test's memo.
        Setting_model::flush_cache();
        $app->model(array('Withdrawal_model', 'Wallet_model', 'Wallet_transaction_model',
            'Setting_model', 'Identity_check_model'));
        return array($app, $customer, $admin, $stranger);
    }

    private function request($app, $customer, array $overrides = array())
    {
        return $app->withdrawalservice->request($customer, array_merge(array(
            'amount' => '5000.00',
            'bank_name' => 'Example Bank',
            'bank_code' => '999',
            'account_number' => '0123456789',
            'account_name' => 'Test Customer',
            'idempotency_key' => 'withdrawal-test-key',
        ), $overrides));
    }

    public function testRequestReservesGrossAmountFreezesFeeAndBalancesLedger()
    {
        list($app, $customer) = $this->app();
        $res = $this->request($app, $customer);

        $this->assertTrue($res['ok'], $res['error'] ?? '');
        $this->assertSame('PENDING', $res['withdrawal']->status);
        $this->assertSame('5000.00000000', $res['withdrawal']->amount);
        $this->assertSame('50.00000000', $res['withdrawal']->fee_amount);
        $this->assertSame('4950.00000000', $res['withdrawal']->payout_amount);
        $this->assertSame('15000.00000000', $app->balance($customer));
        $this->assertNotEmpty($res['withdrawal']->wallet_transaction_id);
        $this->assertCount(1, $app->rows('withdrawal_requests'));
        $this->assertCount(1, $app->rows('withdrawal_events'));
        $hold = array_values(array_filter($app->rows('wallet_transactions'), function ($row) {
            return $row['type'] === 'WITHDRAWAL';
        }));
        $this->assertCount(1, $hold);
        $this->assertSame('DEBIT', $hold[0]['direction']);
        $this->assertSame('WITHDRAWAL', $hold[0]['reference_type']);
        $this->assertNotEmpty(array_filter($app->rows('ledger_entries'), function ($row) {
            return $row['account'] === 'withdrawal_payable';
        }));
        list($debits, $credits) = $app->ledger_is_balanced();
        $this->assertSame($debits, $credits);
    }

    public function testRequestIsIdempotentAndNeverDoubleDebits()
    {
        list($app, $customer) = $this->app();
        $first = $this->request($app, $customer);
        $second = $this->request($app, $customer);

        $this->assertTrue($first['ok']);
        $this->assertTrue($second['ok']);
        $this->assertTrue($second['duplicate']);
        $this->assertSame($first['withdrawal']->id, $second['withdrawal']->id);
        $this->assertSame('15000.00000000', $app->balance($customer));
        $this->assertCount(1, $app->rows('withdrawal_requests'));
        $this->assertCount(1, array_filter($app->rows('wallet_transactions'), function ($row) {
            return $row['type'] === 'WITHDRAWAL';
        }));
    }

    public function testIdempotencyKeyCannotBeReusedByAnotherCustomer()
    {
        list($app, $customer, , $stranger) = $this->app();
        $this->request($app, $customer);
        $res = $this->request($app, $stranger);
        $this->assertFalse($res['ok']);
        $this->assertSame('IDEMPOTENCY_CONFLICT', $res['code']);
    }

    public function testInsufficientBalanceAndInvalidDestinationCreateNothing()
    {
        list($app, $customer) = $this->app('2000');
        $insufficient = $this->request($app, $customer);
        $bad_destination = $this->request($app, $customer, array(
            'amount' => '1000', 'account_number' => '123', 'idempotency_key' => 'bad-destination',
        ));

        $this->assertFalse($insufficient['ok']);
        $this->assertSame('INSUFFICIENT_BALANCE', $insufficient['code']);
        $this->assertFalse($bad_destination['ok']);
        $this->assertSame('BAD_DESTINATION', $bad_destination['code']);
        $this->assertSame('2000.00000000', $app->balance($customer));
        $this->assertEmpty($app->rows('withdrawal_requests'));
    }

    public function testCustomerCancellationReturnsGrossAmountExactlyOnce()
    {
        list($app, $customer) = $this->app();
        $opened = $this->request($app, $customer);
        $cancelled = $app->withdrawalservice->cancel($opened['withdrawal']->public_id, $customer->id);
        $again = $app->withdrawalservice->cancel($opened['withdrawal']->public_id, $customer->id);

        $this->assertTrue($cancelled['ok'], $cancelled['error'] ?? '');
        $this->assertSame('CANCELLED', $cancelled['withdrawal']->status);
        $this->assertNotEmpty($cancelled['withdrawal']->refund_wallet_transaction_id);
        $this->assertTrue($again['ok']);
        $this->assertTrue($again['duplicate']);
        $this->assertSame('20000.00000000', $app->balance($customer));
        $this->assertCount(1, array_filter($app->rows('wallet_transactions'), function ($row) {
            return $row['type'] === 'REFUND' && $row['reference_type'] === 'WITHDRAWAL';
        }));
        list($debits, $credits) = $app->ledger_is_balanced();
        $this->assertSame($debits, $credits);
    }

    public function testApprovalThenPaidDoesNotMoveWalletTwice()
    {
        list($app, $customer, $admin) = $this->app();
        $opened = $this->request($app, $customer);
        $approved = $app->withdrawalservice->approve(
            $opened['withdrawal']->public_id, $admin->id, 'Checks completed'
        );
        $paid = $app->withdrawalservice->mark_paid(
            $opened['withdrawal']->public_id, $admin->id, 'BANK-TRANSFER-123', 'Receipt confirmed'
        );
        $again = $app->withdrawalservice->mark_paid(
            $opened['withdrawal']->public_id, $admin->id, 'BANK-TRANSFER-123'
        );

        $this->assertTrue($approved['ok']);
        $this->assertSame('APPROVED', $approved['withdrawal']->status);
        $this->assertTrue($paid['ok'], $paid['error'] ?? '');
        $this->assertSame('PAID', $paid['withdrawal']->status);
        $this->assertSame('BANK-TRANSFER-123', $paid['withdrawal']->payout_reference);
        $this->assertTrue($again['ok']);
        $this->assertTrue($again['duplicate']);
        $this->assertSame('15000.00000000', $app->balance($customer));
        $this->assertCount(2, $app->rows('wallet_transactions'),
            'the fixture deposit and request reservation are the only wallet movements');
    }

    public function testPendingOrApprovedRejectionReturnsGrossExactlyOnce()
    {
        foreach (array(false, true) as $approve_first) {
            list($app, $customer, $admin) = $this->app();
            $opened = $this->request($app, $customer);
            if ($approve_first) {
                $app->withdrawalservice->approve($opened['withdrawal']->public_id, $admin->id);
            }
            $rejected = $app->withdrawalservice->reject(
                $opened['withdrawal']->public_id, $admin->id, 'Destination could not be verified'
            );
            $again = $app->withdrawalservice->reject(
                $opened['withdrawal']->public_id, $admin->id, 'retry'
            );
            $this->assertTrue($rejected['ok'], $rejected['error'] ?? '');
            $this->assertSame('REJECTED', $rejected['withdrawal']->status);
            $this->assertTrue($again['duplicate']);
            $this->assertSame('20000.00000000', $app->balance($customer));
        }
    }

    public function testInvalidLifecycleAndOwnershipAreRejected()
    {
        list($app, $customer, $admin, $stranger) = $this->app();
        $opened = $this->request($app, $customer);
        $not_owner = $app->withdrawalservice->cancel($opened['withdrawal']->public_id, $stranger->id);
        $too_early = $app->withdrawalservice->mark_paid(
            $opened['withdrawal']->public_id, $admin->id, 'BANK-REF-1'
        );
        $app->withdrawalservice->approve($opened['withdrawal']->public_id, $admin->id);
        $too_late = $app->withdrawalservice->cancel($opened['withdrawal']->public_id, $customer->id);

        $this->assertFalse($not_owner['ok']);
        $this->assertSame('NOT_FOUND', $not_owner['code']);
        $this->assertFalse($too_early['ok']);
        $this->assertSame('BAD_STATE', $too_early['code']);
        $this->assertFalse($too_late['ok']);
        $this->assertSame('BAD_STATE', $too_late['code']);
        $this->assertSame('15000.00000000', $app->balance($customer));
    }

    public function testFailedRefundRollsBackStatusAndCanBeRetried()
    {
        list($app, $customer, $admin) = $this->app();
        $opened = $this->request($app, $customer);
        $real = $app->ledgerservice;
        $app->ledgerservice = new class {
            public function refund_withdrawal() { return array('ok'=>false,'error'=>'simulated outage'); }
        };
        $failed = $app->withdrawalservice->reject(
            $opened['withdrawal']->public_id, $admin->id, 'Manual review failed'
        );
        $this->assertFalse($failed['ok']);
        $this->assertSame('REFUND_FAILED', $failed['code']);
        $this->assertSame('PENDING', $app->Withdrawal_model->find_by_id($opened['withdrawal']->id)->status);
        $this->assertSame('15000.00000000', $app->balance($customer));

        $app->ledgerservice = $real;
        $retry = $app->withdrawalservice->reject(
            $opened['withdrawal']->public_id, $admin->id, 'Manual review failed'
        );
        $this->assertTrue($retry['ok'], $retry['error'] ?? '');
        $this->assertSame('20000.00000000', $app->balance($customer));
    }

    public function testQueueAndCustomerProjectionsNeverExposeCiphertext()
    {
        list($app, $customer, , $stranger) = $this->app();
        $opened = $this->request($app, $customer);
        $stored = $app->Withdrawal_model->find_by_id($opened['withdrawal']->id);
        $customer_rows = $app->Withdrawal_model->for_user($customer->id, 20, 0);
        $admin_rows = $app->Withdrawal_model->admin_search(array(), 20, 0);

        $this->assertNotEmpty($stored->destination_encrypted);
        $this->assertStringNotContainsString('0123456789', $stored->destination_encrypted);
        $this->assertSame('Example Bank ••••6789', $stored->destination_label);
        $this->assertFalse(property_exists($opened['withdrawal'], 'destination_encrypted'));
        $this->assertFalse(property_exists($customer_rows[0], 'destination_encrypted'));
        $this->assertFalse(property_exists($admin_rows[0], 'destination_encrypted'));
        $this->assertNull($app->Withdrawal_model->find_owned($stored->public_id, $stranger->id));
        $source = file_get_contents(self::$root.'/application/models/Withdrawal_model.php');
        $this->assertStringContainsString('deliberately excludes destination_encrypted', $source);
    }

    public function testAdminQueueFiltersAndCountUseTheSameCustomerJoin()
    {
        list($app, $customer, $admin, $stranger) = $this->app();
        $pending = $this->request($app, $customer);
        $app->credit($stranger, '10000.00000000');
        $approved = $this->request($app, $stranger, array(
            'amount' => '2000',
            'account_number' => '5555554321',
            'account_name' => 'Other Customer',
            'idempotency_key' => 'withdrawal-filter-second',
        ));
        $app->withdrawalservice->approve($approved['withdrawal']->public_id, $admin->id);

        $by_status = $app->Withdrawal_model->admin_search(array('status'=>'APPROVED'), 20, 0);
        $by_customer = $app->Withdrawal_model->admin_search(array('search'=>'withdraw_other'), 20, 0);
        $filter = array('status'=>'PENDING', 'search'=>'withdraw_user');
        $combined = $app->Withdrawal_model->admin_search($filter, 20, 0);

        $this->assertCount(1, $by_status);
        $this->assertSame($approved['withdrawal']->public_id, $by_status[0]->public_id);
        $this->assertCount(1, $by_customer);
        $this->assertSame($approved['withdrawal']->public_id, $by_customer[0]->public_id);
        $this->assertCount(1, $combined);
        $this->assertSame($pending['withdrawal']->public_id, $combined[0]->public_id);
        $this->assertSame(1, $app->Withdrawal_model->admin_count($filter));
        $this->assertSame(0, $app->Withdrawal_model->admin_count(
            array('status'=>'PAID', 'search'=>'withdraw_user')
        ));
    }

    public function testDestinationRevealIsExplicitAndRecordedEveryTime()
    {
        list($app, $customer, $admin) = $this->app();
        $opened = $this->request($app, $customer);
        $first = $app->withdrawalservice->reveal($opened['withdrawal']->public_id, $admin->id);
        $second = $app->withdrawalservice->reveal($opened['withdrawal']->public_id, $admin->id);
        $row = $app->Withdrawal_model->find_by_id($opened['withdrawal']->id);

        $this->assertTrue($first['ok'], $first['error'] ?? '');
        $this->assertSame('0123456789', $first['destination']['account_number']);
        $this->assertTrue($second['ok']);
        $this->assertSame(2, (int)$row->reveal_count);
        $this->assertSame((int)$admin->id, (int)$row->last_revealed_by);
        $events = array_filter($app->rows('withdrawal_events'), function ($event) {
            return $event['event_type'] === 'DESTINATION_REVEALED';
        });
        $this->assertCount(2, $events);
    }

    public function testFeeBoundsAndIdentityPolicyAreConfigurable()
    {
        list($app, $customer) = $this->app();
        $app->Setting_model->set('withdrawal_fee_percent', '2.5000', 'withdrawals');
        $app->Setting_model->set('withdrawal_fee_fixed', '100.00000000', 'withdrawals');
        $app->Setting_model->set('withdrawal_min_amount', '2000.00000000', 'withdrawals');
        $app->Setting_model->set('withdrawal_max_amount', '6000.00000000', 'withdrawals');
        $this->assertSame('225.00000000', $app->withdrawalservice->fee_for('5000'));
        $this->assertSame('AMOUNT_TOO_LOW', $this->request($app, $customer,
            array('amount'=>'1000','idempotency_key'=>'too-low'))['code']);
        $this->assertSame('AMOUNT_TOO_HIGH', $this->request($app, $customer,
            array('amount'=>'7000','idempotency_key'=>'too-high'))['code']);

        $app->Setting_model->set('withdrawal_require_verified_identity', true, 'withdrawals');
        $blocked = $this->request($app, $customer, array('idempotency_key'=>'identity-block'));
        $this->assertFalse($blocked['ok']);
        $this->assertSame('IDENTITY_REQUIRED', $blocked['code']);
    }

    public function testSettingsValidationProtectsWithdrawalPolicy()
    {
        list($app) = $this->app();
        $app->library('SettingsService');
        $bounds = $app->settingsservice->save(array(
            'withdrawal_min_amount'=>'9000', 'withdrawal_max_amount'=>'1000',
        ));
        $fee = $app->settingsservice->save(array(
            'withdrawal_fee_percent'=>'26',
        ));
        $no_payout = $app->settingsservice->save(array(
            'withdrawal_min_amount'=>'1000', 'withdrawal_fee_fixed'=>'1000',
        ));
        $this->assertFalse($bounds['ok']);
        $this->assertFalse($fee['ok']);
        $this->assertFalse($no_payout['ok']);
    }

    /* ========================== schema / wiring ========================== */

    public function testMigrationAndGeneratedSchemaProtectFinancialAndPrivateShape()
    {
        require_once self::$root.'/application/migrations/016_withdrawals.php';
        $this->assertSame(array('withdrawal_requests','withdrawal_events'), Migration_Withdrawals::tables());
        $sql = implode("\n", Migration_Withdrawals::statements());
        foreach (array('wallet_transaction_id BIGINT UNSIGNED NOT NULL UNIQUE',
            'refund_wallet_transaction_id BIGINT UNSIGNED NULL UNIQUE',
            'destination_encrypted MEDIUMTEXT NOT NULL', 'idempotency_key VARCHAR(128) NOT NULL UNIQUE',
            'payout_amount DECIMAL(20,8)', 'reveal_count INT UNSIGNED') as $needle) {
            $this->assertStringContainsString($needle, $sql);
        }
        $config = file_get_contents(self::$root.'/application/config/migration.php');
        $schema = file_get_contents(self::$root.'/docs/database.sql');
        $this->assertStringContainsString("\$config['migration_version'] = 17;", $config);
        $this->assertStringContainsString('-- migration 016_withdrawals', $schema);
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS withdrawal_requests (', $schema);
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS withdrawal_events (', $schema);
    }

    public function testRoutesNavigationPermissionsAndSettingsAreRegistered()
    {
        $routes = file_get_contents(self::$root.'/application/config/routes.php');
        foreach (array('dashboard/withdrawals/create', 'dashboard/withdrawals/(:any)/cancel',
            'admin/withdrawals/(:any)/approve', 'admin/withdrawals/(:any)/reveal') as $needle) {
            $this->assertStringContainsString($needle, $routes);
        }
        $layout = file_get_contents(self::$root.'/application/views/layouts/app.php');
        $this->assertStringContainsString("'dashboard/withdrawals'", $layout);
        $this->assertStringContainsString("'admin/withdrawals'", $layout);
        $seed = file_get_contents(self::$root.'/application/seeds/Core_seeder.php');
        foreach (array('withdrawals.view','withdrawals.process','withdrawals.reveal',
            'withdrawal_min_amount','withdrawal_max_amount','withdrawal_fee_fixed',
            'withdrawal_fee_percent','withdrawal_require_verified_identity') as $needle) {
            $this->assertStringContainsString($needle, $seed);
        }
    }

    public function testControllerMutationsArePostOnlyPermissionedOwnedAndAudited()
    {
        $admin = file_get_contents(self::$root.'/application/controllers/admin/Withdrawals.php');
        foreach (array("require_perm('withdrawals.view')", "require_perm('withdrawals.process')",
            "require_perm('withdrawals.reveal')", '$this->post_only();',
            "'withdrawal.destination_revealed'", 'Audit_log_model->record(') as $needle) {
            $this->assertStringContainsString($needle, $admin);
        }
        $customer = file_get_contents(self::$root.'/application/controllers/dashboard/Withdrawals.php');
        $this->assertStringContainsString('find_owned($public_id, $this->current_user->id)', $customer);
        $this->assertStringContainsString('hash_equals($session_token, $token)', $customer);
        $this->assertStringContainsString("userdata('withdrawal_recent_tokens')", $customer);
        $this->assertStringContainsString('array_slice($recent_tokens, -5)', $customer);
        $this->assertStringContainsString('$this->post_only();', $customer);
        $detail = file_get_contents(self::$root.'/application/views/dashboard/withdrawals/detail.php');
        $this->assertStringNotContainsString('onsubmit=', $detail);
        $service = file_get_contents(self::$root.'/application/libraries/WithdrawalService.php');
        $this->assertStringContainsString('find_for_update($public_id)', $service);
        $this->assertStringContainsString('reserve_withdrawal(', $service);
        $this->assertStringContainsString('refund_withdrawal(', $service);
        $this->assertStringContainsString('encryptionservice->open(', $service);
        $this->assertStringNotContainsString('->decrypt(', $service);
        $this->assertStringNotContainsString("update('wallets'", $service);
    }
}
