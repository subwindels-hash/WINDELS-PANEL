<?php
/**
 * link_system.php — materialise the CodeIgniter `system/` directory.
 *
 * CodeIgniter 3.1.13 ships `system/` inside the composer package
 * (vendor/codeigniter/framework/system). The front controller probes the
 * repository-root `system/` first and the vendor path second, so a fresh
 * clone boots after this runs. `system/` is gitignored — without it AND
 * without composer, the app exits 503.
 *
 * Symlink preferred; on hosts where symlink() is unavailable it falls back to
 * copying the tree — a real directory copy, never a failure.
 *
 * Runs automatically from composer post-install-cmd / post-update-cmd, and can
 * be run by hand: php tools/link_system.php
 */

$root   = dirname(__DIR__);
$target = $root . '/vendor/codeigniter/framework/system';
$link   = $root . '/system';

if (!is_dir($target)) {
    fwrite(STDERR, "link_system: {$target} not found — run composer install first.\n");
    exit(1);
}

// Already a real directory (e.g. a copied framework) — leave it alone.
if (is_dir($link) && !is_link($link)) {
    echo "link_system: system/ already present (directory) — left untouched.\n";
    exit(0);
}

// A correct link already exists.
if (is_link($link) && realpath($link) === realpath($target)) {
    echo "link_system: system/ link already correct.\n";
    exit(0);
}

// A stale/dangling link — replace it.
if (is_link($link)) {
    unlink($link);
}

// Relative link so the checkout can be moved/mounted anywhere.
if (@symlink('vendor/codeigniter/framework/system', $link)) {
    echo "link_system: created system -> vendor/codeigniter/framework/system\n";
    exit(0);
}

// Symlink unavailable (Windows without developer mode, hosting accounts that
// disable symlink()). A real directory copy satisfies every path the front
// controller probes — index.php prefers ./system — so this is not a degraded
// mode, just a duplicated one. Composer update still refreshes the vendor
// copy; re-running this script after `composer update` re-syncs ./system.
echo "link_system: symlink() failed — falling back to a real directory copy.\n";

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($target, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);
$copied = 0;
foreach ($iterator as $item) {
    $dest = $link . DIRECTORY_SEPARATOR . $iterator->getSubPathName();
    if ($item->isDir()) {
        if (!is_dir($dest) && !@mkdir($dest, 0775, true)) {
            fwrite(STDERR, "link_system: could not create directory {$dest}\n");
            exit(1);
        }
    } else {
        if (!@copy($item->getPathname(), $dest)) {
            fwrite(STDERR, "link_system: could not copy {$item->getPathname()} -> {$dest}\n");
            exit(1);
        }
        $copied++;
    }
}

if (is_file($link . '/core/CodeIgniter.php')) {
    echo "link_system: copied system/ ({$copied} files) from vendor/codeigniter/framework/system.\n";
    echo "link_system: after future `composer update` runs, re-run this script to re-sync.\n";
    exit(0);
}

fwrite(STDERR, "link_system: copy finished but system/core/CodeIgniter.php is missing — aborting.\n");
exit(1);
