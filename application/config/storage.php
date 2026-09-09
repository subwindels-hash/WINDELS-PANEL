<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
 * File storage. Defaults to the local disk (assets/uploads) because that is
 * the only thing a cPanel account has — S3/R2 is opt-in through
 * VP_STORAGE_DRIVER=s3, which additionally needs the aws-sdk in vendor/.
 */
require_once APPPATH.'core/Env.php';
Env::bootstrap(rtrim(realpath(APPPATH.'..'), DIRECTORY_SEPARATOR));
$marvy_paths = Env::writable_paths();

$config['storage'] = array(
    'driver'     => Env::get('STORAGE_DRIVER', 'local'),
    'path'       => $marvy_paths['uploads'],
    'public_url' => rtrim((string) Env::get('UPLOAD_URL', rtrim((string) Env::get('APP_URL', ''), '/').'/assets/uploads'), '/'),
    'endpoint'   => Env::get('STORAGE_ENDPOINT'),
    'region'     => Env::get('STORAGE_REGION', 'us-east-1'),
    'bucket'     => Env::get('STORAGE_BUCKET', 'marvysocials'),
    'access_key' => Env::get('STORAGE_ACCESS_KEY'),
    'secret_key' => Env::get('STORAGE_SECRET_KEY'),
    'url'        => Env::get('STORAGE_URL'),
);
