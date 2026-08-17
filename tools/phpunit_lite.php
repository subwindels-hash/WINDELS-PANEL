<?php
/**
 * phpunit_lite.php — a tiny, dependency-free runner for the PHPUnit tests in tests/unit.
 *
 * CI and local dev with composer installed should use the real PHPUnit
 * (`vendor/bin/phpunit`). This runner exists so the suite can also be executed in
 * environments without composer/network access — it implements the small subset of
 * PHPUnit\Framework\TestCase used by this repository and nothing more.
 *
 *   php tools/phpunit_lite.php            # run tests/unit
 *   php tools/phpunit_lite.php SchemaTest # run one class
 */

namespace PHPUnit\Framework {

    class AssertionFailedError extends \Exception {}
    class SkippedTest extends \Exception {}

    abstract class TestCase
    {
        public static $assertions = 0;

        public static function setUpBeforeClass(): void {}
        public static function tearDownAfterClass(): void {}
        protected function setUp(): void {}
        protected function tearDown(): void {}

        protected function markTestSkipped($m = '') { throw new SkippedTest($m); }

        private static function pass() { self::$assertions++; }

        private static function fail($message, $detail = '')
        {
            throw new AssertionFailedError(trim($message."\n".$detail));
        }

        private static function export($v)
        {
            if (is_string($v)) return strlen($v) > 200 ? "'".substr($v, 0, 200)."…'" : "'{$v}'";
            if (is_bool($v)) return $v ? 'true' : 'false';
            if (is_null($v)) return 'null';
            if (is_array($v)) return 'array('.count($v).') '.substr(json_encode($v), 0, 200);
            if (is_object($v)) return get_class($v);
            return (string)$v;
        }

        public static function assertTrue($c, $m = '') { $c === true ? self::pass() : self::fail($m ?: 'Failed asserting that value is true.', self::export($c)); }
        public static function assertFalse($c, $m = '') { $c === false ? self::pass() : self::fail($m ?: 'Failed asserting that value is false.', self::export($c)); }
        public static function assertNull($v, $m = '') { $v === null ? self::pass() : self::fail($m ?: 'Failed asserting that value is null.', self::export($v)); }
        public static function assertNotNull($v, $m = '') { $v !== null ? self::pass() : self::fail($m ?: 'Failed asserting that value is not null.'); }

        public static function assertSame($e, $a, $m = '')
        {
            $e === $a ? self::pass() : self::fail($m ?: 'Failed asserting two values are identical.', 'expected: '.self::export($e)."\n     actual: ".self::export($a));
        }
        public static function assertEquals($e, $a, $m = '')
        {
            $e == $a ? self::pass() : self::fail($m ?: 'Failed asserting two values are equal.', 'expected: '.self::export($e)."\n     actual: ".self::export($a));
        }
        public static function assertNotSame($e, $a, $m = '') { $e !== $a ? self::pass() : self::fail($m ?: 'Failed asserting two values are different.'); }
        public static function assertNotFalse($v, $m = '') { $v !== false ? self::pass() : self::fail($m ?: 'Failed asserting that value is not false.'); }
        public static function assertNotTrue($v, $m = '') { $v !== true ? self::pass() : self::fail($m ?: 'Failed asserting that value is not true.'); }
        public static function assertNotEquals($e, $a, $m = '') { $e != $a ? self::pass() : self::fail($m ?: 'Failed asserting two values are not equal.'); }
        public static function assertIsNumeric($v, $m = '') { is_numeric($v) ? self::pass() : self::fail($m ?: 'Failed asserting that value is numeric.', self::export($v)); }
        public static function assertIsObject($v, $m = '') { is_object($v) ? self::pass() : self::fail($m ?: 'Failed asserting that value is an object.', self::export($v)); }
        public static function assertFileDoesNotExist($p, $m = '') { !file_exists($p) ? self::pass() : self::fail($m ?: "Failed asserting that file {$p} does not exist."); }
        public static function assertStringEndsWith($x, $s, $m = '') { substr((string)$s, -strlen((string)$x)) === (string)$x ? self::pass() : self::fail($m ?: 'Failed asserting that string ends with '.self::export($x).'.'); }

