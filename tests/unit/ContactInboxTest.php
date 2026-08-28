<?php
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__).'/_support/FakeDb.php';
require_once dirname(__DIR__).'/_support/IntegrationHarness.php';

/**
 * The contact inbox — the anonymous half of support.
 *
 * A visitor's contact-form message used to exist only as an email_queue row
 * bound for the operator's mailbox: the "Customer messages" screen could list
 * its subject but not show it, and there was no way to answer from the panel
 * at all. With contact_messages the dashboard holds both halves of the
 * conversation, and these tests pin the reply path: queued (never sent
 * inline), recorded on the row, placeholders honoured, and refusal without a
 * usable reply or address.
 */
class ContactInboxTest extends TestCase
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
        $app->model(array('User_model'));
        $app->library(array('TicketService'));
        return $app;
    }

    private function message($app, array $over = array())
    {
        $app->db->insert('contact_messages', array_merge(array(
            'public_id'  => 'CMSG'.substr(bin2hex(random_bytes(8)), 0, 22),
            'name'       => 'Ada Visitor',
            'email'      => 'ada@example.test',
            'subject'    => 'Do you deliver to Nigeria?',
            'department' => 'orders',
            'message'    => 'Hello, do your services work for Nigerian accounts?',
            'ip'         => '127.0.0.1',
            'status'     => 'NEW',
            'created_at' => gmdate('Y-m-d H:i:s'),
        ), $over));
        $id = $app->db->insert_id();
        return $app->db->where('id', $id)->get('contact_messages')->row();
    }

    public function testAReplyIsQueuedAndBothHalvesAreKept()
    {
        $app = $this->app();
        $row = $this->message($app);
        $staff = $app->register('agent', 'agent@x.test', 'Str0ng!pass1', 'STAFF');

        $res = $app->ticketservice->contact_reply($row, $staff, '', 'Yes we do, {{name}} — every service listed on the site works for NG accounts.');
        $this->assertNotEmpty($res['ok'], $res['error'] ?? '');

        // Queued to the visitor, subject defaulting to the thread's.
        $this->assertCount(1, $app->sent_mail);
        $mail = $app->sent_mail[0];
        $this->assertSame('ada@example.test', $mail['to']);
        $this->assertSame('Re: Do you deliver to Nigeria?', $mail['subject']);

        // The row holds the whole conversation, not just the inbound half.
        $saved = $app->db->where('id', $row->id)->get('contact_messages')->row();
        $this->assertSame('REPLIED', $saved->status);
        $this->assertNotEmpty($saved->replied_at);
        $this->assertSame((int)$staff->id, (int)$saved->replied_by_id);
        $this->assertStringContainsString('Yes we do, Ada Visitor', (string)$saved->reply_body,
            'placeholders are filled when the reply is stored');
    }

    public function testPlaceholdersInSubjectAndBodyResolveAndUnknownOnesAreStripped()
    {
        $app = $this->app();
        $row = $this->message($app);
        $staff = $app->register('agent', 'agent@x.test', 'Str0ng!pass1', 'STAFF');

        $res = $app->ticketservice->contact_reply($row, $staff,
            'Re: {{subject}} (from {{site_name}})',
            'Thanks {{name}}! {{totally_unknown}}');
        $this->assertNotEmpty($res['ok']);

        $this->assertSame('Re: Do you deliver to Nigeria? (from MarvySocials)',
            $app->sent_mail[0]['subject']);
        $saved = $app->db->where('id', $row->id)->get('contact_messages')->row();
        $this->assertSame('Thanks Ada Visitor! ', (string)$saved->reply_body,
            'an unknown placeholder must not leak to the visitor');
    }

    public function testAnEmptyReplyIsRefusedAndNothingIsSent()
    {
        $app = $this->app();
        $row = $this->message($app);
        $staff = $app->register('agent', 'agent@x.test', 'Str0ng!pass1', 'STAFF');

        $res = $app->ticketservice->contact_reply($row, $staff, '', '   ');
        $this->assertEmpty($res['ok']);
        $this->assertSame('BAD_BODY', $res['code']);
        $this->assertSame(array(), $app->sent_mail);
        $this->assertSame('NEW', $app->db->where('id', $row->id)->get('contact_messages')->row()->status);
    }

    public function testAMessageWithNoValidAddressCannotBeAnswered()
    {
        $app = $this->app();
        $row = $this->message($app, array('email' => 'not-an-address'));
        $staff = $app->register('agent', 'agent@x.test', 'Str0ng!pass1', 'STAFF');

        $res = $app->ticketservice->contact_reply($row, $staff, '', 'Hello?');
        $this->assertSame('BAD_EMAIL', $res['code']);
        $this->assertSame(array(), $app->sent_mail);
    }

    public function testASecondReplyReplacesTheStoredOne()
    {
        $app = $this->app();
        $row = $this->message($app);
        $staff = $app->register('agent', 'agent@x.test', 'Str0ng!pass1', 'STAFF');

        $app->ticketservice->contact_reply($row, $staff, '', 'First attempt.');
        $res = $app->ticketservice->contact_reply($row, $staff, '', 'Better answer.');
        $this->assertNotEmpty($res['ok']);

        $saved = $app->db->where('id', $row->id)->get('contact_messages')->row();
        $this->assertSame('Better answer.', (string)$saved->reply_body);
        $this->assertCount(2, $app->sent_mail, 'both emails were queued');
    }

    public function testTheReplyActionIsPostOnlyBehindTicketsReply()
    {
        $routes = file_get_contents(self::$root.'/application/config/routes.php');
        $this->assertStringContainsString(
            "\$route['admin/messages/reply/(:num)'] = 'admin/tickets/message_reply/\$1';", $routes);

        $src = file_get_contents(self::$root.'/application/controllers/admin/Tickets.php');
        $this->assertStringContainsString("guard_message(\$id, 'tickets.reply')", $src,
            'the reply must be POST-only behind tickets.reply');
        $this->assertStringContainsString("'contact.replied'", $src,
            'the reply must be audited');

        // The screen answers with the conversation, never a bare subject list.
        $view = file_get_contents(self::$root.'/application/views/admin/tickets/messages.php');
        $this->assertStringContainsString('name="message"', $view, 'a reply form is rendered');
        $this->assertStringContainsString('get_csrf_token_name()', $view, 'POST forms carry CSRF');
        $this->assertStringContainsString('data-ws-template-for', $view, 'the template picker is offered');
    }
}
