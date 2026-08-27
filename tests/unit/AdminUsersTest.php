<?php
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__).'/_support/FakeDb.php';
require_once dirname(__DIR__).'/_support/IntegrationHarness.php';

/**
 * Admin customer directory, staff roles and manual wallet adjustments
 * (Session 30).
 *
 * `admin/customers` was routed and in the sidebar since Session 15, and
 * `users.view` / `users.edit` / `wallets.adjust` were all seeded into the role
 * matrix — but no controller existed, so the nav entry 404'd and those three
 * permissions gated nothing at all. Support could not suspend a fraudulent
 * account or correct a balance without hand-written SQL.
 *
 * The tests concentrate on the two ways this screen could be genuinely
 * dangerous rather than on CRUD mechanics:
 *
 *   1. **Privilege escalation.** Editing your own role, minting a SUPER_ADMIN
 *      without being one, or demoting the last owner and bricking the panel.
 *   2. **Money.** A manual adjustment is a real ledger movement, so it must be
 *      double-entry, floored at zero and idempotent, exactly like a purchase.
 *      A screen that writes `wallets.balance` directly would pass a naive test
 *      and silently destroy the ledger's integrity.
 */
class AdminUsersTest extends TestCase
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

    /** A world with staff, a customer and a funded wallet. */
    private function app($balance = '5000')
    {
        $app = new IntegrationHarness();
        $app->seed_minimal();
        $owner    = $app->register('owner',  'owner@x.test',  'Str0ng!pass1', 'SUPER_ADMIN');
        $admin    = $app->register('admin1', 'admin@x.test',  'Str0ng!pass1', 'ADMIN');
        $customer = $app->register('cust1',  'cust@x.test');
        $app->credit($customer, $balance);
        $app->library(array('LedgerService', 'UserAdminService'));
        $app->model(array('User_model', 'Wallet_model', 'Wallet_transaction_model'));
        return array($app, $owner, $admin, $customer);
    }

    /* ===================== privilege-escalation guards ==================== */

    /**
     * The guard that matters most: nobody edits their own privileges.
     *
     * An admin who can promote themselves does not need anyone's approval to
     * own the panel, and one who can demote themselves can lock the team out
     * by accident.
     */
    public function testAnAdminCannotChangeTheirOwnRole()
    {
        list($app, , $admin,) = $this->app();

        $res = $app->useradminservice->set_role($admin, $admin, 'SUPER_ADMIN');

        $this->assertFalse($res['ok']);
        $this->assertSame('SELF', $res['code']);
        $this->assertSame('ADMIN', $app->User_model->find_by_id($admin->id)->role,
            'a refused promotion must not half-apply');
    }

    public function testAnAdminCannotSuspendTheirOwnAccount()
    {
        list($app, , $admin,) = $this->app();

        $res = $app->useradminservice->set_status($admin, $admin, 'SUSPENDED');

        $this->assertFalse($res['ok']);
        $this->assertSame('SELF', $res['code']);
        $this->assertSame('ACTIVE', $app->User_model->find_by_id($admin->id)->status);
    }

    /**
     * Without this, `staff.manage` is quietly equivalent to owning the panel:
     * an ADMIN promotes a colleague to SUPER_ADMIN, or themselves via that
     * colleague.
     */
    public function testOnlyASuperAdminCanGrantSuperAdmin()
    {
        list($app, $owner, $admin, $customer) = $this->app();

        $denied = $app->useradminservice->set_role($admin, $customer, 'SUPER_ADMIN');
        $this->assertFalse($denied['ok']);
        $this->assertSame('FORBIDDEN', $denied['code']);
        $this->assertSame('CUSTOMER', $app->User_model->find_by_id($customer->id)->role);

        // The owner may do exactly the same thing.
        $allowed = $app->useradminservice->set_role($owner, $customer, 'SUPER_ADMIN');
        $this->assertTrue($allowed['ok'], $allowed['error'] ?? '');
        $this->assertSame('SUPER_ADMIN', $app->User_model->find_by_id($customer->id)->role);
    }

    /**
     * The one that turns a panel into a brick.
     *
     * Demoting the last owner leaves nobody who can grant the role back, so
     * the check counts live rows rather than trusting a flag.
     */
    public function testTheLastSuperAdminCannotBeDemoted()
    {
        list($app, $owner, , ) = $this->app();

        $res = $app->useradminservice->set_role($owner, $owner, 'ADMIN');
        $this->assertFalse($res['ok']);
        $this->assertSame('SUPER_ADMIN', $app->User_model->find_by_id($owner->id)->role);
    }

    public function testTheLastSuperAdminCannotBeSuspended()
    {
        list($app, $owner, $admin, ) = $this->app();

        $res = $app->useradminservice->set_status($admin, $owner, 'SUSPENDED');

        $this->assertFalse($res['ok']);
        $this->assertSame('LAST_ADMIN', $res['code']);
        $this->assertSame('ACTIVE', $app->User_model->find_by_id($owner->id)->status);
    }

    /** ...but once a second owner exists, the first is no longer load-bearing. */
    public function testASuperAdminCanBeDemotedOnceAnotherExists()
    {
        list($app, $owner, , $customer) = $this->app();
        $app->useradminservice->set_role($owner, $customer, 'SUPER_ADMIN');
        // Re-read: the actor's privileges are whatever the row says now, not
        // what the caller happened to load earlier.
        $second_owner = $app->User_model->find_by_id($customer->id);

        $res = $app->useradminservice->set_role($second_owner, $owner, 'ADMIN');

        $this->assertTrue($res['ok'], $res['error'] ?? '');
        $this->assertSame('ADMIN', $app->User_model->find_by_id($owner->id)->role);
    }

    public function testAnUnknownRoleOrStatusIsRejected()
    {
        list($app, $owner, , $customer) = $this->app();

        $role = $app->useradminservice->set_role($owner, $customer, 'ROOT');
        $this->assertFalse($role['ok']);
        $this->assertSame('INVALID', $role['code']);

        $status = $app->useradminservice->set_status($owner, $customer, 'DELETED');
        $this->assertFalse($status['ok']);
        $this->assertSame('INVALID', $status['code']);
    }

    /* ========================= suspension behaviour ====================== */

    public function testSuspendingACustomerRecordsTheChange()
    {
        list($app, $owner, , $customer) = $this->app();

        $res = $app->useradminservice->set_status($owner, $customer, 'SUSPENDED', 'chargeback');

        $this->assertTrue($res['ok'], $res['error'] ?? '');
        $this->assertSame('ACTIVE', $res['before']['status']);
        $this->assertSame('SUSPENDED', $res['after']['status']);
        $this->assertSame('SUSPENDED', $app->User_model->find_by_id($customer->id)->status);
    }

    /* ====================== manual wallet adjustments ==================== */

    /**
     * A goodwill credit is a real ledger movement, not a balance write.
     *
     * If this screen ever wrote `wallets.balance` directly the balance would
     * look right and the ledger would no longer sum to it — the single worst
     * outcome for an accounting system, because it is invisible until an
     * audit.
     */
    public function testAGoodwillCreditGoesThroughTheLedger()
    {
        list($app, $owner, , $customer) = $this->app('5000');
        $opening = $app->balance($customer);

        $res = $app->useradminservice->adjust_wallet(
            $owner, $customer, '1500', 'CREDIT', 'goodwill for failed order');

        $this->assertTrue($res['ok'], $res['error'] ?? '');
        $this->assertSame(bcadd($opening, '1500.00000000', 8), $app->balance($customer));

        list($debits, $credits) = $app->ledger_is_balanced();
        $this->assertSame($debits, $credits, 'a manual adjustment must stay double-entry');
    }

    /** The movement must be attributable: who did it, and why. */
    public function testAnAdjustmentRecordsTheActorAndTheReason()
    {
        list($app, $owner, , $customer) = $this->app();

        $app->useradminservice->adjust_wallet(
            $owner, $customer, '250', 'CREDIT', 'apology credit');

        $rows = array_values(array_filter($app->rows('wallet_transactions'),
            function ($r) { return ($r['type'] ?? '') === 'ADJUSTMENT'; }));

        $this->assertCount(1, $rows);
        $this->assertSame((int)$owner->id, (int)$rows[0]['actor_id'],
            'an unattributed balance change is indistinguishable from theft');
        $this->assertSame('apology credit', $rows[0]['note']);
    }

    /** An unexplained balance change is refused outright. */
    public function testAnAdjustmentRequiresAReason()
    {
        list($app, $owner, , $customer) = $this->app();
        $opening = $app->balance($customer);

        $res = $app->useradminservice->adjust_wallet($owner, $customer, '100', 'CREDIT', '   ');

        $this->assertFalse($res['ok']);
        $this->assertSame('NO_REASON', $res['code']);
        $this->assertSame($opening, $app->balance($customer));
    }

    /**
     * A clawback cannot drive a wallet negative.
     *
     * The balance floor is enforced by LedgerService for every movement; this
     * proves the manual path is not exempt from it.
     */
    public function testAClawbackCannotOverdrawTheWallet()
    {
        list($app, $owner, , $customer) = $this->app('1000');

        $res = $app->useradminservice->adjust_wallet(
            $owner, $customer, '5000', 'DEBIT', 'reversing a mistaken credit');

        $this->assertFalse($res['ok']);
        $this->assertSame('INSUFFICIENT', $res['code']);
        $this->assertSame('1000.00000000', $app->balance($customer),
            'a refused debit must leave the balance untouched');
    }

    public function testAClawbackWithinTheBalanceSucceeds()
    {
        list($app, $owner, , $customer) = $this->app('1000');

        $res = $app->useradminservice->adjust_wallet(
            $owner, $customer, '400', 'DEBIT', 'reversing a mistaken credit');

        $this->assertTrue($res['ok'], $res['error'] ?? '');
        $this->assertSame('600.00000000', $app->balance($customer));
        list($debits, $credits) = $app->ledger_is_balanced();
        $this->assertSame($debits, $credits);
    }

    /**
     * A double-submitted form must not pay twice.
     *
     * The view emits one nonce per render, so the second POST carries the same
     * idempotency key and is absorbed.
     */
    public function testAResubmittedAdjustmentIsAppliedOnce()
    {
        list($app, $owner, , $customer) = $this->app('1000');
        $key = 'admin:adjust:'.$customer->id.':fixed-nonce';

        $first  = $app->useradminservice->adjust_wallet($owner, $customer, '300', 'CREDIT', 'goodwill', $key);
        $second = $app->useradminservice->adjust_wallet($owner, $customer, '300', 'CREDIT', 'goodwill', $key);

        $this->assertTrue($first['ok'], $first['error'] ?? '');
        $this->assertFalse($second['ok'], 'the repeat must not be reported as a fresh adjustment');
        $this->assertSame('DUPLICATE', $second['code']);
        $this->assertSame('1300.00000000', $app->balance($customer),
            'the customer must be paid exactly once');
    }

    public function testAZeroOrNonNumericAmountIsRejected()
    {
        list($app, $owner, , $customer) = $this->app();
        foreach (array('0', '-50', '', 'abc') as $bad) {
            $res = $app->useradminservice->adjust_wallet($owner, $customer, $bad, 'CREDIT', 'why not');
            $this->assertFalse($res['ok'], "amount '{$bad}' must be refused");
            $this->assertSame('INVALID', $res['code']);
        }
        $this->assertSame('5000.00000000', $app->balance($customer));
    }

    /* ============================= directory ============================= */

    public function testTheDirectorySearchesAndScopesToCustomers()
    {
        list($app, , , ) = $this->app();

        $all = $app->useradminservice->grid(array('customers_only' => true));
        $this->assertSame(1, (int)$all['total'], 'staff must not appear in the customer directory');
        $this->assertSame('cust1', $all['rows'][0]->username);

        $hit = $app->useradminservice->grid(array('search' => 'cust@'));
        $this->assertSame(1, (int)$hit['total']);

        $miss = $app->useradminservice->grid(array('search' => 'nobody@nowhere'));
        $this->assertSame(0, (int)$miss['total']);
    }

    public function testTheDirectoryNeverSelectsCredentials()
    {
        list($app, , , ) = $this->app();
        $row = $app->useradminservice->grid(array('customers_only' => true))['rows'][0];
        $cols = array_keys(get_object_vars($row));

        foreach (array('password_hash', 'mfa_secret') as $secret) {
            $this->assertNotContains($secret, $cols,
                'the directory must not read '.$secret.' — a screen cannot leak a column it never selects');
        }
    }

    public function testTheProfileCarriesTheWallet()
    {
        list($app, , , $customer) = $this->app('750');
        $profile = $app->useradminservice->profile($customer->public_id);

        $this->assertNotNull($profile);
        $this->assertSame('750.00000000', $profile->wallet->balance);
        $this->assertNull($app->useradminservice->profile('NOPE'));
    }

    /* ======================= controller guarantees ======================= */

    public function testTheUsersControllerNeverMovesMoneyItself()
    {
        $src = file_get_contents(self::$root.'/application/controllers/admin/Users.php');
        foreach (array('ledgerservice->', "update('wallets'", "insert('wallet_transactions'") as $write) {
            $this->assertStringNotContainsString($write, $src,
                'admin/Users.php must delegate money to the service layer, found '.$write);
        }
    }

    /**
     * Suspending an account is support work; becoming the customer is not.
     * The screen must never read credentials or session material.
     */
    public function testTheUsersScreenNeverTouchesCredentials()
    {
        $files = array(
            self::$root.'/application/controllers/admin/Users.php',
            self::$root.'/application/views/admin/users/index.php',
            self::$root.'/application/views/admin/users/detail.php',
            self::$root.'/application/views/admin/users/wallets.php',
        );
        foreach ($files as $f) {
            $src = file_get_contents($f);
            foreach (array('password_hash', 'mfa_secret', 'api_key', 'set_userdata') as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $src,
                    basename($f).' must not touch '.$forbidden);
            }
        }
    }

    public function testAdjustmentIsGatedOnItsOwnPermission()
    {
        $src = file_get_contents(self::$root.'/application/controllers/admin/Users.php');
        // Reading a customer file and reaching into their wallet are
        // different levels of trust.
        $this->assertStringContainsString("require_perm('users.view')", $src);
        $this->assertStringContainsString("'wallets.adjust'", $src);
        $this->assertStringContainsString("'staff.manage'", $src);
        $this->assertStringContainsString("'users.edit'", $src);
    }
}