        public static function assertCount($n, $x, $m = '')
        {
            count($x) === $n ? self::pass() : self::fail($m ?: 'Failed asserting count.', 'expected: '.$n.', actual: '.count($x));
        }
        public static function assertEmpty($x, $m = '') { empty($x) ? self::pass() : self::fail($m ?: 'Failed asserting that value is empty.'); }
        public static function assertNotEmpty($x, $m = '') { !empty($x) ? self::pass() : self::fail($m ?: 'Failed asserting that value is not empty.'); }

        public static function assertContains($needle, $haystack, $m = '')
        {
            in_array($needle, $haystack, true) ? self::pass() : self::fail($m ?: 'Failed asserting that array contains '.self::export($needle).'.');
        }
        public static function assertNotContains($needle, $haystack, $m = '')
        {
            !in_array($needle, $haystack, true) ? self::pass() : self::fail($m ?: 'Failed asserting that array does not contain '.self::export($needle).'.');
        }
        public static function assertArrayHasKey($k, $a, $m = '')
        {
            array_key_exists($k, $a) ? self::pass() : self::fail($m ?: 'Failed asserting that array has key '.self::export($k).'.');
        }
        public static function assertArrayNotHasKey($k, $a, $m = '')
        {
            !array_key_exists($k, $a) ? self::pass() : self::fail($m ?: 'Failed asserting that array lacks key '.self::export($k).'.');
        }

        public static function assertStringContainsString($needle, $haystack, $m = '')
        {
            strpos((string)$haystack, (string)$needle) !== false ? self::pass() : self::fail($m ?: 'Failed asserting that string contains '.self::export($needle).'.');
        }
        public static function assertStringNotContainsString($needle, $haystack, $m = '')
        {
            strpos((string)$haystack, (string)$needle) === false ? self::pass() : self::fail($m ?: 'Failed asserting that string does not contain '.self::export($needle).'.');
        }
        public static function assertStringContainsStringIgnoringCase($needle, $haystack, $m = '')
        {
            stripos((string)$haystack, (string)$needle) !== false ? self::pass() : self::fail($m ?: 'Failed asserting that string contains (ci) '.self::export($needle).'.');
        }
        public static function assertStringNotContainsStringIgnoringCase($needle, $haystack, $m = '')
        {
            stripos((string)$haystack, (string)$needle) === false ? self::pass() : self::fail($m ?: 'Failed asserting that string does not contain (ci) '.self::export($needle).'.');
        }
        public static function assertStringStartsWith($p, $s, $m = '')
        {
            strpos((string)$s, (string)$p) === 0 ? self::pass() : self::fail($m ?: 'Failed asserting that string starts with '.self::export($p).'.');
        }

        public static function assertMatchesRegularExpression($pattern, $subject, $m = '')
        {
            preg_match($pattern, (string)$subject) === 1 ? self::pass() : self::fail($m ?: 'Failed asserting that subject matches '.$pattern.'.');
        }
        public static function assertDoesNotMatchRegularExpression($pattern, $subject, $m = '')
        {
            preg_match($pattern, (string)$subject) === 0 ? self::pass() : self::fail($m ?: 'Failed asserting that subject does not match '.$pattern.'.');
        }

        public static function assertGreaterThan($e, $a, $m = '') { $a > $e ? self::pass() : self::fail($m ?: "Failed asserting that {$a} > {$e}."); }
        public static function assertGreaterThanOrEqual($e, $a, $m = '') { $a >= $e ? self::pass() : self::fail($m ?: "Failed asserting that {$a} >= {$e}."); }
        public static function assertLessThan($e, $a, $m = '') { $a < $e ? self::pass() : self::fail($m ?: "Failed asserting that {$a} < {$e}."); }
        public static function assertLessThanOrEqual($e, $a, $m = '') { $a <= $e ? self::pass() : self::fail($m ?: "Failed asserting that {$a} <= {$e}."); }

