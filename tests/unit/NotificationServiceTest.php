<?php
use PHPUnit\Framework\TestCase;

/**
 * NotificationService — the panel telling a customer something happened.
 *
 * The tables (`notifications`, `notification_preferences`), the bell in the
 * topbar, the Notifications page and six seeded email templates all existed,
 * and nothing ever wrote a row or sent any of them bar the verification link:
 * orders completed, deposits credited and support replies arrived in silence.
 *
 * These tests pin the contract everything now goes through, and above all the
 * rule that makes it safe to call from inside business code: notifying can
 * fail, and when it does the caller must never know.
 */
class NotificationServiceTest extends TestCase
{
    private static $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(dirname(__DIR__));
        if (!defined('BASEPATH')) define('BASEPATH', self::$root.'/system/');
        if (!defined('APPPATH')) define('APPPATH', self::$root.'/application/');
        if (!function_exists('get_instance')) {
            eval('function &get_instance(){ return $GLOBALS["__fake_ci"]; }');
        }
        if (!function_exists('log_message')) eval('function log_message($l,$m){}');
        if (!function_exists('site_url')) eval('function site_url($p=""){ return "https://panel.example/".$p; }');
        if (!function_exists('marvy_public_id')) require_once self::$root.'/application/helpers/marvy_helper.php';
        require_once self::$root.'/application/libraries/NotificationService.php';
    }

    private function boot(array $options = array())
    {
        $ci = new NotifFakeCI($options);
        $GLOBALS['__fake_ci'] = $ci;
        return $ci;
    }

    /* ------------------------------ happy path --------------------------- */

    public function testAnEventWritesTheInboxRowAndQueuesTheEmail()
    {
        $ci = $this->boot();
        $res = (new NotificationService())->notify(7, 'order.completed',
            'Order ORD1 for Instagram Followers is complete.',
            array('order_id' => 'ORD1'), array('order_id' => 'ORD1'));

        $this->assertTrue($res['in_app']);
        $this->assertTrue($res['email']);

        $row = $ci->notifications[0];
        $this->assertSame(7, $row['user_id']);
        $this->assertSame('order.completed', $row['type']);
        $this->assertSame('Order completed', $row['title'], 'the inbox title comes from the event map');
        $this->assertSame(0, $row['is_read']);
        $this->assertStringContainsString('ORD1', $row['data'], 'the reference is kept for the link');

        $this->assertSame('order.completed', $ci->emails[0]['template']);
        $this->assertSame('buyer@example.com', $ci->emails[0]['to']);
        $this->assertSame('buyer', $ci->emails[0]['vars']['username'],
            'the username is always available to a template');
    }

    public function testAnInPanelOnlyEventSendsNoEmail()
    {
        $ci = $this->boot();
        $res = (new NotificationService())->notify(7, 'order.canceled', 'Order ORD9 was cancelled.');

        $this->assertTrue($res['in_app']);
        $this->assertFalse($res['email']);
        $this->assertSame(array(), $ci->emails);
    }

    public function testAnUnknownEventTypeIsRefusedRatherThanGuessed()
    {
        $ci = $this->boot();
        $res = (new NotificationService())->notify(7, 'order.exploded', 'boom');

        $this->assertFalse($res['in_app']);
        $this->assertFalse($res['email']);
        $this->assertSame(array(), $ci->notifications);
    }

    /* ------------------------------ preferences -------------------------- */

    public function testAMissingPreferenceRowMeansBothChannelsOn()
    {
        $this->boot();
        $prefs = (new NotificationService())->preferences(7, 'order.completed');
        $this->assertTrue($prefs['in_app']);
        $this->assertTrue($prefs['email']);
    }

    public function testACustomerCanTurnOffTheEmailAndKeepTheInboxEntry()
    {
        $ci = $this->boot(array('prefs' => array('order.completed' => array('in_app' => 1, 'email' => 0))));
        $res = (new NotificationService())->notify(7, 'order.completed', 'done');

        $this->assertTrue($res['in_app']);
        $this->assertFalse($res['email']);
        $this->assertCount(1, $ci->notifications);
        $this->assertSame(array(), $ci->emails);
    }

    public function testACustomerCanTurnOffBothChannels()
    {
        $ci = $this->boot(array('prefs' => array('payment.credited' => array('in_app' => 0, 'email' => 0))));
        (new NotificationService())->notify(7, 'payment.credited', 'credited');

        $this->assertSame(array(), $ci->notifications);
        $this->assertSame(array(), $ci->emails);
    }

    public function testTheGlobalSwitchStopsEmailButNotTheInbox()
    {
        $ci = $this->boot(array('settings' => array('notification_emails_enabled' => false)));
        $res = (new NotificationService())->notify(7, 'payment.credited', 'credited');

        $this->assertTrue($res['in_app'], 'the in-app inbox is not part of the email switch');
        $this->assertFalse($res['email']);
    }

    /* --------------------------- failure isolation ----------------------- */

    public function testAMailFailureNeverBreaksTheCaller()
    {
        // The order is already complete when this runs. An exception here would
        // roll back a state change that has genuinely happened.
        $ci = $this->boot(array('mail_throws' => true));
        $res = (new NotificationService())->notify(7, 'order.completed', 'done');

        $this->assertTrue($res['in_app'], 'the inbox entry still lands');
        $this->assertFalse($res['email']);
    }

    public function testADatabaseFailureNeverBreaksTheCaller()
    {
        $ci = $this->boot(array('db_throws' => true));
        $res = (new NotificationService())->notify(7, 'order.completed', 'done');

        $this->assertFalse($res['in_app']);
        $this->assertTrue($res['email'], 'a broken inbox write must not stop the email');
    }

    public function testAUserWithoutAnEmailAddressIsSkippedQuietly()
    {
        $ci = $this->boot(array('user' => null));
        $res = (new NotificationService())->notify(7, 'order.completed', 'done');
        $this->assertFalse($res['email']);
        $this->assertTrue($res['in_app']);
    }

    /* ------------------------------- wiring ------------------------------ */

    public function testEveryEmailTemplateItReferencesIsSeeded()
    {
        $seeder = file_get_contents(self::$root.'/application/seeds/Core_seeder.php');
        foreach (NotificationService::EVENTS as $type => $meta) {
            if ($meta[1] === null) continue;
            $this->assertStringContainsString("'".$meta[1]."'", $seeder,
                'template '.$meta[1].' is referenced but never seeded');
        }
    }

    public function testTheEventsAreActuallyRaisedByTheCodeThatOwnsThem()
    {
        // A notification service nothing calls is the state this module was in.
        $order   = file_get_contents(self::$root.'/application/libraries/OrderService.php');
        $payment = file_get_contents(self::$root.'/application/libraries/PaymentService.php');
        $ticket  = file_get_contents(self::$root.'/application/libraries/TicketService.php');

        $this->assertStringContainsString("'order.completed'", $order);
        $this->assertStringContainsString("'order.partial'", $order);
        $this->assertStringContainsString("notificationservice->notify", $order);
        $this->assertStringContainsString("'payment.credited'", $payment);
        $this->assertStringContainsString("'ticket.replied'", $ticket);
    }

    public function testAnInternalStaffNoteNeverNotifiesTheCustomer()
    {
        $src = file_get_contents(self::$root.'/application/libraries/TicketService.php');
        $this->assertMatchesRegularExpression('~if \(!\$internal\) \{[\s\S]{0,600}ticket\.replied~', $src,
            'an internal note must not tell the customer support replied');
    }
}

