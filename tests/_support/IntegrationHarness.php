<?php
require_once __DIR__.'/FakeDb.php';

/**
 * IntegrationHarness — boots the real application stack against FakeDb.
 *
 * Every other test file in this suite builds a bespoke double per library:
 * `OrderFakeCI` stubs what OrderService needs, `PayFakeCI` stubs what
 * PaymentService needs, and so on. That is the right tool for pinning one
 * unit's behaviour, but it means nothing ever exercises the *seams*. A double
 * always agrees with the assumption its author had in mind, so a service can
 * write `remains` while its caller reads `remaining` and both unit tests pass.
 *
 * This harness does the opposite: real models, real libraries, real schema
 * (parsed from the migrations), and only the genuine edges of the system
 * faked — the provider HTTP call, the session, and the clock. If two
 * components disagree about a column name or a status string, something here
 * throws.
 *
 * Usage:
 *   $app = new IntegrationHarness();
 *   $app->seed_minimal();
 *   $user = $app->register('alice', 'alice@x.test', 'Str0ng!pass');
 */
#[AllowDynamicProperties]
class IntegrationHarness
{
    /** @var FakeDb */
    public $db;
    public $load;
    public $config;
    public $session;
    public $input;
    public $lang;

    /** Provider responses the fake adapter will return, keyed by method. */
    public $provider_responses = array();
    /** Every call made to the provider, for assertions. */
    public $provider_calls = array();
    /** Emails handed to MailService::deliver(). */
    public $sent_mail = array();

    private static $ddl = null;

    public function __construct(array $config = array())
    {
        $GLOBALS['__fake_ci'] = $this;

        $this->db      = new FakeDb(self::ddl());
        $this->load    = new HarnessLoader($this);
        $this->config  = new HarnessConfig($config);
        $this->session = new HarnessSession();
        $this->input   = new HarnessInput();

        // Libraries that are edges of the system, pre-registered so the real
        // loader hands back the fake rather than trying to make a network call.
        $this->providersyncservice = new HarnessProviderSync($this);
        $this->mailservice         = new HarnessMailService($this);
    }

    /* ------------------------------ schema ------------------------------ */

    /** DDL from the real migrations — the schema under test is the shipped one. */
    public static function ddl()
    {
        if (self::$ddl !== null) return self::$ddl;

        $root = dirname(dirname(__DIR__));
        if (!defined('BASEPATH')) define('BASEPATH', $root.'/system/');
        // Migrations assign $mig->db; declare it so PHP 8.2 logs no dynamic-
        // property deprecation (real CI_Migration declares the property).
        if (!class_exists('CI_Migration')) eval('class CI_Migration { public $db; }');

        $statements = array();
        foreach (glob($root.'/application/migrations/*.php') as $file) {
            require_once $file;
            $class = self::class_in($file);
            if ($class && method_exists($class, 'statements')) {
                foreach ($class::statements() as $sql) $statements[] = $sql;
            }
        }
        return self::$ddl = $statements;
    }

    private static function class_in($file)
    {
        if (preg_match('~class\s+(\w+)\s+extends~', file_get_contents($file), $m)) return $m[1];
        return null;
    }

    /* ------------------------------ loading ----------------------------- */

    /** Load a model the way CI does, so $this->ci->Foo_model resolves. */
    public function model($name)
    {
        foreach ((array)$name as $n) {
            if (isset($this->$n)) continue;
            $file = dirname(dirname(__DIR__)).'/application/models/'.$n.'.php';
            if (!file_exists($file)) {
                throw new RuntimeException("IntegrationHarness: no model {$n}");
            }
            // Other test files eval() stub classes with the same names. In a
            // single-process runner whichever file loads first wins, so a stub
            // already in scope would make require_once fatal here. Loading the
            // real file into a separate class name is not possible, so detect
            // the collision and fail with something a human can act on.
            if (!class_exists($n, false)) {
                require_once $file;
            } elseif (!$this->is_real_model($n, $file)) {
                throw new RuntimeException(
                    "IntegrationHarness: '{$n}' is already declared as a test stub. "
                    ."Integration tests need the real model; run this file on its own, "
                    ."or stop the other test from eval()ing a class with this name."
                );
            }
            $m = new $n();
            // Real models reach the driver through CI_Model's magic __get.
            $m->db = $this->db;
            $this->$n = $m;
        }
        return $this;
    }

