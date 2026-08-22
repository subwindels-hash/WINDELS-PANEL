<?php
use PHPUnit\Framework\TestCase;

/**
 * Natural-language coverage for the embedded site operator.
 */
class SiteOperatorConversationTest extends TestCase
{
    private static $root;
    /** @var SiteOperatorEngine */
    private $engine;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(dirname(__DIR__));
        if (!defined('BASEPATH')) define('BASEPATH', self::$root.'/system/');
        require_once self::$root.'/application/libraries/ai/SiteOperatorPhrases.php';
        require_once self::$root.'/application/libraries/SiteOperatorKnowledge.php';
        require_once self::$root.'/application/libraries/SiteOperatorEngine.php';
    }

    protected function setUp(): void
    {
        $this->engine = new SiteOperatorEngine();
    }

    public function greetingProvider()
    {
        return array(
            array('Good morning', 'greeting_morning', 'good morning'),
            array('Morning', 'greeting_morning', 'morning'),
            array('Good morning AI', 'greeting_morning', 'good morning'),
            array('Good morning assistant', 'greeting_morning', 'good morning'),
            array('Hi, good morning', 'greeting_morning', 'good morning'),
            array('Hello, good morning', 'greeting_morning', 'good morning'),
            array('Good afternoon', 'greeting_afternoon', 'good afternoon'),
            array('Afternoon', 'greeting_afternoon', 'afternoon'),
            array('Good afternoon AI', 'greeting_afternoon', 'good afternoon'),
            array('Hi, good afternoon', 'greeting_afternoon', 'good afternoon'),
            array('Good evening', 'greeting_evening', 'good evening'),
            array('Evening', 'greeting_evening', 'evening'),
            array('Good evening AI', 'greeting_evening', 'good evening'),
            array('Good day', 'greeting_day', 'good day'),
            array('Good night', 'farewell', 'night'),
            array('Night', 'farewell', 'night'),
            array('Have a good night', 'farewell', 'night'),
            array('Hello', 'greeting', 'hello'),
            array('Hi', 'greeting', 'hello'),
            array('Hey', 'greeting', 'hello'),
            array('Hey there', 'greeting', 'hello'),
            array('Hi there', 'greeting', 'hello'),
            array('Hello AI', 'greeting', 'hello'),
            array('Hello assistant', 'greeting', 'hello'),
            array('Hey assistant', 'greeting', 'hello'),
            array('Howdy', 'greeting', 'hello'),
            array('Greetings', 'greeting', 'hello'),
        );
    }

    /**
     * @dataProvider greetingProvider
     */
    public function testGreetings($message, $intent, $needle)
    {
        $r = $this->engine->reply($message);
        $this->assertTrue($r['ok'], $message);
        $this->assertSame($intent, $r['intent'], $message);
        $this->assertStringContainsString($needle, strtolower($r['reply']));
        $this->assertStringNotContainsString("i don't understand", strtolower($r['reply']));
    }

    public function courtesyProvider()
    {
        return array(
            array('OK', 'sounds good'),
            array('Okay', 'sounds good'),
            array('Ok thanks', 'welcome'),
            array('Thanks', 'welcome'),
            array('Thank you', 'welcome'),
            array('Thank you so much', 'welcome'),
            array('Thanks a lot', 'welcome'),
            array("You're welcome", 'glad'),
            array('You are welcome', 'glad'),
            array('Great', 'sounds good'),
            array('Perfect', 'sounds good'),
            array('Good', 'sounds good'),
            array('Nice', 'sounds good'),
            array('Awesome', 'sounds good'),
            array('Alright', 'sounds good'),
            array('Got it', 'sounds good'),
            array('I understand', 'sounds good'),
            array("That's fine", 'sounds good'),
            array('Cool', 'sounds good'),
        );
    }

    /**
     * @dataProvider courtesyProvider
     */
    public function testCourtesy($message, $needle)
    {
        $r = $this->engine->reply($message);
        $this->assertTrue($r['ok'], $message);
        $this->assertSame('courtesy', $r['intent'], $message);
        $this->assertStringContainsString($needle, strtolower($r['reply']));
        $this->assertStringNotContainsString('prepaid reseller', strtolower($r['reply']));
    }

    public function conversationProvider()
    {
        return array(
            array('How are you?', 'wellbeing'),
            array('What is going on?', 'wellbeing'),
            array("What's going on?", 'wellbeing'),
            array('What is going on with you?', 'wellbeing'),
            array('What can you do?', 'help'),
            array('Can you help me?', 'help'),
            array('I need help', 'help'),
            array('I have a question', 'help'),
            array('Tell me about this website.', 'about'),
            array('What is this website?', 'about'),
            array('What does Averion Commerce do?', 'about'),
            array('What is Averion Commerce?', 'about'),
            array('What can I do here?', 'about'),
            array('How does this website work?', 'how'),
        );
    }

    /**
     * @dataProvider conversationProvider
     */
    public function testNaturalConversation($message, $intent)
    {
        $r = $this->engine->reply($message);
        $this->assertTrue($r['ok'], $message);
        $this->assertSame($intent, $r['intent'], $message.' got '.$r['intent']);
        $this->assertGreaterThan(20, strlen($r['reply']));
    }

    public function signupProvider()
    {
        return array(
            array('How can I sign up?'),
            array('How do I sign up?'),
            array('Where can I sign up?'),
            array('I want to sign up'),
            array('I want to create an account'),
            array('How do I create an account?'),
            array('Can I register?'),
            array('Where is registration?'),
            array('How can I register?'),
            array('I need an account'),
            array('Help me create an account'),
            array('Take me to registration'),
            array('Register me'),
            array('I want to join'),
            array('Can I make an account?'),
        );
    }

    /**
     * @dataProvider signupProvider
     */
    public function testSignup($message)
    {
        $r = $this->engine->reply($message);
        $this->assertTrue($r['ok'], $message);
        $this->assertSame('register', $r['intent'], $message);
        $this->assertStringContainsString('register', strtolower($r['reply']));
        $this->assertStringNotContainsString('i created', strtolower($r['reply']));
        $hrefs = array();
        foreach ($r['links'] as $l) $hrefs[] = $l['href'];
        $this->assertContains('register', $hrefs, $message);
    }

    public function loginProvider()
    {
        return array(
            array('How do I log in?', 'login', 'login'),
            array('Where is the login page?', 'login', 'login'),
            array('I want to log in', 'login', 'login'),
            array('Sign me in', 'login', 'login'),
            array('Take me to login', 'login', 'login'),
            array('How can I access my account?', 'login', 'login'),
            array('I forgot my password', 'forgot', 'forgot-password'),
            array('How do I reset my password?', 'forgot', 'forgot-password'),
            array("I can't log in", 'forgot', 'forgot-password'),
            array("My login isn't working", 'forgot', 'forgot-password'),
        );
    }

    /**
     * @dataProvider loginProvider
     */
    public function testLogin($message, $intent, $href)
    {
        $r = $this->engine->reply($message);
        $this->assertTrue($r['ok'], $message);
        $this->assertSame($intent, $r['intent'], $message);
        $hrefs = array();
        foreach ($r['links'] as $l) $hrefs[] = $l['href'];
        $this->assertContains($href, $hrefs, $message);
        $this->assertStringNotContainsString('i signed you in', strtolower($r['reply']));
        $this->assertStringNotContainsString('i reset', strtolower($r['reply']));
    }

    public function websiteProvider()
    {
        return array(
            array('What is Averion Commerce?', 'about', null),
            array('What services do you offer?', 'services', 'services'),
            array('How much does it cost?', 'pricing', 'pricing'),
            array('Where can I see the pricing?', 'pricing', 'pricing'),
            array('What is your privacy policy?', 'privacy', 'privacy'),
            array('Where can I find the terms?', 'terms', 'terms'),
            array('Where can I find the FAQ?', 'faq', 'faq'),
            array('What is the design system?', 'design_system', 'design-system'),
        );
    }

    /**
     * @dataProvider websiteProvider
     */
    public function testWebsiteTopics($message, $intent, $href)
    {
        $r = $this->engine->reply($message);
        $this->assertTrue($r['ok'], $message);
        $this->assertSame($intent, $r['intent'], $message.' got '.$r['intent']);
        if ($href) {
            $hrefs = array();
            foreach ($r['links'] as $l) $hrefs[] = $l['href'];
            $this->assertContains($href, $hrefs, $message);
        }
    }

    public function testFollowUpConversation()
    {
        $first = $this->engine->reply('What services do you offer?');
        $this->assertSame('services', $first['intent']);

        $history = array(
            array('role' => 'user', 'content' => 'What services do you offer?'),
            array('role' => 'assistant', 'content' => $first['reply']),
        );

        $cost = $this->engine->reply('How much does that cost?', $history);
        $this->assertSame('pricing', $cost['intent']);
        $this->assertStringContainsString('wallet', strtolower($cost['reply']));

        $history[] = array('role' => 'user', 'content' => 'How much does that cost?');
        $history[] = array('role' => 'assistant', 'content' => $cost['reply']);

        $more = $this->engine->reply('Where can I learn more?', $history);
        $this->assertTrue(in_array($more['intent'], array('pricing', 'services'), true), $more['intent']);

        $start = $this->engine->reply('How do I get started?', $history);
        $this->assertTrue(in_array($start['intent'], array('how', 'register'), true), $start['intent']);
    }

    public function testUnknownIsHelpful()
    {
        $r = $this->engine->reply('What is the weather on Mars tomorrow?');
        $this->assertTrue($r['ok']);
        $this->assertSame('unknown', $r['intent']);
        $this->assertStringContainsString('not sure', strtolower($r['reply']));
        $this->assertStringContainsString('services', strtolower($r['reply']));
        $this->assertStringNotContainsString("i don't understand", strtolower($r['reply']));
    }

    public function testDoesNotClaimCompletedActions()
    {
        foreach (array(
            'Please place an order for me',
            'Pay now',
            'Fund my wallet',
        ) as $msg) {
            $r = $this->engine->reply($msg);
            $this->assertSame('action_refused', $r['intent'], $msg);
            $this->assertStringContainsString('cannot', strtolower($r['reply']));
        }
    }

    public function testPhrasesFileIsOrganised()
    {
        $this->assertFileExists(self::$root.'/application/libraries/ai/SiteOperatorPhrases.php');
        $groups = SiteOperatorPhrases::all();
        foreach (array('greeting_morning', 'courtesy', 'register', 'login', 'pricing', 'services', 'privacy', 'followup') as $g) {
            $this->assertArrayHasKey($g, $groups);
            $this->assertNotEmpty($groups[$g]);
        }
    }
}
