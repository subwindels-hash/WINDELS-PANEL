<?php
use PHPUnit\Framework\TestCase;

/**
 * Every link the unified history draws must resolve to a real route.
 *
 * `ActivityFeed` maps each service domain to the URL of its detail page, and
 * those maps are plain strings that nothing checked. `VTU` pointed at
 * `dashboard/vtu/<public_id>` — a URL with no route behind it, because the
 * receipt lives under `dashboard/vtu/receipt/<public_id>` — so **every VTU
 * purchase in a customer's history was a dead link**. It survived because the
 * demo dataset carried no VTU rows for the feed to render; it surfaced the
 * first time an end-to-end check made a real airtime purchase.
 *
 * A dead link on a customer's own receipts page is the kind of defect that
 * never fails a functional test (the page renders perfectly) and always
 * reaches support. This resolves each prefix against `config/routes.php` the
 * way CodeIgniter does, so the next domain added cannot ship with the same
 * fault.
 */
class ActivityFeedRoutesTest extends TestCase
{
    private static $root;
    private static $routes;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(dirname(__DIR__));
        $src = file_get_contents(self::$root.'/application/config/routes.php');
        preg_match_all("/\\\$route\\['([^']+)'\\]\\s*=/", $src, $m);
        self::$routes = $m[1];
    }

    /** The domain => URL-prefix maps, read out of the library itself. */
    private function maps()
    {
        $src = file_get_contents(self::$root.'/application/libraries/ActivityFeed.php');
        $out = array();
        foreach (array('customer_routes', 'admin_routes') as $name) {
            $this->assertSame(1, preg_match(
                '/\$'.$name.'\s*=\s*array\(([\s\S]*?)\);/', $src, $m), $name.' must be readable');
            preg_match_all("/'([A-Z_]+)'\s*=>\s*'([^']+)'/", $m[1], $pairs, PREG_SET_ORDER);
            foreach ($pairs as $pair) $out[$name][$pair[1]] = $pair[2];
        }
        return $out;
    }

    /**
     * CodeIgniter matches a route by turning `(:any)` into a regex. A prefix
     * like `dashboard/vtu/` resolves if some route matches it followed by an
     * identifier.
     */
    private function resolves($prefix)
    {
        $candidate = rtrim($prefix, '/').'/01H0EXAMPLEPUBLICID000000';
        foreach (self::$routes as $route) {
            $pattern = str_replace(
                array(':any', ':num'), array('[^/]+', '[0-9]+'),
                str_replace(array('(', ')'), array('(', ')'), $route)
            );
            $pattern = '#^'.str_replace('/', '\/', $pattern).'$#';
            if (@preg_match($pattern, $candidate)) {
                if (preg_match($pattern, $candidate)) return true;
            }
        }
        return false;
    }

    public function testTheRouteTableWasRead()
    {
        $this->assertGreaterThan(200, count(self::$routes));
    }

    public function testEveryCustomerHistoryLinkResolvesToARoute()
    {
        $dead = array();
        foreach ($this->maps()['customer_routes'] as $domain => $prefix) {
            if (!$this->resolves($prefix)) $dead[] = "$domain -> {$prefix}<id>";
        }
        $this->assertSame(array(), $dead,
            'a link on the customer\'s own history page that 404s is invisible to every '
            .'functional test and obvious to the customer');
    }

    public function testEveryStaffHistoryLinkResolvesToARoute()
    {
        $dead = array();
        foreach ($this->maps()['admin_routes'] as $domain => $prefix) {
            if (!$this->resolves($prefix)) $dead[] = "$domain -> {$prefix}<id>";
        }
        $this->assertSame(array(), $dead);
    }

    /** The VTU receipt is the specific URL that was wrong. */
    public function testTheVtuLinkPointsAtTheReceipt()
    {
        $this->assertSame('dashboard/vtu/receipt/', $this->maps()['customer_routes']['VTU']);
        $this->assertTrue($this->resolves('dashboard/vtu/receipt/'));
        $this->assertFalse($this->resolves('dashboard/vtu/'),
            'the bare prefix still has no route — which is why the map had to change');
    }
}
