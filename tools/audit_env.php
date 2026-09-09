<?php
/**
 * audit_env.php — environment-variable hygiene, mechanically enforced.
 *
 *   1. Every variable the CODE reads (Env::get*, env_str/env_bool, getenv,
 *      plus composer-file/docker-compose references) must be documented in
 *      .env.example or .env.production.example.          => error if missing
 *   2. Every variable DOCUMENTED there must be read by the code (or compose/
 *      CI/scripts). Dead documentation confuses operators. => warn
 *   3. Duplicate definitions inside each file.            => error
 *
 * The VP_ alias rule is honoured: VP_REDIS_HOST in an example covers a
 * REDIS_HOST read in code (Env expands any VP_* to the unprefixed name).
 *
 *   php tools/audit_env.php          # report, exit 1 on any error
 */

error_reporting(E_ALL & ~E_DEPRECATED);
$ROOT = dirname(__DIR__);

$errors = array();
$warns  = array();

/* ---- 1. variables the code reads ----------------------------------------- */
$read = array();   // name => where
$scopes = array(
    'php'   => array($ROOT . '/application', $ROOT . '/tools', $ROOT . '/index.php', $ROOT . '/deploy-verify.php'),
    'infra' => array($ROOT . '/docker-compose.yml', $ROOT . '/docker-compose.production.yml'),
);
$collectFromDir = function ($dir) {
    $found = glob(rtrim($dir, '/') . '/*');
    return $found ? $found : array();
};
foreach ($scopes['php'] as $path) {
    $it = is_dir($path)
        ? new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path))
        : new ArrayIterator(array($path));
    foreach ($it as $f) {
        $f = (string) $f;
        if (substr($f, -4) !== '.php') continue;
        if (basename($f) === 'audit_env.php') continue;   // its own pattern strings are not consumers
        $code = (string) @file_get_contents($f);
        if ($code === '') continue;
        $code = preg_replace('#/\*.*?\*/#s', '', $code);   // comments are not consumers
        $rel = str_replace($ROOT . DIRECTORY_SEPARATOR, '', $f);
        // Env::get(_int|_bool|...)('NAME'  +  env_str/env_bool('NAME'  +  Env::has/raw('NAME'
        if (preg_match_all('/(?:(?:Env|self)::(?:get|get_int|get_bool|has|raw)|env_str|env_bool|env_int)\(\s*[\'"]([A-Z][A-Z0-9_]*)[\'"]/', $code, $m))
            foreach ($m[1] as $n) if (substr($n, -1) !== '_') $read[$n][$rel] = true;
        // getenv('NAME')
        if (preg_match_all('/getenv\(\s*[\'"]([A-Z][A-Z0-9_]*)[\'"]/', $code, $m))
            foreach ($m[1] as $n) if (substr($n, -1) !== '_') $read[$n][$rel] = true;
        // dynamic: getenv('MARVYSOCIALS_'.strtoupper($g).'_WEBHOOK_SECRET')
        if (strpos($code, "getenv('MARVYSOCIALS_'") !== false)
            $read['MARVYSOCIALS_<GATEWAY>_WEBHOOK_SECRET'][$rel] = true;
    }
}
/* infra consumers (compose files): ${VAR} and ${VAR:-x} / ${VAR:?x} */
foreach ($scopes['infra'] as $f) {
    $c = (string) @file_get_contents($f);
    if (preg_match_all('/\$\{([A-Z][A-Z0-9_]*)/', $c, $m))
        foreach ($m[1] as $n) $read[$n][str_replace($ROOT . '/', '', $f)] = true;
}
/* the loader is a consumer of everything in .env by definition */
$read['CI_ENV'][$ROOT . '/index.php'] = true;
unset($read['VAR']);   // compose-side generic ${VAR:?} placeholder wording in docs

