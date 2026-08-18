<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
 * DEPRECATED — kept only so an existing deployment's config directory does not
 * suddenly lose a file it may reference.
 *
 * The authoritative adapter registry is Provider_manager, which maps
 * (family, api_type) → adapter class and is what ProviderSyncService, VtuService
 * and the admin create form all read. Nothing loads this file, and a value
 * changed here has no effect. Register new integrations in
 * application/libraries/Provider_manager.php instead.
 */
$config['provider_adapters'] = array(
    // SMM
    'STANDARD_SMM' => 'StandardSmmAdapter',
    'CUSTOM'       => 'StandardSmmAdapter',
    'MOCK'         => 'MockProviderAdapter', // tests / APP_ENV=demo
    // VTU
    'STANDARD_VTU' => 'StandardVtuAdapter',
    'VTPASS'       => 'VtpassAdapter',
);