    /** Is the loaded class the real model, or someone's eval()'d stub? */
    private function is_real_model($class, $file)
    {
        $ref = new ReflectionClass($class);
        return $ref->getFileName() === realpath($file);
    }

    /** Load a library, honouring anything already registered as a fake. */
    public function library($name, $params = null, $object_name = null)
    {
        foreach ((array)$name as $n) {
            $prop = strtolower($object_name ?: $n);
            if (isset($this->$prop)) continue;

            $file = dirname(dirname(__DIR__)).'/application/libraries/'.$n.'.php';
            if (!file_exists($file)) {
                throw new RuntimeException("IntegrationHarness: no library {$n}");
            }
            // ProviderAdapterInterface must exist before implementors load.
            require_once dirname(dirname(__DIR__)).'/application/libraries/ProviderAdapterInterface.php';
            require_once $file;
            $this->$prop = $params === null ? new $n() : new $n($params);
        }
        return $this;
    }

    /* ------------------------------- seed ------------------------------- */

    /**
     * The smallest coherent world: a price group, a role with permissions, a
     * currency, a provider, a category and one service.
     */

    /**
     * The managed DIGITAL_GOODS category, once per harness. Seed helpers
     * compose (seed_minimal + seed_giftcards), so each one ASKS for the
     * category instead of inserting it — otherwise the second seed trips the
     * UNIQUE(public_id) constraint, which is the correct behaviour for FakeDb
     * to enforce.
     */
    private $category_seeded = false;

    private function ensure_marketplace_category($now)
    {
        if ($this->category_seeded || !$this->db->table_exists('marketplace_categories')) {
            return;
        }
        $this->db->insert('marketplace_categories', array(
            'public_id' => 'MPC00000000000000000000001',
            'name' => 'Digital goods', 'slug' => 'DIGITAL_GOODS',
            'status' => 'ACTIVE', 'sort_order' => 0,
            'created_at' => $now, 'updated_at' => $now,
        ));
        $this->category_seeded = true;
    }

    public function seed_minimal()
    {
        $now = gmdate('Y-m-d H:i:s');

        $this->db->insert('price_groups', array(
            'name' => 'Default', 'is_default' => 1, 'created_at' => $now,
        ));
        foreach (array('SUPER_ADMIN', 'ADMIN', 'STAFF', 'CUSTOMER') as $role) {
            $this->db->insert('roles', array('name' => $role, 'created_at' => $now));
        }
        $this->db->insert('currencies', array(
            'code' => 'NGN', 'name' => 'Nigerian Naira', 'symbol' => '₦',
            'exchange_rate' => '1.00000000', 'is_base' => 1, 'is_active' => 1,
            'updated_at' => $now,
        ));
        $this->ensure_marketplace_category($now);
        $this->db->insert('providers', array(
            'public_id' => 'PROV0000000000000000000001', 'name' => 'Acme SMM',
            'api_url' => 'https://api.acme.test/v2', 'api_key_encrypted' => 'enc:test',
            'api_type' => 'STANDARD_SMM', 'status' => 'ACTIVE', 'currency' => 'NGN',
            'created_at' => $now, 'updated_at' => $now,
        ));
        $this->db->insert('service_categories', array(
            'public_id' => 'CAT00000000000000000000001', 'name' => 'Instagram',
            'slug' => 'instagram', 'is_active' => 1, 'platform' => 'instagram',
            'created_at' => $now, 'updated_at' => $now,
        ));
        $this->db->insert('services', array(
            'public_id' => 'SVC00000000000000000000001', 'category_id' => 1,
            'provider_id' => 1, 'provider_service_id' => '101',
            'name' => 'Instagram Followers', 'slug' => 'instagram-followers',
            'service_type' => 'DEFAULT', 'rate' => '2.00000000',
            'min_quantity' => 100, 'max_quantity' => 10000, 'increment_step' => 1,
            'status' => 'ACTIVE', 'refill_supported' => 1, 'cancel_supported' => 1,
            'dripfeed_supported' => 1, 'subscription_supported' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ));
        return $this;
    }

