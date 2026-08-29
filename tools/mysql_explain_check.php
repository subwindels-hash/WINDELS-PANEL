<?php
/**
 * mysql_explain_check.php — the index review's proof leg on the real engine.
 *
 * docs/module-index-review.md is a static review: it matches the panel's
 * heaviest read paths against the schema's indexes and argues about what the
 * MySQL optimiser *should* do. This tool is the part the dev harness cannot
 * do — it asks MySQL 8 itself. It connects to a real MySQL/MariaDB, loads the
 * same query shapes the panel runs (bound to representative values), EXPLAINs
 * each, and fails when a plan shows a full table scan on a table that is big
 * enough for the scan to matter.
 *
 * Run it on the real engine, ideally after seeding a year of trading
 * (tools/devserver/seed_load.mjs's shape) so the row counts are real:
 *
 *   php tools/mysql_explain_check.php \
 *       --host=127.0.0.1 --port=3306 --db=marvysocials \
 *       --user=marvy --pass='…'
 *
 * Credentials may also come from the deployment's .env (VP_DB_*); explicit
 * flags win. Exit code is the number of violated queries, so it can gate a
 * release: `php tools/mysql_explain_check.php || echo "index gap in prod"`.
 *
 * Maintainer-only tooling — never shipped in the deployment package.
 */

$root = dirname(__DIR__);
require_once $root . '/application/core/Env.php';
Env::bootstrap($root);

$argv = isset($_SERVER['argv']) ? $_SERVER['argv'] : array();
$opts = array();
foreach (array_slice($argv, 1) as $arg) {
    if (strpos($arg, '--') === 0 && strpos($arg, '=') !== false) {
        list($k, $v) = explode('=', substr($arg, 2), 2);
        $opts[$k] = $v;
    }
}

$dsn = array(
    'host' => $opts['host'] ?? (getenv('VP_DB_HOST') ?: '127.0.0.1'),
    'port' => $opts['port'] ?? (getenv('VP_DB_PORT') ?: '3306'),
    'db'   => $opts['db']   ?? (getenv('VP_DB_NAME') ?: 'marvysocials'),
    'user' => $opts['user'] ?? (getenv('VP_DB_USER') ?: 'root'),
    'pass' => $opts['pass'] ?? (getenv('VP_DB_PASS') ?: ''),
);

if (!class_exists('PDO')) {
    fwrite(STDERR, "PDO is not available in this PHP build.\n");
    exit(2);
}

try {
    $pdo = new PDO(
        'mysql:host='.$dsn['host'].';port='.$dsn['port'].';dbname='.$dsn['db'].';charset=utf8mb4',
        $dsn['user'],
        $dsn['pass'],
        array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION)
    );
} catch (PDOException $e) {
    fwrite(STDERR, 'Cannot connect to '.$dsn['host'].':'.$dsn['port'].' ('.$dsn['db'].'): '.$e->getMessage()."\n");
    exit(2);
}

$version = (string)$pdo->query('SELECT VERSION()')->fetchColumn();
$mysql8 = version_compare($version, '8.0.18', '>=');
echo "MySQL ".$version." — ".$dsn['db']." @ ".$dsn['host'].':'.$dsn['port']."\n";

/* --------------------------------------------------------------------------
 * Representative values for the plan. The shapes matter, not the numbers,
 * but the window edges have to be plausible datetimes.
 * ----------------------------------------------------------------------- */
$now      = gmdate('Y-m-d H:i:s');
$daysAgo  = function ($d) { return gmdate('Y-m-d H:i:s', time() - $d * 86400); };

/**
 * label, SQL, binds, and the tables the query is allowed to full-scan.
 * A small table (settings, currencies, a handful of payment methods) *should*
 * be scanned when unindexed — flagging it would be noise. The allowlist is
 * the review's judgment, stated here so it is auditable.
 */
