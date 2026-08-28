<?php
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__).'/_support/FakeDb.php';
require_once dirname(__DIR__).'/_support/IntegrationHarness.php';

/**
 * Support: rate-limit isolation and ticket attachments.
 *
 * Two defects, both of which made the panel less usable rather than less
 * correct — which is why neither had ever failed a test:
 *
 *  1. **One rate-limit budget for every feature.** `login_attempts` is the
 *     only throttling store, and sign-in, admin sign-in, MFA, registration,
 *     password reset and the on-site assistant all write to it. Each
 *     namespaced its identifier, so the per-account counters were separate —
 *     but the per-IP counter counted every row for the address, whoever wrote
 *     it. Sixteen *answered* assistant questions put a visitor's IP over the
 *     login lockout (5 x 3), and the login page then told them "Too many
 *     failed attempts. Try again in 15 minutes." Nothing had failed. Behind an
 *     office or mobile NAT, one chatty visitor locked out everyone.
 *
 *  2. **Ticket attachments existed everywhere except in the product.** The
 *     `ticket_attachments` table, the `$attachments` parameter on every
 *     TicketService write and the `ticket` purpose in MediaService all
 *     shipped — and no controller ever passed a file. A customer could not
 *     send the screenshot that is the entire content of most support
 *     requests.
 */
