<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Demo seed — categories, services, a MOCK provider, demo users, wallets and orders.
 *
 * NEVER runs in production: Seed.php refuses unless APP_ENV is development/testing/demo
 * or --force is passed explicitly. Demo passwords are generated, printed once, and
 * can be pinned with DEMO_PASSWORD for local development.
 */
class Demo_seeder extends Seeder {

    private $password;

    public function name() { return 'demo'; }

    public function run() {
        $this->password = getenv('DEMO_PASSWORD') ?: 'Demo!' . bin2hex(random_bytes(4));

        $provider_id   = $this->seed_provider();
        $this->seed_vtpass_provider();
        $this->seed_fivesim_provider();
        $this->seed_dojah_provider();
        $this->seed_reloadly_provider();
        $category_ids  = $this->seed_categories();
        $service_ids   = $this->seed_services($category_ids, $provider_id);
        $user_ids      = $this->seed_users();
        $this->seed_wallets($user_ids);
        $this->seed_orders($user_ids, $service_ids);
        $this->seed_referral_commissions($user_ids);
        $this->seed_content($user_ids);

        $this->out('demo seed complete — login with any demo account:');
        $this->out('  admin@marvy.local / '.$this->password.'   (SUPER_ADMIN)');
        $this->out('  staff@marvy.local / '.$this->password.'   (STAFF)');
        $this->out('  demo@marvy.local  / '.$this->password.'   (CUSTOMER)');
        $this->out('Set DEMO_PASSWORD in .env to pin this value.');
    }

    /* ------------------------------------------------------------------ */

    private function seed_provider() {
        $this->ci->load->library('EncryptionService');
        $enc = $this->ci->encryptionservice->encrypt('mock-api-key-not-a-real-secret');
        return $this->upsert('providers', array('name'=>'Mock Provider (demo)'), array(
            'public_id'             => $this->pid(),
            'api_url'               => 'https://mock.invalid/api/v2',
            'api_key_encrypted'     => $enc,
            'api_type'              => 'MOCK',
            'status'                => 'ACTIVE',
            'currency'              => marvy_base_currency(),
            'balance'               => '1000.00000000',
            'timeout_ms'            => 15000,
            'retry_policy'          => json_encode(array('maxRetries'=>3,'backoffMs'=>array(500,1500,4000))),
            'rate_multiplier'       => '1.00000000',
            'markup'                => '0.00000000',
            'sync_interval_minutes' => 60,
            'health_status'         => 'ONLINE',
            'notes'                 => 'Offline adapter used by tests and APP_ENV=demo. No real network calls.',
        ));
    }

    /**
     * A VTpass sandbox provider, but only when its credentials are in the
     * environment.
     *
     * Seeding it unconditionally would be worse than not seeding it: an ACTIVE
     * VTU provider with no keys is picked ahead of the MOCK adapter by
     * VtuService::provider_for(), so every demo purchase would start failing
     * against a vendor nobody configured. With keys present it is seeded
     * INACTIVE anyway — an admin turns it on deliberately, after testing the
     * connection.
     */
    private function seed_vtpass_provider() {
        $api_key = getenv('VTPASS_API_KEY');
        $public  = getenv('VTPASS_PUBLIC_KEY');
        $secret  = getenv('VTPASS_SECRET_KEY');
        if (!$api_key || !$public || !$secret) {
            $this->out('vtpass: no VTPASS_* credentials in env — skipping (MOCK stays the VTU provider)');
            return null;
        }

        $this->ci->load->library('EncryptionService');
        $enc = $this->ci->encryptionservice->encrypt(json_encode(array(
            'api_key' => $api_key, 'public_key' => $public, 'secret_key' => $secret,
        )));
        $url = getenv('VTPASS_BASE_URL') ?: 'https://sandbox.vtpass.com/api';

        return $this->upsert('providers', array('name'=>'VTpass'), array(
            'public_id'             => $this->pid(),
            'api_url'               => rtrim($url, '/'),
            'api_key_encrypted'     => $enc,
            'api_type'              => 'VTPASS',
            'status'                => 'INACTIVE',
            'currency'              => 'NGN',
            'timeout_ms'            => 20000,
            'retry_policy'          => json_encode(array(
                'maxRetries' => 2, 'backoffMs' => array(500, 1500),
            )),
            'rate_multiplier'       => '1.00000000',
            'markup'                => '0.00000000',
            'sync_interval_minutes' => 720,
            'notes'                 => 'Live VTU vendor. Test the connection, sync the '
                                      .'catalogue and price the products before activating.',
        ));
    }

