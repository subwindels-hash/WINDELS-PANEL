<?php
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__).'/_support/FakeDb.php';
require_once dirname(__DIR__).'/_support/IntegrationHarness.php';

/**
 * The panel's own messaging — the three in-dashboard conversations:
 *
 *   · a visitor's contact-form message lands in the STAFF inbox (Admin →
 *     Inbox), beside the polled mailbox mail;
 *   · a customer composes from their dashboard to the team, and a staff
 *     reply is delivered back into the CUSTOMER's dashboard inbox;
 *   · a super admin broadcasts a notification to every user, or to one.
 *
 * The inboxes and the notifications table are real (the harness builds the
 * shipped migration DDL), so what these tests pin is delivery and scoping:
 * the right rows in the right inbox, nobody else's rows visible, a
 * double-submitted send stored once, and a broadcast reaching exactly the
 * people it was addressed to.
 */
class MessagingTest extends TestCase
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
        $app->model(array('Setting_model', 'User_model', 'Notification_model'));
        $app->library(array('InboxService', 'NotificationService'));
        return $app;
    }

    /* ------------------------ contact → staff inbox ---------------------- */

    public function testAContactMessageLandsInTheStaffInbox()
    {
        $app = $this->app();

        $id = $app->inboxservice->deliver(
            'ADMIN', null, 'support@panel.test', 'Ada Visitor', 'ada@example.test',
            '[Contact] Do you deliver to Nigeria?',
            "From: Ada Visitor <ada@example.test>\n\nHello, do your services work?",
            'contact:CMSG0000000000000000000001'
        );

        $this->assertNotNull($id);
        $rows = $app->inboxservice->for_admin();
        $this->assertCount(1, $rows);
        $this->assertSame('[Contact] Do you deliver to Nigeria?', $rows[0]->subject);
        $this->assertSame('ada@example.test', $rows[0]->from_email);
        $this->assertSame(0, (int) $rows[0]->is_read, 'a fresh contact message is unread');
        $this->assertStringContainsString('Hello, do your services work?', $rows[0]->body_text);
    }

    /* ----------------------- replies → customer inbox -------------------- */

    public function testAStaffReplyLandsInTheCustomersDashboardInbox()
    {
        $app = $this->app();
        $app->register('ada', 'ada@example.test');
        $app->register('kofi', 'kofi@example.test');
        $ada = $app->User_model->find_by_email('ada@example.test');
        $kofi = $app->User_model->find_by_email('kofi@example.test');

        $app->inboxservice->deliver(
            'USER', $ada->id, $ada->email, 'MarvySocials', 'support@panel.test',
            'Re: Do you deliver to Nigeria?', 'Yes — all services work for Nigerian accounts.',
            'aireply:CMSG0000000000000000000001'
        );

        $hers = $app->inboxservice->for_user((int) $ada->id);
        $this->assertCount(1, $hers, 'the reply is in Ada\'s inbox');
        $this->assertSame('Re: Do you deliver to Nigeria?', $hers[0]->subject);
        $this->assertSame('support@panel.test', $hers[0]->from_email);

        // Scoping lives in the queries, not the links: another customer sees
        // nothing of it, by list or by guessed public id.
        $this->assertSame(array(), $app->inboxservice->for_user((int) $kofi->id));
        $this->assertNull($app->inboxservice->find_for_user($hers[0]->public_id, (int) $kofi->id));
        $this->assertNotNull($app->inboxservice->find_for_user($hers[0]->public_id, (int) $ada->id));
    }

    public function testADoubleSubmittedSendStoresOnce()
    {
        $app = $this->app();

        $first = $app->inboxservice->deliver(
            'ADMIN', null, 'support@panel.test', 'Ada', 'ada@example.test',
            'Subject', 'Body', 'umsg:7:01HZUNIQUE0000000000000000'
        );
        $again = $app->inboxservice->deliver(
            'ADMIN', null, 'support@panel.test', 'Ada', 'ada@example.test',
            'Subject', 'Body', 'umsg:7:01HZUNIQUE0000000000000000'
        );

        $this->assertNotNull($first);
        $this->assertNull($again, 'the same dedupe source must not store twice');
        $this->assertCount(1, $app->inboxservice->for_admin());
    }

    public function testAnEmptyMessageIsRefused()
    {
        $app = $this->app();

        $this->assertNull($app->inboxservice->deliver(
            'ADMIN', null, 'support@panel.test', 'Ada', 'a@e.test', 'Subject', '   ', 'x1'));
        $this->assertNull($app->inboxservice->deliver(
            'ADMIN', null, '', 'Ada', 'a@e.test', 'Subject', 'Body', 'x2'));

        $this->assertCount(0, $app->inboxservice->for_admin());
    }

    public function testAReplyFindsTheAccountBehindAnAddress()
    {
        $app = $this->app();
        $app->register('ada', 'ada@example.test');

        $this->assertNotNull($app->inboxservice->user_by_email('Ada@Example.TEST'),
            'the lookup is case-insensitive, like the routing key');
        $this->assertNull($app->inboxservice->user_by_email('stranger@example.test'),
            'a visitor with no account has no inbox to deliver to');
        $this->assertNull($app->inboxservice->user_by_email('   '));
    }

    /* ---------------------------- broadcasts ----------------------------- */

    public function testABroadcastReachesEveryUser()
    {
        $app = $this->app();
        $app->register('ada', 'ada@example.test');
        $app->register('kofi', 'kofi@example.test');
        $app->register('ama', 'ama@example.test');
        $ids = array();
        foreach (array('ada@example.test', 'kofi@example.test', 'ama@example.test') as $email) {
            $ids[] = (int) $app->User_model->find_by_email($email)->id;
        }

        $written = $app->notificationservice->broadcast($ids, 'Maintenance window',
            'The panel will be briefly unavailable on Sunday 02:00–02:30 UTC.');

        $this->assertSame(count($ids), $written);
        $rows = $app->db->where('type', 'admin.broadcast')->get('notifications')->result();
        $this->assertCount(count($ids), $rows);
        foreach ($rows as $r) {
            $this->assertSame('IN_APP', $r->channel);
            $this->assertSame(0, (int) $r->is_read);
            $this->assertSame('Maintenance window', $r->title);
        }
    }

    public function testABroadcastToOneUserReachesOnlyThem()
    {
        $app = $this->app();
        $app->register('ada', 'ada@example.test');
        $app->register('kofi', 'kofi@example.test');
        $ada = $app->User_model->find_by_email('ada@example.test');

        $written = $app->notificationservice->broadcast(
            array((int) $ada->id), 'About your withdrawal', 'We are looking into it — expect an update today.');

        $this->assertSame(1, $written);
        $row = $app->db->where('user_id', (int) $ada->id)->get('notifications')->row();
        $this->assertNotNull($row);
        $this->assertSame('About your withdrawal', $row->title);
        $others = 0;
        foreach ($app->db->all('notifications') as $r) {
            if ((int) $r['user_id'] !== (int) $ada->id) $others++;
        }
        $this->assertSame(0, $others, 'nobody else was notified');
    }

    public function testABroadcastNeedsATitleAndABody()
    {
        $app = $this->app();
        $app->register('ada', 'ada@example.test');
        $ada = $app->User_model->find_by_email('ada@example.test');

        $this->assertSame(0, $app->notificationservice->broadcast(array((int) $ada->id), '   ', 'Body'));
        $this->assertSame(0, $app->notificationservice->broadcast(array((int) $ada->id), 'Title', ''));
        $this->assertSame(0, $app->notificationservice->broadcast(array(), 'Title', 'Body'));
        $this->assertSame(0, count($app->db->all('notifications')));
    }

    public function testRecentBroadcastsListNewestFirstWithTheRecipient()
    {
        $app = $this->app();
        $app->register('ada', 'ada@example.test');
        $ada = $app->User_model->find_by_email('ada@example.test');

        $app->notificationservice->broadcast(array((int) $ada->id), 'First', 'one');
        $app->notificationservice->broadcast(array((int) $ada->id), 'Second', 'two');

        $rows = $app->notificationservice->recent_broadcasts();
        $this->assertCount(2, $rows);
        $this->assertSame('Second', $rows[0]->title, 'newest first');
        $this->assertSame('ada@example.test', $rows[0]->email, 'the recipient is named');
    }

    /* ---------------------------- source pins ---------------------------- */

    public function testTheContactFormDeliversIntoTheStaffInbox()
    {
        $src = file_get_contents(self::$root.'/application/controllers/Home.php');
        $this->assertStringContainsString("->inboxservice->deliver(", $src,
            'the contact form must land in Admin → Inbox');
        $this->assertStringContainsString("'contact:'", $src,
            'the contact row id is the dedupe source — one visitor message, one inbox row');
    }

    public function testCustomersComposeFromTheirDashboard()
    {
        $src = file_get_contents(self::$root.'/application/controllers/dashboard/Inbox.php');
        $this->assertStringContainsString('public function compose(', $src);
        $this->assertStringContainsString('public function send(', $src);
        $this->assertStringContainsString("method(true) !== 'POST'", $src, 'sending is POST-only');
        $this->assertStringContainsString('RateLimiter', $src,
            'a signed-in customer must not be able to flood the shared staff inbox');

        $routes = file_get_contents(self::$root.'/application/config/routes.php');
        $this->assertLessThan(strpos($routes, "dashboard/inbox/(:any)"),
            strpos($routes, "dashboard/inbox/compose"),
            'compose must be routed before the (:any) detail route, or it 404s');
    }

    public function testTheStaffReplyReachesTheCustomersDashboard()
    {
        $src = file_get_contents(self::$root.'/application/controllers/admin/Inbox.php');
        $this->assertStringContainsString('user_by_email(', $src,
            'the reply resolves the account behind the From address');
        $this->assertStringContainsString("deliver('USER'", $src,
            'a registered sender gets the reply in their dashboard inbox');
        $this->assertStringContainsString("'aireply:'", $src,
            'a double-submitted reply must not store twice');
    }

    public function testBroadcastingIsGatedOnItsOwnPermission()
    {
        $src = file_get_contents(self::$root.'/application/controllers/admin/Notifications.php');
        $this->assertStringContainsString("require_perm('notifications.send')", $src);
        $this->assertStringContainsString("method(true) !== 'POST'", $src, 'sending is POST-only');
        $this->assertStringContainsString('Audit_log_model', $src,
            'a write addressed to every user is audited');

        $seeder = file_get_contents(self::$root.'/application/seeds/Core_seeder.php');
        $this->assertStringContainsString("'notifications' => array('notifications.send')", $seeder);

        // No staff role carries the grant by default: the matrix section of
        // the seeder must not hand notifications.send to ADMIN or STAFF.
        preg_match('~function role_matrix\(\)\s*\{.*?\n    \}~s', $seeder, $m);
        $this->assertNotEmpty($m);
        $this->assertStringNotContainsString('notifications.send', $m[0],
            'broadcasting to every customer is the super admin\'s call by default');
    }

    public function testTheNotificationsScreenIsWiredAndReachable()
    {
        $routes = file_get_contents(self::$root.'/application/config/routes.php');
        $this->assertStringContainsString("admin/notifications'] = 'admin/notifications/index'", $routes);
        $this->assertStringContainsString("admin/notifications/send'] = 'admin/notifications/send'", $routes);

        $nav = file_get_contents(self::$root.'/application/views/layouts/_app_context.php');
        $this->assertStringContainsString("array('admin/notifications'", $nav,
            'the super admin needs the screen in the sidebar');
        $this->assertStringContainsString("'notifications.send'", $nav,
            'the nav entry is gated on the same permission as the controller');
    }
}
