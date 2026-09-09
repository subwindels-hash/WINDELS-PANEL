<?php
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__).'/_support/ShellSource.php';
require_once dirname(__DIR__).'/_support/FakeDb.php';
require_once dirname(__DIR__).'/_support/IntegrationHarness.php';

/**
 * Private support attachments (module 17).
 *
 * Support attachments used to be ordinary files under `assets/uploads`, served
 * by the web server. The only protection was a 32-hex-character filename —
 * unguessable, but not authorised. Anyone who ever came into possession of the
 * URL kept the file for ever, and a support attachment is where customers put
 * bank statements, ID photographs and payment screenshots. Closing the ticket,
 * refunding the order or deleting the account changed nothing.
 *
 * These tests pin the three things that make that untrue now: the bytes are
 * written outside the document root, the recorded URL is an authorising route,
 * and the rule that route applies is explicit and refuses the cases that
 * matter.
 */
class PrivateAttachmentsTest extends TestCase
{
    private static $root;
    private $tmpdir;
    private $storage;
    private $storage_was;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(dirname(__DIR__));
        if (!defined('BASEPATH')) define('BASEPATH', self::$root.'/system/');
        if (!defined('FCPATH')) define('FCPATH', sys_get_temp_dir().'/marvy_private_attach/');
        if (!defined('APPPATH')) define('APPPATH', self::$root.'/application/');
        if (!class_exists('CI_Model')) {
            eval('#[AllowDynamicProperties] class CI_Model { public $db; }');
        }
        if (!function_exists('get_instance')) {
            eval('function &get_instance(){ return $GLOBALS["__fake_ci"]; }');
        }
        if (!function_exists('log_message')) eval('function log_message($l,$m){}');
        if (!function_exists('base_url')) {
            eval('function base_url($p = ""){ return "https://panel.test/".ltrim($p, "/"); }');
        }
        require_once self::$root.'/application/core/MY_Model.php';
        require_once self::$root.'/application/core/Env.php';
        require_once self::$root.'/application/helpers/marvy_helper.php';
        require_once self::$root.'/application/libraries/TicketService.php';
    }

    protected function setUp(): void
    {
        $this->tmpdir  = FCPATH;
        $this->storage = rtrim(sys_get_temp_dir(), '/').'/marvy_private_attach_storage';
        $this->rmtree($this->tmpdir);
        $this->rmtree($this->storage);
        @mkdir($this->tmpdir, 0755, true);
        @mkdir($this->storage, 0755, true);
        // Point the private store at a temp root so a test never writes into
        // the repository's own storage/ directory. The previous value is put
        // back in tearDown: Env writes to getenv()/$_ENV/$_SERVER, which every
        // later test in the same process would otherwise inherit — that is how
        // this file made ProductionReadinessTest report a missing storage
        // directory.
        $this->storage_was = Env::get('STORAGE_PATH', '');
        Env::put('STORAGE_PATH', $this->storage);
    }

    protected function tearDown(): void
    {
        Env::put('STORAGE_PATH', (string)$this->storage_was);
        $this->rmtree($this->tmpdir);
        $this->rmtree($this->storage);
    }

    private function rmtree($dir)
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) as $f) {
            if ($f === '.' || $f === '..') continue;
            $p = $dir.'/'.$f;
            is_dir($p) ? $this->rmtree($p) : @unlink($p);
        }
        @rmdir($dir);
    }

    private function app()
    {
        $app = new IntegrationHarness();
        $app->seed_minimal();
        $app->library('MediaService');
        $app->model(array('Media_model'));
        $user = $app->register('buyer', 'buyer@x.test', 'Str0ng!pass1', 'CUSTOMER');
        return array($app, $user);
    }

    private function png()
    {
        return base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    }

    private function upload($bytes, $client_name = 'receipt.png')
    {
        $tmp = tempnam(sys_get_temp_dir(), 'up');
        file_put_contents($tmp, $bytes);
        return array(
            'name'     => $client_name,
            'type'     => 'image/png',
            'tmp_name' => $tmp,
            'error'    => UPLOAD_ERR_OK,
            'size'     => strlen($bytes),
        );
    }

    private function mover()
    {
        return function ($from, $to) { return rename($from, $to); };
    }

    private function source($relative)
    {
        return file_get_contents(self::$root.'/'.$relative);
    }

    /**
     * Source with comments stripped. Assertions about what a file *does* must
     * not be satisfiable — or broken — by what its docblock says about it.
     */
    private function code($relative)
    {
        $out = '';
        foreach (token_get_all($this->source($relative)) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], array(T_COMMENT, T_DOC_COMMENT), true)) continue;
                $out .= $token[1];
            } else {
                $out .= $token;
            }
        }
        return $out;
    }

    /* =================== where the bytes actually land =================== */

    /**
     * The headline fix: a ticket upload must not be written anywhere the web
     * server can serve it.
     */
    public function testATicketUploadIsWrittenOutsideTheDocumentRoot()
    {
        list($app, $user) = $this->app();

        $res = $app->mediaservice->store(
            $this->upload($this->png(), 'bank-statement.png'), 'ticket', $user->id, $this->mover());

        $this->assertTrue($res['ok'], isset($res['error']) ? $res['error'] : '');
        $key = $res['media']->storage_key;
        $this->assertStringStartsWith(MediaService::PRIVATE_PREFIX, $key,
            'a ticket attachment must be recorded as private storage');
        $this->assertSame(array(), glob($this->tmpdir.MediaService::DIR.'/*.png'),
            'nothing may be left in the publicly served upload directory');

        $stored = glob($this->storage.'/'.MediaService::PRIVATE_DIR.'/*.png');
        $this->assertCount(1, $stored, 'the file belongs in the private store');
        $this->assertSame($this->png(), file_get_contents($stored[0]));
    }

    /**
     * The private store carries a blanket deny, not the upload directory's
     * "data, never code" guard. On the shared hosting this panel targets,
     * `storage/` sits inside the document root and that file is the last line
     * between the web server and a customer's bank statement.
     */
    public function testThePrivateStoreDeniesTheWebServerOutright()
    {
        list($app, $user) = $this->app();
        $app->mediaservice->store(
            $this->upload($this->png()), 'ticket', $user->id, $this->mover());

        $guard = $this->storage.'/'.MediaService::PRIVATE_DIR.'/.htaccess';
        $this->assertFileExists($guard);
        $this->assertStringContainsString('Require all denied', file_get_contents($guard));
        $this->assertStringNotContainsString('php_flag engine off', file_get_contents($guard),
            'the upload guard permits fetching; this directory must permit nothing');

        // …and the shipped copy says the same thing, because a fresh clone and
        // the deployment package both need it before the first upload.
        $shipped = self::$root.'/storage/'.MediaService::PRIVATE_DIR.'/.htaccess';
        $this->assertFileExists($shipped, 'the store must exist in a fresh clone, guarded');
        $this->assertStringContainsString('Require all denied', file_get_contents($shipped));
    }

    /** A branding image is public and must stay exactly as it was. */
    public function testPublicPurposesAreUnchanged()
    {
        list($app, $user) = $this->app();

        $res = $app->mediaservice->store(
            $this->upload($this->png(), 'logo.png'), 'branding', $user->id, $this->mover());

        $this->assertTrue($res['ok']);
        $this->assertStringStartsWith(MediaService::DIR.'/', $res['media']->storage_key);
        $this->assertStringContainsString(MediaService::DIR.'/', $res['media']->url);
        $this->assertFileExists($this->tmpdir.$res['media']->storage_key);
    }

    /**
     * The URL recorded for a private file is the authorising route. The ticket
     * thread renders `file_url` straight into an <a href>, so this is what
     * makes every existing caller safe without touching the view.
     */
    public function testThePrivateUrlIsTheAuthorisingRouteNotAFilePath()
    {
        list($app, $user) = $this->app();

        $res = $app->mediaservice->store(
            $this->upload($this->png()), 'ticket', $user->id, $this->mover());

        $url = $res['media']->url;
        $this->assertStringContainsString('support/attachment/'.$res['media']->public_id, $url);
        $this->assertStringNotContainsString(MediaService::DIR, $url,
            'the private file must have no direct URL at all');
        $this->assertStringNotContainsString('.png', $url,
            'the stored filename must not appear in the link');
    }

    /** Private rows resolve to a real path; the resolver still refuses escapes. */
    public function testPathResolutionIsConfinedToThePrivateDirectory()
    {
        list($app, $user) = $this->app();

        $res  = $app->mediaservice->store(
            $this->upload($this->png()), 'ticket', $user->id, $this->mover());
        $path = $app->mediaservice->path_for($res['media']);

        $this->assertNotNull($path);
        $this->assertFileExists($path);
        $this->assertStringStartsWith(realpath($app->mediaservice->private_dir()), $path);

        $evil = (object)array('storage_key' => MediaService::PRIVATE_PREFIX.'../../../.env');
        $this->assertNull($app->mediaservice->path_for($evil),
            'a row that points outside the private store must resolve to nothing');
    }

    /** Deleting still finds — and removes — a private file. */
    public function testDeletingRemovesThePrivateFileToo()
    {
        list($app, $user) = $this->app();

        $res  = $app->mediaservice->store(
            $this->upload($this->png()), 'ticket', $user->id, $this->mover());
        $path = $app->mediaservice->path_for($res['media']);
        $this->assertFileExists($path);

        $app->mediaservice->delete($res['media']);
        $this->assertFileDoesNotExist($path);
    }

    /* ======================== the access rule =========================== */

    public function testStaffMayReadAnyAttachment()
    {
        $media = (object)array('uploader_id' => 7);
        $ctx   = (object)array('is_internal_note' => 0, 'ticket_user_id' => 42, 'author_id' => 42);
        $this->assertTrue(TicketService::may_read_attachment(true, 99, $media, $ctx));
    }

    public function testACustomerReadsOnlyTheirOwnTicketsAttachments()
    {
        $media = (object)array('uploader_id' => 42);
        $ctx   = (object)array('is_internal_note' => 0, 'ticket_user_id' => 42, 'author_id' => 42);

        $this->assertTrue(TicketService::may_read_attachment(false, 42, $media, $ctx));
        $this->assertFalse(TicketService::may_read_attachment(false, 43, $media, $ctx),
            'a signed-in stranger with the link must be refused');
        $this->assertFalse(TicketService::may_read_attachment(false, 0, $media, $ctx),
            'a signed-out caller must be refused');
    }

    /**
     * An internal note is staff-only by definition. The thread view already
     * hides the message; the file must be hidden with it, even from the
     * customer whose ticket it is.
     */
    public function testAnInternalNoteAttachmentIsNeverServedToTheCustomer()
    {
        $media = (object)array('uploader_id' => 9);
        $ctx   = (object)array('is_internal_note' => 1, 'ticket_user_id' => 42, 'author_id' => 9);

        $this->assertFalse(TicketService::may_read_attachment(false, 42, $media, $ctx));
        $this->assertTrue(TicketService::may_read_attachment(true, 9, $media, $ctx));
    }

    /** An upload whose message was never saved belongs to its uploader alone. */
    public function testAnOrphanUploadIsReadableOnlyByItsUploader()
    {
        $media = (object)array('uploader_id' => 42);

        $this->assertTrue(TicketService::may_read_attachment(false, 42, $media, null));
        $this->assertFalse(TicketService::may_read_attachment(false, 43, $media, null));
        $this->assertFalse(TicketService::may_read_attachment(false, 0, (object)array('uploader_id' => 0), null),
            'a NULL uploader must not match a signed-out caller');
    }

    /* ===================== the wiring around it ========================= */

    public function testTheRouteExistsAndPointsAtTheAuthorisingController()
    {
        $routes = $this->source('application/config/routes.php');
        $this->assertStringContainsString(
            "\$route['support/attachment/(:any)'] = 'attachment/ticket/\$1';", $routes);
    }

    /**
     * The controller must sit behind the signed-in base controller, must
     * refuse to serve non-ticket media, and must answer 404 rather than 403 —
     * a 403 confirms the attachment exists.
     */
    public function testTheControllerRequiresASessionAndLeaksNothingOnRefusal()
    {
        $src = $this->code('application/controllers/Attachment.php');

        $this->assertStringContainsString('class Attachment extends Auth_Controller', $src);
        $this->assertStringContainsString("purpose !== 'ticket'", $src);
        $this->assertStringContainsString('may_read_attachment', $src);
        $this->assertStringNotContainsString('403', $src,
            'refusals must be 404 so this endpoint cannot confirm an id exists');
        $this->assertSame(3, substr_count($src, 'show_404()'),
            'unknown media, refused access and a missing file all answer 404');
    }

    /**
     * A customer-supplied file must never render inline in the panel's own
     * origin, where an HTML-ish payload would run against the session.
     */
    public function testTheStreamIsAlwaysADownloadAndIsNeverCached()
    {
        $src = $this->code('application/controllers/Attachment.php');

        $this->assertStringContainsString('Content-Disposition: attachment;', $src);
        $this->assertStringContainsString('X-Content-Type-Options: nosniff', $src);
        $this->assertStringContainsString('Cache-Control: private, no-store', $src);
        $this->assertStringNotContainsString('inline', $src);
    }

    /** The lookup that ties a media row to its ticket. */
    public function testTheAttachmentContextJoinsThroughToTheTicketOwner()
    {
        $src = $this->source('application/models/Ticket_message_model.php');

        $this->assertStringContainsString('function attachment_context', $src);
        $this->assertStringContainsString('ticket_attachments ta', $src);
        $this->assertStringContainsString('tm.is_internal_note', $src);
        $this->assertStringContainsString('t.user_id AS ticket_user_id', $src);
        $this->assertStringContainsString("where('ta.file_url'", $src);
    }

    /* ======================== the migration ============================= */

    public function testTheMigrationMovesExistingAttachmentsAndIsRegistered()
    {
        $src = $this->source('application/migrations/029_private_ticket_attachments.php');

        $this->assertStringContainsString('class Migration_Private_ticket_attachments', $src);
        $this->assertStringContainsString("where('purpose', 'ticket')", $src);
        $this->assertStringContainsString("update('ticket_attachments'", $src,
            'the rendered link in the thread has to be rewritten too, not just media');
        $this->assertStringContainsString('PRIVATE_PREFIX', $src);

        // The panel must be configured to run AT LEAST this migration; later
        // modules move the version on, and pinning the exact number here would
        // make an unrelated migration look like an attachment regression.
        $config = $this->source('application/config/migration.php');
        preg_match("/migration_version'\]\s*=\s*(\d+)/", $config, $m);
        $this->assertGreaterThanOrEqual(29, (int)($m[1] ?? 0),
            'a migration nobody runs fixes nothing');
    }

    /**
     * Rolling back would copy identity documents back into a public
     * directory. `down()` must stay a no-op.
     */
    public function testTheMigrationRefusesToPutTheFilesBack()
    {
        $src = $this->source('application/migrations/029_private_ticket_attachments.php');
        $this->assertMatchesRegularExpression('/function down\(\)\s*\{\s*\}/', $src);
    }
}