    /**
     * VTU networks and products, plus a VTU-capable provider.
     *
     * Airtime and electricity get one variable-amount row each (price NULL,
     * bounds + discount); data/cable/exam get fixed-price rows. That mirrors
     * how VtuService distinguishes them.
     */
    public function seed_vtu()
    {
        $now = gmdate('Y-m-d H:i:s');

        $this->ensure_marketplace_category($now);
        $this->db->insert('providers', array(
            'public_id' => 'PROV0000000000000000000002', 'name' => 'Acme VTU',
            'api_url' => 'https://api.vtu.test', 'api_key_encrypted' => 'enc:test',
            'api_type' => 'MOCK', 'status' => 'ACTIVE', 'currency' => 'NGN',
            'created_at' => $now, 'updated_at' => $now,
        ));
        $provider_id = $this->db->insert_id();

        $networks = array(
            array('MTN',  'MTN Nigeria',   'AIRTIME'),
            array('MTND', 'MTN Data',      'DATA'),
            array('DSTV', 'DSTV',          'CABLE'),
            array('IKEDC','Ikeja Electric','ELECTRICITY'),
            array('WAEC', 'WAEC',          'EXAM_PIN'),
        );
        $ids = array();
        foreach ($networks as $i => $n) {
            $this->db->insert('vtu_networks', array(
                'public_id' => 'VNET'.str_pad((string)($i + 1), 22, '0', STR_PAD_LEFT),
                'code' => $n[0], 'name' => $n[1], 'service_type' => $n[2],
                'is_active' => 1, 'sorting' => $i,
                'created_at' => $now, 'updated_at' => $now,
            ));
            $ids[$n[0]] = $this->db->insert_id();
        }

        $products = array(
            // network, type, code, name, price, cost, face, discount, min, max
            array('MTN',  'AIRTIME',  'MTN-AIRTIME', 'MTN Airtime',      null,  null,  null, '2.0000', '50', '50000'),
            array('IKEDC','ELECTRICITY','IKEDC-PP',  'Ikeja Prepaid',    null,  null,  null, '1.0000', '500','100000'),
            array('MTND', 'DATA',     'MTN-1GB',     'MTN 1GB (30 days)','300', '285', '300','0.0000', null, null),
            array('DSTV', 'CABLE',    'DSTV-COMPACT','DSTV Compact',     '10500','10200','10500','0.0000', null, null),
            array('WAEC', 'EXAM_PIN', 'WAEC-PIN',    'WAEC Result PIN',  '3500','3400','3500','0.0000', null, null),
        );
        foreach ($products as $i => $p) {
            $this->db->insert('vtu_products', array(
                'public_id' => 'VPRD'.str_pad((string)($i + 1), 22, '0', STR_PAD_LEFT),
                'network_id' => $ids[$p[0]], 'provider_id' => $provider_id,
                'service_type' => $p[1], 'code' => $p[2], 'provider_code' => $p[2],
                'name' => $p[3], 'price' => $p[4], 'provider_cost' => $p[5],
                'face_value' => $p[6], 'discount_percent' => $p[7],
                'min_amount' => $p[8], 'max_amount' => $p[9],
                'is_active' => 1, 'sorting' => $i,
                'created_at' => $now, 'updated_at' => $now,
            ));
        }
        return $this;
    }

