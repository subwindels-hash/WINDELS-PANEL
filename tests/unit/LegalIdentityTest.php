<?php
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__).'/_support/ShellSource.php';
require_once dirname(__DIR__).'/_support/FakeDb.php';
require_once dirname(__DIR__).'/_support/IntegrationHarness.php';

/**
 * The operator's legal identity (module 19).
 *
 * The Terms, the Privacy Policy and the footer all shipped with the same
 * honest hole: "the legal entity, registered address and governing
 * jurisdiction are those of the party that deployed this instance". Honest,
 * and useless — a customer about to put money in a prepaid wallet could not
 * tell who they were contracting with, where to serve a notice, whose law
 * applied, or which regulator to complain to about their data. There was no
 * field anywhere in the panel to record any of it.
 *
 * These tests pin two things: the details are real settings an operator can
 * fill in, and **nothing is ever invented**. An unpublished identity produces
 * a page that says so, not an empty line, a stray comma or a fabricated
 * company.
 */
class LegalIdentityTest extends TestCase
{
    private static $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(dirname(__DIR__));
        if (!defined('BASEPATH')) define('BASEPATH', self::$root.'/system/');
        if (!defined('APPPATH')) define('APPPATH', self::$root.'/application/');
        if (!class_exists('CI_Model')) {
            eval('#[AllowDynamicProperties] class CI_Model { public $db; }');
        }
        if (!function_exists('get_instance')) {
            eval('function &get_instance(){ return $GLOBALS["__fake_ci"]; }');
        }
        if (!function_exists('log_message')) eval('function log_message($l,$m){}');
        require_once self::$root.'/application/core/MY_Model.php';
        require_once self::$root.'/application/helpers/marvy_helper.php';
        require_once self::$root.'/application/libraries/LegalIdentity.php';
        require_once self::$root.'/application/libraries/SettingsService.php';
    }

    protected function setUp(): void
    {
        LegalIdentity::flush();
    }

    protected function tearDown(): void
    {
        // The identity is memoised per request; a leaked memo would make the
        // next test read this one's company name.
        LegalIdentity::flush();
        unset($GLOBALS['__fake_ci']);
    }

    /** A harness whose settings table answers with $values. */
    private function app(array $values = array())
    {
        $app = new IntegrationHarness();
        $app->seed_minimal();
        $app->model(array('Setting_model'));
        foreach ($values as $key => $value) {
            $app->db->insert('settings', array(
                'setting_key'   => $key,
                'setting_value' => json_encode(array('value' => $value)),
                'category'      => 'legal',
                'is_public'     => 0,
                'updated_at'    => gmdate('Y-m-d H:i:s'),
            ));
        }
        if (class_exists('Setting_model')) Setting_model::flush_cache();
        LegalIdentity::flush();
        return $app;
    }

    private function published()
    {
        return array(
            'legal_entity_name'         => 'Marvy Digital Ltd',
            'legal_registration_number' => 'RC 1234567',
            'legal_registered_address'  => "12 Broad Street\nLagos Island\nLagos, Nigeria",
            'legal_jurisdiction'        => 'the Federal Republic of Nigeria',
            'legal_contact_email'       => 'legal@marvy.example',
        );
    }

    private function source($relative)
    {
        return file_get_contents(self::$root.'/'.$relative);
    }

    /* ===================== nothing is ever invented ====================== */

    public function testAFreshInstallPublishesNothingAndSaysSo()
    {
        $this->app();

        $this->assertFalse(LegalIdentity::is_published());
        $this->assertSame('', LegalIdentity::line(),
            'the footer must render no line at all rather than a stray comma');
        $this->assertSame('', LegalIdentity::address_inline());
        $this->assertSame(
            array('Legal entity name', 'Registered address', 'Governing law'),
            LegalIdentity::missing());
    }

    public function testAPublishedIdentityIsReadBackWholeAndFlattened()
    {
        $this->app($this->published());

        $this->assertTrue(LegalIdentity::is_published());
        $this->assertSame(array(), LegalIdentity::missing());
        $this->assertSame('12 Broad Street, Lagos Island, Lagos, Nigeria',
            LegalIdentity::address_inline(),
            'a multi-line address becomes one line for inline prose');
        $this->assertSame(
            'Marvy Digital Ltd (RC 1234567), 12 Broad Street, Lagos Island, Lagos, Nigeria',
            LegalIdentity::line());
    }

    /** A sole trader has no registration number, and the line must still read. */
    public function testTheRegistrationNumberIsOptional()
    {
        $values = $this->published();
        unset($values['legal_registration_number']);
        $this->app($values);

        $this->assertTrue(LegalIdentity::is_published());
        $this->assertSame('Marvy Digital Ltd, 12 Broad Street, Lagos Island, Lagos, Nigeria',
            LegalIdentity::line());
        $this->assertStringNotContainsString('()', LegalIdentity::line());
    }

    /**
     * An operator should not have to publish the same address twice. The
     * fallbacks exist so a notice clause never points at nothing.
     */
    public function testContactsFallBackRatherThanPointingAtNothing()
    {
        $values = $this->published();
        unset($values['legal_contact_email']);
        $app = $this->app(array_merge($values, array('support_email' => 'support@marvy.example')));

        $d = LegalIdentity::details();
        $this->assertSame('support@marvy.example', $d['legal_contact_email'],
            'the legal contact falls back to the support address');
        $this->assertSame('support@marvy.example', $d['legal_dpo_contact'],
            'and the privacy contact falls back to the legal one');
        $this->assertSame('the Federal Republic of Nigeria', $d['legal_courts'],
            'courts default to the governing jurisdiction, which is the usual arrangement');
    }

    /** A database having a bad day must not take the Terms page down. */
    public function testTheIdentityDegradesToUnpublishedWithoutADatabase()
    {
        unset($GLOBALS['__fake_ci']);
        LegalIdentity::flush();

        $this->assertFalse(LegalIdentity::is_published());
        $this->assertSame('', LegalIdentity::line());
    }

    /* ========================= the settings ============================== */

    public function testEverySettingIsInTheCatalogueUnderItsOwnGroup()
    {
        $catalogue = SettingsService::schema();

        foreach (array_keys(LegalIdentity::FIELDS) as $key) {
            $this->assertArrayHasKey($key, $catalogue, $key.' must be editable by the operator');
            $this->assertSame('legal', $catalogue[$key][1],
                $key.' belongs in the legal group, not scattered through general settings');
            $this->assertSame('', $catalogue[$key][4],
                'no legal detail may ship with an invented default');
        }
    }

    public function testTheAdminScreenNamesTheGroup()
    {
        $src = $this->source('application/views/admin/settings/index.php');
        $this->assertStringContainsString("'legal'", $src);
        $this->assertStringContainsString('Legal and company details', $src,
            'a settings group with no title renders as "Legal", which reads like a typo');
    }

    /* ====================== what the pages render ======================== */

    public function testTheTermsPageStopsSayingOperatorPlaceholderOncePublished()
    {
        $src = $this->source('application/views/public/terms.php');

        $this->assertStringContainsString('LegalIdentity::details()', $src);
        $this->assertStringContainsString('legal_entity_name', $src);
        $this->assertStringContainsString('legal_jurisdiction', $src,
            'section 20 must state the governing law when there is one');
        $this->assertStringContainsString('has not published their legal details yet', $src,
            'and must still say so plainly when there is not');
    }

    public function testThePrivacyPageNamesTheControllerAndTheRegulator()
    {
        $src = $this->source('application/views/public/privacy.php');

        $this->assertStringContainsString('legal_dpo_contact', $src);
        $this->assertStringContainsString('legal_supervisory_authority', $src,
            'a GDPR-style notice has to say who the customer can complain to');
        $this->assertStringContainsString('controller has not been published yet', $src);
    }

    /** The footer must never render an empty "Operated by" line. */
    public function testTheFooterOnlyPrintsTheOperatorWhenThereIsOne()
    {
        $src = $this->source('application/views/partials/footer.php');

        $this->assertStringContainsString('LegalIdentity::line()', $src);
        $this->assertMatchesRegularExpression("/if\s*\(\\\$legal_line\s*!==\s*''\)/", $src);
    }

    /**
     * Preflight has to mention it — otherwise an operator who never opens the
     * Terms page never learns their customers are being told nobody is home.
     * A WARN, not a FAIL: unfinished paperwork must not stop a panel booting.
     */
    public function testPreflightWarnsButDoesNotBlockOnAnUnpublishedIdentity()
    {
        $src = $this->source('application/libraries/Preflight.php');

        $this->assertStringContainsString('check_legal_identity', $src);
        $this->assertStringContainsString("result('legal_identity', self::WARN", $src);
        $this->assertStringNotContainsString("result('legal_identity', self::FAIL", $src);
    }
}