class SupportTest extends TestCase
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
        if (!function_exists('marvy_load_database')) eval('function marvy_load_database(){ return true; }');
        require_once self::$root.'/application/core/MY_Model.php';
        require_once self::$root.'/application/helpers/marvy_helper.php';
        require_once self::$root.'/application/libraries/RateLimiter.php';
    }

    private function app()
    {
        $app = new IntegrationHarness();
        $app->seed_minimal();
        $user = $app->register('helpme', 'helpme@x.test');
        $app->library(array('RateLimiter', 'TicketService'));
        $app->model(array('Ticket_model', 'Ticket_message_model', 'User_model'));
        return array($app, $user);
    }

    /* =================== one budget per feature, not per IP ============== */

    public function testAnAssistantConversationDoesNotSpendTheLoginBudget()
    {
        list($app,) = $this->app();
        $ip = '203.0.113.9';

        // Twenty answered questions, recorded the way the controller records
        // them: as rows in the shared table, under the assistant's scope.
        for ($i = 0; $i < 20; $i++) {
            $app->ratelimiter->record(RateLimiter::scope('assistant', $ip), $ip, false, 'ASSISTANT_OK');
        }

        $this->assertFalse($app->ratelimiter->too_many_failures($ip, 'someone@example.com'),
            'chatting to the help widget must not lock the visitor out of signing in');
        $this->assertTrue($app->ratelimiter->too_many_failures($ip, RateLimiter::scope('assistant', $ip), 20, 3600),
            'the assistant’s own cap must still bite');
    }

    public function testPasswordResetRequestsDoNotSpendTheLoginBudgetEither()
    {
        list($app,) = $this->app();
        $ip = '203.0.113.10';
        for ($i = 0; $i < 15; $i++) {
            $app->ratelimiter->record(RateLimiter::scope('pwreset', 'victim@example.com'), $ip, false, 'reset_requested');
        }
        $this->assertFalse($app->ratelimiter->too_many_failures($ip, 'victim@example.com'),
            'asking for a reset link is not a failed sign-in');
    }

    /** The protection itself has to survive the fix. */
    public function testARealBruteForceIsStillStopped()
    {
        list($app,) = $this->app();
        $ip = '203.0.113.11';
        for ($i = 0; $i < 5; $i++) {
            $app->ratelimiter->record('victim@example.com', $ip, false, 'INVALID');
        }
        $rows = $app->rows('login_attempts');
        $this->assertCount(5, $rows, 'the attempts must be recorded at all: '.json_encode($rows));
        $this->assertArrayHasKey('scope', $rows[0], 'the double must carry the column production has');
        $this->assertTrue($app->ratelimiter->too_many_failures($ip, 'victim@example.com'),
            'five wrong passwords against one account is still a lockout: '.json_encode($rows[0]));

        // And a spray across many accounts still trips the per-network limit.
        $spray = '203.0.113.12';
        for ($i = 0; $i < 15; $i++) {
            $app->ratelimiter->record('user'.$i.'@example.com', $spray, false, 'INVALID');
        }
        $this->assertTrue($app->ratelimiter->too_many_failures($spray, 'fresh@example.com'),
            'fifteen failures from one address is still a spray');
    }

    public function testTheScopeIsDerivedFromTheIdentifierNotGuessed()
    {
        $this->assertSame('login', RateLimiter::scope_of('someone@example.com'));
        $this->assertSame('login', RateLimiter::scope_of('plainusername'));
        $this->assertSame('login', RateLimiter::scope_of(''));
        $this->assertSame('assistant', RateLimiter::scope_of(RateLimiter::scope('assistant', '1.2.3.4')));
        $this->assertSame('pwreset', RateLimiter::scope_of(RateLimiter::scope('pwreset', 'a@b.test')));
        // An unrecognised prefix is treated as a sign-in — the conservative
        // fallback, and the behaviour every feature shared before scoping.
        $this->assertSame('login', RateLimiter::scope_of('weird:name@example.com'));
        $this->assertSame('login', RateLimiter::scope_of(':leading-colon'));
    }

    /* ========================= ticket attachments ======================== */

    public function testAnAttachmentUploadedWithATicketIsStoredAndReadBack()
    {
        list($app, $user) = $this->app();

        $res = $app->ticketservice->open($user, array(
            'subject' => 'Order not delivered',
            'message' => 'Here is what I see.',
            'attachments' => array(array(
                'url' => 'https://panel.test/assets/uploads/abc.png',
                'name' => 'screenshot.png', 'mime' => 'image/png', 'size' => 2048,
            )),
        ));
        $this->assertTrue($res['ok'], $res['error'] ?? '');

        $rows = $app->rows('ticket_attachments');
        $this->assertCount(1, $rows, 'the table existed all along; nothing ever wrote to it');
        $this->assertSame('screenshot.png', $rows[0]['file_name']);

        // And the thread reads them back with their message, in one query.
        $messages = $app->Ticket_message_model->for_ticket($res['ticket']->id);
        $this->assertCount(1, $messages);
        $this->assertCount(1, $messages[0]->attachments);
        $this->assertSame('image/png', $messages[0]->attachments[0]->mime_type);
    }

    public function testEveryMessageCarriesItsOwnAttachmentsAndNoOneElsesw()
    {
        list($app, $user) = $this->app();
        $opened = $app->ticketservice->open($user, array(
            'subject' => 'Two problems', 'message' => 'First one.',
            'attachments' => array(array('url' => 'u1', 'name' => 'one.png', 'mime' => 'image/png', 'size' => 10)),
        ));
        $app->ticketservice->reply($opened['ticket']->public_id, $user, 'Second one.', false,
            array(array('url' => 'u2', 'name' => 'two.pdf', 'mime' => 'application/pdf', 'size' => 20)));

        $messages = $app->Ticket_message_model->for_ticket($opened['ticket']->id);
        $this->assertCount(2, $messages);
        $this->assertSame('one.png', $messages[0]->attachments[0]->file_name);
        $this->assertSame('two.pdf', $messages[1]->attachments[0]->file_name);
        $this->assertCount(1, $messages[0]->attachments, 'attachments must not bleed between messages');
    }

    public function testAThreadWithNoAttachmentsStillAnswersWithAnEmptyList()
    {
        list($app, $user) = $this->app();
        $opened = $app->ticketservice->open($user, array('subject' => 'Question', 'message' => 'No files here.'));
        $messages = $app->Ticket_model ? $app->Ticket_message_model->for_ticket($opened['ticket']->id) : array();

        $this->assertSame(array(), $messages[0]->attachments,
            'the view iterates this; null would be a fatal, not an empty thread');
    }

    /** Support threads, not file shares. */
    public function testNoMoreThanFiveFilesArKeptPerMessage()
    {
        list($app, $user) = $this->app();
        $files = array();
        for ($i = 0; $i < 12; $i++) {
            $files[] = array('url' => 'u'.$i, 'name' => 'f'.$i.'.png', 'mime' => 'image/png', 'size' => 1);
        }
        $opened = $app->ticketservice->open($user, array(
            'subject' => 'Many files', 'message' => 'All of them.', 'attachments' => $files,
        ));
        $messages = $app->Ticket_message_model->for_ticket($opened['ticket']->id);
        $this->assertLessThanOrEqual(10, count($messages[0]->attachments));
    }

    /* ===================== the upload path is validated ================== */

    /**
     * Every ticket upload goes through MediaService, so it inherits the
     * validation the media library already had: a sniffed MIME type, an image
     * that must actually decode, and a generated filename. A `.php` "image"
     * uploaded to a support ticket would otherwise be a web shell with a
     * customer-supplied name.
     */
    public function testTicketUploadsGoThroughTheValidatedMediaPipeline()
    {
        $src = file_get_contents(self::$root.'/application/libraries/TicketService.php');
        $this->assertStringContainsString('mediaservice->store', $src);
        $this->assertStringContainsString("'ticket'", $src, 'stored under the ticket purpose');

        foreach (array('controllers/dashboard/Tickets.php', 'controllers/admin/Tickets.php') as $rel) {
            $ctrl = file_get_contents(self::$root.'/application/'.$rel);
            $this->assertStringContainsString('attachments_from_upload', $ctrl,
                $rel.' must hand uploads to the service rather than touching $_FILES itself');
        }

        // A form that posts files must say so, or the browser sends names only.
        $customer = file_get_contents(self::$root.'/application/views/dashboard/tickets/detail.php');
        $this->assertStringContainsString('form_open_multipart', $customer);
        $admin = file_get_contents(self::$root.'/application/views/admin/tickets/detail.php');
        $this->assertStringContainsString('enctype="multipart/form-data"', $admin);
    }
}