    /**
     * A 5sim virtual-number vendor, on the same terms as VTpass: only when
     * its token is in the environment, and INACTIVE even then.
     *
     * The rate_to_base note matters. 5sim quotes roubles; this panel is
     * denominated in naira. Without FIVESIM_RATE_TO_BASE the adapter reports
     * no converted cost at all, which shows up as an unknown margin rather
     * than a rouble figure masquerading as naira.
     */
    private function seed_fivesim_provider() {
        $token = getenv('FIVESIM_API_KEY');
        if (!$token) {
            $this->out('5sim: no FIVESIM_API_KEY in env — skipping (MOCK_NUMBER stays the numbers provider)');
            return null;
        }

        $this->ci->load->library('EncryptionService');
        $enc = $this->ci->encryptionservice->encrypt($token);
        $url = getenv('FIVESIM_BASE_URL') ?: 'https://5sim.net/v1';
        $rate = getenv('FIVESIM_RATE_TO_BASE');

        $config = array('maxRetries' => 2, 'backoffMs' => array(500, 1500));
        if ($rate && is_numeric($rate) && (float)$rate > 0) {
            $config['fivesim'] = array('rate_to_base' => (string)$rate);
        }

        return $this->upsert('providers', array('name'=>'5sim'), array(
            'public_id'             => $this->pid(),
            'api_url'               => rtrim($url, '/'),
            'api_key_encrypted'     => $enc,
            'api_type'              => 'FIVESIM',
            'status'                => 'INACTIVE',
            'currency'              => 'RUB',
            'timeout_ms'            => 20000,
            'retry_policy'          => json_encode($config),
            'rate_multiplier'       => '1.00000000',
            'markup'                => '0.00000000',
            'sync_interval_minutes' => 60,
            'notes'                 => 'Live virtual-number vendor, priced in RUB. Set '
                                      .'FIVESIM_RATE_TO_BASE before syncing, or costs come '
                                      .'back unconverted. Sync and price the products before activating.',
        ));
    }

    /**
     * A Dojah identity vendor, on the same env-gated, INACTIVE terms as the
     * other two live vendors.
     *
     * The default URL is the sandbox. Pointing a demo install at api.dojah.io
     * with real keys would spend real money on every click, and the failure
     * mode of the reverse mistake — sandbox keys against the live URL — is a
     * flat 401, which is a much better afternoon.
     */
    private function seed_dojah_provider() {
        $api_key = getenv('DOJAH_API_KEY');
        $app_id  = getenv('DOJAH_APP_ID');
        if (!$api_key || !$app_id) {
            $this->out('dojah: no DOJAH_* credentials in env — skipping (MOCK_IDENTITY stays the identity provider)');
            return null;
        }

        $this->ci->load->library('EncryptionService');
        $enc = $this->ci->encryptionservice->encrypt(json_encode(array(
            'api_key' => $api_key, 'app_id' => $app_id,
        )));
        $url = getenv('DOJAH_BASE_URL') ?: 'https://sandbox.dojah.io';

        return $this->upsert('providers', array('name'=>'Dojah'), array(
            'public_id'             => $this->pid(),
            'api_url'               => rtrim($url, '/'),
            'api_key_encrypted'     => $enc,
            'api_type'              => 'DOJAH',
            'status'                => 'INACTIVE',
            'currency'              => 'NGN',
            'timeout_ms'            => 30000,
            'retry_policy'          => json_encode(array(
                'maxRetries' => 2, 'backoffMs' => array(500, 1500),
            )),
            'rate_multiplier'       => '1.00000000',
            'markup'                => '0.00000000',
            'sync_interval_minutes' => 1440,
            'notes'                 => 'Live identity/KYC vendor. There is no catalogue to '
                                      .'sync — price the identity products by hand against your '
                                      .'per-lookup contract, then activate. Every lookup is billable, '
                                      .'including ones that find nobody.',
        ));
    }

