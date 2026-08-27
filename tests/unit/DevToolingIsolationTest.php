<?php
use PHPUnit\Framework\TestCase;

/**
 * The dev database and dev application server must stay development-only.
 *
 * They exist so the real application can be exercised end to end where MySQL
 * and PHP-FPM cannot be installed. That is only acceptable while they are
 * provably unreachable from a deployment: a SQLite-backed MySQL emulator or a
 * WASM web server in front of a payment system would be a serious problem.
 *
 * These tests are the guard rail. They fail if anything in application/ ever
 * references the tooling, or if the deployment package starts shipping it.
 */
class DevToolingIsolationTest extends TestCase
{
    private static $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(dirname(__DIR__));
    }

    /** Every PHP file the application loads at runtime. */
    private function applicationSources()
    {
        $files = array();
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(self::$root.'/application', FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
                $files[] = $file->getPathname();
            }
        }
        $files[] = self::$root.'/index.php';
        return $files;
    }

    public function testNoApplicationCodeReferencesTheDevTooling()
    {
        $offenders = array();
        foreach ($this->applicationSources() as $path) {
            // Strip comments first. A docblock may legitimately *mention* the
            // offline harness (JobRunner explains why one flock assertion is
            // skipped there); what must never exist is executable code that
            // reaches for the tooling.
            $src = file_get_contents($path);
            $code = '';
            foreach (token_get_all($src) as $token) {
                if (is_array($token)) {
                    if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) continue;
                    $code .= $token[1];
                } else {
                    $code .= $token;
                }
            }
            if (preg_match('~tools/devdb|tools/devserver|devdb/server|php-wasm~i', $code)) {
                $offenders[] = str_replace(self::$root.'/', '', $path);
            }
        }
        $this->assertSame(array(), $offenders,
            'application code must never reference the development tooling: '.implode(', ', $offenders));
    }

    public function testTheDeploymentPackageDoesNotShipTheDevTooling()
    {
        $script = file_get_contents(self::$root.'/tools/build_deployment_package.sh');

        // The packager stages an explicit allowlist. Assert tools/ is not on it
        // (the two references that do exist are the script invoking itself and
        // copying one template file).
        $this->assertStringNotContainsString('copy tools', $script,
            'the deployment package must not stage the tools/ directory');
        $this->assertStringNotContainsString('copy node_modules', $script);

        // Anything the packager does copy must be a real application path.
        preg_match_all('~^copy\s+(\S+)~m', $script, $m);
        $this->assertNotEmpty($m[1], 'the packager should stage files with copy()');
        foreach ($m[1] as $staged) {
            $this->assertNotSame(0, strpos($staged, 'tools/'),
                "the packager stages {$staged}, which is development tooling");
        }
    }

    public function testTheDevToolingIsDocumentedAsDevelopmentOnly()
    {
        $readme = self::$root.'/tools/devdb/README.md';
        $this->assertFileExists($readme,
            'the dev tooling needs a README saying plainly that it is not for production');

        $text = file_get_contents($readme);
        $this->assertStringContainsString('development and automated testing only', $text);
        // It must be honest about what it cannot prove.
        $this->assertStringContainsString('does not prove', strtolower($text));
    }

    /**
     * The dev server exists to run the real application, so it must not carry
     * its own copy of any application logic.
     */
    public function testTheDevServerContainsNoBusinessLogic()
    {
        $server = file_get_contents(self::$root.'/tools/devserver/server.mjs');
        foreach (array('wallet', 'ledger', 'password', 'order', 'invoice') as $term) {
            $this->assertStringNotContainsStringIgnoringCase($term.' =', $server,
                "the dev server must not implement {$term} logic — it only proxies to PHP");
        }
    }

    /** The dev database file must never be committed. */
    public function testTheDevDatabaseIsGitIgnored()
    {
        $ignore = file_get_contents(self::$root.'/.gitignore');
        $this->assertStringContainsString('/storage/devdb/', $ignore,
            'the SQLite dev database must not be committed');
    }
}
