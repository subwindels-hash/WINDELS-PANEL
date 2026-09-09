<?php
use PHPUnit\Framework\TestCase;

/**
 * Schema tests — assert the migration set matches the approved Checkpoint 01 plan
 * and the generated docs/database.sql is in sync with it.
 *
 * These run without a database: migrations expose their DDL through a static
 * statements() method, so the SQL can be inspected directly.
 */
class SchemaTest extends TestCase
{
    private static $root;
    private static $sql;
    private static $sections = array();

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(dirname(__DIR__));
        if (!defined('BASEPATH')) define('BASEPATH', self::$root.'/system/');
        if (!class_exists('CI_Migration')) {
            eval('class CI_Migration { public $db; public $dbforge; }');
        }
        foreach (self::migrationFiles() as $file) {
            require_once $file;
            $class = self::classFor($file);
            self::$sections[basename($file, '.php')] = call_user_func(array($class, 'statements'));
        }
        self::$sql = file_get_contents(self::$root.'/docs/database.sql');
    }

    private static function migrationFiles()
    {
        $files = glob(self::$root.'/application/migrations/*.php');
        sort($files);
        return $files;
    }

    private static function classFor($file)
    {
        $suffix = preg_replace('/^\d+_/', '', basename($file, '.php'));
        return 'Migration_'.ucfirst($suffix);
    }

    private function allStatements()
    {
        $out = array();
        foreach (self::$sections as $stmts) { $out = array_merge($out, $stmts); }
        return $out;
    }

    private function createStatementFor($table)
    {
        foreach ($this->allStatements() as $sql) {
            if (preg_match('/CREATE TABLE IF NOT EXISTS '.preg_quote($table, '/').'\s*\(/i', $sql)) return $sql;
        }
        return null;
    }

    private function tableNames()
    {
        $tables = array();
        foreach ($this->allStatements() as $sql) {
            if (preg_match('/CREATE TABLE IF NOT EXISTS (\w+)/i', $sql, $m)) $tables[] = $m[1];
        }
        return $tables;
    }

    /* ------------------------------------------------------------------ */

    public function testMigrationsAreSequentialAndComplete()
    {
        $files = self::migrationFiles();
        // Was pinned at exactly 9 for Checkpoint 01. The rebuild spec adds
        // service domains that need new tables, and editing already-deployed
        // migrations is unsafe, so new work lands as 010+. The guarantee worth
        // keeping is sequential numbering that matches migration_version.
        $this->assertGreaterThanOrEqual(9, count($files), 'the original 9 migrations must not be removed');
        foreach ($files as $i => $file) {
            $this->assertMatchesRegularExpression('/^\d{3}_/', basename($file));
            $this->assertSame($i + 1, (int)substr(basename($file), 0, 3), 'migrations must be sequential from 001');
        }
    }

    public function testEveryMigrationDeclaresClassAndUpDown()
    {
        foreach (self::migrationFiles() as $file) {
            $class = self::classFor($file);
            $this->assertTrue(class_exists($class), "missing class {$class} in ".basename($file));
            $this->assertTrue(method_exists($class, 'up'), "{$class} needs up()");
            $this->assertTrue(method_exists($class, 'down'), "{$class} needs down()");
            $this->assertTrue(method_exists($class, 'statements'), "{$class} needs statements()");
            $this->assertTrue(method_exists($class, 'tables'), "{$class} needs tables()");
        }
    }

    public function testMigrationVersionMatchesFileCount()
    {
        $config = file_get_contents(self::$root.'/application/config/migration.php');
        preg_match("/migration_version'\]\s*=\s*(\d+)/", $config, $m);
        $this->assertSame(count(self::migrationFiles()), (int)$m[1], 'migration_version must match the number of migrations');
        $this->assertStringContainsString("'sequential'", $config);
    }

    public function testDownDropsEveryTableItCreates()
    {
        foreach (self::migrationFiles() as $file) {
            $class = self::classFor($file);
            $declared = call_user_func(array($class, 'tables'));
            $created = array();
            foreach (call_user_func(array($class, 'statements')) as $sql) {
                if (preg_match('/CREATE TABLE IF NOT EXISTS (\w+)/i', $sql, $m)) $created[] = $m[1];
            }
            sort($declared); sort($created);
            $this->assertSame($created, $declared, $class.'::tables() must list exactly the tables it creates');
        }
    }

    public function testCoreDomainTablesArePresent()
    {
        $expected = array(
            'users','price_groups','roles','permissions','role_permissions','user_sessions','refresh_tokens','mfa_methods','login_attempts',
            'wallets','wallet_transactions','ledger_entries','idempotency_keys',
            'service_categories','services','service_prices','user_service_prices','service_favorites',
            'providers','provider_services','provider_sync_logs','provider_health_logs',
            'orders','order_status_history','provider_orders',
            'refills','refill_status_history','cancellation_requests','dripfeed_orders','dripfeed_runs','subscriptions','subscription_events',
            'payment_methods','payment_transactions','payment_webhooks','payment_events',
            'tickets','ticket_messages','ticket_attachments','referral_accounts','referrals','referral_commissions',
            'blog_categories','blog_posts','faqs','announcements','media',
            'audit_logs','api_keys','api_usage_logs','blacklisted_emails','blacklisted_ips','blacklisted_links',
            'settings','notifications','notification_preferences','feature_flags','email_templates','currencies',
        );
        $actual = $this->tableNames();
        foreach ($expected as $table) {
            $this->assertContains($table, $actual, "missing table: {$table}");
        }
    }

    public function testNoTableIsCreatedTwice()
    {
        $tables = $this->tableNames();
        $this->assertSame(array_unique($tables), $tables, 'duplicate CREATE TABLE detected');
    }

    public function testEveryTableIsInnodbUtf8mb4()
    {
        foreach ($this->allStatements() as $sql) {
            if (!preg_match('/CREATE TABLE IF NOT EXISTS (\w+)/i', $sql, $m)) continue;
            $this->assertStringContainsString('ENGINE=InnoDB', $sql, $m[1].' must be InnoDB');
            $this->assertStringContainsString('utf8mb4', $sql, $m[1].' must be utf8mb4');
        }
    }

    public function testMoneyColumnsUseDecimal208()
    {
        $money = array(
            'wallets' => array('balance','total_deposited','total_spent'),
            'wallet_transactions' => array('amount','balance_before','balance_after'),
            'ledger_entries' => array('amount'),
            'services' => array('rate','provider_rate'),
            'service_prices' => array('rate'),
            'user_service_prices' => array('rate'),
            'orders' => array('charge','provider_charge','rate_at_order','refunded_amount'),
            'payment_transactions' => array('amount','fee','bonus','credited_amount'),
            'referral_commissions' => array('amount'),
            'currencies' => array('exchange_rate'),
        );
        foreach ($money as $table => $columns) {
            $sql = $this->createStatementFor($table);
            $this->assertNotNull($sql, "table {$table} not found");
            foreach ($columns as $col) {
                $this->assertMatchesRegularExpression(
                    '/\b'.preg_quote($col, '/').'\s+DECIMAL\(20,8\)/i',
                    $sql,
                    "{$table}.{$col} must be DECIMAL(20,8)"
                );
            }
        }
    }

    public function testNoUsersBalanceColumn()
    {
        // §24/25/56 — wallets are the single source of truth, never users.balance
        $users = $this->createStatementFor('users');
        $this->assertDoesNotMatchRegularExpression('/^\s*balance\s/mi', $users);
    }

    public function testTimestampsAreDatetimeNotTimestamp()
    {
        foreach ($this->allStatements() as $sql) {
            $this->assertDoesNotMatchRegularExpression(
                '/\b\w+\s+TIMESTAMP\b/i',
                $sql,
                'use DATETIME (UTC) instead of TIMESTAMP'
            );
        }
    }

    public function testPublicIdsAreUniqueChar26()
    {
        foreach ($this->allStatements() as $sql) {
            if (!preg_match('/CREATE TABLE IF NOT EXISTS (\w+)/i', $sql, $m)) continue;
            if (strpos($sql, 'public_id') === false) continue;
            $this->assertMatchesRegularExpression(
                '/public_id CHAR\(26\) NOT NULL UNIQUE/',
                $sql,
                $m[1].'.public_id must be CHAR(26) NOT NULL UNIQUE'
            );
        }
    }

    public function testIdempotencyGuardsExist()
    {
        // §64 — duplicate submissions must be impossible at the DB level
        $this->assertMatchesRegularExpression('/idempotency_key VARCHAR\(128\) NULL UNIQUE/', $this->createStatementFor('wallet_transactions'));
        $this->assertMatchesRegularExpression('/idempotency_key VARCHAR\(128\) NULL UNIQUE/', $this->createStatementFor('orders'));
        $this->assertMatchesRegularExpression('/idempotency_key VARCHAR\(128\) NULL UNIQUE/', $this->createStatementFor('payment_transactions'));
        $this->assertStringContainsString('uq_gateway_event (gateway_type, event_id)', $this->createStatementFor('payment_webhooks'));
        $this->assertStringContainsString('idem_key VARCHAR(128) NOT NULL UNIQUE', $this->createStatementFor('idempotency_keys'));
    }

    public function testForeignKeysReferenceAlreadyCreatedTables()
    {
        $created = array();
        foreach ($this->allStatements() as $sql) {
            if (preg_match('/CREATE TABLE IF NOT EXISTS (\w+)/i', $sql, $m)) $created[$m[1]] = true;
            if (preg_match_all('/REFERENCES\s+(\w+)\s*\(/i', $sql, $refs)) {
                foreach ($refs[1] as $parent) {
                    $this->assertArrayHasKey($parent, $created, "FK references {$parent} before it is created");
                }
            }
        }
    }

    public function testCriticalIndexesExist()
    {
        $this->assertStringContainsString('idx_ord_user_status_created', $this->createStatementFor('orders'));
        $this->assertStringContainsString('idx_ord_status_created', $this->createStatementFor('orders'));
        $this->assertStringContainsString('FULLTEXT INDEX ft_svc_search (name, description)', $this->createStatementFor('services'));
        $this->assertStringContainsString('idx_wt_wallet_created', $this->createStatementFor('wallet_transactions'));
        $this->assertStringContainsString('idx_audit_action_created', $this->createStatementFor('audit_logs'));
    }

    public function testApiKeysStoreHashOnly()
    {
        $sql = $this->createStatementFor('api_keys');
        $this->assertStringContainsString('key_hash CHAR(64) NOT NULL UNIQUE', $sql);
        $this->assertDoesNotMatchRegularExpression('/\bkey_plain|raw_key|api_key VARCHAR/i', $sql);
    }

    public function testProviderCredentialsAreEncryptedAtRest()
    {
        $this->assertStringContainsString('api_key_encrypted', $this->createStatementFor('providers'));
        $this->assertStringContainsString('config_encrypted', $this->createStatementFor('payment_methods'));
    }

    public function testNoLicensingArtifacts()
    {
        // §81 — no license server, purchase codes or domain locks anywhere in the schema
        foreach (array('purchase_code','license_server','license_key','envato','domain_lock') as $banned) {
            foreach ($this->allStatements() as $sql) {
                $this->assertStringNotContainsStringIgnoringCase($banned, $sql);
            }
        }
    }

    public function testGeneratedSqlDumpIsInSync()
    {
        $this->assertNotEmpty(self::$sql, 'docs/database.sql is empty — run php tools/export_schema.php');
        foreach ($this->tableNames() as $table) {
            $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS '.$table.' (', self::$sql, "docs/database.sql is stale (missing {$table})");
        }
        $this->assertStringContainsString('GENERATED FILE', self::$sql);
    }
}