    /**
     * A Reloadly gift card vendor, on the same env-gated, INACTIVE terms as
     * the other live vendors.
     *
     * The default URL is the sandbox, and the adapter derives its OAuth
     * audience from that URL — so a demo install cannot accidentally mint a
     * production token and start spending real money on test clicks. The
     * reverse mistake, sandbox credentials against the live host, is a flat
     * 401, which is a much better afternoon.
     *
     * No catalogue is seeded with it: run the sync, then price the
     * denominations against the rate you actually get, then activate.
     */
    private function seed_reloadly_provider() {
        $client_id     = getenv('RELOADLY_CLIENT_ID');
        $client_secret = getenv('RELOADLY_CLIENT_SECRET');
        if (!$client_id || !$client_secret) {
            $this->out('reloadly: no RELOADLY_* credentials in env — skipping (MOCK_GIFTCARD stays the gift card provider)');
            return null;
        }

        $this->ci->load->library('EncryptionService');
        $enc = $this->ci->encryptionservice->encrypt(json_encode(array(
            'client_id' => $client_id, 'client_secret' => $client_secret,
        )));
        $url = getenv('RELOADLY_BASE_URL') ?: 'https://giftcards-sandbox.reloadly.com';

        return $this->upsert('providers', array('name'=>'Reloadly'), array(
            'public_id'             => $this->pid(),
            'api_url'               => rtrim($url, '/'),
            'api_key_encrypted'     => $enc,
            'api_type'              => 'RELOADLY',
            'status'                => 'INACTIVE',
            'currency'              => 'NGN',
            'timeout_ms'            => 30000,
            'retry_policy'          => json_encode(array(
                'maxRetries' => 2, 'backoffMs' => array(500, 1500),
            )),
            'rate_multiplier'       => '1.00000000',
            'markup'                => '0.00000000',
            'sync_interval_minutes' => 720,
            'notes'                 => 'Live gift card vendor. Sync the catalogue, then price the '
                                      .'denominations by hand — vendor cost moves with the FX rate, so '
                                      .'a sync never sets your selling price. Cards are charged to your '
                                      .'Reloadly wallet whether or not the code reaches the customer.',
        ));
    }

    public static function category_catalog() {
        return array(
            array('Instagram', 'instagram', 'instagram', 'instagram', 10),
            array('TikTok',    'tiktok',    'music',     'tiktok',    20),
            array('YouTube',   'youtube',   'youtube',   'youtube',   30),
            array('Twitter / X','twitter-x','twitter',   'twitter',   40),
            array('Facebook',  'facebook',  'facebook',  'facebook',  50),
            array('Telegram',  'telegram',  'send',      'telegram',  60),
            array('Spotify',   'spotify',   'music-2',   'spotify',   70),
            array('Website Traffic','website-traffic','globe','web',  80),
        );
    }

    private function seed_categories() {
        $ids = array();
        foreach (self::category_catalog() as $c) {
            $ids[$c[1]] = $this->upsert('service_categories', array('slug'=>$c[1]), array(
                'public_id'   => $this->pid(),
                'name'        => $c[0],
                'icon'        => $c[2],
                'platform'    => $c[3],
                'sorting'     => $c[4],
                'is_active'   => 1,
                'description' => $c[0].' growth services.',
            ));
        }
        return $ids;
    }

