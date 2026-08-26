<?php
defined('BASEPATH') OR exit('No direct script access allowed');

$autoload['packages'] = array();
// Database is loaded on demand (see marvy_load_database()). Autoloading it
// turns a missing/wrong VP_DB_* value into a white-screen 500 before /setup
// or /health/live can explain what is wrong — the exact first-boot failure
// on cPanel.
$autoload['libraries'] = array('session', 'form_validation', 'encryption');
$autoload['drivers'] = array();
$autoload['helper'] = array('url', 'form', 'security', 'marvy', 'language');
// config/marvy.php must be autoloaded, not just present: every reader of
// its keys (item('marvy') for base_currency/homepage, item('upload'),
// item('rate_limits'), item('cron'), item('provider_http'), …) goes through
// CI's config registry, and before this line the file was never loaded —
// each consumer silently ran on its hardcoded fallback, so an operator
// editing marvy.php changed nothing.
$autoload['config'] = array('marvy');
$autoload['language'] = array();
$autoload['model'] = array();
