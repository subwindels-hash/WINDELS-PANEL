<?php
use PHPUnit\Framework\TestCase;

/**
 * ContactMapService — the first-party contact map.
 *
 * The privacy claim is the point: a visitor who opens /contact makes requests
 * to exactly one origin. These tests pin the pieces that make that true and
 * keep it honest — the slippy-map maths, the 3×3 grid, the pin offset, the
 * 30-day caches, and every degradation path (no outbound route, a corrupt
 * tile, an unknown place) ending in "no map" rather than "broken map".
 *
 * No network: the HTTP client is an injected double and the cache is a temp
 * directory. site_url() is the suite-wide shim (whichever test class defines
 * it first), so assertions compare against site_url() rather than a host.
 */
class ContactMapServiceTest extends TestCase
{
    private static $root;
    private $cache;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(dirname(__DIR__));
        if (!defined('BASEPATH')) define('BASEPATH', self::$root.'/system/');
        if (!class_exists('CI_Model')) eval('#[AllowDynamicProperties] class CI_Model { public $db; }');
        if (!function_exists('get_instance')) eval('function &get_instance(){ return $GLOBALS["__fake_ci"]; }');
        if (!function_exists('log_message')) eval('function log_message($l,$m){}');
        if (!function_exists('site_url')) eval('function site_url($uri=""){ return "http://unit.test".($uri?"/".$uri:""); }');
        require_once self::$root.'/application/libraries/ContactMapService.php';
    }

    protected function setUp(): void
    {
        $this->cache = sys_get_temp_dir().'/marvy-map-test-'.getmypid().'-'.random_int(1, 999999);
        mkdir($this->cache, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->cache, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->cache);
    }

    private function http($responses = array(), $fail = true) {
        // $responses: url-substring => body; anything else fails.
        return new class($responses, $fail) {
            public $calls = array();
            private $responses; private $fail;
            public function __construct($responses, $fail) { $this->responses = $responses; $this->fail = $fail; }
            public function get($url, $headers = array(), $options = array()) {
                $this->calls[] = $url;
                foreach ($this->responses as $needle => $body) {
                    if (strpos($url, $needle) !== false) {
                        return array('http_code' => 200, 'body' => $body, 'request_id' => 't');
                    }
                }
                return array('http_code' => 0, 'body' => null, 'error' => 'no route', 'request_id' => 't');
            }
        };
    }

    private function service($http) { return new ContactMapService(null, $http, $this->cache); }

    private function seed_tile($z, $x, $y, $png = 'PNGBYTES') {
        $dir = $this->cache.'/tiles/'.$z.'/'.$x;
        if (!is_dir($dir)) mkdir($dir, 0775, true); // recursive alone still warns on emscripten
        file_put_contents($dir.'/'.$y.'.png', $png);
    }

    /** assertEqualsWithDelta() does not exist in the lite runner. */
    private function assertNear($expected, $actual, $delta, $msg = '') {
        $this->assertTrue(abs((float)$expected - (float)$actual) <= $delta,
            ($msg ?: 'assertNear') ." expected {$expected}±{$delta}, got {$actual}");
    }

    private function seed_geo($query, $lat, $lng, $age = 0) {
        file_put_contents($this->cache.'/geo-'.sha1($query).'.json',
            json_encode(array('time' => time() - $age, 'lat' => $lat, 'lng' => $lng)));
    }

    /* ============================ the maths ============================== */

    public function testTileCoordinatesMatchTheSlippyMap() {
        // The whole world at z=0 is one tile.
        $this->assertSame(0, ContactMapService::tile_x(0, 0));
        $this->assertSame(0, ContactMapService::tile_y(0, 0));
        $this->assertSame(0, ContactMapService::tile_x(179.9, 0));
        // At z=1 the equator/prime-meridian point sits at the centre of tile (1,1).
        $this->assertSame(1, ContactMapService::tile_x(0, 1));
        $this->assertSame(1, ContactMapService::tile_y(0, 1));
        // Abuja, Nigeria (~9.06N 7.5E) at z=15 — a known-correct cell.
        $this->assertSame(17066, ContactMapService::tile_x(7.5, 15));
        $this->assertSame(15555, ContactMapService::tile_y(9.06, 15));
        // Longitude wraps, latitude clamps at the Mercator limit.
        $this->assertSame(0, ContactMapService::tile_x(-180.0, 1));
        $this->assertSame(ContactMapService::tile_y(89.0, 5), ContactMapService::tile_y(89.9, 5));
    }

    public function testTheMarkerSitsOnTheRequestedPoint() {
        $z = 10; $n = 1 << $z;
        // A point at the exact centre of the centre tile reads 50/50.
        $lng_c = 512.5 / $n * 360.0 - 180.0;              // centre column of tile 512
        $fy_c  = 1.0 - 2.0 * 512.5 / $n;                    // mercator line for tile 512's centre
        $lat_c = rad2deg(atan(sinh(M_PI * $fy_c)));         // inverse of the tile_y math
        $x0 = ContactMapService::tile_x($lng_c, $z);
        $y0 = ContactMapService::tile_y($lat_c, $z);
        $this->assertSame(512, $x0);
        $this->assertSame(512, $y0);
        $off = ContactMapService::marker_offset($lat_c, $lng_c, $x0, $y0, $z);
        $this->assertNear(50.0, (float)$off['left'], 0.01);
        $this->assertNear(50.0, (float)$off['top'], 0.01);

        // The centre tile's left edge (lng 0.0 is tile 512's left edge) sits
        // one tile-width left of centre: 1/3 across the grid.
        $off = ContactMapService::marker_offset($lat_c, 0.0, $x0, $y0, $z);
        $this->assertNear(100.0 / 3.0, (float)$off['left'], 0.01);
        $this->assertNear(50.0, (float)$off['top'], 0.01);
    }

    public function testTheMapKeyIsStableOpaquedAndPerMap() {
        $a = ContactMapService::map_key('6.5244, 3.3792', 16);
        $this->assertSame(24, strlen($a));
        $this->assertSame($a, ContactMapService::map_key('6.5244, 3.3792', 16));
        $this->assertNotSame($a, ContactMapService::map_key('6.5244, 3.3792', 17));
        $this->assertNotSame($a, ContactMapService::map_key('6.5244, 3.3793', 16));
    }

    /* =========================== the view context ========================= */

    public function testDisabledMapRendersNothing() {
        $svc = $this->service($this->http());
        $ctx = $svc->view_context(array('map_enabled' => false, 'map_query' => '6.5,3.3'));
        $this->assertFalse($ctx['enabled']);
        $this->assertFalse($ctx['resolved']);
        $this->assertNull($ctx['tiles']);
    }

    public function testLatLngQueryNeedsNoNetworkAtAll() {
        $http = $this->http();
        $svc  = $this->service($http);
        // Seed only the centre tile (using the same maths the service uses);
        // the other eight stream in later.
        $z = 15; $x = ContactMapService::tile_x(7.5, $z); $y = ContactMapService::tile_y(9.06, $z);
        $this->seed_tile($z, $x, $y);

        $ctx = $svc->view_context(array('map_enabled' => true, 'map_query' => '9.06, 7.5', 'map_zoom' => 15));
        $this->assertTrue($ctx['enabled']);
        $this->assertTrue($ctx['resolved']);
        $this->assertCount(9, $ctx['tiles']);
        // The base is whatever site_url() of the running environment says;
        // the contract here is the path, and that it is same-origin.
        $this->assertSame(site_url('contact/map/tile/'.$ctx['map_key'].'/0/0'), $ctx['tiles'][0]);
        // The point is inside the centre tile, so the pin sits in the centre
        // third of the grid on both axes (not exactly 50 — that only holds at
        // the tile's own centre, which testTheMarker… pins precisely).
        $this->assertGreaterThanOrEqual(100.0 / 3.0 - 0.01, (float)$ctx['marker']['left']);
        $this->assertLessThanOrEqual(200.0 / 3.0 + 0.01, (float)$ctx['marker']['left']);
        $this->assertGreaterThanOrEqual(100.0 / 3.0 - 0.01, (float)$ctx['marker']['top']);
        $this->assertLessThanOrEqual(200.0 / 3.0 + 0.01, (float)$ctx['marker']['top']);
        $this->assertNotNull($ctx['search']);
        $this->assertSame(array(), $http->calls, 'lat,lng must never touch the network');
    }

    public function testAnUnresolvableMapIsOmittedNotBroken() {
        $svc = $this->service($this->http());   // no cache, no network
        $ctx = $svc->view_context(array('map_enabled' => true, 'map_query' => '9.06, 7.5', 'map_zoom' => 15));
        $this->assertTrue($ctx['enabled']);
        $this->assertFalse($ctx['resolved'], 'a map that cannot be served is omitted');
        $this->assertNull($ctx['tiles']);
        $this->assertNotNull($ctx['search'], 'the user-initiated link still works');
    }

    public function testFreeTextUsesOneCachedGeocodeAndNeverTheVisitorsNetwork() {
        $this->seed_geo('123 Main Street, Abuja', 9.0, 7.5);
        $z = 15; $this->seed_tile($z, ContactMapService::tile_x(7.5, $z), ContactMapService::tile_y(9.0, $z));

        $http = $this->http();
        $ctx  = $this->service($http)->view_context(array(
            'map_enabled' => true, 'map_query' => '123 Main Street, Abuja', 'map_zoom' => 15));
        $this->assertTrue($ctx['resolved']);
        $this->assertCount(9, $ctx['tiles']);
        $this->assertSame(array(), $http->calls, 'a fresh geocode cache means zero fetches');
    }

    public function testAStaleGeocodeSurvivesALostOutboundRoute() {
        $this->seed_geo('123 Main Street, Abuja', 9.0, 7.5, ContactMapService::CACHE_TTL + 3600);
        $z = 15; $this->seed_tile($z, ContactMapService::tile_x(7.5, $z), ContactMapService::tile_y(9.0, $z));

        $http = $this->http();   // every fetch fails
        $ctx  = $this->service($http)->view_context(array(
            'map_enabled' => true, 'map_query' => '123 Main Street, Abuja', 'map_zoom' => 15));
        $this->assertTrue($ctx['resolved'], 'a month-old address beats no address');
        $this->assertCount(1, $http->calls, 'it tried the geocoder once and kept the cache');
    }

    public function testUnknownPlacesDegradeToNoMap() {
        $ctx = $this->service($this->http())
            ->view_context(array('map_enabled' => true, 'map_query' => 'Atlantis', 'map_zoom' => 15));
        $this->assertFalse($ctx['resolved']);
        $this->assertNull($ctx['tiles']);
        $this->assertStringContainsString('Atlantis', $ctx['search']);
    }

    public function testOutOfRangeCoordinatesRefuseTheMap() {
        $ctx = $this->service($this->http())
            ->view_context(array('map_enabled' => true, 'map_query' => '95.0, 7.5', 'map_zoom' => 15));
        $this->assertFalse($ctx['resolved']);
    }

    /* ============================== tiles ================================= */

    private function seeded_map($lat = 9.06, $lng = 7.5, $zoom = 15) {
        $key = ContactMapService::map_key('9.06, 7.5', $zoom);
        file_put_contents($this->cache.'/map-'.$key.'.json', json_encode(array(
            'query' => '9.06, 7.5', 'zoom' => $zoom, 'lat' => $lat, 'lng' => $lng, 'time' => time())));
        return $key;
    }

    public function testACachedTileIsServedWithoutNetwork() {
        $http = $this->http();
        $key  = $this->seeded_map();
        $z = 15; $x = ContactMapService::tile_x(7.5, $z); $y = ContactMapService::tile_y(9.06, $z);
        $this->seed_tile($z, $x, $y, 'CENTREPNG');
        // The centre cell is grid (1,1).
        $this->assertSame('CENTREPNG', $this->service($http)->tile($key, 1, 1));
        $this->assertSame(array(), $http->calls);
    }

    public function testAMissingTileIsFetchedOnceAndCached() {
        $png = "\x89PNG\r\n\x1a\n" . str_repeat('x', 64);
        $http = $this->http(array('.png' => $png));
        $key  = $this->seeded_map();
        $z = 15; $x = ContactMapService::tile_x(7.5, $z); $y = ContactMapService::tile_y(9.06, $z);

        $this->assertSame($png, $this->service($http)->tile($key, 1, 1));
        $this->assertCount(1, $http->calls);
        $this->assertFileExists($this->cache.'/tiles/'.$z.'/'.$x.'/'.$y.'.png');

        // Second serve comes from the cache.
        $http2 = $this->http(array('.png' => $png));
        $this->assertSame($png, $this->service($http2)->tile($key, 1, 1));
        $this->assertSame(array(), $http2->calls);
    }

    public function testACorruptTileResponseIsRejected() {
        $http = $this->http(array('.png' => 'this is not a png'));
        $key  = $this->seeded_map();
        $this->assertNull($this->service($http)->tile($key, 1, 1));
        $z = 15; $x = ContactMapService::tile_x(7.5, $z); $y = ContactMapService::tile_y(9.06, $z);
        $this->assertFileDoesNotExist($this->cache.'/tiles/'.$z.'/'.$x.'/'.$y.'.png',
            'corrupt bytes are never written to the cache');
    }

    public function testUnknownMapsAndBadCellsCannotBeProbed() {
        $svc = $this->service($this->http());
        $this->assertNull($svc->tile(str_repeat('0', 24), 0, 0), 'a key with no configured map is a 404');
        $this->assertNull($svc->tile(str_repeat('a', 23), 0, 0), 'wrong length');
        $this->assertNull($svc->tile('zz'.str_repeat('a', 22), 0, 0), 'non-hex');
        $key = $this->seeded_map();
        $this->assertNull($svc->tile($key, 3, 1), 'outside the 3×3 grid');
        $this->assertNull($svc->tile($key, 1, -1), 'outside the 3×3 grid');
    }

    public function testTheEndpointOnlyReachesTheConfiguredNeighbourhood() {
        // Every cell of the grid resolves to a tile adjacent to the map's
        // centre — nothing further out is reachable through the key.
        $key = $this->seeded_map(9.06, 7.5, 15);
        $z = 15;
        $x0 = ContactMapService::tile_x(7.5, $z);
        $y0 = ContactMapService::tile_y(9.06, $z);
        for ($i = 0; $i < 3; $i++) for ($j = 0; $j < 3; $j++) {
            // Seed whatever that cell is so the fetch never happens; then the
            // service must find it — proving the cell maps onto the 3×3.
            $x = $x0 + ($i - 1); $y = $y0 + ($j - 1);
            $this->seed_tile($z, $x, $y, 'C');
        }
        $http = $this->http();
        $svc  = $this->service($http);
        for ($i = 0; $i < 3; $i++) for ($j = 0; $j < 3; $j++) {
            $this->assertSame('C', $svc->tile($key, $i, $j), "cell ($i,$j)");
        }
        $this->assertSame(array(), $http->calls);
    }
}
