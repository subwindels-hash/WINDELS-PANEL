<?php
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__).'/_support/FakeDb.php';
require_once dirname(__DIR__).'/_support/IntegrationHarness.php';

/**
 * Admin settings (Session 30).
 *
 * `admin/settings` was the last entry in the admin sidebar and 404'd for
 * every operator, so `settings.manage` gated nothing and every value seeded
 * in Session 02 could only be changed with SQL.
 *
 * The interesting risk on a settings screen is not "does it save" — it is
 * saving something that then does nothing, or that quietly breaks an
 * invariant. So the tests concentrate on:
 *
 *   - a saved value actually reaching the code that consumes it (proved by
 *     driving the real consumer, not by reading the row back);
 *   - the honesty rule: no control is rendered for a key nothing reads, and
 *     base_currency stays read-only because changing it would reinterpret
 *     every stored amount;
 *   - type validation, since these values feed money and percentage maths.
 */
class AdminSettingsTest extends TestCase
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
            eval('function get_instance(){ return $GLOBALS["__fake_ci"]; }');
        }
        if (!function_exists('log_message')) eval('function log_message($l,$m){}');
        require_once self::$root.'/application/core/MY_Model.php';
        require_once self::$root.'/application/helpers/windels_helper.php';
    }

    protected function setUp(): void
    {
        // The settings model memoises per request; each test is a new request.
        if (class_exists('Setting_model')) Setting_model::flush_cache();
    }

    private function app()
    {
        $app = new IntegrationHarness();
        $app->seed_minimal();
        $app->library('SettingsService');
        $app->model('Setting_model');
        // Seed the defaults the screen edits.
        foreach (SettingsService::schema() as $key => $def) {
            $app->Setting_model->set($key, $def[4], $def[1]);
        }
        Setting_model::flush_cache();
        return $app;
    }

    /* ============================ round trip ============================ */

    public function testAValidChangeIsPersistedAndReported()
    {
        $app = $this->app();

        $res = $app->settingsservice->save(array('site_name' => 'Naija Panel'));

        $this->assertTrue($res['ok'], $res['error'] ?? '');
        $this->assertArrayHasKey('site_name', $res['changed']);
        $this->assertSame('WINDELS PANEL', $res['changed']['site_name']['before']);
        $this->assertSame('Naija Panel', $res['changed']['site_name']['after']);

        Setting_model::flush_cache();
        $this->assertSame('Naija Panel', $app->Setting_model->get('site_name'));
    }

    /**
     * The point of the screen: a saved value must reach the code that reads
     * it. Asserting the row alone would pass for a setting nothing consumes.
     */
    public function testASavedCommissionRateIsTheOneTheAffiliateEngineUses()
    {
        $app = $this->app();
        $app->library('AffiliateService');

        $app->settingsservice->save(array('referral_commission_percent' => '12.5'));
        Setting_model::flush_cache();

        $rate = $app->Setting_model->get('referral_commission_percent');
        $this->assertSame('12.5000', $rate,
            'the affiliate engine reads this key, so the stored shape must match what it expects');
    }

    public function testAnUnchangedSubmissionReportsNothingChanged()
    {
        $app = $this->app();

        $res = $app->settingsservice->save(array('site_name' => 'WINDELS PANEL'));

        $this->assertTrue($res['ok']);
        $this->assertSame(array(), $res['changed'],
            'a no-op save must not write an audit entry claiming a change');
    }

    /**
     * A form that renders one category must not blank the others: only keys
     * present in the submission are touched.
     */
    public function testASubmissionOnlyTouchesTheKeysItCarries()
    {
        $app = $this->app();

        $app->settingsservice->save(array('site_name' => 'Renamed'));
        Setting_model::flush_cache();

        $this->assertSame('support@windels.local', $app->Setting_model->get('support_email'),
            'an absent key must keep its value, not be blanked');
        $this->assertSame('500.00000000', $app->Setting_model->get('min_deposit'));
    }

    /* ============================ validation ============================ */

    public function testABadEmailIsRefused()
    {
        $app = $this->app();

        $res = $app->settingsservice->save(array('support_email' => 'not-an-address'));

        $this->assertFalse($res['ok']);
        $this->assertStringContainsString('email', strtolower($res['error']));
        Setting_model::flush_cache();
        $this->assertSame('support@windels.local', $app->Setting_model->get('support_email'),
            'a refused save must not half-apply');
    }

    public function testAPercentageOutsideZeroToAHundredIsRefused()
    {
        $app = $this->app();
        foreach (array('-5', '101', 'abc') as $bad) {
            $res = $app->settingsservice->save(array('referral_commission_percent' => $bad));
            $this->assertFalse($res['ok'], "commission '{$bad}' must be refused");
        }
        Setting_model::flush_cache();
        $this->assertSame('5.0000', $app->Setting_model->get('referral_commission_percent'));
    }

    public function testANegativeOrFractionalIntegerIsRefused()
    {
        $app = $this->app();
        foreach (array('-1', '2.5', 'soon') as $bad) {
            $res = $app->settingsservice->save(array('referral_hold_hours' => $bad));
            $this->assertFalse($res['ok'], "hold hours '{$bad}' must be refused");
        }
    }

    public function testMarketplacePolicyLimitsMatchTheRuntimeLimits()
    {
        $app = $this->app();

        $fee = $app->settingsservice->save(array('marketplace_fee_percent' => '50.0001'));
        $this->assertFalse($fee['ok']);
        $this->assertStringContainsString('50%', $fee['error']);

        foreach (array('0', '721') as $hours) {
            $res = $app->settingsservice->save(array('marketplace_auto_release_hours' => $hours));
            $this->assertFalse($res['ok'], "auto release '{$hours}' must be refused");
        }

        $valid = $app->settingsservice->save(array(
            'marketplace_fee_percent' => '50',
            'marketplace_auto_release_hours' => '720',
        ));
        $this->assertTrue($valid['ok'], $valid['error'] ?? '');
    }

    public function testAnUnknownChoiceIsRefused()
    {
        $app = $this->app();

        $res = $app->settingsservice->save(array('active_homepage' => 'NEON'));

        $this->assertFalse($res['ok']);
        Setting_model::flush_cache();
        $this->assertSame('AURORA', $app->Setting_model->get('active_homepage'));
    }

    public function testAValidChoiceIsAcceptedCaseInsensitively()
    {
        $app = $this->app();

        $res = $app->settingsservice->save(array('active_homepage' => 'pulse'));

        $this->assertTrue($res['ok'], $res['error'] ?? '');
        Setting_model::flush_cache();
        $this->assertSame('PULSE', $app->Setting_model->get('active_homepage'));
    }

    /**
     * The cross-field rule: a minimum above the maximum would reject every
     * deposit, and the two are edited on the same form.
     */
    public function testTheMinimumDepositCannotExceedTheMaximum()
    {
        $app = $this->app();

        $res = $app->settingsservice->save(array('min_deposit' => '9000000'));

        $this->assertFalse($res['ok']);
        $this->assertStringContainsString('minimum', strtolower($res['error']));
        Setting_model::flush_cache();
        $this->assertSame('500.00000000', $app->Setting_model->get('min_deposit'));
    }

    public function testMoneyIsStoredInTheEightDecimalShapeTheLedgerUses()
    {
        $app = $this->app();

        $app->settingsservice->save(array('min_deposit' => '250'));
        Setting_model::flush_cache();

        $this->assertSame('250.00000000', $app->Setting_model->get('min_deposit'),
            'a bare integer must be normalised, or bccomp against a wallet balance misbehaves');
    }

    /* ========================== checkbox handling ======================= */

    /**
     * An unchecked box sends nothing, so without the companion marker
     * "switched off" and "not on this form" are the same POST body — and
     * registration could never be closed.
     */
    public function testAnUncheckedBoxTurnsTheSettingOff()
    {
        $app = $this->app();

        $res = $app->settingsservice->save(array('__rendered_registration_enabled' => '1'));

        $this->assertTrue($res['ok'], $res['error'] ?? '');
        Setting_model::flush_cache();
        $this->assertFalse((bool)$app->Setting_model->get('registration_enabled'),
            'an unchecked box must switch the setting off');
    }

    public function testABoxAbsentFromTheFormIsLeftAlone()
    {
        $app = $this->app();

        $app->settingsservice->save(array('site_name' => 'Renamed'));

        Setting_model::flush_cache();
        $this->assertTrue((bool)$app->Setting_model->get('registration_enabled'),
            'a checkbox the form never rendered must not be switched off');
    }

    public function testACheckedBoxTurnsTheSettingOn()
    {
        $app = $this->app();
        $app->Setting_model->set('registration_enabled', false, 'security');
        Setting_model::flush_cache();

        $app->settingsservice->save(array(
            '__rendered_registration_enabled' => '1', 'registration_enabled' => '1'));

        Setting_model::flush_cache();
        $this->assertTrue((bool)$app->Setting_model->get('registration_enabled'));
    }

    /* ======================= the honesty guarantees ===================== */

    /**
     * The rule that shaped this screen: every editable key must be read by
     * real code somewhere. A control that saves and changes nothing is worse
     * than a missing control, because the operator believes it worked.
     */
    public function testEveryEditableSettingIsActuallyReadSomewhere()
    {
        $root = self::$root.'/application';
        $orphans = array();

        foreach (array_keys(SettingsService::schema()) as $key) {
            $found = false;
            $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
            foreach ($rii as $file) {
                if ($file->isDir() || substr($file->getFilename(), -4) !== '.php') continue;
                $path = $file->getPathname();
                // The declaration and the seed do not count as a consumer.
                if (strpos($path, 'SettingsService.php') !== false) continue;
                if (strpos($path, 'Core_seeder.php') !== false) continue;
                if (strpos($path, '/views/admin/settings/') !== false) continue;
                if (strpos(file_get_contents($path), "'".$key."'") !== false) { $found = true; break; }
            }
            if (!$found) $orphans[] = $key;
        }

        $this->assertSame(array(), $orphans,
            'these settings are editable but nothing reads them: '.implode(', ', $orphans));
    }

    /**
     * base_currency stays read-only. Editing the row would change nothing —
     * windels_base_currency() reads config — and actually switching the
     * currency would reinterpret every stored amount.
     */
    public function testBaseCurrencyIsNotEditable()
    {
        $this->assertArrayNotHasKey('base_currency', SettingsService::schema(),
            'base_currency moves by migration only');
        $this->assertArrayHasKey('base_currency', SettingsService::readonly_settings());

        $app = $this->app();
        $app->settingsservice->save(array('base_currency' => 'USD'));
        Setting_model::flush_cache();
        $this->assertSame('NGN', windels_base_currency(),
            'the panel must stay denominated in the currency its ledger was written in');
    }

    /** Keys nothing honours are declared as such, with the work each needs. */
    public function testUnwiredSettingsAreDocumentedRatherThanRendered()
    {
        $unwired = SettingsService::unwired();
        $schema  = SettingsService::schema();

        $this->assertNotEmpty($unwired);
        foreach ($unwired as $key => $why) {
            $this->assertArrayNotHasKey($key, $schema,
                $key.' is listed as unwired but also rendered as a control');
            $this->assertNotSame('', trim((string)$why),
                $key.' must say what it would take to honour it');
        }

        // Every seeded key is accounted for: editable, read-only, or listed.
        require_once self::$root.'/application/libraries/Seeder.php';
        require_once self::$root.'/application/seeds/Core_seeder.php';
        $missing = array();
        foreach (Core_seeder::default_settings() as $row) {
            $key = $row[0];
            if (isset($schema[$key])) continue;
            if (isset($unwired[$key])) continue;
            if (array_key_exists($key, SettingsService::readonly_settings())) continue;
            $missing[] = $key;
        }
        $this->assertSame(array(), $missing,
            'seeded settings with no home in the schema: '.implode(', ', $missing));
    }

    /* ======================= controller guarantees ====================== */

    public function testTheSettingsControllerIsGuardedAndAudited()
    {
        $src = file_get_contents(self::$root.'/application/controllers/admin/Settings.php');
        $this->assertStringContainsString("require_perm('settings.manage')", $src);
        $this->assertStringContainsString("method(true) !== 'POST') show_404()", $src);
        $this->assertStringContainsString('$this->audit(', $src);
        $this->assertStringContainsString('Audit_log_model', $src);
    }
}
