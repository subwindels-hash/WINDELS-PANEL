<?php
/**
 * Symbols CodeIgniter provides at runtime, shimmed so static analysis of the
 * plain-domain classes resolves them without booting the framework. The app
 * autoloads application/helpers/marvy_helper.php via composer "files", so
 * only true CI3 runtime symbols belong here.
 */
if (!function_exists('get_instance')) {
    function get_instance() { return null; }
}
if (!function_exists('log_message')) {
    function log_message($level, $message) { return true; }
}
if (!class_exists('CI_Model')) {
    class CI_Model {}
}