/* -------------------------------- doubles -------------------------------- */

#[AllowDynamicProperties]
class NotifFakeCI
{
    public $db, $load, $mailservice, $Setting_model, $Notification_model;
    public $notifications = array(), $emails = array();
    public $options;

    public function __construct(array $options)
    {
        $this->options = $options + array('prefs' => array(), 'settings' => array(),
                                          'mail_throws' => false, 'db_throws' => false,
                                          'user' => 'default');
        $this->db = new NotifFakeDb($this);
        $this->load = new NotifFakeLoader($this);
        $this->mailservice = new NotifFakeMail($this);
        $this->Setting_model = new NotifFakeSettings($this);
        $this->Notification_model = new stdClass();
    }
}

class NotifFakeLoader
{
    private $ci;
    public function __construct($ci) { $this->ci = $ci; }
    public function model($n = null) {}
    public function library($n = null) {}
}

class NotifFakeSettings
{
    private $ci;
    public function __construct($ci) { $this->ci = $ci; }
    public function get($key, $default = null)
    {
        return array_key_exists($key, $this->ci->options['settings'])
            ? $this->ci->options['settings'][$key] : $default;
    }
}

class NotifFakeMail
{
    private $ci;
    public function __construct($ci) { $this->ci = $ci; }
    public function enqueue_template($to, $template, array $vars = array(), $name = null)
    {
        if ($this->ci->options['mail_throws']) throw new RuntimeException('smtp exploded');
        $this->ci->emails[] = array('to' => $to, 'template' => $template, 'vars' => $vars, 'name' => $name);
        return true;
    }
}

class NotifFakeDb
{
    private $ci;
    private $where = array();
    public function __construct($ci) { $this->ci = $ci; }

    public function select($s, $e = null) { return $this; }
    public function where($k, $v = null)
    {
        if (is_array($k)) { foreach ($k as $kk => $vv) $this->where[$kk] = $vv; }
        else $this->where[$k] = $v;
        return $this;
    }
    public function get($table)
    {
        $where = $this->where; $this->where = array();
        if ($table === 'notification_preferences') {
            $type = $where['type'] ?? '';
            $prefs = $this->ci->options['prefs'];
            return new NotifFakeResult(isset($prefs[$type]) ? (object)$prefs[$type] : null);
        }
        if ($table === 'users') {
            if ($this->ci->options['user'] === null) return new NotifFakeResult(null);
            return new NotifFakeResult((object)array(
                'email' => 'buyer@example.com', 'username' => 'buyer',
                'first_name' => 'Ada', 'last_name' => 'Obi',
            ));
        }
        return new NotifFakeResult(null);
    }
    public function insert($table, array $data)
    {
        if ($this->ci->options['db_throws']) throw new RuntimeException('table is gone');
        if ($table === 'notifications') $this->ci->notifications[] = $data;
        return true;
    }
}

class NotifFakeResult
{
    private $row;
    public function __construct($row) { $this->row = $row; }
    public function row() { return $this->row; }
}