    /**
     * Virtual-number catalogue, plus a number-capable provider.
     *
     * Mirrors what an operator would have after seeding the reference data
     * and pricing one synced product: countries and services from the core
     * seed, and priced (country, service) rows on top. WHATSAPP is priced and
     * in stock, NOSTOCK is priced but out of stock, and SLOW exists so the
     * "no code ever arrives" path is reachable — see MockNumberAdapter.
     */
    public function seed_numbers()
    {
        $now = gmdate('Y-m-d H:i:s');

        $this->ensure_marketplace_category($now);
        $this->db->insert('providers', array(
            'public_id' => 'PROV0000000000000000000003', 'name' => 'Acme Numbers',
            'api_url' => 'https://api.numbers.test', 'api_key_encrypted' => 'enc:test',
            'api_type' => 'MOCK_NUMBER', 'status' => 'ACTIVE', 'currency' => 'NGN',
            'created_at' => $now, 'updated_at' => $now,
        ));
        $provider_id = $this->db->insert_id();

        $countries = array(array('NG', 'Nigeria', '+234'), array('GB', 'United Kingdom', '+44'));
        $country_ids = array();
        foreach ($countries as $i => $c) {
            $this->db->insert('number_countries', array(
                'public_id' => 'NCTY'.str_pad((string)($i + 1), 22, '0', STR_PAD_LEFT),
                'code' => $c[0], 'name' => $c[1], 'dial_prefix' => $c[2],
                'is_active' => 1, 'sorting' => $i,
                'created_at' => $now, 'updated_at' => $now,
            ));
            $country_ids[$c[0]] = $this->db->insert_id();
        }

        $services = array(
            array('WHATSAPP', 'WhatsApp'),
            array('TELEGRAM', 'Telegram'),
            array('NOSTOCK',  'Out of stock service'),
            array('SLOW',     'Never receives a code'),
        );
        $service_ids = array();
        foreach ($services as $i => $s) {
            $this->db->insert('number_services', array(
                'public_id' => 'NSVC'.str_pad((string)($i + 1), 22, '0', STR_PAD_LEFT),
                'code' => $s[0], 'name' => $s[1], 'is_active' => 1, 'sorting' => $i,
                'created_at' => $now, 'updated_at' => $now,
            ));
            $service_ids[$s[0]] = $this->db->insert_id();
        }

        $products = array(
            // country, service, price, cost, stock, ttl
            array('NG', 'WHATSAPP', '450.00000000', '250.00000000', 812, 15),
            array('NG', 'TELEGRAM', '500.00000000', '300.00000000', 415, 15),
            array('NG', 'NOSTOCK',  '400.00000000', '200.00000000', 0,   15),
            array('NG', 'SLOW',     '350.00000000', '180.00000000', 99,  15),
            array('GB', 'WHATSAPP', '900.00000000', '600.00000000', 40,  20),
        );
        foreach ($products as $i => $p) {
            $this->db->insert('number_products', array(
                'public_id' => 'NPRD'.str_pad((string)($i + 1), 22, '0', STR_PAD_LEFT),
                'country_id' => $country_ids[$p[0]], 'service_id' => $service_ids[$p[1]],
                'provider_id' => $provider_id,
                'code' => $p[0].'-'.$p[1],
                'provider_country' => strtolower($p[0]),
                'provider_operator' => 'any',
                'provider_product' => strtolower($p[1]),
                'price' => $p[2], 'provider_cost' => $p[3], 'stock' => $p[4],
                'ttl_minutes' => $p[5], 'is_active' => 1, 'sorting' => $i,
                'created_at' => $now, 'updated_at' => $now,
            ));
        }
        return $this;
    }

    /**
     * Identity domain: a MOCK_IDENTITY provider and a priced catalogue.
     *
     * NIN_UNPRICED is seeded deliberately. The catalogue rule that unpriced
     * rows are not sellable is enforced in Identity_product_model::active()
     * and re-checked in IdentityService::verify(), and a fixture that only
     * ever contains priced rows cannot tell whether either check is still
     * there.
     */
    public function seed_identity()
    {
        $now = gmdate('Y-m-d H:i:s');

        $this->ensure_marketplace_category($now);
        $this->db->insert('providers', array(
            'public_id' => 'PROV0000000000000000000004', 'name' => 'Acme Identity',
            'api_url' => 'https://api.identity.test', 'api_key_encrypted' => 'enc:test',
            'api_type' => 'MOCK_IDENTITY', 'status' => 'ACTIVE', 'currency' => 'NGN',
            'created_at' => $now, 'updated_at' => $now,
        ));
        $provider_id = $this->db->insert_id();

        $products = array(
            // code, name, id_type, lookup_field, provider_code, price, cost, active
            array('NIN_BASIC',    'NIN verification',   'NIN', 'IDENTIFIER', 'kyc/nin',
                  '250.00000000', '120.00000000', 1),
            array('BVN_BASIC',    'BVN verification',   'BVN', 'IDENTIFIER', 'kyc/bvn',
                  '300.00000000', '150.00000000', 1),
            array('NIN_PHONE',    'NIN by phone',       'NIN', 'PHONE',      'kyc/nin/phone_number',
                  '400.00000000', '200.00000000', 1),
            array('NIN_UNPRICED', 'NIN premium',        'NIN', 'IDENTIFIER', 'kyc/nin/advance',
                  null,            null,           1),
            array('BVN_OFF',      'BVN advanced',       'BVN', 'IDENTIFIER', 'kyc/bvn/advance',
                  '500.00000000', '300.00000000', 0),
        );
        foreach ($products as $i => $p) {
            $this->db->insert('identity_products', array(
                'public_id' => 'IDPR'.str_pad((string)($i + 1), 22, '0', STR_PAD_LEFT),
                'code' => $p[0], 'name' => $p[1], 'id_type' => $p[2],
                'lookup_field' => $p[3], 'provider_id' => $provider_id,
                'provider_code' => $p[4], 'price' => $p[5], 'provider_cost' => $p[6],
                'is_active' => $p[7], 'sorting' => $i,
                'created_at' => $now, 'updated_at' => $now,
            ));
        }
        return $this;
    }

