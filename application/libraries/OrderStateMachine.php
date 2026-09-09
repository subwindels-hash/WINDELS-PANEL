<?php
defined('BASEPATH') OR exit('No direct script access allowed');
class OrderStateMachine {
    private static $transitions = array(
        'PENDING' => array('PROCESSING','CANCELED','FAILED','EXPIRED'),
        'PROCESSING' => array('IN_PROGRESS','COMPLETED','PARTIAL','CANCELED','FAILED','ERROR','PAUSED'),
        'IN_PROGRESS' => array('COMPLETED','PARTIAL','CANCELED','FAILED','ERROR','PAUSED'),
        'PAUSED' => array('IN_PROGRESS','CANCELED'),
        'PARTIAL' => array('COMPLETED','REFUNDED','CANCELED'),
        'COMPLETED' => array('REFUNDED'),
        'FAILED' => array('REFUNDED'),
        'ERROR' => array('PENDING','FAILED','CANCELED'),
        'CANCELED' => array('REFUNDED'),
    );
    public static function can($from,$to){
        if ($from===$to) return TRUE;
        return in_array($to, self::$transitions[$from] ?? array(), TRUE);
    }
    public static function assert($from,$to){
        if (!self::can($from,$to)) throw new Exception("Invalid order transition {$from} -> {$to}");
    }
}
