<?php
/**
 * link_system.php — materialise the CodeIgniter `system/` directory.
 *
 * CodeIgniter 3.1.13 ships `system/` inside the composer package
 * (vendor/codeigniter/framework/system), but the front controller expects it
 * at the repository root. `system/` is gitignored, so a fresh clone has none
 * — without this link the app exits 503 ("Your system folder path does not
 * appear to be set correctly") before booting.
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

fwrite(STDERR, "link_system: could not create the system symlink.\n"
    . "Create it manually: ln -s vendor/codeigniter/framework/system system\n");
exit(1);