    /** Brands and denominations, with a MOCK_GIFTCARD vendor behind them. */
    public function seed_giftcards()
    {
        $now = gmdate('Y-m-d H:i:s');

        $this->ensure_marketplace_category($now);
        $this->db->insert('providers', array(
            'public_id' => 'PROV0000000000000000000005', 'name' => 'Acme Gift Cards',
            'api_url' => 'https://api.giftcards.test', 'api_key_encrypted' => 'enc:test',
            'api_type' => 'MOCK_GIFTCARD', 'status' => 'ACTIVE', 'currency' => 'NGN',
            'created_at' => $now, 'updated_at' => $now,
        ));
        $provider_id = $this->db->insert_id();

        $brands = array(
            array('AMAZON', 'Amazon', 1),
            array('STEAM',  'Steam',  1),
            array('SWITCHED-OFF', 'Switched Off', 0),
        );
        $brand_ids = array();
        foreach ($brands as $i => $b) {
            $this->db->insert('giftcard_brands', array(
                'public_id' => 'GCBR'.str_pad((string)($i + 1), 22, '0', STR_PAD_LEFT),
                'code' => $b[0], 'name' => $b[1],
                'redeem_instructions' => 'Redeem at '.$b[1].'.',
                'is_active' => $b[2], 'sorting' => $i,
                'created_at' => $now, 'updated_at' => $now,
            ));
            $brand_ids[$b[0]] = $this->db->insert_id();
        }

        $products = array(
            // code, brand, name, vendor product id, type, face, price, cost, max qty, active
            array('AMAZON-US-25', 'AMAZON', 'Amazon US $25', '11', 'FIXED',
                  '25.00000000',  '42000.00000000', '38000.00000000', 5, 1),
            array('AMAZON-US-50', 'AMAZON', 'Amazon US $50', '12', 'FIXED',
                  '50.00000000',  '83000.00000000', '76000.00000000', 3, 1),
            array('STEAM-US-20',  'STEAM',  'Steam US $20',  '13', 'FIXED',
                  '20.00000000',  '34000.00000000', '30000.00000000', 5, 1),
            // Out of stock at the mock vendor: its product id ends in 0.
            array('STEAM-US-10',  'STEAM',  'Steam US $10',  '10', 'FIXED',
                  '10.00000000',  '17000.00000000', '15000.00000000', 5, 1),
            // Accepted but never delivered at the mock vendor: id ends in 7.
            array('AMAZON-US-100','AMAZON', 'Amazon US $100','17', 'FIXED',
                  '100.00000000', '166000.00000000','152000.00000000', 2, 1),
            // Imported by a sync and not yet priced — must not be buyable.
            array('AMAZON-GB-10', 'AMAZON', 'Amazon UK £10', '21', 'FIXED',
                  '10.00000000',  null, null, 5, 1),
            // Custom-amount card: no fixed denomination, so not sellable yet.
            array('AMAZON-US-RANGE','AMAZON','Amazon US custom','31','RANGE',
                  null, '50000.00000000', '46000.00000000', 1, 1),
            // Switched off by an operator.
            array('STEAM-US-5',   'STEAM',  'Steam US $5',   '14', 'FIXED',
                  '5.00000000',   '9000.00000000',  '8000.00000000', 5, 0),
        );
        foreach ($products as $i => $p) {
            $this->db->insert('giftcard_products', array(
                'public_id' => 'GCPR'.str_pad((string)($i + 1), 22, '0', STR_PAD_LEFT),
                'brand_id' => $brand_ids[$p[1]], 'provider_id' => $provider_id,
                'code' => $p[0], 'name' => $p[2], 'country_code' => 'US',
                'provider_product_id' => $p[3], 'denomination_type' => $p[4],
                'recipient_currency' => 'USD', 'face_value' => $p[5],
                'price' => $p[6], 'provider_cost' => $p[7],
                'max_quantity' => $p[8], 'is_active' => $p[9], 'sorting' => $i,
                'created_at' => $now, 'updated_at' => $now,
            ));
        }
        return $this;
    }