    /**
     * name, slug, category slug, type, rate/1000, min, max, avg label, avg minutes,
     * provider service id, provider rate, flags[refill, cancel, dripfeed, featured, trending]
     */
    public static function service_catalog() {
        return array(
            array('Instagram Followers — Real Mix','instagram-followers-real-mix','instagram','DEFAULT','2.35000000',50,100000,'0-1 hour',45,'1001','1.60000000',array(1,1,1,1,1)),
            array('Instagram Likes — High Quality','instagram-likes-high-quality','instagram','DEFAULT','0.85000000',20,50000,'0-30 minutes',20,'1002','0.52000000',array(1,1,1,1,0)),
            array('Instagram Views — Reels','instagram-views-reels','instagram','DEFAULT','0.18000000',100,1000000,'0-15 minutes',10,'1003','0.09000000',array(0,1,1,0,1)),
            array('Instagram Custom Comments','instagram-custom-comments','instagram','CUSTOM_COMMENTS','12.00000000',10,5000,'1-3 hours',120,'1004','8.40000000',array(0,1,0,0,0)),
            array('Instagram Story Views','instagram-story-views','instagram','DEFAULT','0.42000000',100,50000,'0-1 hour',35,'1005','0.24000000',array(0,0,1,0,0)),
            array('TikTok Followers','tiktok-followers','tiktok','DEFAULT','3.10000000',50,200000,'0-2 hours',80,'2001','2.05000000',array(1,1,1,1,1)),
            array('TikTok Likes','tiktok-likes','tiktok','DEFAULT','0.65000000',20,100000,'0-30 minutes',18,'2002','0.38000000',array(1,1,1,0,1)),
            array('TikTok Video Views','tiktok-video-views','tiktok','DEFAULT','0.09000000',100,5000000,'0-15 minutes',8,'2003','0.04000000',array(0,0,1,0,1)),
            array('YouTube Subscribers','youtube-subscribers','youtube','DEFAULT','14.50000000',50,20000,'6-24 hours',600,'3001','10.90000000',array(1,1,0,1,0)),
            array('YouTube Views — High Retention','youtube-views-high-retention','youtube','DEFAULT','1.95000000',500,1000000,'1-6 hours',180,'3002','1.35000000',array(1,1,1,1,1)),
            array('YouTube Watch Hours (4000h)','youtube-watch-hours','youtube','PACKAGE','89.00000000',1,10,'3-10 days',7200,'3003','66.00000000',array(0,0,0,0,0)),
            array('X (Twitter) Followers','x-followers','twitter-x','DEFAULT','5.80000000',50,50000,'1-6 hours',240,'4001','4.10000000',array(1,1,0,0,0)),
            array('X (Twitter) Retweets','x-retweets','twitter-x','DEFAULT','1.40000000',20,20000,'0-2 hours',60,'4002','0.95000000',array(0,1,1,0,0)),
            array('Facebook Page Likes','facebook-page-likes','facebook','DEFAULT','4.20000000',50,50000,'2-12 hours',300,'5001','3.05000000',array(1,1,0,0,0)),
            array('Telegram Channel Members','telegram-channel-members','telegram','DEFAULT','2.90000000',100,100000,'0-3 hours',90,'6001','1.95000000',array(1,1,1,0,1)),
            array('Telegram Post Views','telegram-post-views','telegram','DEFAULT','0.12000000',100,1000000,'0-15 minutes',10,'6002','0.06000000',array(0,0,1,0,0)),
            array('Spotify Plays','spotify-plays','spotify','DEFAULT','1.10000000',1000,1000000,'1-12 hours',360,'7001','0.72000000',array(0,1,1,0,0)),
            array('Spotify Monthly Listeners','spotify-monthly-listeners','spotify','SUBSCRIPTION','8.40000000',100,50000,'12-48 hours',1440,'7002','6.10000000',array(0,0,0,0,0)),
            array('Website Traffic — Google Referral','website-traffic-google','website-traffic','DEFAULT','0.55000000',1000,500000,'0-6 hours',120,'8001','0.28000000',array(0,1,1,0,0)),
            array('Instagram Mentions — User Followers','instagram-mentions-user-followers','instagram','MENTIONS_USER_FOLLOWERS','9.60000000',100,20000,'2-8 hours',300,'1006','7.15000000',array(0,0,0,0,0)),
        );
    }

