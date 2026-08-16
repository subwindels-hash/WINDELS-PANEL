<?php
defined('BASEPATH') OR exit('No direct script access allowed');
$config['storage'] = array(
    'driver' => getenv('STORAGE_DRIVER') ?: 's3',
    'endpoint' => getenv('STORAGE_ENDPOINT') ?: NULL,
    'region' => getenv('STORAGE_REGION') ?: 'us-east-1',
    'bucket' => getenv('STORAGE_BUCKET') ?: 'windels-panel',
    'access_key' => getenv('STORAGE_ACCESS_KEY') ?: NULL,
    'secret_key' => getenv('STORAGE_SECRET_KEY') ?: NULL,
    'url' => getenv('STORAGE_URL') ?: NULL,
);