$queries = array(
    array(
        'dashboard: revenue window over service_transactions (created_at only)',
        'SELECT COUNT(*) n, COALESCE(SUM(CASE WHEN created_at >= ? AND status IN (\'COMPLETED\',\'PAID\') THEN amount ELSE 0 END),0) gross
           FROM service_transactions WHERE created_at >= ?',
        array($daysAgo(7), $daysAgo(30)),
        array(),
    ),
    array(
        'dashboard: revenue window over orders (created_at only)',
        'SELECT COUNT(*) n, COALESCE(SUM(CASE WHEN created_at >= ? AND status IN (\'COMPLETED\',\'PAID\') THEN charge ELSE 0 END),0) gross
           FROM orders WHERE created_at >= ?',
        array($daysAgo(7), $daysAgo(30)),
        array(),
    ),
    array(
        'analytics: revenue by domain (status IN + created_at range)',
        'SELECT service_domain, COUNT(*) n, COALESCE(SUM(amount),0) gross
           FROM service_transactions
          WHERE created_at >= ? AND status IN (\'COMPLETED\',\'PAID\')
          GROUP BY service_domain',
        array($daysAgo(30)),
        array(),
    ),
    array(
        'analytics: provider performance (created_at range, group by provider)',
        'SELECT provider_id, COUNT(*) calls
           FROM provider_transactions WHERE created_at >= ?
          GROUP BY provider_id',
        array($daysAgo(7)),
        array(),
    ),
    array(
        'admin: transaction list, no filter, newest first',
        'SELECT * FROM service_transactions ORDER BY created_at DESC LIMIT 25',
        array(),
        array(),
    ),
    array(
        'admin: order list, no filter, newest first',
        'SELECT * FROM orders ORDER BY created_at DESC LIMIT 25',
        array(),
        array(),
    ),
    array(
        'dashboard: customer transaction history (user_id + range)',
        'SELECT * FROM service_transactions
          WHERE user_id = ? AND created_at >= ?
          ORDER BY created_at DESC LIMIT 25',
        array(1, $daysAgo(365)),
        array(),
    ),
    array(
        'dashboard: wallet statement (wallet_id + range)',
        'SELECT * FROM wallet_transactions
          WHERE wallet_id = ? AND created_at >= ?
          ORDER BY created_at DESC LIMIT 25',
        array(1, $daysAgo(365)),
        array(),
    ),
    array(
        'support: ticket queue (status + priority + created_at)',
        'SELECT * FROM tickets WHERE status = \'OPEN\' ORDER BY priority DESC, created_at DESC LIMIT 50',
        array(),
        array(),
    ),
    array(
        'checkout: coupon lookup by code',
        'SELECT * FROM coupons WHERE code = ? AND is_active = 1',
        array('SOME_CODE'),
        array(),
    ),
    array(
        'auth: user lookup by email',
        'SELECT * FROM users WHERE email = ?',
        array('nobody@example.com'),
        array(),
    ),
);

/* Tables small enough that a scan is the right plan, whatever the size. */
$scan_ok = array('settings', 'currencies', 'payment_methods', 'roles', 'permissions',
                 'price_groups', 'feature_flags', 'email_templates', 'faqs',
                 'shipping_methods', 'marketplace_categories', 'service_categories');

$violations = 0;
$shown = 0;

foreach ($queries as $q) {
    list($label, $sql, $binds, $allow) = $q;

    // The table(s) the statement touches — for the allowlist and the report.
    preg_match_all('/\bFROM\s+`?(\w+)`?|\bJOIN\s+`?(\w+)`?/i', $sql, $tm);
    $tables = array_values(array_unique(array_map('trim', array_merge($tm[1], $tm[2]))));

    if ($mysql8) {
        $stmt = $pdo->prepare('EXPLAIN FORMAT=JSON '.$sql);
        $stmt->execute($binds);
        $json = json_decode((string)$stmt->fetchColumn(), true);
        $walk = function ($node) use (&$walk) {
            $out = array();
            if (!is_array($node)) return $out;
            if (isset($node['table'])) $out[] = $node['table'];
            if (isset($node['query_block'])) $out = array_merge($out, $walk($node['query_block']));
            if (isset($node['ordering_operation'])) $out = array_merge($out, $walk($node['ordering_operation']));
            if (isset($node['nested_loop'])) foreach ((array)$node['nested_loop'] as $child) $out = array_merge($out, $walk($child));
            return $out;
        };
        $plan = $walk($json);
        $planLines = array();
        foreach ($plan as $t) {
            $name = $t['table_name'] ?? '?';
            $type = $t['access_type'] ?? '?';
            $rows = $t['rows_examined_per_scan'] ?? ($t['rows'] ?? 0);
            $key  = $t['key'] ?? '-';
            $planLines[] = sprintf('    %-24s type=%-4s rows≈%-9s key=%s', $name, $type, $rows, $key);
            if ($type === 'ALL' && !in_array($name, $scan_ok, true) && !in_array($name, $allow, true)) {
                $violations++;
                echo "  ✗ FULL SCAN  $name — $label\n";
            }
        }
    } else {
        // MariaDB / older MySQL: classic tabular EXPLAIN.
        $stmt = $pdo->prepare('EXPLAIN '.$sql);
        $stmt->execute($binds);
        $planLines = array();
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $name = $row['table'];
            $type = $row['type'];
            $rows = $row['rows'];
            $key  = $row['key'] ?? '-';
            $planLines[] = sprintf('    %-24s type=%-4s rows≈%-9s key=%s', $name, $type, $rows, $key);
            if ($type === 'ALL' && !in_array($name, $scan_ok, true) && !in_array($name, $allow, true)) {
                $violations++;
                echo "  ✗ FULL SCAN  $name — $label\n";
            }
        }
    }

    $shown++;
    echo "  ✓ $label\n";
    foreach ($planLines as $line) echo $line."\n";
}

echo "\n$shown queries explained, $violations full-scan violation(s).\n";
if ($violations > 0) {
    echo "Fix with a leading-column index (see docs/module-index-review.md) and re-run.\n";
}
exit($violations);