    private function seed_services($category_ids, $provider_id) {
        $ids = array();
        foreach (self::service_catalog() as $i => $s) {
            list($name,$slug,$cat,$type,$rate,$min,$max,$avg,$avgmin,$psid,$prate,$flags) = $s;
            if (!isset($category_ids[$cat])) continue;
            $service_id = $this->upsert('services', array('slug'=>$slug), array(
                'public_id'                 => $this->pid(),
                'name'                      => $name,
                'category_id'               => $category_ids[$cat],
                'description'               => $name.'. Delivered by our vetted provider network with automatic refill support where marked.',
                'service_type'              => $type,
                'rate'                      => $rate,
                'min_quantity'              => $min,
                'max_quantity'              => $max,
                'average_time'              => $avg,
                'average_time_minutes'      => $avgmin,
                'provider_id'               => $provider_id,
                'provider_service_id'       => $psid,
                'provider_rate'             => $prate,
                'status'                    => 'ACTIVE',
                'refill_supported'          => $flags[0],
                'refill_days'               => $flags[0] ? 30 : NULL,
                'cancel_supported'          => $flags[1],
                'dripfeed_supported'        => $flags[2],
                'subscription_supported'    => ($type === 'SUBSCRIPTION') ? 1 : 0,
                'package_supported'         => ($type === 'PACKAGE') ? 1 : 0,
                'custom_comments_supported' => (strpos($type, 'CUSTOM_COMMENTS') === 0) ? 1 : 0,
                'featured'                  => $flags[3],
                'trending'                  => $flags[4],
                'sorting'                   => ($i + 1) * 10,
                'auto_price_sync'           => 0,
                'metadata'                  => json_encode(array('platform'=>$cat)),
            ));
            $ids[$slug] = $service_id;

            // mirror into provider_services so provider sync has a baseline
            $this->upsert('provider_services', array('provider_id'=>$provider_id,'provider_service_id'=>$psid), array(
                'name'               => $name,
                'category'           => $cat,
                'rate'               => $prate,
                'min_quantity'       => $min,
                'max_quantity'       => $max,
                'service_type'       => $type,
                'refill_supported'   => $flags[0],
                'cancel_supported'   => $flags[1],
                'dripfeed_supported' => $flags[2],
                'raw_payload'        => json_encode(array('service'=>$psid,'name'=>$name,'rate'=>$prate,'min'=>$min,'max'=>$max)),
                'last_synced_at'     => $this->now(),
            ));

            // tiered pricing for Silver / Gold / Reseller groups
            foreach (array('Silver'=>0.95, 'Gold'=>0.90, 'Reseller'=>0.85) as $group => $factor) {
                $g = $this->ci->db->where('name', $group)->get('price_groups')->row();
                if (!$g) continue;
                $this->upsert('service_prices', array('service_id'=>$service_id,'price_group_id'=>$g->id), array(
                    'rate' => bcmul($rate, (string)$factor, 8),
                ));
            }
        }
        return $ids;
    }

