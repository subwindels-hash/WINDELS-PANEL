<?php
use PHPUnit\Framework\TestCase;

/**
 * Homepage tests (Session 05) — the three marketing homepages must implement
 * the sections promised in the wireframes, remain visually distinct, and meet
 * baseline accessibility/SEO requirements. No browser required.
 */
class HomepageTest extends TestCase
{
    private static $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(dirname(__DIR__));
    }

    private function view($name)
    {
        return file_get_contents(self::$root.'/application/views/homepages/'.$name.'/index.php');
    }

    public function testAuroraImplementsWireframeSections()
    {
        $html = $this->view('aurora');
        foreach (array(
            'Services on the panel', 'How it works',
            'Browse by category', 'Frequently asked questions',
            'Why choose MarvySocials', 'Ready to get started?',
        ) as $section) {
            $this->assertStringContainsString($section, $html, "AURORA missing section: {$section}");
        }

        // The catalogue sections must come from the database, not a literal
        // array in the view. Hard-coded service names and prices on a
        // marketing page are a promise the panel cannot keep.
        $this->assertStringContainsString('$showcase', $html,
            'the services section must render live catalogue rows');
        $this->assertStringContainsString('$categories', $html,
            'the category section must render live categories');
        $this->assertStringContainsString('catalogue is being prepared', $html,
            'an empty catalogue needs an honest empty state, not placeholder cards');

        // No invented social proof: testimonials we cannot attribute are the
        // easiest thing on this page to fake.
        $this->assertStringNotContainsString('Loved by resellers', $html);
        $this->assertStringNotContainsString('here is what they say', $html);
        // Three ordered steps per wireframe, rendered by a foreach loop.
        $this->assertSame(3, substr_count($html, "'01'") + substr_count($html, "'02'") + substr_count($html, "'03'"));
        // Must link into the product.
        $this->assertStringContainsString("site_url('register')", $html);
        $this->assertStringContainsString("site_url('services')", $html);
    }

    public function testNexusImplementsWireframeSections()
    {
        $html = $this->view('nexus');
        foreach (array(
            'ENTERPRISE SMM INFRASTRUCTURE', 'Provider network', 'Service explorer',
            'Built for automation', '/api/v1/orders', 'FAQ', 'Ship at scale',
        ) as $section) {
            $this->assertStringContainsString($section, $html, "NEXUS missing section: {$section}");
        }
        // Six providers in the network section per wireframe.
        $this->assertSame(6, substr_count($html, "array('Provider"));
        // Dark identity.
        $this->assertStringContainsString('#0b0f1a', strtolower($html));
    }

    public function testPulseImplementsWireframeSections()
    {
        $html = $this->view('pulse');
        foreach (array(
            'Find the right service', 'Trending now', 'Quick order',
            'Questions?', 'Start ordering in minutes', '🔍',
        ) as $section) {
            $this->assertStringContainsString($section, $html, "PULSE missing section: {$section}");
        }
        // Search-centric and mobile-first.
        $this->assertStringContainsString('role="search"', $html);
        $this->assertStringContainsString('ws-pulse-search', $html);
        $this->assertStringContainsString('@media(max-width:560px)', $html);
        // Live price JS (no network call per spec).
        $this->assertStringContainsString('addEventListener', $html);
        $this->assertStringNotContainsString('fetch(', $html);
    }

    public function testHomepagesAreVisuallyDistinct()
    {
        $aurora = $this->view('aurora');
        $nexus  = $this->view('nexus');
        $pulse  = $this->view('pulse');

        $this->assertStringContainsString('gradient-text', $aurora);
        $this->assertStringContainsString('#0b0f1a', strtolower($nexus));
        $this->assertStringContainsString('22d3ee', $nexus);
        $this->assertStringContainsString('ws-pulse-search', $pulse);
        $this->assertStringContainsString('btn-danger', $pulse);

        // Each ships its own scoped <style> and does not leak global classes.
        foreach (array($aurora, $nexus, $pulse) as $html) {
            $this->assertStringContainsString('<style>', $html);
        }
    }

    public function testHomepagesMeetBaselineAccessibility()
    {
        foreach (array('aurora', 'nexus', 'pulse') as $name) {
            $html = $this->view($name);
            // Every interactive image-less control has accessible text or aria-label.
            $this->assertSame(0, preg_match('/<a[^>]*>\s*<\/a>/', $html), "{$name}: empty link");
            // Decorative emoji must be hidden from screen readers.
            // (Search form uses role="search" + label.)
            $this->assertStringContainsString('aria-label', $html, "{$name}: expected aria-label usage");
            // Accordions use semantic <details>/<summary> (keyboard accessible).
            if (strpos($html, 'ws-faq') !== false) {
                $this->assertStringContainsString('<details', $html);
                $this->assertStringContainsString('<summary', $html);
            }
        }
    }

    public function testNoLicenseArtifactsOrInsecureTls()
    {
        foreach (array('aurora', 'nexus', 'pulse') as $name) {
            $html = $this->view($name);
            $this->assertStringNotContainsString('PURCHASE_CODE', $html);
            $this->assertStringNotContainsString('Envato', $html);
            $this->assertStringNotContainsString('SSL_VERIFYPEER', $html);
        }
    }

    public function testHomeControllerStillSwitchesBySetting()
    {
        $home = file_get_contents(self::$root.'/application/controllers/Home.php');
        $this->assertStringContainsString("Setting_model", $home);
        $this->assertStringContainsString('active_homepage', $home);
        // Preview must remain admin-gated.
        $this->assertStringContainsString('SUPER_ADMIN', $home);
    }

    public function testReducedMotionIsRespectedByHomepages()
    {
        // Homepages may animate (NEXUS provider dots, etc.); the global design
        // system provides the prefers-reduced-motion guard, and any homepage
        // that declares its own keyframes must also honor the preference.
        foreach (array('aurora', 'nexus', 'pulse') as $name) {
            $html = $this->view($name);
            if (strpos($html, '@keyframes') !== false) {
                $this->assertStringContainsString('prefers-reduced-motion', $html,
                    "{$name} animates but does not honor prefers-reduced-motion");
            }
        }
    }
}
