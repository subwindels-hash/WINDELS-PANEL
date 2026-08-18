<?php
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__).'/_support/FakeDb.php';
require_once dirname(__DIR__).'/_support/IntegrationHarness.php';

/**
 * Media library, uploads and branding (Session 30).
 *
 * The last two routed-but-missing screens. They ship together because
 * `admin/appearance` picks a logo and a favicon, and before this there was no
 * way to get an image into the panel to point them at — migration 008 created
 * `media` and nothing ever wrote to it.
 *
 * This adds the application's **first file-upload path**, so these tests are
 * almost entirely adversarial. The document root is the repository root:
 * `index.php` sits beside `assets/`, so a file written under `assets/uploads`
 * is fetchable by URL, and a `.php` file there is not a stored file — it is
 * remote code execution. The tests below are the specific attacks that turns
 * into, and each is refused before anything reaches disk.
 */
class AdminMediaTest extends TestCase
{
    private static $root;
    private $tmpdir;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(dirname(__DIR__));
        if (!defined('BASEPATH')) define('BASEPATH', self::$root.'/system/');
        if (!defined('FCPATH')) define('FCPATH', sys_get_temp_dir().'/windels_media_test/');
        if (!class_exists('CI_Model')) {
            eval('#[AllowDynamicProperties] class CI_Model { public $db; }');
        }
        if (!function_exists('get_instance')) {
            eval('function get_instance(){ return $GLOBALS["__fake_ci"]; }');
        }
        if (!function_exists('log_message')) eval('function log_message($l,$m){}');
        if (!function_exists('base_url')) {
            eval('function base_url($p = ""){ return "https://panel.test/".ltrim($p, "/"); }');
        }
        require_once self::$root.'/application/core/MY_Model.php';
        require_once self::$root.'/application/helpers/windels_helper.php';
    }

    protected function setUp(): void
    {
        $this->tmpdir = FCPATH;
        $this->rmtree($this->tmpdir);
        @mkdir($this->tmpdir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->rmtree($this->tmpdir);
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
        $app->model(array('Media_model', 'Setting_model'));
        $admin = $app->register('curator', 'curator@x.test', 'Str0ng!pass1', 'ADMIN');
        return array($app, $admin);
    }

    /** A $_FILES-shaped entry backed by a real temp file. */
    private function upload($bytes, $client_name = 'photo.png')
    {
        $tmp = tempnam(sys_get_temp_dir(), 'up');
        file_put_contents($tmp, $bytes);
        return array(
            'name'     => $client_name,
            'type'     => 'image/png',   // client-supplied; deliberately a lie in most tests
            'tmp_name' => $tmp,
            'error'    => UPLOAD_ERR_OK,
            'size'     => strlen($bytes),
        );
    }

    /** A real 1×1 PNG. */
    private function png()
    {
        return base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    }

    /** move_uploaded_file() refuses non-HTTP uploads, so tests use rename. */
    private function mover()
    {
        return function ($from, $to) { return rename($from, $to); };
    }

    /* ==================== the attacks that matter ======================= */

    /**
     * The headline case: a PHP web shell renamed to .png.
     *
     * It must not be stored at all — the bytes are not a decodable image, and
     * the sniffed type is not on the allow-list.
     */
    public function testAPhpShellDisguisedAsAnImageIsRefused()
    {
        list($app, $admin) = $this->app();
        $shell = "<?php system(\$_GET['c']); ?>";

        $res = $app->mediaservice->store(
            $this->upload($shell, 'shell.png'), 'branding', $admin->id, $this->mover());

        $this->assertFalse($res['ok']);
        $this->assertContains($res['code'], array('BAD_TYPE', 'BAD_IMAGE'));
        $this->assertSame(0, $app->mediaservice->grid()['total']);
        $this->assertSame(array(), glob($this->tmpdir.MediaService::DIR.'/*'),
            'nothing may reach disk when the type check fails');
    }

    /**
     * The subtler one: valid GIF magic bytes with PHP appended. Some sniffers
     * report image/gif, so getimagesize() has to be the second gate.
     */
    public function testAPolyglotGifWithAppendedPhpIsRefused()
    {
        list($app, $admin) = $this->app();
        $polyglot = "GIF89a".str_repeat("\x00", 8)."<?php system(\$_GET['c']); ?>";

        $res = $app->mediaservice->store(
            $this->upload($polyglot, 'cute.gif'), 'blog', $admin->id, $this->mover());

        $this->assertFalse($res['ok']);
        $this->assertSame(0, $app->mediaservice->grid()['total']);
    }

    /**
     * A double extension must not round-trip: even a *genuine* image called
     * `evil.php.png` has to land under an extension we chose.
     */
    public function testTheStoredNameNeverComesFromTheUploader()
    {
        list($app, $admin) = $this->app();

        $res = $app->mediaservice->store(
            $this->upload($this->png(), 'evil.php.png'), 'branding', $admin->id, $this->mover());

        $this->assertTrue($res['ok'], $res['error'] ?? '');
        $key = $res['media']->storage_key;
        $this->assertStringEndsWith('.png', $key);
        $this->assertStringNotContainsString('.php', $key,
            'the uploader must not influence the stored extension');
        $this->assertStringNotContainsString('evil', $key,
            'the stored name is random, not derived from the upload');
        // ...but the original is kept for humans to read.
        $this->assertSame('evil.php.png', $res['media']->file_name);
    }

    /** Path traversal in the client filename cannot escape the directory. */
    public function testPathTraversalInTheFilenameIsNeutralised()
    {
        list($app, $admin) = $this->app();

        $res = $app->mediaservice->store(
            $this->upload($this->png(), '../../../index.php'), 'branding', $admin->id, $this->mover());

        $this->assertTrue($res['ok'], $res['error'] ?? '');
        $this->assertStringNotContainsString('..', $res['media']->storage_key);
        $this->assertStringStartsWith(MediaService::DIR.'/', $res['media']->storage_key);
        $this->assertFileExists($this->tmpdir.$res['media']->storage_key);
        // The real index.php is untouched.
        $this->assertStringNotContainsString('..', $res['media']->file_name);
    }

    /**
     * SVG is an image everywhere else and a script host here: it can carry
     * <script> and runs same-origin when viewed.
     */
    public function testSvgIsRefusedEvenThoughItIsAnImage()
    {
        list($app, $admin) = $this->app();
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(document.cookie)</script></svg>';

        $res = $app->mediaservice->store(
            $this->upload($svg, 'logo.svg'), 'branding', $admin->id, $this->mover());

        $this->assertFalse($res['ok']);
        $this->assertSame(0, $app->mediaservice->grid()['total']);
    }

    /** An HTML file would be stored XSS on our own origin. */
    public function testHtmlIsRefused()
    {
        list($app, $admin) = $this->app();
        $res = $app->mediaservice->store(
            $this->upload('<html><script>alert(1)</script></html>', 'page.html'),
            'blog', $admin->id, $this->mover());
        $this->assertFalse($res['ok']);
    }

    /** Defence in depth: the folder itself refuses to execute anything. */
    public function testTheUploadDirectoryIsHardenedAgainstExecution()
    {
        list($app, $admin) = $this->app();
        $app->mediaservice->store($this->upload($this->png()), 'branding', $admin->id, $this->mover());

        $htaccess = $this->tmpdir.MediaService::DIR.'/.htaccess';
        $this->assertFileExists($htaccess, 'the upload folder must carry its own guard');
        $body = file_get_contents($htaccess);
        $this->assertStringContainsString('php_flag engine off', $body);
        $this->assertStringContainsString('-ExecCGI', $body);
    }

    /* ========================== ordinary limits ========================= */

    public function testAGenuineImageIsStoredAndRecorded()
    {
        list($app, $admin) = $this->app();

        $res = $app->mediaservice->store(
            $this->upload($this->png(), 'logo.png'), 'branding', $admin->id, $this->mover());

        $this->assertTrue($res['ok'], $res['error'] ?? '');
        $this->assertSame('image/png', $res['media']->mime_type);
        $this->assertSame('branding', $res['media']->purpose);
        $this->assertSame((int)$admin->id, (int)$res['media']->uploader_id);
        $this->assertFileExists($this->tmpdir.$res['media']->storage_key);
        $this->assertStringContainsString('assets/uploads/', $res['media']->url);
    }

    public function testAnOversizeFileIsRefused()
    {
        list($app, $admin) = $this->app();
        $file = $this->upload($this->png());
        $file['size'] = $app->mediaservice->max_bytes() + 1;

        $res = $app->mediaservice->store($file, 'branding', $admin->id, $this->mover());

        $this->assertFalse($res['ok']);
        $this->assertSame('TOO_BIG', $res['code']);
    }

    public function testAnEmptyOrMissingUploadIsRefused()
    {
        list($app, $admin) = $this->app();

        $none = $app->mediaservice->store(
            array('error' => UPLOAD_ERR_NO_FILE), 'branding', $admin->id, $this->mover());
        $this->assertFalse($none['ok']);

        $empty = $this->upload('');
        $this->assertFalse($app->mediaservice->store($empty, 'branding', $admin->id, $this->mover())['ok']);
    }

    public function testAnUnknownPurposeFallsBackRatherThanStoringGarbage()
    {
        list($app, $admin) = $this->app();
        $res = $app->mediaservice->store(
            $this->upload($this->png()), 'wherever', $admin->id, $this->mover());
        $this->assertTrue($res['ok'], $res['error'] ?? '');
        $this->assertContains($res['media']->purpose, MediaService::PURPOSES);
    }

    /* ============================= deletion ============================= */

    public function testDeletingRemovesBothTheRowAndTheFile()
    {
        list($app, $admin) = $this->app();
        $res  = $app->mediaservice->store($this->upload($this->png()), 'branding', $admin->id, $this->mover());
        $path = $this->tmpdir.$res['media']->storage_key;
        $this->assertFileExists($path);

        $app->mediaservice->delete($res['media']);

        $this->assertFileDoesNotExist($path);
        $this->assertSame(0, $app->mediaservice->grid()['total']);
    }

    /**
     * A delete that trusts a database string to build a filesystem path is one
     * SQL bug away from unlinking arbitrary files.
     */
    public function testDeletionCannotEscapeTheUploadDirectory()
    {
        list($app, ) = $this->app();
        $outsider = $this->tmpdir.'important.txt';
        file_put_contents($outsider, 'keep me');

        $rogue = (object)array('id' => 999, 'storage_key' => '../important.txt');
        $this->assertNull($app->mediaservice->path_for($rogue),
            'a storage_key pointing outside the upload directory must resolve to nothing');

        $app->mediaservice->delete($rogue);
        $this->assertFileExists($outsider, 'a file outside the upload directory must survive');
    }

    /* ============================= branding ============================= */

    /**
     * The settings screen documents keys nothing honours. Wiring branding up
     * means these three must leave that list — otherwise the screen lies in
     * the other direction.
     */
    public function testBrandingKeysAreNoLongerListedAsUnwired()
    {
        require_once self::$root.'/application/libraries/SettingsService.php';
        $unwired = SettingsService::unwired();
        foreach (array('brand_primary_color', 'brand_logo_url', 'brand_favicon_url') as $key) {
            $this->assertArrayNotHasKey($key, $unwired,
                $key.' is honoured now, so it must not still be listed as doing nothing');
        }
        // They are editable on their own screen, not as settings text fields.
        $readonly = SettingsService::readonly_settings();
        foreach (array('brand_primary_color', 'brand_logo_url', 'brand_favicon_url') as $key) {
            $this->assertArrayHasKey($key, $readonly);
        }
    }

    /** The layout must actually render what the branding screen stores. */
    public function testTheLayoutRendersTheStoredBranding()
    {
        $layout = file_get_contents(self::$root.'/application/views/layouts/app.php');
        $this->assertStringContainsString("brand_logo_url", $layout);
        $this->assertStringContainsString("brand_favicon_url", $layout);
        $this->assertStringContainsString("brand_primary_color", $layout);
        // ...escaped, since it ends up in an href and a style block.
        $this->assertStringContainsString("htmlspecialchars(\$brand['brand_logo_url'])", $layout);
    }

    /* ======================= controller guarantees ====================== */

    public function testTheControllerIsGuardedAndAudited()
    {
        $src = file_get_contents(self::$root.'/application/controllers/admin/Media.php');
        $this->assertStringContainsString("require_perm('media.manage')", $src);
        $this->assertStringContainsString("require_perm('appearance.manage')", $src);
        $this->assertStringContainsString("method(true) !== 'POST') show_404()", $src);
        $this->assertStringContainsString('$this->audit(', $src);
    }

    /**
     * The branding form stores a URL the panel produced, resolved from a
     * library id — never a string the operator typed, which would otherwise
     * put `javascript:` into an href in the layout.
     */
    public function testBrandingUrlsComeFromTheLibraryNotFromInput()
    {
        $src = file_get_contents(self::$root.'/application/controllers/admin/Media.php');
        $this->assertStringContainsString('$this->mediaservice->find($choice)', $src);
        $this->assertStringContainsString('$media->url', $src);
    }

    public function testUploadedFilesAreNotCommittedToGit()
    {
        $ignore = file_get_contents(self::$root.'/.gitignore');
        $this->assertStringContainsString('/assets/uploads/*', $ignore,
            'runtime uploads must never enter the repository');
    }
}