    private function seed_users() {
        $accounts = array(
            array('admin','admin@marvy.local','SUPER_ADMIN','Ada','Marvy'),
            array('staff','staff@marvy.local','STAFF','Sam','Support'),
            array('demo','demo@marvy.local','CUSTOMER','Dana','Demo'),
            array('reseller','reseller@marvy.local','CUSTOMER','Rio','Reseller'),
        );
        $default_group  = $this->ci->db->where('name','Default')->get('price_groups')->row();
        $reseller_group = $this->ci->db->where('name','Reseller')->get('price_groups')->row();

        $ids = array();
        foreach ($accounts as $a) {
            list($username,$email,$role,$first,$last) = $a;
            $group = ($username === 'reseller' && $reseller_group) ? $reseller_group->id : ($default_group ? $default_group->id : NULL);
            // Match on username, not email. The SQL bootstrap imports the same
            // demo accounts with @example.com addresses; matching by email
            // missed them, tried to INSERT another row with the same username,
            // and the whole transaction rolled back on the UNIQUE constraint.
            // Keying on username finds those rows and updates them to the
            // demo addresses/passwords, which keeps `seed demo` idempotent on
            // top of either the SQL dump or an empty database.
            $ids[$username] = $this->upsert('users', array('username'=>$username), array(
                'public_id'         => $this->pid(),
                'email'             => $email,
                'password_hash'     => $this->hash_password($this->password),
                'first_name'        => $first,
                'last_name'         => $last,
                'status'            => 'ACTIVE',
                'role'              => $role,
                'price_group_id'    => $group,
                'referral_code'     => strtoupper($username).'-'.strtoupper(bin2hex(random_bytes(3))),
                'email_verified_at' => $this->now(),
                'timezone'          => 'UTC',
                'locale'            => 'en',
            ));

            // Allocated after the upsert and only when missing: re-running the
            // seed must not hand an existing demo account a different account
            // number every time.
            if ($ids[$username]) {
                $row = $this->ci->db->where('id', $ids[$username])->get('users')->row();
                if ($row && empty($row->user_code)) {
                    // Allocate first, then build the UPDATE: the allocator runs
                    // its own query, which would otherwise consume the pending
                    // where('id') and update every row in the table.
                    $code = marvy_allocate_user_code($this->ci->db);
                    if ($code !== null) {
                        $this->ci->db->where('id', $ids[$username])
                            ->update('users', array('user_code' => $code));
                    }
                }
            }
        }

        // referral: reseller was referred by demo
        if (isset($ids['demo'], $ids['reseller'])) {
            $referrer = $this->ci->db->where('id',$ids['demo'])->get('users')->row();
            $account_id = $this->upsert('referral_accounts', array('user_id'=>$ids['demo']), array(
                'code'               => $referrer ? $referrer->referral_code : 'DEMO-REF',
                'commission_percent' => '5.0000',
                'total_referred'     => 1,
            ));
            $this->insert_once('referrals', array('referred_id'=>$ids['reseller']), array(
                'referrer_id'         => $ids['demo'],
                'referral_account_id' => $account_id,
            ));
            $this->ci->db->where('id',$ids['reseller'])->update('users', array('referred_by_id'=>$ids['demo']));
        }
        return $ids;
    }

    private function seed_wallets($user_ids) {
        $balances = array('admin'=>'0.00000000','staff'=>'0.00000000','demo'=>'250.00000000','reseller'=>'1500.00000000');
        foreach ($user_ids as $username => $uid) {
            // insert_once, never upsert: re-running the seed must not reset a balance.
            $wallet_id = $this->insert_once('wallets', array('user_id'=>$uid), array(
                'public_id' => $this->pid(),
                'balance'   => '0.00000000',
                'currency'  => marvy_base_currency(),
            ));
            $target = $balances[$username] ?? '0.00000000';
            if (bccomp($target, '0', 8) <= 0) continue;

            // credit through a real wallet_transaction + ledger entries (never a bare UPDATE)
            $idem = 'seed:deposit:'.$username;
            $exists = $this->ci->db->where('idempotency_key',$idem)->get('wallet_transactions')->row();
            if ($exists) { $this->bump('wallet_transactions','skipped'); continue; }

            $this->ci->db->insert('wallet_transactions', array(
                'public_id'       => $this->pid(),
                'wallet_id'       => $wallet_id,
                'type'            => 'DEPOSIT',
                'direction'       => 'CREDIT',
                'amount'          => $target,
                'balance_before'  => '0.00000000',
                'balance_after'   => $target,
                'currency'        => marvy_base_currency(),
                'reference_type'  => 'Seed',
                'reference_id'    => 'demo',
                'note'            => 'Demo opening balance',
                'idempotency_key' => $idem,
                'created_at'      => $this->now(),
            ));
            $wt_id = (int)$this->ci->db->insert_id();
            $this->bump('wallet_transactions','inserted');

            foreach (array(array('wallet:'.$wallet_id,'CREDIT'), array('liability','DEBIT')) as $entry) {
                $this->ci->db->insert('ledger_entries', array(
                    'wallet_transaction_id' => $wt_id,
                    'account'               => $entry[0],
                    'direction'             => $entry[1],
                    'amount'                => $target,
                    'currency'              => marvy_base_currency(),
                    'created_at'            => $this->now(),
                ));
                $this->bump('ledger_entries','inserted');
            }
            $this->ci->db->where('id',$wallet_id)->update('wallets', array(
                'balance'         => $target,
                'total_deposited' => $target,
                'updated_at'      => $this->now(),
            ));
        }
    }

