<?php
defined('BASEPATH') OR exit('No direct script access allowed');
class EncryptionService {
    private $key;
    public function __construct(){
        $k = getenv('ENCRYPTION_KEY') ?: 'change-me-32-byte-key-replace!!';
        // Derive 32 bytes
        $this->key = hash('sha256', $k, TRUE);
    }
    public function encrypt($plain){
        $iv = random_bytes(12);
        $tag=''; $ct = openssl_encrypt($plain,'aes-256-gcm',$this->key,OPENSSL_RAW_DATA,$iv,$tag);
        return base64_encode($iv.$tag.$ct);
    }
    public function decrypt($b64){
        $raw = base64_decode($b64);
        if (strlen($raw) < 28) return $b64; // fallback for plain
        $iv = substr($raw,0,12); $tag=substr($raw,12,16); $ct=substr($raw,28);
        $pt = openssl_decrypt($ct,'aes-256-gcm',$this->key,OPENSSL_RAW_DATA,$iv,$tag);
        return $pt !== FALSE ? $pt : $b64;
    }
}
