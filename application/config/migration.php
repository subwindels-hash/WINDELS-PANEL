<?php
defined('BASEPATH') OR exit('No direct script access allowed');
$config['migration_enabled'] = TRUE;
$config['migration_type'] = 'sequential';
$config['migration_table'] = 'migrations';
$config['migration_auto_latest'] = FALSE;
$config['migration_version'] = 31;
$config['migration_path'] = APPPATH . 'migrations/';