    private function seed_orders($user_ids, $service_ids) {
        if (empty($user_ids['demo']) || empty($service_ids)) return;

        $plan = array(
            array('instagram-followers-real-mix', 1000, 'COMPLETED', 'https://instagram.com/marvydemo'),
            array('tiktok-likes',                 2500, 'IN_PROGRESS', 'https://tiktok.com/@marvydemo/video/123'),
            array('youtube-views-high-retention', 5000, 'PARTIAL', 'https://youtube.com/watch?v=demo123'),
            array('telegram-post-views',         10000, 'PENDING', 'https://t.me/marvydemo/42'),
            array('instagram-likes-high-quality',  500, 'CANCELED', 'https://instagram.com/p/demo'),
        );
        $user_id = $user_ids['demo'];

        foreach ($plan as $i => $p) {
            list($slug, $qty, $status, $link) = $p;
            if (empty($service_ids[$slug])) continue;
            $service = $this->ci->db->where('id',$service_ids[$slug])->get('services')->row();
            if (!$service) continue;

            $charge   = bcmul(bcdiv($service->rate, '1000', 8), (string)$qty, 8);
            $pcharge  = bcmul(bcdiv($service->provider_rate ?: $service->rate, '1000', 8), (string)$qty, 8);
            $idem     = 'seed:order:'.$slug;
            $order_id = $this->insert_once('orders', array('idempotency_key'=>$idem), array(
                'public_id'           => $this->pid(),
                'user_id'             => $user_id,
                'service_id'          => $service->id,
                'provider_id'         => $service->provider_id,
                'provider_order_id'   => ($status === 'PENDING') ? NULL : 'MOCK-'.(90000 + $i),
                'provider_service_id' => $service->provider_service_id,
                'status'              => $status,
                'link'                => $link,
                'quantity'            => $qty,
                'charge'              => $charge,
                'rate_at_order'       => $service->rate,
                'provider_charge'     => $pcharge,
                'currency'            => marvy_base_currency(),
                'start_count'         => ($status === 'PENDING') ? NULL : 1000 + $i * 37,
                'remains'             => ($status === 'PARTIAL') ? (int)round($qty * 0.2) : (($status === 'COMPLETED') ? 0 : NULL),
                'refunded_amount'     => ($status === 'PARTIAL') ? bcmul($charge, '0.2', 8) : '0.00000000',
                'source'              => 'WEB',
                'submitted_at'        => ($status === 'PENDING') ? NULL : $this->now(),
                'completed_at'        => ($status === 'COMPLETED') ? $this->now() : NULL,
                'created_at'          => gmdate('Y-m-d H:i:s', time() - (86400 * ($i + 1))),
                'updated_at'          => gmdate('Y-m-d H:i:s', time() - (86400 * ($i + 1))),
            ));
            if (!is_int($order_id) || $order_id === 0) continue;

            $history = array(array(NULL, 'PENDING', 'SYSTEM'));
            if ($status !== 'PENDING') $history[] = array('PENDING', 'PROCESSING', 'WORKER');
            if (in_array($status, array('IN_PROGRESS','COMPLETED','PARTIAL'), TRUE)) $history[] = array('PROCESSING','IN_PROGRESS','PROVIDER');
            if (in_array($status, array('COMPLETED','PARTIAL'), TRUE)) $history[] = array('IN_PROGRESS', $status, 'PROVIDER');
            if ($status === 'CANCELED') $history[] = array('PROCESSING','CANCELED','ADMIN');

            foreach ($history as $h) {
                $this->insert_once('order_status_history', array(
                    'order_id'    => $order_id,
                    'new_status'  => $h[1],
                ), array(
                    'previous_status' => $h[0],
                    'source'          => $h[2],
                    'reason'          => 'Demo seed',
                ));
            }
        }
    }

