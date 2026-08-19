<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Seed — CLI-only seed runner.
 *
 *   php index.php seed              # core seed (safe everywhere)
 *   php index.php seed core
 *   php index.php seed demo         # demo data — blocked in production without --force
 *   php index.php seed all
 *   php index.php seed list
 */
class Seed extends Cron_Controller {

    /** seed name => [class, file, production-safe?] */
    private $registry = array(
        'core' => array('Core_seeder', 'Core_seeder.php', TRUE),
        'demo' => array('Demo_seeder', 'Demo_seeder.php', FALSE),
    );

    public function __construct() {
        parent::__construct();
        $this->load->database();
        // Seeder is an abstract base class: it must be *defined*, never
        // instantiated. $this->load->library('Seeder') would do the latter and
        // fatal ("Cannot instantiate abstract class Seeder") on every PHP.
        require_once APPPATH.'libraries/Seeder.php';
    }

    public function index() { $this->run('core'); }

    public function core() { $this->run('core'); }

    public function demo() { $this->run('demo'); }

    public function all() {
        foreach (array_keys($this->registry) as $name) { $this->run($name); }
    }

    public function list_seeds() { $this->show_list(); }

    /** `php index.php seed list` maps here via _remap-free routing fallback. */
    public function _remap($method, $params = array()) {
        if ($method === 'list') return $this->show_list();
        if (method_exists($this, $method)) return call_user_func_array(array($this, $method), $params);
        if (isset($this->registry[$method])) return $this->run($method);
        $this->fail('Unknown seed "'.$method.'". Try: php index.php seed list');
    }

    /* ------------------------------------------------------------------ */

    private function run($name) {
        if (!isset($this->registry[$name])) $this->fail('Unknown seed: '.$name);
        list($class, $file, $prod_safe) = $this->registry[$name];

        if (!$prod_safe && !$this->env_allows_demo() && !$this->has_flag('--force')) {
            $this->fail(sprintf(
                'Refusing to run the "%s" seed with APP_ENV=%s. Allowed: development, testing, demo. Use --force to override.',
                $name, ENVIRONMENT
            ));
        }
        if (!$this->schema_ready()) {
            $this->fail('Schema is not migrated yet. Run: php index.php migrate');
        }

        $path = APPPATH.'seeds/'.$file;
        if (!file_exists($path)) $this->fail('Seed file missing: '.$path);
        require_once $path;
        if (!class_exists($class)) $this->fail('Seed class missing: '.$class);

        $this->line('== seed:'.$name.' ==');
        $started = microtime(TRUE);

        /** @var Seeder $seeder */
        $seeder = new $class();
        $this->db->trans_start();
        $seeder->run();
        $this->db->trans_complete();

        if ($this->db->trans_status() === FALSE) {
            $this->fail('Seed "'.$name.'" rolled back — database transaction failed.');
        }
        $summary = $seeder->summary();
        if ($summary !== '') $this->line($summary);
        $this->line(sprintf('done in %.2fs', microtime(TRUE) - $started));
        $this->line('');
    }

    private function show_list() {
        $this->line('Available seeds:');
        foreach ($this->registry as $name => $meta) {
            $this->line(sprintf('  %-6s %s', $name, $meta[2] ? '(safe in production)' : '(non-production only)'));
        }
    }

    private function env_allows_demo() {
        return in_array(ENVIRONMENT, array('development', 'testing', 'demo'), TRUE);
    }

    private function schema_ready() {
        return $this->db->table_exists('settings') && $this->db->table_exists('users');
    }

    private function has_flag($flag) {
        return in_array($flag, (array)($GLOBALS['argv'] ?? array()), TRUE);
    }

    private function line($msg) { echo $msg."\n"; }

    private function fail($msg) {
        fwrite(STDERR, 'ERROR: '.$msg."\n");
        exit(1);
    }
}