    /* ----------------------------- factories ---------------------------- */

    /** A user with a wallet, created the way the app creates them. */
    public function register($username, $email, $password = 'Str0ng!pass1', $role = 'CUSTOMER')
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->db->insert('users', array(
            'public_id'     => 'USR'.str_pad((string)($this->db->count('users') + 1), 23, '0', STR_PAD_LEFT),
            'username'      => $username,
            'email'         => $email,
            'password_hash' => password_hash($password, PASSWORD_BCRYPT),
            'role'          => $role,
            'status'        => 'ACTIVE',
            'price_group_id'=> 1,
            'created_at'    => $now,
            'updated_at'    => $now,
        ));
        $id = $this->db->insert_id();
        $this->db->insert('wallets', array(
            'public_id' => 'WAL'.str_pad((string)$id, 23, '0', STR_PAD_LEFT),
            'user_id' => $id, 'balance' => '0.00000000', 'currency' => 'NGN',
            'created_at' => $now, 'updated_at' => $now,
        ));
        return $this->db->where('id', $id)->get('users')->row();
    }

    /** Credit a wallet through the real ledger, never by writing the balance. */
    public function credit($user, $amount, $reference = null)
    {
        $this->library('LedgerService');
        $wallet = $this->db->where('user_id', $user->id)->get('wallets')->row();
        return $this->ledgerservice->credit(
            $wallet->id, $amount, 'DEPOSIT',
            $reference ?: 'test:'.bin2hex(random_bytes(4)),
            array('source' => 'test')
        );
    }

    public function balance($user)
    {
        $w = $this->db->where('user_id', $user->id)->get('wallets')->row();
        return $w ? $w->balance : null;
    }

    /* ----------------------------- assertions --------------------------- */

    /** Rows of a table, as arrays, for direct inspection. */
    public function rows($table) { return $this->db->all($table); }

    /** Ledger entries must always balance: sum(debits) === sum(credits). */
    public function ledger_is_balanced()
    {
        $debits = '0'; $credits = '0';
        foreach ($this->db->all('ledger_entries') as $e) {
            if (($e['direction'] ?? '') === 'DEBIT')  $debits  = bcadd($debits,  (string)$e['amount'], 8);
            if (($e['direction'] ?? '') === 'CREDIT') $credits = bcadd($credits, (string)$e['amount'], 8);
        }
        return array($debits, $credits);
    }
}

/* ------------------------------- edges ----------------------------------- */

class HarnessLoader
{
    private $h;
    public function __construct($h) { $this->h = $h; }
    public function model($n, $alias = null, $db = false) { return $this->h->model($n); }
    public function library($n, $p = null, $o = null) { return $this->h->library($n, $p, $o); }
    public function helper($n = '') {
        foreach ((array)$n as $one) {
            $f = dirname(dirname(__DIR__)).'/application/helpers/'.$one.'_helper.php';
            if (file_exists($f)) require_once $f;
        }
        return $this;
    }
    public function database() { return $this; }
    public function dbforge()  { return $this; }
    public function view($v, $data = array(), $return = false) { return ''; }
    public function config($n, $fail = false, $quiet = false) { return $this; }
}

class HarnessConfig
{
    private $items;
    public function __construct(array $items = array())
    {
        // Real defaults from config/marvy.php matter to pricing and orders,
        // so load the file rather than inventing values.
        $config = array();
        $path = dirname(dirname(__DIR__)).'/application/config/marvy.php';
        if (file_exists($path)) {
            $BASEPATH = true;
            include $path;
        }
        $this->items = array_merge($config, $items);
    }
    public function item($key, $index = '')
    {
        return array_key_exists($key, $this->items) ? $this->items[$key] : null;
    }
    public function set_item($key, $value) { $this->items[$key] = $value; }
}

