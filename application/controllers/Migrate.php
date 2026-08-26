<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Migrate — CLI-only migration runner (§66: no web-triggered migrations).
 *
 *   php index.php migrate              # migrate to latest
 *   php index.php migrate latest
 *   php index.php migrate version 5    # migrate up/down to version 5
 *   php index.php migrate fresh        # drop all app tables then migrate (non-production only)
 *   php index.php migrate status
 */
class Migrate extends Cron_Controller {

    public function __construct() {
        parent::__construct();
        $this->load->library('migration');
        $this->load->dbforge();
    }

    public function index() { $this->latest(); }

    public function latest() {
        $this->line('Migrating to latest ('.windels_migration_item('migration_version', 0).') ...');
        if ($this->migration->latest() === FALSE) {
            $this->fail($this->migration->error_string());
        }
        $this->line('OK — schema at version '.$this->current_version());
        $this->status();
    }

    public function version($target = NULL) {
        if ($target === NULL) { $this->fail('Usage: php index.php migrate version <n>'); }
        $this->line('Migrating to version '.(int)$target.' ...');
        if ($this->migration->version((int)$target) === FALSE) {
            $this->fail($this->migration->error_string());
        }
        $this->line('OK — schema at version '.$this->current_version());
    }

    /** Drop every application table, then migrate to latest. Never in production. */
    public function fresh() {
        if (ENVIRONMENT === 'production' && !$this->has_flag('--force')) {
            $this->fail('Refusing to run `migrate fresh` in production. Pass --force if you really mean it.');
        }
        $this->line('Dropping all tables ...');
        $this->db->query('SET FOREIGN_KEY_CHECKS=0');
        foreach ($this->db->list_tables() as $table) {
            $this->db->query('DROP TABLE IF EXISTS `'.$table.'`');
        }
        $this->db->query('SET FOREIGN_KEY_CHECKS=1');
        $this->line('Dropped. Re-running migrations ...');
        $this->latest();
    }

    public function status() {
        // CI3's driver memoises list_tables()/table_exists() per connection in
        // $db->data_cache. On a fresh install the migration run that just
        // created 80-odd tables happens *after* that memo was filled from an
        // empty schema, so without this the command reports "current version:
        // 0, tables: 1" on a database it has just built correctly.
        $this->db->data_cache = array();
        $current = $this->current_version();
        $target  = (int)windels_migration_item('migration_version', 0);
        $this->line('');
        $this->line('  current version : '.$current);
        $this->line('  target version  : '.$target);
        $this->line('  tables          : '.count($this->db->list_tables()));
        $this->line('');
        foreach ($this->migration_files() as $v => $file) {
            $this->line(sprintf('  [%s] %03d  %s', $v <= $current ? 'x' : ' ', $v, $file));
        }
        $this->line('');
    }

    /* ------------------------------------------------------------------ */

    private function migration_files() {
        $out = array();
        foreach (glob(windels_migration_item('migration_path', APPPATH.'migrations/').'*.php') as $path) {
            $name = basename($path);
            if (preg_match('/^(\d+)_/', $name, $m)) $out[(int)$m[1]] = $name;
        }
        ksort($out);
        return $out;
    }

    private function current_version() {
        $table = windels_migration_item('migration_table', 'migrations');
        if (!$this->db->table_exists($table)) return 0;
        $row = $this->db->get($table)->row();
        return $row ? (int)$row->version : 0;
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
