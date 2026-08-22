<?php
use PHPUnit\Framework\TestCase;

/**
 * Embedded site operator — deterministic knowledge engine.
 * No network, no third-party AI.
 */
class SiteOperatorEngineTest extends TestCase
{
    private static $root;
    /** @var SiteOperatorEngine */
    private $engine;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(dirname(__DIR__));
        if (!defined('BASEPATH')) define('BASEPATH', self::$root.'/system/');
        require_once self::$root.'/application/libraries/SiteOperatorKnowledge.php';
        require_once self::$root.'/application/libraries/SiteOperatorEngine.php';
    }

    protected function setUp(): void
    {
        $this->engine = new SiteOperatorEngine();
    }

    public function testWelcomeStatesCapabilitiesHonestly()
    {
        $w = $this->engine->welcome();
        $this->assertTrue($w['ok']);
        $this->assertStringContainsString('not a cloud generative ai', strtolower($w['reply']).' '.strtolower(SiteOperatorKnowledge::assistant_disclaimer()));
        $this->assertNotEmpty($w['suggestions']);
    }

    public function testEmptyMessageFails()
    {
        $r = $this->engine->reply('   ');
        $this->assertFalse($r['ok']);
        $this->assertSame('EMPTY', $r['error']);
    }

    public function testPricingQuestionReturnsWalletModel()
    {
        $r = $this->engine->reply('How does pricing work?');
        $this->assertTrue($r['ok']);
        $this->assertSame('pricing', $r['intent']);
        $this->assertStringContainsString('wallet', strtolower($r['reply']));
        $this->assertStringContainsString('subscription', strtolower($r['reply']));
    }

    public function testCannotPretendToPlaceAnOrder()
    {
        $r = $this->engine->reply('Please place an order for me');
        $this->assertTrue($r['ok']);
        $this->assertSame('action_refused', $r['intent']);
        $this->assertStringContainsString('cannot', strtolower($r['reply']));
    }

    public function testServicesOverviewListsRealProductAreas()
    {
        $r = $this->engine->reply('What services can I order?');
        $this->assertTrue($r['ok']);
        $this->assertStringContainsString('VTU', $r['reply']);
        $this->assertStringContainsString('gift', strtolower($r['reply']));
        $this->assertNotEmpty($r['links']);
    }

    public function testAdminQuestionPointsAtStaffLogin()
    {
        $r = $this->engine->reply('Where is the admin login?');
        $this->assertTrue($r['ok']);
        $hrefs = array();
        foreach ($r['links'] as $l) $hrefs[] = $l['href'];
        $this->assertContains('admin/login', $hrefs);
    }

    public function testFaqRetrievalForWithdrawals()
    {
        $r = $this->engine->reply('Can I withdraw wallet funds?');
        $this->assertTrue($r['ok']);
        $this->assertStringContainsString('spending', strtolower($r['reply']));
    }

    public function testUnknownFallsBackWithoutGuessing()
    {
        $r = $this->engine->reply('What is the weather on Mars tomorrow?');
        $this->assertTrue($r['ok']);
        $this->assertTrue(in_array($r['intent'], array('unknown', 'faq'), true));
        $this->assertStringNotContainsString('I have placed', $r['reply']);
    }

    public function testKnowledgeHasNoLoremIpsum()
    {
        $blob = json_encode(SiteOperatorKnowledge::faqs()).json_encode(SiteOperatorKnowledge::product_areas());
        $this->assertStringNotContainsString('lorem ipsum', strtolower($blob));
        $this->assertStringNotContainsString('coming soon TBD', strtolower($blob));
    }

    public function testChatControllerAndRouteExist()
    {
        $this->assertFileExists(self::$root.'/application/controllers/Chat.php');
        $routes = file_get_contents(self::$root.'/application/config/routes.php');
        $this->assertStringContainsString("'assistant/chat'", $routes);
        $this->assertStringContainsString("'admin/login'", $routes);
        $src = file_get_contents(self::$root.'/application/controllers/Chat.php');
        $this->assertStringNotContainsString('openai', strtolower($src));
        $this->assertStringNotContainsString('anthropic', strtolower($src));
    }
}
