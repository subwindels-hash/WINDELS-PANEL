<?php
use PHPUnit\Framework\TestCase;

/**
 * Site chrome: the menu, the footer, the announcement bar and the pages that
 * carry them.
 *
 * All of this was reported by the operator, and each item is the kind of
 * defect that no functional test notices because every page still returns 200:
 *
 *  · the public site had TWO different headers — `partials/navbar` on some
 *    pages and a hand-written one inside `layouts/public_theme` on the rest,
 *    with a different link set, a hard-coded brand name and no mobile menu;
 *  · `/api/docs` had no navigation, no footer and no announcement bar at all,
 *    so a visitor arriving from a search result had no way into the site;
 *  · the brand name was hard-coded as WINDELSOCIALS in four places, so
 *    renaming the site in settings left the old name on screen;
 *  · the announcement strip could not be edited or recoloured by an operator;
 *  · the sign-in panel's copy was `aria-hidden`, which both hid it from screen
 *    readers and — because the logo's alt text sat inside it — made the brand
 *    name run into the sentence.
 */
class SiteChromeTest extends TestCase
{
    private static $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(dirname(__DIR__));
    }

    private function view($rel)
    {
        $path = self::$root.'/application/views/'.$rel;
        $this->assertFileExists($path, $rel.' must exist');
        return file_get_contents($path);
    }

    /* ========================== one menu, everywhere ===================== */

    public function testEveryPublicShellUsesTheOneSharedNavigation()
    {
        foreach (array('layouts/main.php', 'layouts/public_theme.php') as $layout) {
            $src = $this->view($layout);
            $this->assertMatchesRegularExpression(
                "/partials\/(header|navbar)/", $src,
                $layout.' must render the shared navigation, not its own');
        }
        // The hand-written duplicate is gone for good.
        $this->assertStringNotContainsString('<header class="ws-public-header',
            $this->view('layouts/public_theme.php'),
            'a second hand-written public header is how the site ended up with two menus');
    }

    public function testTheApiReferenceCarriesTheSiteChrome()
    {
        $src = $this->view('api/docs.php');
        foreach (array('partials/navbar', 'partials/footer', 'partials/announcement') as $partial) {
            $this->assertStringContainsString($partial, $src,
                'the API reference is a public page and must not be a dead end');
        }
    }

    public function testEveryLayoutRendersAFooterAndTheAnnouncementBar()
    {
        foreach (array('main.php', 'public_theme.php', 'auth.php', 'app.php', 'app_theme.php') as $layout) {
            $src = $this->view('layouts/'.$layout);
            $this->assertMatchesRegularExpression('/partials\/(footer|app_footer)/', $src,
                $layout.' must end in a footer');
            // main.php reaches it through partials/header, which is the
            // shared "announcement + navigation" block.
            $reaches_announcement = strpos($src, 'partials/announcement') !== false
                || strpos($src, 'partials/header') !== false;
            $this->assertTrue($reaches_announcement,
                $layout.' must render the announcement bar');
        }
    }

    /* ============================ the navy chrome ======================== */

    public function testTheNavyTreatmentIsDefinedOnceForMenuAndFooter()
    {
        $css = file_get_contents(self::$root.'/assets/css/design-system.css');
        $this->assertStringContainsString('--ws-navy-900', $css, 'the palette must be a variable');

        // Each surface has to actually take the navy background, or "the menu"
        // means something different depending on which page you are on.
        foreach (array('.ws-public-nav', '.ws-auth-header', '.ws-sidebar', '.ws-footer') as $selector) {
            $this->assertMatchesRegularExpression(
                '/'.preg_quote($selector, '/').'[^{]*\{[^}]*--ws-navy-900|'
                .preg_quote($selector, '/').',[\s\S]{0,120}\{[^}]*--ws-navy-900/',
                $css, $selector.' must use the navy background');
        }
        $this->assertStringContainsString('--ws-navy-ink:#ffffff', $css,
            'white ink on navy is the whole point');
    }

    /* ======================== the brand name is settings ================= */

    public function testNoHardCodedLegacyBrandNameSurvives()
    {
        $offenders = array();
        $rii = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(self::$root.'/application'));
        foreach ($rii as $file) {
            if ($file->isDir() || substr($file->getFilename(), -4) !== '.php') continue;
            $src = file_get_contents($file->getPathname());
            if (stripos($src, 'WINDELSOCIALS') !== false) {
                $offenders[] = str_replace(self::$root.'/', '', $file->getPathname());
            }
        }
        $this->assertSame(array(), $offenders,
            'the brand name comes from settings; a hard-coded one survives a rename');
    }

    public function testTheShellsPrintTheConfiguredSiteName()
    {
        foreach (array('partials/theme/sidebar.php', 'partials/theme/dashboard_header.php') as $partial) {
            $this->assertStringContainsString('marvy_site_name', $this->view($partial),
                $partial.' must print the configured name');
        }
    }

    /* ====================== the announcement bar is data ================= */

    public function testTheAnnouncementBarIsOperatorControlled()
    {
        $src = $this->view('partials/announcement_bar.php');
        foreach (array('announcement_enabled', 'announcement_text', 'announcement_bg_color',
                       'announcement_text_color', 'announcement_speed_seconds') as $key) {
            $this->assertStringContainsString($key, $src, $key.' must be read by the bar');
        }

        $settings = file_get_contents(self::$root.'/application/libraries/SettingsService.php');
        foreach (array('announcement_enabled', 'announcement_text', 'announcement_bg_color',
                       'announcement_text_color', 'announcement_speed_seconds') as $key) {
            $this->assertStringContainsString("'".$key."'", $settings,
                $key.' must be in the settings catalogue, or no screen can edit it');
        }
        $this->assertStringContainsString("'color', 'branding'", $settings,
            'colours need the colour type so they can be validated');
    }

    /** A colour lands in a style attribute, so anything odd must be refused. */
    public function testColourSettingsAreValidatedNotEscapedAndHoped()
    {
        $src = file_get_contents(self::$root.'/application/libraries/SettingsService.php');
        $this->assertMatchesRegularExpression('/case \'color\':/', $src);
        $this->assertStringContainsString('#[0-9a-f]{6}', $src,
            'only a full hex colour may be stored');
    }

    /* ===================== the sign-in panel's arrangement =============== */

    public function testTheSignInPanelCopyIsReadableAndNotHiddenFromScreenReaders()
    {
        $src = $this->view('layouts/auth.php');
        $this->assertStringContainsString('<aside class="ws-auth-visual">', $src,
            'the panel carries real content and must not be aria-hidden');
        $this->assertStringNotContainsString('<aside class="ws-auth-visual" aria-hidden="true">', $src);
        $this->assertStringContainsString('auth_visual_title', $src,
            'the copy must be overridable so the staff door does not read as a sales pitch');

        $css = file_get_contents(self::$root.'/assets/css/design-system.css');
        $this->assertMatchesRegularExpression('/\.ws-auth-visual\{[^}]*flex-direction:column/', $css,
            'the panel stacks its logo, heading and line as blocks');
        $this->assertMatchesRegularExpression('/\.ws-auth-visual img\{\s*display:none/',
            preg_replace('/\s+/', ' ', substr($css, strpos($css, '@media(max-width:880px)'))) ?: '',
            'below 880px the photo is dropped rather than squeezed behind the words');
    }

    public function testTheBrandMarkIsASeparateBlockFromTheSignInCopy()
    {
        $src = $this->view('layouts/auth.php');
        $this->assertStringContainsString('ws-auth-visual-brand', $src,
            'the logo sits in its own block, not loose above the heading');
        $this->assertStringContainsString('ws-auth-visual-copy', $src,
            'the heading and the line are wrapped as one write-up block');
        $brand = strpos($src, 'ws-auth-visual-brand');
        $copy  = strpos($src, 'ws-auth-visual-copy');
        $this->assertNotFalse($brand);
        $this->assertNotFalse($copy);
        $this->assertLessThan($copy, $brand, 'the mark comes before the write-up');

        $css = preg_replace('/\s+/', ' ',
            file_get_contents(self::$root.'/assets/css/design-system.css'));
        $this->assertMatchesRegularExpression('/\.ws-auth-visual-brand\{[^}]*border-bottom/', $css,
            'a rule separates the mark from the sentence beneath it');
        $this->assertMatchesRegularExpression('/\.ws-auth-visual-copy\{[^}]*flex-direction:column/', $css,
            'the heading and the line stack as their own group');
    }

    /* ================= the staff door is not advertised ================== */

    public function testCustomersAreNotShownTheAdminSignInDoor()
    {
        $login = $this->view('auth/login.php');
        $this->assertStringNotContainsString('admin/login', $login,
            'the customer sign-in form must not link the back-office door');
        $this->assertStringNotContainsString('admin sign-in', $login);

        $announce = $this->view('partials/announcement_bar.php');
        $this->assertStringNotContainsString('Staff sign in at Admin login', $announce,
            'the default ticker no longer points visitors at the staff door');

        // The footer keeps the route (staff still need it once signed in) but
        // only renders it for a back-office role.
        $footer = $this->view('partials/footer.php');
        $this->assertStringContainsString('admin/login', $footer);
        $this->assertMatchesRegularExpression(
            "/SUPER_ADMIN[^)]*ADMIN[^)]*STAFF.*?admin\/login/s", $footer,
            'the staff login link is gated behind a staff role');
    }

    public function testTheStaffDoorHasItsOwnWords()
    {
        $src = file_get_contents(self::$root.'/application/controllers/Auth.php');
        $this->assertStringContainsString("'auth_visual_title' => 'Staff sign-in.'", $src);
    }

    /* ============================= the artwork =========================== */

    /**
     * The logo files themselves carried the old name. Renaming the words in
     * the markup and leaving `logo.png` reading WINDELSOCIALS is the version
     * of this fix that looks done and is not.
     */
    public function testTheBrandArtworkWasRedrawn()
    {
        $svgs = array('logo.svg', 'logo-dark.svg', 'logo-horizontal.svg',
                      'logo-icon.svg', 'favicon.svg');
        foreach ($svgs as $file) {
            $path = self::$root.'/assets/brand/'.$file;
            $this->assertFileExists($path);
            $src = file_get_contents($path);
            $this->assertStringNotContainsStringIgnoringCase('WINDELSOCIALS', $src, $file);
            if (strpos($file, 'icon') === false && strpos($file, 'favicon') === false) {
                $this->assertStringContainsString('MarvySocials', $src,
                    $file.' must carry the current wordmark');
            }
        }

        // The rasterised set has to exist and be a plausible wordmark/mark,
        // because that is what actually renders in a browser and in email.
        $expect = array(
            'logo.png' => array(4.5, 6.0), 'logo-dark.png' => array(4.5, 6.0),
            'logo-horizontal.png' => array(4.5, 6.0), 'logo-white.png' => array(4.5, 6.0),
            'logo-icon.png' => array(0.9, 1.1),
        );
        foreach ($expect as $file => $ratio) {
            $path = self::$root.'/assets/brand/'.$file;
            $this->assertFileExists($path);
            $info = getimagesize($path);
            $this->assertNotFalse($info, $file.' must decode');
            $actual = $info[0] / max(1, $info[1]);
            $this->assertGreaterThanOrEqual($ratio[0], $actual, $file.' aspect');
            $this->assertLessThanOrEqual($ratio[1], $actual, $file.' aspect');
        }

        foreach (array('favicon-16.png', 'favicon-32.png', 'favicon-48.png',
                       'icon-192.png', 'icon-512.png', 'apple-touch-icon.png',
                       'favicon.ico') as $file) {
            $this->assertFileExists(self::$root.'/assets/brand/'.$file);
        }
    }

    /**
     * A logo must render at the height its caller asked for. `.ws-logo` used
     * to pin only `max-height:2.25rem`, so the 40px footer mark drew at 36px
     * and an uploaded wordmark with small intrinsic pixels drew smaller still
     * — the same footer looking tiny on some pages.
     */
    public function testTheLogoRendersAtTheHeightItWasAskedFor()
    {
        $src = $this->view('partials/brand_logo.php');
        $this->assertMatchesRegularExpression(
            '/\$style\s*=\s*\'--ws-logo-h:\'\s*\.\s*\$h/', $src,
            'the requested height must travel to CSS, not just to the attribute');
        $this->assertStringContainsString('style="<?=htmlspecialchars($style)?>"', $src);

        $css = preg_replace('/\s+/', ' ',
            file_get_contents(self::$root.'/assets/css/design-system.css'));
        $this->assertMatchesRegularExpression(
            '/\.ws-logo[^{]*\{[^}]*height:var\(--ws-logo-h/', $css,
            'the stylesheet turns the requested height into a real height');
        $this->assertDoesNotMatchRegularExpression(
            '/\.ws-logo\{[^}]*max-height:2\.25rem/', $css,
            'the old cap silently shrank every mark taller than 36px');

        $footer = $this->view('partials/footer.php');
        $this->assertMatchesRegularExpression("/'height'\s*=>\s*44/", $footer,
            'the footer sign-off mark is the full-size placement');
        $this->assertStringContainsString('ws-footer-logo', $footer);
        $this->assertMatchesRegularExpression('/\.ws-footer-logo\{--ws-logo-h:44px\}/', $css,
            'and the footer size is stated once, in the stylesheet');
    }

    /** The declared aspect ratio must match the file, or the page jumps. */
    public function testTheLogoRatiosMatchTheShippedArtwork()
    {
        $src = $this->view('partials/brand_logo.php');
        $this->assertSame(1, preg_match('/\$ratios = array\(([^)]*)\)/', $src, $m));
        $this->assertMatchesRegularExpression("/'horizontal' => 5\.0625/", $m[1]);

        $info = getimagesize(self::$root.'/assets/brand/logo-horizontal.png');
        $this->assertLessThan(0.02, abs(5.0625 - ($info[0] / $info[1])),
            'the declared ratio and the artwork have drifted apart');
    }

    /* ============================ review photos ========================== */

    public function testTheReviewAvatarsArePhotographs()
    {
        for ($i = 1; $i <= 4; $i++) {
            $path = self::$root.'/assets/images/reviews/reviewer-'.$i.'.jpg';
            $this->assertFileExists($path);
            // The illustrated avatars were tiny flat-colour SVG exports; a
            // photograph carries far more entropy. 40 kB is comfortably above
            // the old files (7–10 kB) and below any real photo.
            $this->assertGreaterThan(40000, filesize($path),
                'reviewer-'.$i.'.jpg looks like the old illustration, not a photo');
            $info = getimagesize($path);
            $this->assertNotFalse($info, 'must decode as an image');
            $this->assertSame('image/jpeg', $info['mime']);
            $this->assertGreaterThanOrEqual(400, $info[0], 'a review photo should not be a thumbnail');
        }
    }

    public function testTheReviewAvatarNamesTheReviewer()
    {
        $src = $this->view('public/shop/product.php');
        $this->assertStringContainsString('alt="<?=htmlspecialchars($r->username)?>"', $src,
            'a review photo with an empty alt tells a screen-reader user nothing');
    }
}
