<?php
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__).'/_support/FakeDb.php';
require_once dirname(__DIR__).'/_support/IntegrationHarness.php';

/**
 * The PIN's second life: issued at sign-up, readable by staff.
 *
 * The PIN began as a one-way hash with a deliberate "no reveal" contract —
 * staff could clear it, never read it. The operator asked for the opposite:
 * accounts should start with a working PIN, and support should be able to
 * answer "what is my PIN?" from the admin file. These tests pin what that
 * change must never quietly give up:
 *
 *   - the encrypted copy is a real envelope (not the plaintext, not the hash)
 *     and only ever travels alongside the hash;
 *   - a reveal returns exactly what was set, while the audit row records the
 *     reveal and never the PIN;
 *   - PINs chosen before the envelope existed are reported unreadable rather
 *     than guessed at;
 *   - clearing a PIN clears both halves;
 *   - a sign-up through the real AuthService leaves the account with a
 *     deliverable PIN when the setting is on, and without one when it is off.
 */
class PinVisibilityTest extends TestCase
{
    private static $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(dirname(__DIR__));
        if (!defined('BASEPATH')) define('BASEPATH', self::$root.'/system/');
        if (!class_exists('CI_Model')) eval('#[AllowDynamicProperties] class CI_Model { public $db; }');
        if (!function_exists('get_instance')) eval('function &get_instance(){ return $GLOBALS["__fake_ci"]; }');
        if (!function_exists('log_message')) eval('function log_message($l,$m){}');
        require_once self::$root.'/application/core/MY_Model.php';
        require_once self::$root.'/application/helpers/marvy_helper.php';
    }

    private function app()
    {
        $app = new IntegrationHarness();
        $app->seed_minimal();
        $app->model(array('User_model', 'Setting_model'));
        $app->library('PinService');
        return $app;
    }

    private function user($app, $username = 'pinny')
    {
        return $app->register($username, $username.'@x.test');
    }

    /* ------------------------- set / reveal round-trip ------------------- */

    public function testASetPinCanBeRevealedBackExactly()
    {
        $app = $this->app();
        $u = $this->user($app);

        $res = $app->pinservice->set($u, '8317');
        $this->assertNotEmpty($res['ok']);

        $row = $app->db->where('id', $u->id)->get('users')->row();
        $this->assertNotEmpty($row->pin_hash, 'the verification hash is kept');
        $this->assertNotEmpty($row->pin_cipher, 'the encrypted copy is kept');
        $this->assertNotSame('8317', $row->pin_cipher, 'the copy is not the plaintext');
        $this->assertNotSame($row->pin_hash, $row->pin_cipher, 'the copy is not the hash re-used');

        $out = $app->pinservice->reveal($row);
        $this->assertNotEmpty($out['ok']);
        $this->assertSame('8317', $out['pin'], 'reveal returns exactly what was set');
    }

    public function testAReplacedPinRevealsTheNewValueOnly()
    {
        $app = $this->app();
        $u = $this->user($app);
        $app->pinservice->set($u, '8317');
        $app->pinservice->set($u, '4926', '8317');

        $row = $app->db->where('id', $u->id)->get('users')->row();
        $out = $app->pinservice->reveal($row);
        $this->assertSame('4926', $out['pin']);
    }

    /* --------------------------- the legacy gap -------------------------- */

    public function testAPinSetBeforeTheEnvelopeExistedIsHonestlyUnreadable()
    {
        $app = $this->app();
        $u = $this->user($app);
        $app->pinservice->set($u, '8317');

        // Simulate a row written before migration 033: hash, no cipher.
        $app->db->where('id', $u->id)->update('users', array('pin_cipher' => null));
        $row = $app->db->where('id', $u->id)->get('users')->row();

        $out = $app->pinservice->reveal($row);
        $this->assertArrayNotHasKey('pin', $out, 'a hash-only PIN must never be guessed at');
        $this->assertSame('NOT_REVEALABLE', $out['code']);
        $this->assertStringContainsString('cannot be shown', $out['error']);
    }

    public function testAnAccountWithNoPinHasNothingToReveal()
    {
        $app = $this->app();
        $u = $this->user($app);
        $out = $app->pinservice->reveal($u);
        $this->assertSame('NO_PIN', $out['code']);
    }

    /* ------------------------- reset clears both halves ------------------ */

    public function testAnAdminResetClearsHashAndEnvelopeTogether()
    {
        $app = $this->app();
        $u = $this->user($app);
        $app->pinservice->set($u, '8317');

        $actor = $app->register('staffpin', 'staffpin@x.test', 'Str0ng!pass1', 'ADMIN');
        $app->pinservice->admin_reset($u, $actor, 'customer asked');

        $row = $app->db->where('id', $u->id)->get('users')->row();
        $this->assertEmpty($row->pin_hash);
        $this->assertEmpty($row->pin_cipher, 'the envelope must not outlive the PIN it belonged to');

        $out = $app->pinservice->reveal($row);
        $this->assertSame('NO_PIN', $out['code']);
    }

    /* ------------------------- rotation keeps the envelope --------------- */

    public function testRotationReplacesHashAndEnvelopeAndCanStillBeRevealed()
    {
        $app = $this->app();
        $u = $this->user($app);
        $app->pinservice->set($u, '8317');
        $u = $app->db->where('id', $u->id)->get('users')->row();

        $res = $app->pinservice->rotate($u);
        $this->assertNotEmpty($res['ok']);
        $row = $app->db->where('id', $u->id)->get('users')->row();
        $this->assertNotSame('8317', $app->pinservice->reveal($row)['pin'],
            'the old PIN no longer reveals');
        $this->assertSame($res['pin'], $app->pinservice->reveal($row)['pin'],
            'the rotated PIN does');
    }

    /* --------------------------- issue() at sign-up ---------------------- */

    public function testIssuingAPinSetsBothCopiesAndDeliversItOnce()
    {
        $app = $this->app();
        $u = $this->user($app);

        $res = $app->pinservice->issue($u, 'signup');
        $this->assertNotEmpty($res['ok']);
        $pin = $res['pin'];
        $this->assertMatchesRegularExpression('/^\d{4}$/', $pin);

        $row = $app->db->where('id', $u->id)->get('users')->row();
        $this->assertNotEmpty($row->pin_hash);
        $this->assertSame($pin, $app->pinservice->reveal($row)['pin']);

        // Delivered: one in-app notification and one email.
        $notes = array_filter($app->rows('notifications'),
            function ($n) use ($pin) { return strpos($n['body'], $pin) !== false; });
        $this->assertCount(1, $notes, 'the PIN reaches the in-app notification');

        $mails = array_filter($app->sent_mail,
            function ($m) use ($pin, $u) {
                return $m['to'] === $u->email && strpos($m['subject'], 'security PIN') !== false;
            });
        $this->assertCount(1, $mails, 'the PIN reaches the email queue exactly once');

        // Audited as an issuance — without the secret in the trail.
        $audit = null;
        foreach ($app->rows('audit_logs') as $a) {
            if ($a['action'] === 'security.pin_issued') { $audit = $a; break; }
        }
        $this->assertNotNull($audit, 'the issuance is audited');
        $this->assertStringNotContainsString($pin, json_encode($audit),
            'the audit trail must not contain the PIN it recorded');
    }

    public function testIssueRefusesWhenAPinAlreadyExists()
    {
        $app = $this->app();
        $u = $this->user($app);
        $app->pinservice->set($u, '8317');
        // Callers pass freshly-read rows; re-read so the guard sees the PIN.
        $u = $app->db->where('id', $u->id)->get('users')->row();

        $res = $app->pinservice->issue($u, 'signup');
        $this->assertEmpty($res['ok']);
        $this->assertSame('PIN_ALREADY_SET', $res['code']);
        $row = $app->db->where('id', $u->id)->get('users')->row();
        $this->assertSame('8317', $app->pinservice->reveal($row)['pin'],
            'the existing PIN is untouched');
    }

    /* -------------------- registration issues the start PIN -------------- */

    public function testRegistrationThroughTheRealAuthServiceLeavesAPinBehind()
    {
        $app = $this->app();
        $app->library('AuthService');

        $res = $app->authservice->register(array(
            'username' => 'newbie', 'email' => 'newbie@x.test',
            'password' => 'Str0ng!pass1', 'ip' => '127.0.0.1',
        ));
        $this->assertNotEmpty($res['ok'], 'registration itself must succeed');

        $row = $app->db->where('email', 'newbie@x.test')->get('users')->row();
        $this->assertNotEmpty($row->pin_hash, 'the new account has a PIN');
        $revealed = $app->pinservice->reveal($row);
        $this->assertNotEmpty($revealed['ok'], 'and staff can read it back');

        $delivered = array_filter($app->sent_mail,
            function ($m) { return strpos($m['subject'], 'security PIN') !== false; });
        $this->assertCount(1, $delivered, 'the customer is told their PIN once');
    }

    public function testRegistrationWithoutTheSettingLeavesThePinUnset()
    {
        $app = $this->app();
        $app->library('AuthService');

        $app->Setting_model->set('pin_issue_at_signup', false, 'security');

        $res = $app->authservice->register(array(
            'username' => 'nopin', 'email' => 'nopin@x.test',
            'password' => 'Str0ng!pass1', 'ip' => '127.0.0.1',
        ));
        $this->assertNotEmpty($res['ok']);

        $row = $app->db->where('email', 'nopin@x.test')->get('users')->row();
        $this->assertEmpty($row->pin_hash, 'no PIN is forced on the account');
        $this->assertSame('NO_PIN', $app->pinservice->reveal($row)['code']);
    }
}
