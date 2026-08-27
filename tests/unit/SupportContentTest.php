<?php
use PHPUnit\Framework\TestCase;

/**
 * Support & content tests (Session 13) — tickets, FAQ/blog content models and
 * route/controller guarantees.
 */
class SupportContentTest extends TestCase
{
    private static $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(dirname(__DIR__));
        if (!defined('BASEPATH')) define('BASEPATH', self::$root.'/system/');
        if (!class_exists('CI_Model')) eval('class CI_Model {}');
        if (!function_exists('get_instance')) eval('function &get_instance(){ return $GLOBALS["__fake_ci"]; }');
        if (!function_exists('log_message')) eval('function log_message($l,$m){}');
        if (!function_exists('marvy_public_id')) require_once self::$root.'/application/helpers/marvy_helper.php';
        require_once self::$root.'/application/libraries/TicketService.php';
    }

    /* --------------------------- validation -------------------------- */

    public function testOpenRejectsEmptySubjectOrMessage()
    {
        $ci = $this->fresh();
        $svc = new TicketService();
        $r = $svc->open($ci->user, array('subject'=>'','message'=>'hi'));
        $this->assertFalse($r['ok']); $this->assertSame('BAD_SUBJECT', $r['code']);
        $r = $svc->open($ci->user, array('subject'=>'Hi','message'=>''));
        $this->assertFalse($r['ok']); $this->assertSame('BAD_MESSAGE', $r['code']);
    }

    public function testOpenRejectsUnknownOrder()
    {
        $ci = $this->fresh();
        $svc = new TicketService();
        $r = $svc->open($ci->user, array('subject'=>'x','message'=>'y','order_id'=>'nope'));
        $this->assertFalse($r['ok']); $this->assertSame('NO_ORDER', $r['code']);
    }

    public function testOpenCreatesTicketAndFirstMessage()
    {
        $ci = $this->fresh();
        $svc = new TicketService();
        $r = $svc->open($ci->user, array('subject'=>'My order','message'=>'Please help','priority'=>'HIGH'));
        $this->assertTrue($r['ok'], $r['error'] ?? '');
        $this->assertSame('OPEN', $r['ticket']->status);
        $this->assertSame('HIGH', $r['ticket']->priority);
        $this->assertSame(1, $ci->inserts['tickets']);
        $this->assertSame(1, $ci->inserts['ticket_messages']);
    }

    public function testReplyReopensClosedTicket()
    {
        $ci = $this->fresh();
        $ci->ticket->status = 'CLOSED';
        $svc = new TicketService();
        $r = $svc->reply('TKT1', $ci->user, 'One more thing');
        $this->assertTrue($r['ok']);
        $this->assertSame('OPEN', $r['ticket']->status);
        $this->assertNull($r['ticket']->closed_at);
        $this->assertSame(1, $ci->inserts['ticket_messages']);
    }

    public function testReplyRejectsEmptyBody()
    {
        $ci = $this->fresh();
        $svc = new TicketService();
        $r = $svc->reply('TKT1', $ci->user, '   ');
        $this->assertFalse($r['ok']);
        $this->assertSame('BAD_MESSAGE', $r['code']);
    }

    public function testCustomerCanOnlySeeOwnTickets()
    {
        $src = file_get_contents(self::$root.'/application/models/Ticket_model.php');
        $this->assertStringContainsString("where('public_id', \$public_id)", $src);
        $this->assertStringContainsString("where('user_id', \$user_id)", $src);
        $ctrl = file_get_contents(self::$root.'/application/controllers/dashboard/Tickets.php');
        $this->assertStringContainsString('find_public_for_user', $ctrl);
    }

    public function testInternalNotesNeverExposedToCustomer()
    {
        $model = file_get_contents(self::$root.'/application/models/Ticket_message_model.php');
        $this->assertStringContainsString('is_internal_note', $model);
        $this->assertStringContainsString("where('is_internal_note', 0)", $model);
    }

    /* ----------------------------- content --------------------------- */

    public function testFaqOnlyReturnsActiveSorted()
    {
        $src = file_get_contents(self::$root.'/application/models/Faq_model.php');
        $this->assertStringContainsString("where('is_active', 1)", $src);
        $this->assertStringContainsString('order_by', $src);
    }

    public function testBlogOnlyReturnsPublished()
    {
        $src = file_get_contents(self::$root.'/application/models/Blog_post_model.php');
        $this->assertStringContainsString("'PUBLISHED'", $src);
        $this->assertStringContainsString('published_at <=', $src);
    }

    public function testAnnouncementsRespectWindowAndAudience()
    {
        $src = file_get_contents(self::$root.'/application/models/Announcement_model.php');
        $this->assertStringContainsString('starts_at', $src);
        $this->assertStringContainsString('ends_at', $src);
        $this->assertStringContainsString('audience', $src);
    }

    /* ------------------------------ routing --------------------------- */

    public function testTicketRoutesOrderedBeforeCatchAll()
    {
        $routes = file_get_contents(self::$root.'/application/config/routes.php');
        $this->assertStringContainsString("'dashboard/tickets/create'", $routes);
        $this->assertStringContainsString("'dashboard/tickets/(:any)/reply'", $routes);
        $reply = strpos($routes, "'dashboard/tickets/(:any)/reply'");
        $catch = strpos($routes, "'dashboard/tickets/(:any)'");
        $this->assertNotFalse($reply); $this->assertNotFalse($catch);
        $this->assertLessThan($catch, $reply);
    }

    public function testBlogRoutesAndControllerExist()
    {
        $routes = file_get_contents(self::$root.'/application/config/routes.php');
        $this->assertStringContainsString("'blog'] = 'blog/index'", $routes);
        $this->assertStringContainsString("'blog/(:any)'", $routes);
        $this->assertFileExists(self::$root.'/application/controllers/Blog.php');
        $this->assertFileExists(self::$root.'/application/views/public/blog/list.php');
        $this->assertFileExists(self::$root.'/application/views/public/blog/detail.php');
    }

    public function testFaqUsesLiveDataAndCustomerTicketLink()
    {
        $home = file_get_contents(self::$root.'/application/controllers/Home.php');
        $this->assertStringContainsString('Faq_model', $home);
        $faq = file_get_contents(self::$root.'/application/views/public/faq.php');
        $this->assertStringContainsString('dashboard/tickets', $faq);
        $this->assertStringContainsString('<details', $faq);
    }

    public function testAnnouncementsRenderedInPublicLayout()
    {
        $partial = file_get_contents(self::$root.'/application/views/partials/announcement_bar.php');
        $this->assertStringContainsString('Announcement_model', $partial);
        $this->assertStringContainsString('visible(', $partial);
        $this->assertStringContainsString('get_instance()', $partial,
            'views must not resolve models through $this (CI_Loader has no __get)');
        // The bar is a marquee ticker (design-system: .ws-announce-track,
        // paused on hover/focus and disabled under prefers-reduced-motion).
        $this->assertStringContainsString('ws-announce-track', $partial);
        $this->assertStringContainsString('ws-announce-item', $partial);
        $this->assertStringContainsString('aria-label="Announcements"', $partial);

        $css = file_get_contents(self::$root.'/assets/css/design-system.css');
        $this->assertStringContainsString('.ws-announce-track', $css);
        $this->assertStringContainsString('prefers-reduced-motion', $css);
    }

    public function testAnnouncementBarIsIncludedInEveryLayout()
    {
        // The component is partials/announcement (which forwards to
        // partials/announcement_bar). The public shell renders it through
        // partials/header; auth and app load it directly.
        $wrapper = file_get_contents(self::$root.'/application/views/partials/announcement.php');
        $this->assertStringContainsString('partials/announcement_bar', $wrapper);
        $header = file_get_contents(self::$root.'/application/views/partials/header.php');
        $this->assertStringContainsString('partials/announcement', $header);
        foreach (array('main.php', 'app.php', 'auth.php') as $layout) {
            $src = file_get_contents(self::$root.'/application/views/layouts/'.$layout);
            $this->assertTrue(
                strpos($src, 'partials/announcement') !== false || strpos($src, 'partials/header') !== false,
                $layout.' must render the announcement component');
        }
    }

    public function testTicketActionsArePostAndCsrfProtected()
    {
        $src = file_get_contents(self::$root.'/application/controllers/dashboard/Tickets.php');
        $this->assertSame(3, substr_count($src, "method(true) !== 'POST') show_404()"));
        $views = array(
            self::$root.'/application/views/dashboard/tickets/index.php',
            self::$root.'/application/views/dashboard/tickets/detail.php',
        );
        foreach ($views as $v) {
            $html = file_get_contents($v);
            // Either spelling protects the post: form_open() emits the hidden
            // CSRF field itself, which is why the reply/close/reopen forms in
            // detail.php no longer hand-roll it (a hand-rolled <form> is how
            // one of them ended up mis-nested with its neighbour).
            $this->assertTrue(
                strpos($html, 'csrf_token_name') !== false || strpos($html, 'form_open(') !== false,
                basename($v).' posts must carry a CSRF token');
            $this->assertStringNotContainsString('<form method="post"', $html,
                basename($v).': hand-rolled form tags miss the token form_open() adds');
        }
    }

    /* ------------------------------ fakes ----------------------------- */

    private function fresh() {
        $ci = new ScFakeCI();
        $GLOBALS['__fake_ci'] = $ci;
        return $ci;
    }
}