class HarnessSession
{
    private $data = array(), $flash = array();
    public function userdata($k = null) {
        if ($k === null) return $this->data;
        return array_key_exists($k, $this->data) ? $this->data[$k] : null;
    }
    public function set_userdata($k, $v = null) {
        if (is_array($k)) { foreach ($k as $kk => $vv) $this->data[$kk] = $vv; }
        else $this->data[$k] = $v;
    }
    public function unset_userdata($k) { unset($this->data[$k]); }
    public function sess_regenerate($destroy = false) { $this->data['__regenerated'] = true; }
    public function sess_destroy() { $this->data = array(); }
    public function set_flashdata($k, $v = null) { $this->flash[$k] = $v; }
    public function flashdata($k = null) {
        if ($k === null) return $this->flash;
        return $this->flash[$k] ?? null;
    }
}

class HarnessInput
{
    public $post_data = array(), $ip = '203.0.113.10';
    public function post($k = null, $xss = false) {
        if ($k === null) return $this->post_data;
        return $this->post_data[$k] ?? null;
    }
    public function get($k = null, $xss = false) { return null; }
    public function ip_address() { return $this->ip; }
    public function user_agent() { return 'IntegrationHarness'; }
    public function is_ajax_request() { return false; }
    public function get_request_header($h, $xss = false) { return null; }
}

/**
 * The provider boundary. Real adapters make HTTP calls; this returns whatever
 * the test queued and records what was asked, so "did we submit the right
 * quantity" is assertable.
 */
class HarnessProviderSync
{
    private $h;
    public function __construct($h) { $this->h = $h; }
    public function adapter($provider) { return new HarnessAdapter($this->h); }
    public function test_connection($p) { return array('ok' => true, 'latency_ms' => 12); }
    public function sync_services($p)   { return array('ok' => true, 'total' => 0, 'inserted' => 0, 'updated' => 0); }
    public function sync_balance($p)    { return array('ok' => true, 'balance' => '100.00000000'); }
}

class HarnessAdapter
{
    private $h;
    public function __construct($h) { $this->h = $h; }

    public function __call($method, $args)
    {
        $this->h->provider_calls[] = array('method' => $method, 'args' => $args);

        if (array_key_exists($method, $this->h->provider_responses)) {
            $r = $this->h->provider_responses[$method];
            if ($r instanceof Closure) return $r($args);
            if (is_array($r) && isset($r['__throw'])) throw new RuntimeException($r['__throw']);
            return $r;
        }

        // Sensible defaults: the happy path.
        switch ($method) {
            case 'createOrder':
                return array('ok' => true, 'provider_order_id' => 'P-'.count($this->h->provider_calls));
            case 'getOrderStatus':
                return array('ok' => true, 'data' => array('status' => 'In progress'));
            case 'getMultipleOrderStatus':
                return array('ok' => true, 'data' => array());
            // These names are the ProviderAdapterInterface ones. They used to
            // read createRefill/cancelOrder — methods no adapter has — so the
            // fallback `array('ok' => true)` answered every refill with no
            // refill id, which the service correctly reads as a refusal. A
            // double that disagrees with the interface tests nothing.
            case 'requestRefill':
                return array('ok' => true, 'provider_refill_id' => 'R-1');
            case 'getRefillStatus':
                return array('ok' => true, 'data' => array('status' => 'Completed'));
            case 'requestCancel':
                return array('ok' => true);
            default:
                return array('ok' => true);
        }
    }
}

class HarnessMailService
{
    private $h;
    public function __construct($h) { $this->h = $h; }
    public function enqueue_template($to, $key, array $vars = array(), $to_name = null)
    {
        $this->h->sent_mail[] = array('to' => $to, 'template' => $key, 'vars' => $vars);
        return array('ok' => true);
    }
    public function enqueue_raw($to, $subject, $html, $text = null, $to_name = null)
    {
        $this->h->sent_mail[] = array('to' => $to, 'subject' => $subject);
        return array('ok' => true);
    }
    public function deliver($mail)
    {
        $this->h->sent_mail[] = array('delivered' => $mail);
        return array('ok' => true, 'transport' => 'test');
    }
}
