<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * ContactMapService — a first-party map for the contact page.
 *
 * The contact map used to be a third-party iframe (OpenStreetMap embed or
 * Google's keyless `output=embed`). An iframe is a request to someone else's
 * origin: the visitor's IP goes to OpenStreetMap or Google for a page that
 * exists to show a street address, and an EU operator must disclose that
 * tracking in the privacy policy.
 *
 * This service renders the same map from the panel's own origin instead:
 *
 *   · "lat,lng" queries resolve locally (no network at all);
 *   · free-text queries are geocoded by the SERVER (Nominatim, OSM's public
 *     keyless geocoder) once per address, then cached for 30 days — the
 *     visitor's browser never talks to the geocoder;
 *   · the map is nine OSM tiles (a 3×3 grid around the point) fetched by the
 *     SERVER on first view and cached for 30 days, served back by the panel
 *     as /contact/map/tile/… with a 30-day Cache-Control;
 *   · the tile URL carries a 96-bit reference to the configured map, not raw
 *     coordinates, so the endpoint cannot be used to proxy arbitrary OSM
 *     tiles — only the nine around the operator's own address;
 *   · if the server has no outbound route (or a fetch fails), the map box is
 *     omitted entirely and the page falls back to the printed address, phone
 *     and hours plus the user-initiated "Open in maps" link. No broken
 *     image, no iframe, nothing to disclose.
 *
 * The result: a visitor who opens /contact makes requests to exactly one
 * origin — this one.
 */
class ContactMapService {

    /** OSM's public tile server (the same tiles the old embed showed). */
    const TILE_URL = 'https://tile.openstreetmap.org/%d/%d/%d.png';

    /** OSM's public keyless geocoder (server-side only, one hit per month). */
    const GEO_URL = 'https://nominatim.openstreetmap.org/search';

    /** A descriptive, contactable identity — both OSM policies ask for one. */
    const USER_AGENT = 'marvysocials contact page (server-side cache; one request per location per month)';

    /** Tiles and geocodes are geography, not news: 30 days is enough. */
    const CACHE_TTL = 2592000;

    /** A 768×768 grid of 256 px tiles, centred on the point. */
    const GRID = 3;
    const TILE_PX = 256;

    /** A tile that is not a tiny PNG is not a tile (defense in depth). */
    const MAX_TILE_BYTES = 2 * 1024 * 1024;

    private $ci;
    private $http;       // injectable double for tests; defaults to SecureHttpClient
    private $dir;        // cache root: storage/cache/maps

    public function __construct($ci = null, $http = null, $cache_dir = null) {
        $this->ci   = $ci !== null ? $ci : get_instance();
        $this->http = $http;
        if ($cache_dir !== null) {
            $this->dir = rtrim($cache_dir, '/');
        } else {
            require_once APPPATH.'core/Env.php';
            $this->dir = rtrim(Env::writable_paths()['cache'], '/').'/maps';
        }
    }

    /* =============================== the view ============================ */

    /**
     * Build the context the contact view renders.
     *
     * @param array $contact_details map_enabled / map_query / map_zoom
     * @return array enabled, resolved, map_key, tiles (9 URLs or null),
     *               marker (percent offsets or null), search (user-initiated
     *               "Open in maps" URL or null)
     */
    public function view_context(array $contact_details) {
        $ctx = array(
            'enabled'  => false,
            'resolved' => false,
            'map_key'  => null,
            'tiles'    => null,
            'marker'   => null,
            'search'   => null,
        );
        if (empty($contact_details['map_enabled'])) return $ctx;
        $query = trim((string)($contact_details['map_query'] ?? ''));
        $zoom  = max(1, min(20, (int)($contact_details['map_zoom'] ?? 15)));
        if ($query === '') return $ctx;
        $ctx['enabled'] = true;

        $key   = self::map_key($query, $zoom);
        $point = $this->resolve_query($query, $zoom, $key);

        $ctx['map_key'] = $key;
        $ctx['search']  = $this->search_url($query, $point);
        if (!$point) return $ctx;   // nothing to draw, and nothing to lie about

        $z = $zoom;
        $x = self::tile_x($point['lng'], $z);
        $y = self::tile_y($point['lat'], $z);

        // The centre tile must be servable or the box would render as a
        // broken picture; the other eight stream in from the same cache.
        if ($this->tile_bytes($z, $x, $y) === null) return $ctx;

        // Row-major: tiles[j*3+i] is column i, row j.
        $base = site_url('contact/map/tile/'.$key);
        $tiles = array();
        for ($j = 0; $j < self::GRID; $j++) {
            for ($i = 0; $i < self::GRID; $i++) {
                $tiles[] = $base.'/'.$i.'/'.$j;   // column i (x), row j (y)
            }
        }
        $ctx['tiles']  = $tiles;
        $ctx['marker'] = self::marker_offset($point['lat'], $point['lng'], $x, $y, $z);
        $ctx['resolved'] = true;
        return $ctx;
    }

    /* =============================== tiles =============================== */

    /**
     * Serve one grid cell of the configured map.
     *
     * @param string $map_key 24-hex reference to the configured map
     * @param int    $i       column 0..2
     * @param int    $j       row 0..2
     * @return string|null PNG bytes, or null to 404
     */
    public function tile($map_key, $i, $j) {
        if (!preg_match('/^[a-f0-9]{24}$/', (string)$map_key)) return null;
        $i = (int)$i; $j = (int)$j;
        if ($i < 0 || $i > self::GRID - 1 || $j < 0 || $j > self::GRID - 1) return null;

        $map = $this->read_map_file((string)$map_key);
        if (!$map) return null;

        $z = (int)$map['zoom'];
        $x0 = self::tile_x($map['lng'], $z);
        $y0 = self::tile_y($map['lat'], $z);
        $wrap = 1 << $z;
        $x = (($x0 + ($i - 1) + $wrap) % $wrap + $wrap) % $wrap;
        $y = $y0 + ($j - 1);
        if ($y < 0 || $y > $wrap - 1) return null;   // pole: no tile exists

        return $this->tile_bytes($z, $x, $y);
    }

    /* ============================== resolution =========================== */

    /**
     * Resolve the operator's query to a point.
     *
     * "lat,lng" is local maths. Free text goes to Nominatim through the
     * server, once per address, cached 30 days. A failed fetch falls back to
     * a stale cache entry (an old address beats no address); with no cache at
     * all the map is omitted.
     *
     * @return array{lat:float,lng:float}|null
     */
    private function resolve_query($query, $zoom, $map_key) {
        if (preg_match('/^\s*(-?\d{1,2}(?:\.\d+)?)\s*,\s*(-?\d{1,3}(?:\.\d+)?)\s*$/', $query, $m)) {
            $lat = (float)$m[1]; $lng = (float)$m[2];
            if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) return null;
            $this->write_map_file($map_key, $query, $zoom, $lat, $lng);
            return array('lat' => $lat, 'lng' => $lng);
        }

        $geo = $this->geo_cache_file($query);
        $cached = $this->read_json($geo);
        $fresh  = $cached && (time() - (int)$cached['time']) < self::CACHE_TTL;

        if (!$fresh) {
            $live = $this->geocode($query);
            if ($live !== null) {
                $this->write_json($geo, array('time' => time(), 'lat' => $live['lat'], 'lng' => $live['lng']));
                $cached = array('time' => time(), 'lat' => $live['lat'], 'lng' => $live['lng']);
            }
            // $cached may now be stale-but-present: geography does not go
            // out of date in a month; use it rather than hiding the map.
        }

        if (!$cached || !isset($cached['lat'], $cached['lng'])) return null;
        $this->write_map_file($map_key, $query, $zoom, (float)$cached['lat'], (float)$cached['lng']);
        return array('lat' => (float)$cached['lat'], 'lng' => (float)$cached['lng']);
    }

    private function geocode($query) {
        $url = self::GEO_URL.'?format=jsonv2&limit=1&q='.rawurlencode($query);
        $res = $this->http_client()->get($url, array('User-Agent: '.self::USER_AGENT), array('timeout' => 8));
        if (empty($res['http_code']) || $res['http_code'] !== 200 || empty($res['body'])) return null;
        $rows = json_decode((string)$res['body'], true);
        if (!is_array($rows) || !isset($rows[0]['lat'], $rows[0]['lon'])) return null;
        $lat = (float)$rows[0]['lat']; $lng = (float)$rows[0]['lon'];
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) return null;
        return array('lat' => $lat, 'lng' => $lng);
    }

    /** The user-initiated "Open in maps" link — the one allowed third-party hop. */
    private function search_url($query, $point) {
        if ($point) {
            return 'https://www.openstreetmap.org/?mlat='.rawurlencode((string)$point['lat'])
                 . '&mlon='.rawurlencode((string)$point['lng']).'#map=16/'.$point['lat'].'/'.$point['lng'];
        }
        return 'https://www.openstreetmap.org/search?query='.rawurlencode($query);
    }

    /* ============================ tile fetching =========================== */

    /**
     * PNG bytes for one tile, from the cache or the tile server.
     *
     * @return string|null null when the tile cannot be served (no cache and
     *                     no outbound route, or a corrupt response)
     */
    private function tile_bytes($z, $x, $y) {
        $file = $this->tile_file($z, $x, $y);
        if (is_file($file) && (time() - (int)filemtime($file)) < self::CACHE_TTL) {
            $bytes = @file_get_contents($file);
            return ($bytes !== false && $bytes !== '') ? $bytes : null;
        }

        $url = sprintf(self::TILE_URL, $z, $x, $y);
        $res = $this->http_client()->get($url, array('User-Agent: '.self::USER_AGENT), array('timeout' => 8));
        $ok = !empty($res['http_code']) && $res['http_code'] === 200
           && is_string($res['body'])
           && strlen($res['body']) > 8
           && strlen($res['body']) < self::MAX_TILE_BYTES
           && substr($res['body'], 0, 8) === "\x89PNG\r\n\x1a\n";
        if (!$ok) return null;

        $this->ensure_dir(dirname($file));
        if (@file_put_contents($file, $res['body']) === false) return $res['body'];
        return $res['body'];
    }

    /* =============================== helpers ============================= */

    /** Slippy-map tile column for a longitude (longitude wraps). */
    public static function tile_x($lng, $z) {
        $n = 1 << $z;
        return (int)floor(((float)$lng + 180.0) / 360.0 * $n) % $n;
    }

    /** Slippy-map tile row for a latitude (Mercator). */
    public static function tile_y($lat, $z) {
        $n = 1 << $z;
        $lat = max(-85.05112878, min(85.05112878, (float)$lat));
        $rad = deg2rad($lat);
        return (int)floor((1.0 - log(tan($rad) + 1.0 / cos($rad)) / M_PI) / 2.0 * $n);
    }

    /**
     * Where the requested point sits inside the 3×3 grid, in percent — for
     * the pin. 50/50 means dead centre of the centre tile.
     */
    public static function marker_offset($lat, $lng, $x0, $y0, $z) {
        $n = 1 << $z;
        $fx = ((float)$lng + 180.0) / 360.0 * $n - ($x0 - 1);   // tile units, 0..3
        $rad = deg2rad(max(-85.05112878, min(85.05112878, (float)$lat)));
        $fy = (1.0 - log(tan($rad) + 1.0 / cos($rad)) / M_PI) / 2.0 * $n - ($y0 - 1);
        return array(
            'left' => number_format($fx / self::GRID * 100, 2, '.', ''),
            'top'  => number_format($fy / self::GRID * 100, 2, '.', ''),
        );
    }

    public static function map_key($query, $zoom) {
        // The endpoint only needs to find the configured map again; 96 bits
        // keep it unguessable without exposing the query.
        return substr(sha1($query.'|'.(int)$zoom), 0, 24);
    }

    private function map_file($map_key) { return $this->dir.'/map-'.$map_key.'.json'; }
    private function geo_cache_file($query) { return $this->dir.'/geo-'.sha1($query).'.json'; }
    private function tile_file($z, $x, $y) { return $this->dir.'/tiles/'.$z.'/'.$x.'/'.$y.'.png'; }

    private function read_map_file($map_key) {
        $row = $this->read_json($this->map_file($map_key));
        if (!$row || !isset($row['lat'], $row['lng'], $row['zoom'])) return null;
        return $row;
    }

    private function write_map_file($map_key, $query, $zoom, $lat, $lng) {
        $this->write_json($this->map_file($map_key), array(
            'query' => $query, 'zoom' => (int)$zoom,
            'lat' => (float)$lat, 'lng' => (float)$lng, 'time' => time(),
        ));
    }

    private function read_json($file) {
        if (!is_file($file)) return null;
        $raw = @file_get_contents($file);
        if ($raw === false) return null;
        $row = json_decode($raw, true);
        return is_array($row) ? $row : null;
    }

    private function write_json($file, array $row) {
        $this->ensure_dir(dirname($file));
        @file_put_contents($file, json_encode($row), LOCK_EX);
    }

    private function ensure_dir($dir) {
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
    }

    private function http_client() {
        if ($this->http === null) {
            require_once APPPATH.'libraries/SecureHttpClient.php';
            $this->http = new SecureHttpClient();
        }
        return $this->http;
    }
}