#[AllowDynamicProperties]
class ScFakeCI {
    public $user, $ticket, $order, $db, $load, $inserts=array();
    public function __construct() {
        // Register before constructing anything that calls get_instance()
        // inside its own constructor (the real libraries below do).
        $GLOBALS['__fake_ci'] = $this;
        $this->user = (object)array('id'=>7,'status'=>'ACTIVE');
        $this->ticket = (object)array('id'=>21,'public_id'=>'TKT1','user_id'=>7,'status'=>'OPEN','closed_at'=>null);
        $this->order = (object)array('id'=>9,'public_id'=>'ORD1','user_id'=>7);
        $this->db = new ScFakeDb($this);
        $this->load = new ScFakeLoader();
        $this->Ticket_model = new ScFakeTicketModel($this);
        $this->Ticket_message_model = new ScFakeMessageModel($this);
        $this->Order_model = new ScFakeOrderModel($this);
    }
}
class ScFakeLoader { function model($n){} function library($n){} }
class ScFakeDb {
    private $ci;
    public function __construct($ci){$this->ci=$ci;}
    public function trans_start(){} public function trans_complete(){} public function trans_status(){return true;}
    public function where($k,$v=null){return $this;}
    public function where_in($k,$v){return $this;}
    public function order_by($k,$d='ASC'){return $this;}
    public function limit($l,$o=0){return $this;}
    public function insert($t,$d=array()){
        $this->ci->inserts[$t]=($this->ci->inserts[$t]??0)+1;
        if ($t==='tickets') $this->ci->ticket=(object)array_merge((array)$this->ci->ticket,$d);
        if ($t==='ticket_messages') $this->ci->message=(object)array_merge(array('id'=>5), $d);
        return true;
    }
    public function insert_batch($t,$rows){ return true; }
    public function update($t,$d){ if($t==='tickets') foreach($d as $k=>$v) $this->ci->ticket->$k=$v; return true; }
    public function get($t=null){ return new ScFakeResult($t==='orders'?$this->ci->order:null); }
    public function insert_id(){ return 5; }
}
class ScFakeResult {
    private $row; public $rows;
    public function __construct($r){$this->row=$r;$this->rows=$r?array($r):array();}
    public function row(){return $this->row;} public function result(){return $this->rows;}
}
class ScFakeTicketModel {
    private $ci; function __construct($ci){$this->ci=$ci;}
    function find_public_for_user($p,$u){
        return ($p === $this->ci->ticket->public_id && (int)$u === (int)$this->ci->ticket->user_id)
            ? $this->ci->ticket : null;
    }
    function create($d){$this->ci->db->insert('tickets',$d);return $this->ci->ticket;}
    function touch($id,$e=array()){foreach($e as $k=>$v)$this->ci->ticket->$k=$v;}
    function close($id){$this->ci->ticket->status='CLOSED';}
    function find_by_id($id){return $this->ci->ticket;}
}
class ScFakeMessageModel {
    private $ci; function __construct($ci){$this->ci=$ci;}
    function create($d){$this->ci->db->insert('ticket_messages',$d);return $this->ci->message;}
}
class ScFakeOrderModel {
    private $ci; function __construct($ci){$this->ci=$ci;}
    function find_public_for_user($p,$u){return ($p==='ORD1' && $u===$this->ci->user->id)?$this->ci->order:null;}
    function find_by_id($id){return $this->ci->order;}
}
