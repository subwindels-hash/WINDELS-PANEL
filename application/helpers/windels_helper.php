<?php
defined('BASEPATH') OR exit('No direct script access allowed');

if (!function_exists('windels_public_id')) {
    function windels_public_id(){
        if (class_exists(\Robbins\Ulid\Ulid::class)) return (string)\Robbins\Ulid\Ulid::generate();
        if (class_exists(\Ramsey\Uuid\Uuid::class)) return \Ramsey\Uuid\Uuid::uuid4()->toString();
        return bin2hex(random_bytes(13));
    }
}
if (!function_exists('windels_money')) {
    function windels_money($amount, $currency='USD'){
        $sym = array('USD'=>'$','EUR'=>'€','GBP'=>'£','NGN'=>'₦')[strtoupper($currency)] ?? $currency.' ';
        return $sym . number_format((float)$amount, 2, '.', ',');
    }
}
if (!function_exists('windels_request_id')) {
    function windels_request_id(){ return bin2hex(random_bytes(8)); }
}
