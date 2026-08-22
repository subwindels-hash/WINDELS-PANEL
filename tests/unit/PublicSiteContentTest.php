<?php
use PHPUnit\Framework\TestCase;

/**
 * Public-site content and navigation contracts.
 */
class PublicSiteContentTest extends TestCase
{
    private static $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(dirname(__DIR__));
    }

    private function view($rel)
    {
        return file_get_contents(self::$root.'/application/views/'.$rel);
    }

    public function testLegalPagesAreNoLongerStubs()
    {
        foreach (array('public/terms.php', 'public/privacy.php', 'public/refund_policy.php', 'public/acceptable_use.php', 'public/about.php', 'public/pricing.php') as $rel) {
            $html = $this->view($rel);
            $this->assertGreaterThan(800, strlen($html), $rel.' is still a stub');
            $this->assertStringNotContainsString('lorem ipsum', strtolower($html), $rel);
        }
        $this->assertStringContainsString('id="acceptance"', $this->view('public/terms.php'));
        $this->assertStringContainsString('Information collected', $this->view('public/privacy.php'));
        $this->assertStringContainsString('Contact sales', $this->view('public/pricing.php'));
        $this->assertStringContainsString('spending balance', strtolower($this->view('public/refund_policy.php')));
    }

    public function testFaqKeepsTicketLinkAndDetails()
    {
        $faq = $this->view('public/faq.php');
        $this->assertStringContainsString('dashboard/tickets', $faq);
        $this->assertStringContainsString('<details', $faq);
        $this->assertStringContainsString('ws-faq-search', $faq);
    }

    public function testNavAndFooterHaveNoHashPlaceholders()
    {
        foreach (array('partials/public_nav.php', 'partials/footer.php') as $rel) {
            $html = $this->view($rel);
            $this->assertStringNotContainsString('href="#"', $html, $rel);
            $this->assertStringContainsString('site_url(', $html);
        }
        $footer = $this->view('partials/footer.php');
        foreach (array('terms', 'privacy', 'contact', 'faq', 'services', 'admin/login') as $path) {
            $this->assertStringContainsString($path, $footer, 'footer missing '.$path);
        }
    }

    public function testPublicLayoutHasSeoTagsAndAssistant()
    {
        $layout = $this->view('layouts/public.php');
        $this->assertStringContainsString('og:title', $layout);
        $this->assertStringContainsString('canonical', $layout);
        $this->assertStringContainsString('partials/site_operator', $layout);
        $this->assertStringContainsString('partials/public_nav', $layout);
        $this->assertStringContainsString('partials/footer', $layout);
    }

    public function testAuthPagesHavePasswordToggleAndTerms()
    {
        $this->assertStringContainsString('data-password-toggle', $this->view('auth/login.php'));
        $this->assertStringContainsString('remember', $this->view('auth/login.php'));
        $this->assertStringContainsString('name="terms"', $this->view('auth/register.php'));
        $this->assertFileExists(self::$root.'/application/views/auth/admin_login.php');
        $admin = $this->view('auth/admin_login.php');
        $this->assertStringContainsString('Staff', $admin);
    }

    public function testHomepagesDoNotInventCustomerCounts()
    {
        foreach (array('aurora', 'nexus', 'pulse') as $name) {
            $html = $this->view('homepages/'.$name.'/index.php');
            $this->assertStringNotContainsString('50,000+', $html, $name);
            $this->assertStringNotContainsString('12,000+', $html, $name);
            $this->assertStringNotContainsString('2M+', $html, $name);
            $this->assertStringNotContainsString('99.8%', $html, $name);
        }
    }

    public function testDesignSystemDocumentsLiveClasses()
    {
        $page = $this->view('public/styleguide.php');
        $css  = file_get_contents(self::$root.'/assets/css/design-system.css');
        foreach (array('btn-primary', 'card-title', 'alert-danger', 'badge-success', 'ws-assistant') as $cls) {
            $this->assertStringContainsString($cls, $page);
            $this->assertStringContainsString('.'.$cls, $css);
        }
    }

    public function testFourOhFourOverrideAndAssistantRoutes()
    {
        $routes = file_get_contents(self::$root.'/application/config/routes.php');
        $this->assertStringContainsString("'home/not_found'", $routes);
        $this->assertStringContainsString("'assistant'", $routes);
        $this->assertStringContainsString("'admin/login' = 'auth/admin_login'", $routes);
        $home = file_get_contents(self::$root.'/application/controllers/Home.php');
        $this->assertStringContainsString('function not_found', $home);
        $this->assertStringContainsString('Faq_model', $home);
        $auth = file_get_contents(self::$root.'/application/controllers/Auth.php');
        $this->assertStringContainsString('function admin_login', $auth);
    }
}