    /**
     * A PENDING referral commission on the demo referral edge (Session 14).
     *
     * Deliberately left PENDING and unpaid: paying it would have to move money,
     * and only LedgerService may do that. `php index.php cron affiliate_payouts`
     * is the supported way to settle it in a demo environment.
     */
    private function seed_referral_commissions($user_ids) {
        if (empty($user_ids['demo']) || empty($user_ids['reseller'])) return;

        $referral = $this->ci->db->where('referred_id', $user_ids['reseller'])->get('referrals')->row();
        if (!$referral) return;

        $account = $this->ci->db->where('id', $referral->referral_account_id)->get('referral_accounts')->row();
        $percent = $account ? (string)$account->commission_percent : '5.0000';

        // Attribute it to the reseller's most valuable completed order, if any.
        $order = $this->ci->db->where('user_id', $user_ids['reseller'])
            ->where_in('status', array('COMPLETED','PARTIAL'))
            ->order_by('charge', 'DESC')->limit(1)->get('orders')->row();

        $charge = $order ? (string)$order->charge : '40.00000000';
        $amount = bcdiv(bcmul($charge, $percent, 12), '100', 8);
        if (bccomp($amount, '0', 8) <= 0) return;

        $this->insert_once('referral_commissions', array(
            'referral_id' => $referral->id,
            'order_id'    => $order ? $order->id : NULL,
        ), array(
            'amount'   => $amount,
            'currency' => marvy_base_currency(),
            'status'   => 'PENDING',
        ));
    }

    private function seed_content($user_ids) {
        $author = $user_ids['admin'] ?? NULL;

        $cat_id = $this->upsert('blog_categories', array('slug'=>'growth-guides'), array(
            'name' => 'Growth Guides',
            'description' => 'Playbooks for growing social accounts responsibly.',
        ));

        $posts = array(
            array('How to Plan a 30-Day Instagram Growth Sprint','how-to-plan-30-day-instagram-growth-sprint',
                'A practical, week-by-week plan that mixes content cadence with paid amplification.'),
            array('Drip-Feed vs. Instant Delivery: Which Should You Use?','dripfeed-vs-instant-delivery',
                'Drip-feed spreads delivery over time so growth looks organic. Here is when each option wins.'),
            array('Reseller API: From First Key to First 1,000 Orders','reseller-api-first-1000-orders',
                'Everything you need to automate ordering against the MARVYSOCIALS reseller API.'),
        );
        foreach ($posts as $i => $p) {
            $this->insert_once('blog_posts', array('slug'=>$p[1]), array(
                'public_id'        => $this->pid(),
                'title'            => $p[0],
                'excerpt'          => $p[2],
                'content'          => '<p>'.$p[2].'</p><p>This is demo content seeded for local development and the demo environment.</p>',
                'status'           => 'PUBLISHED',
                'author_id'        => $author,
                'category_id'      => $cat_id,
                'meta_title'       => $p[0],
                'meta_description' => $p[2],
                'published_at'     => gmdate('Y-m-d H:i:s', time() - (86400 * ($i + 2))),
            ));
        }

        $this->insert_once('announcements', array('title'=>'Welcome to the MARVYSOCIALS demo panel'), array(
            'public_id' => $this->pid(),
            'content'   => 'You are viewing seeded demo data. Providers are mocked and no real orders are placed.',
            'severity'  => 'INFO',
            'audience'  => 'all',
            'is_active' => 1,
        ));

        if (!empty($user_ids['demo'])) {
            $ticket_id = $this->insert_once('tickets', array('subject'=>'Order still pending after 2 hours'), array(
                'public_id' => $this->pid(),
                'user_id'   => $user_ids['demo'],
                'status'    => 'ANSWERED',
                'priority'  => 'MEDIUM',
                'department'=> 'orders',
            ));
            if (is_int($ticket_id) && $ticket_id > 0) {
                $this->insert_once('ticket_messages', array('ticket_id'=>$ticket_id,'is_staff'=>0), array(
                    'public_id' => $this->pid(),
                    'author_id' => $user_ids['demo'],
                    'message'   => 'Hi, my Telegram views order has been pending for a while. Can you check?',
                ));
                if (!empty($user_ids['staff'])) {
                    $this->insert_once('ticket_messages', array('ticket_id'=>$ticket_id,'is_staff'=>1), array(
                        'public_id' => $this->pid(),
                        'author_id' => $user_ids['staff'],
                        'message'   => 'Thanks for reaching out — the provider queue was backed up and your order is now processing.',
                    ));
                }
            }
        }
    }
}
