<?php
defined('BASEPATH') OR exit('No direct script access allowed');
$config['provider_adapters'] = array(
    'STANDARD_SMM' => 'StandardSmmAdapter',
    'CUSTOM' => 'StandardSmmAdapter', // override per provider via factory
    'MOCK' => 'MockProviderAdapter', // tests / APP_ENV=demo
);
