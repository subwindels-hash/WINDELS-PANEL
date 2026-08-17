<?php
use PHPUnit\Framework\TestCase;

/**
 * Design-system guards (Session 04) — validate token/stylesheet wiring without
 * a browser or Node toolchain.
 */
class DesignSystemTest extends TestCase
{
    private static $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(dirname(__DIR__));
    }

    private function get($path)
    {
        return file_get_contents(self::$root.'/'.$path);
    }

    public function testTailwindConfigDefinesBrandTokens()
    {
        $cfg = $this->get('tailwind.config.js');
        foreach (array('brand:', 'accent:', 'success:', 'warning:', 'danger:', 'info:') as $token) {
            $this->assertStringContainsString($token, $cfg, "missing token group: {$token}");
        }
        // Core brand colors used across views must be present.
        $this->assertStringContainsString("#4f46e5", $cfg); // brand-600
        $this->assertStringContainsString("#6366f1", $cfg); // brand-500
        // Inter + Fraunces are the design-system families.
        $this->assertStringContainsString('Inter', $cfg);
        $this->assertStringContainsString('Fraunces', $cfg);
    }

    public function testDesignSystemCssMirrorsBrandTokens()
    {
        $css = $this->get('assets/css/design-system.css');
        foreach (array('--brand-500', '--brand-600', '--brand-700', '--accent-500',
                        '--success-500', '--warning-500', '--danger-500', '--info-500',
                        '--font-sans', '--font-display', '--shadow-card', '--radius') as $var) {
            $this->assertStringContainsString($var.':', $css, "missing CSS variable {$var}");
        }
        // Component classes the views rely on must exist.
        foreach (array('.btn', '.btn-primary', '.card', '.input', '.badge', '.alert', '.table',
                        '.container', '.nav-link', '.badge-brand', '.btn-secondary') as $cls) {
            $this->assertStringContainsString($cls, $css, "missing component class {$cls}");
        }
    }

    public function testBrandColorsMatchBetweenTailwindAndCss()
    {
        $cfg = $this->get('tailwind.config.js');
        $css = $this->get('assets/css/design-system.css');
        // The brand ramp values must not drift between the two sources.
        foreach (array('6366f1', '4f46e5', '4338ca') as $hex) {
            $this->assertStringContainsString('#'.$hex, strtolower($cfg));
            $this->assertStringContainsString('#'.$hex, strtolower($css));
        }
    }

    public function testEveryLayoutLinksBothStylesheets()
    {
        foreach (array('public', 'auth', 'app') as $layout) {
            $src = $this->get("application/views/layouts/{$layout}.php");
            $this->assertStringContainsString('assets/css/tailwind.css', $src, "{$layout} must link tailwind.css");
            $this->assertStringContainsString('assets/css/design-system.css', $src, "{$layout} must link design-system.css");
            $this->assertStringContainsString('fonts.googleapis.com', $src, "{$layout} must load the design-system fonts");
        }
    }

    public function testStyleguideIsRoutedAndRendered()
    {
        $routes = $this->get('application/config/routes.php');
        $this->assertStringContainsString("'design-system'", $routes);
        $this->assertStringContainsString('home/styleguide', $routes);

        $home = $this->get('application/controllers/Home.php');
        $this->assertStringContainsString('function styleguide', $home);

        $view = $this->get('application/views/public/styleguide.php');
        // The styleguide should showcase the core component classes.
        foreach (array('btn-primary', 'card-title', 'badge-brand', 'alert-success', 'class="table"') as $needle) {
            $this->assertStringContainsString($needle, $view);
        }
    }

    public function testBuiltTailwindArtifactIsGitIgnored()
    {
        // tailwind.css is a generated artifact (design-system.css is the
        // committed fallback). It may exist locally after `npm run build:css`
        // but must never be tracked by git.
        $gitignore = $this->get('.gitignore');
        $this->assertStringContainsString('tailwind.css', $gitignore);
        exec('git -C '.escapeshellarg(self::$root).' ls-files --error-unmatch assets/css/tailwind.css 2>/dev/null', $out, $rc);
        $this->assertNotSame(0, $rc, 'assets/css/tailwind.css must not be tracked by git');
    }

    public function testPackageJsonExposesBuildScripts()
    {
        $pkg = json_decode($this->get('package.json'), true);
        $this->assertIsArray($pkg);
        $this->assertArrayHasKey('build:css', $pkg['scripts']);
        $this->assertArrayHasKey('watch:css', $pkg['scripts']);
        $this->assertSame('tailwindcss -i ./assets/css/app.css -o ./assets/css/tailwind.css --minified', $pkg['scripts']['build:css']);
        $this->assertArrayHasKey('tailwindcss', $pkg['devDependencies']);
    }

    public function testHomepagesUseDesignSystemAndRemainDistinct()
    {
        $aurora = $this->get('application/views/homepages/aurora/index.php');
        $nexus  = $this->get('application/views/homepages/nexus/index.php');
        $pulse  = $this->get('application/views/homepages/pulse/index.php');

        // Each uses shared component classes.
        foreach (array($aurora, $nexus, $pulse) as $html) {
            $this->assertStringContainsString('btn', $html);
            $this->assertStringContainsString('container', $html);
        }
        // Distinct identities preserved.
        $this->assertStringContainsString('gradient-text', $aurora);
        $this->assertStringContainsString('#0b0f1a', $nexus);
        $this->assertStringContainsString('22d3ee', $nexus);
        $this->assertStringContainsString('ws-pulse-search', $pulse);
        $this->assertStringContainsString('btn-danger', $pulse);
    }

    public function testReducedMotionIsRespected()
    {
        $css = $this->get('assets/css/design-system.css');
        $this->assertStringContainsString('prefers-reduced-motion', $css);
    }
}