        public static function assertIsString($v, $m = '') { is_string($v) ? self::pass() : self::fail($m ?: 'Failed asserting that value is a string.', self::export($v)); }
        public static function assertIsArray($v, $m = '') { is_array($v) ? self::pass() : self::fail($m ?: 'Failed asserting that value is an array.', self::export($v)); }
        public static function assertIsInt($v, $m = '') { is_int($v) ? self::pass() : self::fail($m ?: 'Failed asserting that value is an int.', self::export($v)); }
        public static function assertIsBool($v, $m = '') { is_bool($v) ? self::pass() : self::fail($m ?: 'Failed asserting that value is a bool.', self::export($v)); }
        public static function assertFileExists($p, $m = '') { file_exists($p) ? self::pass() : self::fail($m ?: "Failed asserting that file {$p} exists."); }
        public static function assertDirectoryExists($p, $m = '') { is_dir($p) ? self::pass() : self::fail($m ?: "Failed asserting that directory {$p} exists."); }
        public static function assertInstanceOf($class, $v, $m = '') { $v instanceof $class ? self::pass() : self::fail($m ?: "Failed asserting instance of {$class}."); }
    }
}

namespace {

    use PHPUnit\Framework\AssertionFailedError;
    use PHPUnit\Framework\SkippedTest;
    use PHPUnit\Framework\TestCase;

    $root = dirname(__DIR__);
    $filter = isset($argv[1]) ? $argv[1] : null;

    /** PHPUnit's setUp()/tearDown() are protected; call them the way PHPUnit does. */
    function invoke_protected($instance, $method) {
        $ref = new \ReflectionMethod($instance, $method);
        if (PHP_VERSION_ID < 80100) $ref->setAccessible(true);
        $ref->invoke($instance);
    }

    $files = glob($root.'/tests/unit/*Test.php');
    sort($files);

    $declared_before = get_declared_classes();
    foreach ($files as $file) { require_once $file; }
    $classes = array_values(array_diff(get_declared_classes(), $declared_before));

    $passed = $failed = $skipped = 0;
    $failures = array();
    $started = microtime(true);

    echo "PHPUnit-lite (offline runner) — PHP ".PHP_VERSION."\n\n";

    foreach ($classes as $class) {
        if (!is_subclass_of($class, TestCase::class)) continue;
        if ($filter !== null && stripos($class, $filter) === false) continue;

        echo $class."\n";
        try {
            call_user_func(array($class, 'setUpBeforeClass'));
        } catch (\Throwable $e) {
            echo "  ✘ setUpBeforeClass — ".$e->getMessage()."\n";
            $failed++;
            $failures[] = $class.'::setUpBeforeClass — '.$e->getMessage();
            continue;
        }

        $methods = get_class_methods($class);
        sort($methods);
        foreach ($methods as $method) {
            if (strpos($method, 'test') !== 0) continue;
            $instance = new $class();
            try {
                invoke_protected($instance, 'setUp');
                $instance->$method();
                invoke_protected($instance, 'tearDown');
                echo "  ✔ ".$method."\n";
                $passed++;
            } catch (SkippedTest $e) {
                echo "  ○ ".$method." (skipped: ".$e->getMessage().")\n";
                $skipped++;
            } catch (AssertionFailedError $e) {
                echo "  ✘ ".$method."\n      ".str_replace("\n", "\n      ", $e->getMessage())."\n";
                $failed++;
                $failures[] = $class.'::'.$method."\n    ".$e->getMessage();
            } catch (\Throwable $e) {
                echo "  ✘ ".$method." — ".get_class($e).': '.$e->getMessage()."\n";
                $failed++;
                $failures[] = $class.'::'.$method.' — '.$e->getMessage();
            }
        }
        call_user_func(array($class, 'tearDownAfterClass'));
        echo "\n";
    }

    printf(
        "Tests: %d, Assertions: %d, Failures: %d, Skipped: %d — %.2fs\n",
        $passed + $failed + $skipped,
        TestCase::$assertions,
        $failed,
        $skipped,
        microtime(true) - $started
    );

    if ($failures) {
        echo "\nFAILURES:\n";
        foreach ($failures as $i => $f) { echo '  '.($i + 1).') '.$f."\n"; }
        exit(1);
    }
    echo "OK\n";
    exit(0);
}