/* ---- 2. variables the examples document ----------------------------------- */
$documented = array();   // per file
foreach (array('.env.example', '.env.production.example') as $file) {
    $keys = array();
    foreach ((array) @file($ROOT . '/' . $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $ln => $line) {
        $line = trim($line);
        if ($line === '') continue;
        $is_comment = $line[0] === '#';
        $body = $is_comment ? trim(substr($line, 1)) : $line;
        if (!preg_match('/^([A-Z][A-Z0-9_]*)=/', $body, $m)) continue;
        $key = $m[1];
        if (isset($keys[$key]))
            $errors[] = "{$file}: duplicate definition of {$key} (first line {$keys[$key]}, again line " . ($ln + 1) . ')';
        $keys[$key] = $ln + 1;
        $documented[$file][$key] = array('commented' => $is_comment, 'line' => $ln + 1);
    }
}
$doc_any = array();
foreach ($documented as $file => $keys) foreach (array_keys($keys) as $k) $doc_any[$k] = true;

/* ---- alias resolution (mirror of Env::$aliases + generic VP_ strip) ------ */
$ALIASES = array(
    'VP_ENV'=>'APP_ENV','VP_BASE_URL'=>'APP_URL','VP_DEBUG'=>'APP_DEBUG',
    'VP_TIMEZONE'=>'APP_TIMEZONE','VP_MAINTENANCE_MODE'=>'MAINTENANCE_MODE',
    'VP_ENCRYPTION_KEY'=>'ENCRYPTION_KEY','VP_AUTH_SECRET'=>'APP_KEY',
    'VP_DB_DRIVER'=>'DB_DRIVER','VP_DB_HOST'=>'DB_HOST','VP_DB_PORT'=>'DB_PORT',
    'VP_DB_NAME'=>'DB_NAME','VP_DB_USER'=>'DB_USER','VP_DB_PASS'=>'DB_PASSWORD',
    'VP_DB_PASSWORD'=>'DB_PASSWORD','VP_DB_CHARSET'=>'DB_CHARSET','VP_DB_COLLATION'=>'DB_COLLATION',
    'VP_DB_STRICT'=>'DB_STRICT',
    'VP_SESSION_DRIVER'=>'SESS_DRIVER','VP_SESSION_SAVE_PATH'=>'SESS_SAVE_PATH',
    'VP_SESSION_COOKIE'=>'SESS_COOKIE_NAME','VP_SESSION_EXPIRATION'=>'SESS_EXPIRATION',
    'VP_CACHE_DRIVER'=>'CACHE_DRIVER','VP_CACHE_PATH'=>'CACHE_PATH',
    'VP_LOG_PATH'=>'LOG_PATH','VP_UPLOAD_PATH'=>'UPLOAD_PATH',
    'VP_MAIL_DRIVER'=>'MAIL_DRIVER','VP_MAIL_HOST'=>'SMTP_HOST','VP_MAIL_PORT'=>'SMTP_PORT',
    'VP_MAIL_USER'=>'SMTP_USER','VP_MAIL_PASS'=>'SMTP_PASSWORD','VP_MAIL_PASSWORD'=>'SMTP_PASSWORD',
    'VP_MAIL_CRYPTO'=>'SMTP_CRYPTO','VP_MAIL_FROM_ADDRESS'=>'MAIL_FROM_ADDRESS',
    'VP_MAIL_FROM_NAME'=>'MAIL_FROM_NAME','VP_CSRF_EXPIRE'=>'CSRF_EXPIRE',
    'VP_CSRF_REGENERATE'=>'CSRF_REGENERATE','VP_SETUP_TOKEN'=>'SETUP_TOKEN',
    'VP_MAIL_LOG'=>'MAIL_LOG',
    // legacy aliases the code accepts (Paystack scaffold fallback names)
    'PAYSTACK_KEY'=>'PAYSTACK_SECRET_KEY','PAYSTACK_PK'=>'PAYSTACK_PUBLIC_KEY',
);
$names_of = function ($key) use ($ALIASES) {
    $plain = strpos($key, 'VP_') === 0 ? substr($key, 3) : $key;
    $set = array($key => true, $plain => true, 'VP_' . $plain => true);
    if (isset($ALIASES[$key])) $set[$ALIASES[$key]] = true;
    if (isset($ALIASES[$plain])) $set[$ALIASES[$plain]] = true;
    return array_keys($set);
};

/* ---- 3. coverage: code → examples ----------------------------------------- */
$skip_doc = array(   // runtime-provided, never written to .env by hand
    'PHP_SELF' => true, 'SCRIPT_NAME' => true, 'PWD' => true,
);
foreach ($read as $name => $where) {
    if (strpos($name, '<') !== false) continue;               // pattern-style names
    if (isset($skip_doc[$name])) continue;
    $covered = false;
    foreach ($names_of($name) as $cand) if (isset($doc_any[$cand])) { $covered = true; break; }
    // and the reverse-lookup: is any documented key an alias of this read?
    if (!$covered) foreach (array_keys($doc_any) as $dk) {
        foreach ($names_of($dk) as $cand) if ($cand === $name) { $covered = true; break 2; }
    }
    if (!$covered) {
        $errors[] = "code reads \${$name} (in " . implode(', ', array_slice(array_keys($where), 0, 3)) . ") — not documented in either .env example";
    }
}

/* ---- 4. hygiene: examples → code ------------------------------------------ */
foreach ($documented as $file => $keys) {
    foreach ($keys as $k => $meta) {
        $found = false;
        foreach ($names_of($k) as $cand) if (isset($read[$cand])) { $found = true; break; }
        if (!$found)
            $warns[] = "{$file}:" . $meta['line'] . " documents {$k} — no consumer found in code or compose files";
    }
}

/* ---- report ---------------------------------------------------------------- */
echo "MarvySocials — environment audit\n";
echo "code references: " . count($read) . " distinct variables\n";
foreach ($documented as $file => $keys)
    echo "{$file}: " . count($keys) . " documented keys\n";
echo str_repeat('-', 64) . "\n";
foreach ($errors as $e) echo "[ERROR] {$e}\n";
foreach ($warns  as $w) echo "[warn]  {$w}\n";
if (!$errors && !$warns) echo "[ok]    every code-consumed variable is documented; every documented variable is consumed\n";
$exit = $errors ? 1 : 0;
echo $exit ? "RESULT: FAIL\n" : "RESULT: PASS\n";
exit($exit);
