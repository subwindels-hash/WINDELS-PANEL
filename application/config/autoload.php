<?php
defined('BASEPATH') OR exit('No direct script access allowed');

$autoload['packages'] = array();
// Database is loaded on demand (see windels_load_database()). Autoloading it
// turns a missing/wrong VP_DB_* value into a white-screen 500 before /setup
// or /health/live can explain what is wrong — the exact first-boot failure
// on cPanel.
$autoload['libraries'] = array('session', 'form_validation', 'encryption');
$autoload['drivers'] = array();
$autoload['helper'] = array('url', 'form', 'security', 'windels', 'language');
$autoload['config'] = array();
$autoload['language'] = array();
$autoload['model'] = array();
