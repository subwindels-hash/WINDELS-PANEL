<?php
defined('BASEPATH') OR exit('No direct script access allowed');

// The api_type whitelist and the family split are read straight off the
// registry, including from static context, so the class must be present even
// when CI has not loaded the library yet.
require_once __DIR__.'/Provider_manager.php';
// Instantiated directly in adapter(); plain library classes are not
// autoloaded by CI3, so an unrequired `new` fatals at the first real sync.
require_once __DIR__.'/MockProviderAdapter.php';
require_once __DIR__.'/StandardSmmAdapter.php';

/**
 * ProviderSyncService — adapter factory, connection test, service sync and
 * balance sync for providers (Session 08, extended in session 24).
 *
 * The service never logs the decrypted API key, always goes through
 * SecureHttpClient (TLS verify ON), and writes structured sync/health logs.
 *
 * Providers come in families. SMM panels answer getBalance()/getServices();
 * VTU vendors answer balance() and a per-service variation list. Rather than
 * pretend one is the other, everything here dispatches on the family the
 * api_type belongs to, and VTU adapters are built by Provider_manager so there
 * is still exactly one registry of integrations.
 */
class ProviderSyncService {

    /** Largest percentage markup the console offers (200% = sell at 3x cost). */
    const MAX_MARKUP_PERCENT = 200;

    private $ci;

    public function __construct() {
        $this->ci =& get_instance();
        $this->ci->load->model(array('Provider_model','Provider_service_model'));
        $this->ci->load->library(array('EncryptionService','SecureHttpClient'));
    }

    /**
     * Which family a provider row belongs to.
     *
     * MOCK is registered in both families and stays SMM here: it is the demo
     * seed's SMM panel, and VTU code reaches it through Provider_manager.
     */
    public static function family($provider) {
        $type = strtoupper(is_object($provider) ? ($provider->api_type ?? '') : (string)$provider);
        if ($type === 'MOCK') return Provider_manager::FAMILY_SMM;

        foreach (Provider_manager::families() as $family) {
            if ($family === Provider_manager::FAMILY_SMM) continue;
            if (in_array($type, Provider_manager::supported_types($family), true)) return $family;
        }
        return Provider_manager::FAMILY_SMM;
    }

    /** Every api_type this build can talk to, across all families. */
    public static function supported_types() {
        $types = array();
        foreach (Provider_manager::families() as $family) {
            foreach (Provider_manager::supported_types($family) as $type) {
                if (!in_array($type, $types, true)) $types[] = $type;
            }
        }
        return $types;
    }

    /**
     * Build the SMM adapter for a stored provider row.
     * MOCK providers use the offline adapter; everything else uses StandardSmmAdapter.
     */
    public function adapter($provider) {
        $type = strtoupper($provider->api_type ?? 'STANDARD_SMM');
        if ($type === 'MOCK') {
            // Same production rule as Provider_manager: never let an offline
            // double fulfil paid production traffic.
            Provider_manager::assert_mock_allowed($type);
            return new MockProviderAdapter();
        }
        return new StandardSmmAdapter($provider, $this->ci->securehttpclient);
    }

    /** Build the VTU adapter through the one registry (§14). */
    public function vtu_adapter($provider) {
        $this->ci->load->library('Provider_manager');
        return $this->ci->provider_manager->adapter($provider, Provider_manager::FAMILY_VTU);
    }

    /** Build the virtual-number adapter through the same registry (§10, §14). */
    public function number_adapter($provider) {
        $this->ci->load->library('Provider_manager');
        return $this->ci->provider_manager->adapter($provider, Provider_manager::FAMILY_NUMBER);
    }

    /** Build the identity/KYC adapter through the same registry (§14, §22). */
    public function identity_adapter($provider) {
        $this->ci->load->library('Provider_manager');
        return $this->ci->provider_manager->adapter($provider, Provider_manager::FAMILY_IDENTITY);
    }

    /** Build the gift card adapter through the same registry (§14, §23). */
    public function giftcard_adapter($provider) {
        $this->ci->load->library('Provider_manager');
        return $this->ci->provider_manager->adapter($provider, Provider_manager::FAMILY_GIFTCARD);
    }

    /**
     * Live connectivity + auth check via the adapter's balance call.
     *
     * @return array{ok:bool,balance?:string,currency?:string,latency_ms?:int,error?:string,http_code?:int}
     */
    public function test_connection($provider) {
        $family = self::family($provider);
        if ($family === Provider_manager::FAMILY_VTU) {
            return $this->test_vtu_connection($provider);
        }
        if ($family === Provider_manager::FAMILY_NUMBER) {
            return $this->test_number_connection($provider);
        }
        if ($family === Provider_manager::FAMILY_IDENTITY) {
            return $this->test_identity_connection($provider);
        }
        if ($family === Provider_manager::FAMILY_GIFTCARD) {
            return $this->test_giftcard_connection($provider);
        }
        $start = microtime(true);
        try {
            $adapter = $this->adapter($provider);
            $res = $adapter->getBalance();
            $latency = (int)round((microtime(true) - $start) * 1000);
        } catch (Exception $e) {
            $latency = (int)round((microtime(true) - $start) * 1000);
            $this->ci->Provider_model->record_health($provider->id, 'OFFLINE', $latency, $e->getMessage());
            return array('ok' => false, 'error' => $e->getMessage(), 'latency_ms' => $latency);
        }

        if (empty($res['ok'])) {
            $err = $res['error'] ?? 'Connection failed';
            $this->ci->Provider_model->record_health($provider->id, 'OFFLINE', $latency, $err);
            return array('ok' => false, 'error' => $err, 'latency_ms' => $latency);
        }

        $data = $res['data'] ?? array();
        // Standard SMM panel returns {balance, currency}; mock returns the same shape.
        $balance = isset($data['balance']) ? (string)$data['balance'] : null;
        $currency = $data['currency'] ?? ($provider->currency ?? marvy_base_currency());

        $this->ci->db->trans_start();
        $this->ci->Provider_model->record_health($provider->id, 'ONLINE', $latency, null);
        if ($balance !== null) {
            $this->ci->Provider_model->update_provider($provider->id, array(
                'balance' => number_format((float)$balance, 8, '.', ''),
                'currency' => $currency,
            ));
        }
        $this->ci->db->trans_complete();

        return array(
            'ok' => true,
            'balance' => $balance,
            'currency' => $currency,
            'latency_ms' => $latency,
        );
    }

    /**
     * Connectivity + auth check for a VTU vendor.
     *
     * Same contract as the SMM path — health row, balance refresh, latency —
     * but the adapter comes from Provider_manager and answers balance(), which
     * for VTpass is also the cheapest way to prove the api-key/public-key pair
     * is the right way round.
     */
    private function test_vtu_connection($provider) {
        $start = microtime(true);
        try {
            $res = $this->vtu_adapter($provider)->balance();
        } catch (Exception $e) {
            $latency = (int)round((microtime(true) - $start) * 1000);
            $this->ci->Provider_model->record_health($provider->id, 'OFFLINE', $latency, $e->getMessage());
            return array('ok' => false, 'error' => $e->getMessage(), 'latency_ms' => $latency);
        }
        $latency = (int)round((microtime(true) - $start) * 1000);

        if (empty($res['ok'])) {
            $err = $res['error'] ?? 'Connection failed';
            $this->ci->Provider_model->record_health($provider->id, 'OFFLINE', $latency, $err);
            return array('ok' => false, 'error' => $err, 'latency_ms' => $latency);
        }

        $balance  = isset($res['balance']) ? (string)$res['balance'] : null;
        $currency = $res['currency'] ?? ($provider->currency ?? marvy_base_currency());

        $this->ci->db->trans_start();
        $this->ci->Provider_model->record_health($provider->id, 'ONLINE', $latency, null);
        if ($balance !== null) {
            $this->ci->Provider_model->update_provider($provider->id, array(
                'balance'  => number_format((float)$balance, 8, '.', ''),
                'currency' => $currency,
            ));
        }
        $this->ci->db->trans_complete();

        return array('ok' => true, 'balance' => $balance, 'currency' => $currency,
                     'latency_ms' => $latency);
    }

    /**
     * Connectivity + auth check for a virtual-number vendor.
     *
     * Identical contract to the other two families. Worth noting the currency:
     * a number vendor may bill in its own currency (5sim quotes roubles), and
     * the adapter says which — so the provider row records what it is really
     * denominated in rather than being relabelled to the panel's base.
     */
    private function test_number_connection($provider) {
        $start = microtime(true);
        try {
            $res = $this->number_adapter($provider)->balance();
        } catch (Exception $e) {
            $latency = (int)round((microtime(true) - $start) * 1000);
            $this->ci->Provider_model->record_health($provider->id, 'OFFLINE', $latency, $e->getMessage());
            return array('ok' => false, 'error' => $e->getMessage(), 'latency_ms' => $latency);
        }
        $latency = (int)round((microtime(true) - $start) * 1000);

        if (empty($res['ok'])) {
            $err = $res['error'] ?? 'Connection failed';
            $this->ci->Provider_model->record_health($provider->id, 'OFFLINE', $latency, $err);
            return array('ok' => false, 'error' => $err, 'latency_ms' => $latency);
        }

        $balance  = isset($res['balance']) ? (string)$res['balance'] : null;
        $currency = $res['currency'] ?? ($provider->currency ?? marvy_base_currency());

        $this->ci->db->trans_start();
        $this->ci->Provider_model->record_health($provider->id, 'ONLINE', $latency, null);
        if ($balance !== null) {
            $this->ci->Provider_model->update_provider($provider->id, array(
                'balance'  => number_format((float)$balance, 8, '.', ''),
                'currency' => $currency,
            ));
        }
        $this->ci->db->trans_complete();

        return array('ok' => true, 'balance' => $balance, 'currency' => $currency,
                     'latency_ms' => $latency);
    }

    /**
     * Connectivity check for an identity/KYC vendor (§22).
     *
     * Same shape as the number probe, and deliberately the *balance* call
     * rather than a sample lookup: a KYC vendor charges per query, so a test
     * button that ran a real NIN search would bill us every time an admin
     * clicked it — and would need somebody's actual NIN to click with.
     * Checking the wallet proves the key, the AppId and the network without
     * touching anybody's identity.
     */
    private function test_identity_connection($provider) {
        $start = microtime(true);
        try {
            $res = $this->identity_adapter($provider)->balance();
        } catch (Exception $e) {
            $latency = (int)round((microtime(true) - $start) * 1000);
            $this->ci->Provider_model->record_health($provider->id, 'OFFLINE', $latency, $e->getMessage());
            return array('ok' => false, 'error' => $e->getMessage(), 'latency_ms' => $latency);
        }
        $latency = (int)round((microtime(true) - $start) * 1000);

        if (empty($res['ok'])) {
            $err = $res['error'] ?? 'Connection failed';
            $this->ci->Provider_model->record_health($provider->id, 'OFFLINE', $latency, $err);
            return array('ok' => false, 'error' => $err, 'latency_ms' => $latency);
        }

        $balance  = isset($res['balance']) ? (string)$res['balance'] : null;
        $currency = $res['currency'] ?? ($provider->currency ?? marvy_base_currency());

        $this->ci->db->trans_start();
        $this->ci->Provider_model->record_health($provider->id, 'ONLINE', $latency, null);
        if ($balance !== null) {
            $this->ci->Provider_model->update_provider($provider->id, array(
                'balance'  => number_format((float)$balance, 8, '.', ''),
                'currency' => $currency,
            ));
        }
        $this->ci->db->trans_complete();

        return array('ok' => true, 'balance' => $balance, 'currency' => $currency,
                     'latency_ms' => $latency);
    }

    /**
     * Connectivity check for a gift card vendor (§23).
     *
     * The balance call again, and here it earns its keep twice over: it proves
     * the OAuth handshake (a gift card token is minted against its own
     * audience, so a working airtime credential still fails this), and the
     * float it returns is the number that decides whether the next order can
     * be filled at all. A vendor wallet at zero is the commonest cause of a
     * gift card outage, and it is invisible from our side until an order
     * bounces.
     */
    private function test_giftcard_connection($provider) {
        $start = microtime(true);
        try {
            $res = $this->giftcard_adapter($provider)->balance();
        } catch (Exception $e) {
            $latency = (int)round((microtime(true) - $start) * 1000);
            $this->ci->Provider_model->record_health($provider->id, 'OFFLINE', $latency, $e->getMessage());
            return array('ok' => false, 'error' => $e->getMessage(), 'latency_ms' => $latency);
        }
        $latency = (int)round((microtime(true) - $start) * 1000);

        if (empty($res['ok'])) {
            $err = $res['error'] ?? 'Connection failed';
            $this->ci->Provider_model->record_health($provider->id, 'OFFLINE', $latency, $err);
            return array('ok' => false, 'error' => $err, 'latency_ms' => $latency);
        }

        $balance  = isset($res['balance']) ? (string)$res['balance'] : null;
        $currency = $res['currency'] ?? ($provider->currency ?? marvy_base_currency());

        $this->ci->db->trans_start();
        $this->ci->Provider_model->record_health($provider->id, 'ONLINE', $latency, null);
        if ($balance !== null) {
            $this->ci->Provider_model->update_provider($provider->id, array(
                'balance'  => number_format((float)$balance, 8, '.', ''),
                'currency' => $currency,
            ));
        }
        $this->ci->db->trans_complete();

        return array('ok' => true, 'balance' => $balance, 'currency' => $currency,
                     'latency_ms' => $latency);
    }

    /**
     * Pull the provider's service list and upsert into provider_services.
     *
     * @return array{ok:bool,inserted?:int,updated?:int,total?:int,error?:string,latency_ms?:int}
     */
    public function sync_services($provider) {
        $family = self::family($provider);
        if ($family === Provider_manager::FAMILY_VTU) {
            return $this->sync_vtu_catalogue($provider);
        }
        if ($family === Provider_manager::FAMILY_NUMBER) {
            return $this->sync_number_catalogue($provider);
        }
        if ($family === Provider_manager::FAMILY_GIFTCARD) {
            return $this->sync_giftcard_catalogue($provider);
        }
        if ($family === Provider_manager::FAMILY_IDENTITY) {
            // Identity vendors publish no catalogue to mirror: there are a
            // handful of lookup types, they do not change, and what they cost
            // the customer is our pricing decision, not theirs. Say so plainly
            // instead of falling through to the SMM path, which would call
            // getServices() on an adapter that has no such method.
            return array(
                'ok'    => false,
                'error' => 'Identity vendors have no catalogue to sync. '
                          .'Lookup types and prices are managed in the identity product list.',
            );
        }
        $start = microtime(true);
        try {
            $res = $this->adapter($provider)->getServices();
        } catch (Exception $e) {
            $latency = (int)round((microtime(true) - $start) * 1000);
            $this->ci->Provider_model->record_sync($provider->id, 'services', 'FAILED', $e->getMessage(), 0, $latency);
            return array('ok' => false, 'error' => $e->getMessage(), 'latency_ms' => $latency);
        }

        if (empty($res['ok']) || !is_array($res['data'] ?? null)) {
            $err = $res['error'] ?? 'Malformed response from provider';
            $latency = (int)round((microtime(true) - $start) * 1000);
            $this->ci->Provider_model->record_sync($provider->id, 'services', 'FAILED', $err, 0, $latency);
            return array('ok' => false, 'error' => $err, 'latency_ms' => $latency);
        }

        $inserted = 0; $updated = 0;
        $this->ci->db->trans_start();
        foreach ($res['data'] as $row) {
            $normalized = $this->normalize_service($row);
            if (!$normalized) continue;
            $outcome = $this->ci->Provider_service_model->upsert_service($provider->id, $normalized);
            if ($outcome === 'inserted') $inserted++;
            elseif ($outcome === 'updated') $updated++;
        }
        $this->ci->Provider_model->record_sync(
            $provider->id, 'services', 'SUCCESS', null,
            $inserted + $updated,
            (int)round((microtime(true) - $start) * 1000)
        );
        $this->ci->db->trans_complete();

        return array(
            'ok' => true,
            'inserted' => $inserted,
            'updated' => $updated,
            'total' => $inserted + $updated,
            'latency_ms' => (int)round((microtime(true) - $start) * 1000),
        );
    }

    /**
     * Pull a VTU vendor's price list into vtu_products.
     *
     * provider_services is an SMM-panel shape (rate per 1000, min/max
     * quantity) and means nothing for a data bundle, so VTU syncs land in the
     * VTU catalogue instead. Two rules make this safe to run on a live panel:
     *
     *   - it never overwrites a price we set. The vendor's amount becomes
     *     provider_cost; our selling price is only filled in when the row does
     *     not have one yet. A sync must not be able to move the panel onto a
     *     losing margin.
     *   - a network the vendor does not carry is skipped, not failed. One
     *     unsupported disco should not abort the other twenty.
     *
     * @return array{ok:bool,inserted?:int,updated?:int,total?:int,skipped?:int,error?:string,latency_ms?:int}
     */
    private function sync_vtu_catalogue($provider) {
        $this->ci->load->model(array('Vtu_network_model', 'Vtu_product_model'));
        $start = microtime(true);

        try {
            $adapter = $this->vtu_adapter($provider);
        } catch (Exception $e) {
            $latency = (int)round((microtime(true) - $start) * 1000);
            $this->ci->Provider_model->record_sync($provider->id, 'services', 'FAILED', $e->getMessage(), 0, $latency);
            return array('ok' => false, 'error' => $e->getMessage(), 'latency_ms' => $latency);
        }
        if (!method_exists($adapter, 'variations')) {
            $latency = (int)round((microtime(true) - $start) * 1000);
            $err = 'This VTU adapter cannot list products.';
            $this->ci->Provider_model->record_sync($provider->id, 'services', 'FAILED', $err, 0, $latency);
            return array('ok' => false, 'error' => $err, 'latency_ms' => $latency);
        }

        $types = method_exists($adapter, 'catalogue_types')
            ? $adapter::catalogue_types() : array('DATA', 'CABLE', 'EXAM_PIN');

        $inserted = 0; $updated = 0; $skipped = 0; $errors = array();
        foreach ($types as $type) {
            foreach ($this->ci->Vtu_network_model->active($type) as $network) {
                try {
                    $res = $adapter->variations($network->code);
                } catch (Exception $e) {
                    $skipped++;
                    $errors[] = $network->code.': '.$e->getMessage();
                    continue;
                }
                if (empty($res['ok']) || empty($res['variations'])) {
                    $skipped++;
                    if (!empty($res['error'])) $errors[] = $network->code.': '.$res['error'];
                    continue;
                }
                foreach ($res['variations'] as $i => $v) {
                    $outcome = $this->ci->Vtu_product_model->upsert_from_provider(
                        $provider->id, (int)$network->id, $type, $v, $i);
                    if ($outcome === 'inserted') $inserted++;
                    elseif ($outcome === 'updated') $updated++;
                }
            }
        }

        $latency = (int)round((microtime(true) - $start) * 1000);
        // Nothing at all came back: that is a failed sync, not an empty one.
        if ($inserted + $updated === 0 && $errors) {
            $message = implode(' | ', array_slice($errors, 0, 5));
            $this->ci->Provider_model->record_sync($provider->id, 'services', 'FAILED', $message, 0, $latency);
            return array('ok' => false, 'error' => $message, 'latency_ms' => $latency);
        }

        $this->ci->Provider_model->record_sync(
            $provider->id, 'services', 'SUCCESS',
            $errors ? 'Skipped: '.implode(' | ', array_slice($errors, 0, 5)) : null,
            $inserted + $updated, $latency
        );

        return array(
            'ok'       => true,
            'inserted' => $inserted,
            'updated'  => $updated,
            'total'    => $inserted + $updated,
            'skipped'  => $skipped,
            'latency_ms' => $latency,
        );
    }

    /**
     * Pull a number vendor's availability and pricing into number_products.
     *
     * Same two rules as the VTU sync, for the same reasons:
     *
     *   - the vendor owns cost and stock, never `price`. A number vendor's
     *     stock moves minute to minute and its price moves with it; letting a
     *     sync write our selling price would re-price the panel every hour.
     *   - a (country, service) pair the panel does not carry is skipped, not
     *     created. 5sim sells hundreds of services; the catalogue is an
     *     operator decision.
     *
     * A vendor cost that could not be converted into the base currency is
     * recorded as NULL rather than as a rouble figure sitting in a naira
     * column — see FiveSimAdapter's rate_to_base note.
     */
    private function sync_number_catalogue($provider) {
        $this->ci->load->model(array('Number_country_model', 'Number_service_model', 'Number_product_model'));
        $start = microtime(true);

        try {
            $adapter = $this->number_adapter($provider);
        } catch (Exception $e) {
            $latency = (int)round((microtime(true) - $start) * 1000);
            $this->ci->Provider_model->record_sync($provider->id, 'services', 'FAILED', $e->getMessage(), 0, $latency);
            return array('ok' => false, 'error' => $e->getMessage(), 'latency_ms' => $latency);
        }

        // Index our services by code once, so an unknown vendor product is a
        // cheap skip rather than a query each time.
        $services = array();
        foreach ($this->ci->Number_service_model->all() as $s) {
            $services[strtoupper($s->code)] = $s;
        }

        // Ask the vendor once which countries it actually carries (current
        // protocol: GET /guest/countries). A panel country the vendor has
        // dropped is then a local skip instead of a doomed HTTP call per
        // country — and the sync record says so, instead of a row of
        // identical "bad country" errors. If the list itself cannot be
        // fetched, sync the old way; the per-country products() call still
        // guards itself.
        $vendor_countries = null;
        $vendor_country_codes = array();
        if (method_exists($adapter, 'countries')) {
            $list = $adapter->countries();
            if (!empty($list['ok']) && !empty($list['countries'])) {
                $vendor_countries = $list['countries'];
                $vendor_country_codes = $list['country_codes'] ?? array();
            }
        }

        $inserted = 0; $updated = 0; $skipped = 0; $errors = array();
        foreach ($this->ci->Number_country_model->active() as $country) {
            try {
                if ($vendor_countries !== null) {
                    $slug = strtolower($vendor_country_codes[strtoupper($country->code)] ?? $country->code);
                    if (!isset($vendor_countries[$slug])) {
                        $skipped++;
                        $errors[] = $country->code.': not carried by the vendor';
                        continue;
                    }
                }
                $res = $adapter->products($country->code);
            } catch (Exception $e) {
                $skipped++;
                $errors[] = $country->code.': '.$e->getMessage();
                continue;
            }
            if (empty($res['ok']) || empty($res['products'])) {
                $skipped++;
                if (!empty($res['error'])) $errors[] = $country->code.': '.$res['error'];
                continue;
            }
            foreach ($res['products'] as $i => $row) {
                $code = strtoupper((string)($row['service'] ?? ''));
                if ($code === '' || !isset($services[$code])) { $skipped++; continue; }

                $outcome = $this->ci->Number_product_model->upsert_from_provider(
                    $provider->id, (int)$country->id, (int)$services[$code]->id, $row, $i);
                if ($outcome === 'inserted') $inserted++;
                elseif ($outcome === 'updated') $updated++;
            }
        }

        $latency = (int)round((microtime(true) - $start) * 1000);
        if ($inserted === 0 && $updated === 0 && $errors) {
            $message = implode(' | ', array_slice($errors, 0, 5));
            $this->ci->Provider_model->record_sync($provider->id, 'services', 'FAILED', $message, 0, $latency);
            return array('ok' => false, 'error' => $message, 'latency_ms' => $latency);
        }

        $this->ci->Provider_model->record_sync(
            $provider->id, 'services', 'SUCCESS',
            $errors ? 'Skipped: '.implode(' | ', array_slice($errors, 0, 5)) : null,
            $inserted + $updated, $latency
        );

        return array(
            'ok'       => true,
            'inserted' => $inserted,
            'updated'  => $updated,
            'total'    => $inserted + $updated,
            'skipped'  => $skipped,
            'latency_ms' => $latency,
        );
    }

    /**
     * Pull a gift card vendor's catalogue into giftcard_brands/products (§23).
     *
     * The same rule as every other sync — the vendor owns cost, we own price —
     * with one addition that is specific to this domain: the catalogue is
     * *large*. Reloadly lists thousands of products across 140 countries, and
     * importing all of them would bury the operator's twenty real products in
     * a list nobody can price. So the sync is scoped to the countries the
     * panel has decided to sell (giftcard_countries in the config, defaulting
     * to the ones the seed ships), and anything outside that is never fetched.
     *
     * A country the vendor rejects is skipped, not failed: one unsupported
     * market must not abort the other five.
     *
     * @return array{ok:bool,inserted?:int,updated?:int,total?:int,skipped?:int,error?:string,latency_ms?:int}
     */
    private function sync_giftcard_catalogue($provider) {
        $this->ci->load->model(array('Giftcard_brand_model', 'Giftcard_product_model'));
        $start = microtime(true);

        try {
            $adapter = $this->giftcard_adapter($provider);
        } catch (Exception $e) {
            $latency = (int)round((microtime(true) - $start) * 1000);
            $this->ci->Provider_model->record_sync($provider->id, 'services', 'FAILED', $e->getMessage(), 0, $latency);
            return array('ok' => false, 'error' => $e->getMessage(), 'latency_ms' => $latency);
        }

        $inserted = 0; $updated = 0; $skipped = 0; $errors = array();
        // Brands are resolved once per name, not once per denomination: a
        // hundred Amazon products must not be a hundred brand upserts.
        $brands = array();

        foreach ($this->giftcard_countries() as $country) {
            try {
                $res = $adapter->products($country);
            } catch (Exception $e) {
                $skipped++;
                $errors[] = $country.': '.$e->getMessage();
                continue;
            }
            if (empty($res['ok']) || empty($res['products'])) {
                $skipped++;
                if (!empty($res['error'])) $errors[] = $country.': '.$res['error'];
                continue;
            }

            foreach ($res['products'] as $i => $row) {
                $name = trim((string)($row['brand_name'] ?? ''));
                if ($name === '') { $skipped++; continue; }

                if (!isset($brands[$name])) {
                    $brands[$name] = $this->ci->Giftcard_brand_model->upsert_from_provider($row, count($brands));
                }
                if (!$brands[$name]) { $skipped++; continue; }

                $outcome = $this->ci->Giftcard_product_model->upsert_from_provider(
                    $provider->id, $brands[$name], $row, $i);
                if ($outcome === 'inserted') $inserted++;
                elseif ($outcome === 'updated') $updated++;
                else $skipped++;
            }
        }

        $latency = (int)round((microtime(true) - $start) * 1000);
        if ($inserted === 0 && $updated === 0 && $errors) {
            $message = implode(' | ', array_slice($errors, 0, 5));
            $this->ci->Provider_model->record_sync($provider->id, 'services', 'FAILED', $message, 0, $latency);
            return array('ok' => false, 'error' => $message, 'latency_ms' => $latency);
        }

        $this->ci->Provider_model->record_sync(
            $provider->id, 'services', 'SUCCESS',
            $errors ? 'Skipped: '.implode(' | ', array_slice($errors, 0, 5)) : null,
            $inserted + $updated, $latency
        );

        return array(
            'ok'       => true,
            'inserted' => $inserted,
            'updated'  => $updated,
            'total'    => $inserted + $updated,
            'skipped'  => $skipped,
            'latency_ms' => $latency,
        );
    }

    /** Which markets the gift card sync imports, from config. */
    private function giftcard_countries() {
        $configured = $this->ci->config->item('giftcard_countries');
        if (is_string($configured) && trim($configured) !== '') {
            $configured = array_map('trim', explode(',', $configured));
        }
        if (!is_array($configured) || !$configured) return array('US');
        $out = array();
        foreach ($configured as $c) {
            $c = strtoupper(substr(trim((string)$c), 0, 2));
            if ($c !== '' && !in_array($c, $out, true)) $out[] = $c;
        }
        return $out ?: array('US');
    }

    /**
     * Pull the current provider balance and update the row.
     */
    public function sync_balance($provider) {
        $res = $this->test_connection($provider);
        if (!$res['ok']) return $res;
        return array(
            'ok' => true,
            'balance' => $res['balance'],
            'currency' => $res['currency'],
            'latency_ms' => $res['latency_ms'],
        );
    }

    /**
     * Normalize a provider service row into our canonical shape.
     * Tolerates the common SMM panel fields (service/service/ID, name, rate,
     * min/max, type, category) and ignores unknown columns.
     */
    public function normalize_service($row) {
        if (!is_array($row)) return null;
        // Panels differ in both key spelling and casing ('ID', 'Service',
        // 'minimum'), so match case-insensitively across the known aliases.
        $lower = array();
        foreach ($row as $k => $v) $lower[strtolower((string)$k)] = $v;
        $pick = function(array $keys) use ($lower) {
            foreach ($keys as $k) {
                if (isset($lower[$k]) && $lower[$k] !== '') return $lower[$k];
            }
            return null;
        };

        $id   = $pick(array('service', 'service_id', 'serviceid', 'id'));
        $name = $pick(array('name', 'title', 'service_name'));
        $rate = $pick(array('rate', 'cost', 'price', 'rate_per_1000'));
        if ($id === null || $name === null || $rate === null) return null;
        if (!is_numeric($rate) || $rate < 0) return null;

        return array(
            'provider_service_id' => (string)$id,
            'name'               => mb_substr((string)$name, 0, 255),
            'category'           => $pick(array('category', 'category_name')),
            'rate'               => number_format((float)$rate, 8, '.', ''),
            'min_quantity'       => (int)($pick(array('min', 'minimum', 'min_order')) ?? 1),
            'max_quantity'       => (int)($pick(array('max', 'maximum', 'max_order')) ?? 0),
            'service_type'       => $this->map_type($pick(array('type', 'service_type')) ?? 'DEFAULT'),
            'cancel'             => $this->flag($row, 'cancel'),
            'refill'             => $this->flag($row, 'refill'),
            'dripfeed'           => $this->flag($row, 'dripfeed'),
            'raw'                => $row,
        );
    }

    private function map_type($raw) {
        $t = strtoupper(str_replace(array('-', ' '), '_', (string)$raw));
        $allowed = array('DEFAULT','CUSTOM_COMMENTS','CUSTOM','PACKAGE','SUBSCRIPTION',
                         'MENTIONS_USER_FOLLOWERS','MENTIONS_HASHTAG','MENTIONS',
                         'COMMENT_LIKES','POLL_VOTES');
        // Common provider spellings that mean one of the canonical types.
        $aliases = array(
            'SUBSCRIPTIONS'          => 'SUBSCRIPTION',
            'DRIP_FEED'              => 'DEFAULT',
            'COMMENTS'               => 'CUSTOM_COMMENTS',
            'CUSTOM_COMMENT'         => 'CUSTOM_COMMENTS',
            'CUSTOM_COMMENTS_PACKAGE'=> 'CUSTOM_COMMENTS',
            'MENTIONS_CUSTOM_LIST'   => 'MENTIONS',
            'MENTIONS_USER_FOLLOWER' => 'MENTIONS_USER_FOLLOWERS',
            'MENTIONS_HASHTAGS'      => 'MENTIONS_HASHTAG',
            'POLL_VOTE'              => 'POLL_VOTES',
            'COMMENT_LIKE'           => 'COMMENT_LIKES',
            'PACKAGES'               => 'PACKAGE',
        );
        if (isset($aliases[$t])) $t = $aliases[$t];
        return in_array($t, $allowed, true) ? $t : 'DEFAULT';
    }

    private function flag($row, $key) {
        foreach (array($key, $key.'_supported') as $k) {
            if (isset($row[$k])) {
                $v = $row[$k];
                if (is_bool($v)) return $v;
                return in_array(strtolower((string)$v), array('1','true','yes','on'), true);
            }
        }
        return false;
    }

    /**
     * Create a provider from form input, encrypting the API key at rest.
     *
     * @return array{ok:bool,provider?:object,error?:string,errors?:array}
     */
    public function create_provider(array $input) {
        $errors = $this->validate($input);
        if ($errors) return array('ok' => false, 'errors' => $errors);

        $data = array(
            'public_id'         => marvy_public_id(),
            'name'              => trim($input['name']),
            'api_url'           => rtrim(trim($input['api_url']), '/'),
            'api_key_encrypted' => $this->ci->encryptionservice->encrypt($this->credential_payload($input)),
            'api_type'          => strtoupper($input['api_type'] ?? 'STANDARD_SMM'),
            'status'            => $input['status'] ?? 'ACTIVE',
            'currency'          => $input['currency'] ?? marvy_base_currency(),
            'timeout_ms'        => (int)($input['timeout_ms'] ?? 15000),
            'sync_interval_minutes' => max(1, (int)($input['sync_interval_minutes'] ?? 60)),
            // The console asks for a percentage (0–200); a raw multiplier is
            // still accepted for API/CLI callers that already speak it.
            'rate_multiplier'   => isset($input['markup_percent']) && $input['markup_percent'] !== ''
                ? number_format(1 + (min(self::MAX_MARKUP_PERCENT, max(0, (float)$input['markup_percent'])) / 100), 8, '.', '')
                : number_format((float)($input['rate_multiplier'] ?? 1.0), 8, '.', ''),
            'markup'            => number_format((float)($input['markup'] ?? 0.0), 8, '.', ''),
            'notes'             => $input['notes'] ?? null,
            'created_at'        => gmdate('Y-m-d H:i:s'),
        );
        $provider = $this->ci->Provider_model->create($data);
        $this->ci->load->model('Audit_log_model');
        // request_id is protected on MY_Controller: property_exists() reports
        // TRUE but reading it from here raises an Error. Use the accessor.
        $this->ci->Audit_log_model->record(
            (isset($this->ci->authservice) && $this->ci->authservice) ? $this->ci->authservice->id() : null,
            'provider.create', 'providers', $provider->public_id,
            null, array('name'=>$provider->name,'api_url'=>$provider->api_url),
            $this->ci->input->ip_address(), $this->ci->input->user_agent(),
            method_exists($this->ci, 'request_id') ? $this->ci->request_id() : null);
        return array('ok' => true, 'provider' => $provider);
    }

    /**
     * Percentage markup, the way an operator actually thinks about it.
     *
     * A provider row prices with `rate = vendor_rate * rate_multiplier + markup`.
     * Admins do not think in multipliers, they think "sell this vendor's stock
     * at 20% over cost", so the console offers 0–200% and this converts it:
     * 20% -> 1.20000000. Zero percent is legitimate (resell at cost).
     *
     * @param float $percent   0–200, the increase over the vendor's own rate
     * @param float $flat      optional flat amount added after the percentage
     * @param bool  $reprice   also re-price the services already mirrored from
     *                         this provider that opted into auto price sync
     */
    public function set_pricing_rule($provider, $percent, $flat = 0.0, $reprice = false) {
        if (!is_numeric($percent) || (float)$percent < 0 || (float)$percent > self::MAX_MARKUP_PERCENT) {
            return array('ok' => false, 'error' => 'Choose a markup between 0% and '.self::MAX_MARKUP_PERCENT.'%.');
        }
        if (!is_numeric($flat) || (float)$flat < 0) {
            return array('ok' => false, 'error' => 'The flat amount cannot be negative.');
        }

        $multiplier = number_format(1 + ((float)$percent / 100), 8, '.', '');
        $flat_value = number_format((float)$flat, 8, '.', '');
        $before = array(
            'rate_multiplier' => (string)$provider->rate_multiplier,
            'markup'          => (string)$provider->markup,
        );
        $after = array('rate_multiplier' => $multiplier, 'markup' => $flat_value);

        $this->ci->db->where('id', (int)$provider->id)->update('providers', array_merge($after, array(
            'updated_at' => gmdate('Y-m-d H:i:s'),
        )));

        $repriced = 0;
        if ($reprice) {
            $repriced = $this->reprice_auto_synced_services($provider->id, $multiplier, $flat_value);
        }

        $this->ci->load->model('Audit_log_model');
        $this->ci->Audit_log_model->record(
            (isset($this->ci->authservice) && $this->ci->authservice) ? $this->ci->authservice->id() : null,
            'provider.pricing_rule', 'providers', $provider->public_id,
            $before, array_merge($after, array('percent' => (float)$percent, 'repriced' => $repriced)),
            $this->ci->input->ip_address(), $this->ci->input->user_agent(),
            method_exists($this->ci, 'request_id') ? $this->ci->request_id() : null);

        return array('ok' => true, 'percent' => (float)$percent, 'multiplier' => $multiplier,
            'flat' => $flat_value, 'repriced' => $repriced, 'error' => null);
    }

    /**
     * Apply a new pricing rule to services already mirrored from a provider.
     *
     * Only rows that opted into `auto_price_sync` are touched: a rate an admin
     * typed by hand is never overwritten by a provider-level rule. The vendor
     * cost used is `services.provider_rate`, the last cost the sync recorded —
     * so this never invents a price out of a stale selling rate.
     */
    private function reprice_auto_synced_services($provider_id, $multiplier, $flat) {
        $rows = $this->ci->db->select('id, provider_rate', false)
            ->where('provider_id', (int)$provider_id)
            ->where('auto_price_sync', 1)
            ->where('provider_rate IS NOT NULL', null, false)
            ->get('services')->result();

        $count = 0;
        foreach ($rows as $row) {
            $rate = bcadd(bcmul((string)$row->provider_rate, (string)$multiplier, 8), (string)$flat, 8);
            if (!preg_match('/^(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{8})$/', $rate) || bccomp($rate, '0', 8) <= 0) {
                continue;
            }
            $this->ci->db->where('id', (int)$row->id)->update('services', array(
                'rate' => $rate,
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ));
            $count++;
        }
        return $count;
    }

    /** The percentage a provider's current multiplier represents. */
    public static function markup_percent($provider) {
        $multiplier = (float)(isset($provider->rate_multiplier) ? $provider->rate_multiplier : 1);
        return round(max(0, ($multiplier - 1) * 100), 2);
    }

    /**
     * Delete a provider and everything that only exists because of it.
     *
     * Until now a provider row was immortal: once added, the only way out was
     * SQL, because the schema (deliberately) has no ON DELETE path wired to a
     * screen. This is the screen half.
     *
     * What goes, what stays — spelled out because both halves are surprising:
     *
     *   - **Gone:** the provider row, its synced catalogue (provider_services),
     *     its sync/health logs, its provider_orders mapping rows and its
     *     provider_transactions ledger. These are mirrors of the upstream
     *     account and mean nothing without it.
     *   - **Kept, unlinked:** panel services sourced from the provider (they
     *     keep selling at their current rate, with auto-price-sync off since
     *     there is nothing to sync from), and past orders (history is history;
     *     they lose their provider link, exactly what the schema's
     *     ON DELETE SET NULL always said would happen).
     *
     * The explicit child deletes and NULL-outs below match the schema's FK
     * actions one-for-one. On MySQL they are redundant with the cascades that
     * fire on the final DELETE; doing them explicitly means the same routine
     * is also correct on engines without enforced foreign keys (the dev
     * harness), and the counts it reports are counts of rows it actually
     * handled.
     *
     * @return array{ok:bool, error?:string, counts?:array}
     */
    public function delete_provider($provider) {
        if (!$provider || empty($provider->id)) {
            return array('ok' => false, 'error' => 'Unknown provider.');
        }
        $id = (int)$provider->id;

        $counts = array(
            'synced_services' => (int)$this->ci->db->where('provider_id', $id)->count_all_results('provider_services'),
            'panel_services'  => (int)$this->ci->db->where('provider_id', $id)->count_all_results('services'),
            'orders'          => (int)$this->ci->db->where('provider_id', $id)->count_all_results('orders'),
        );

        $this->ci->db->trans_start();

        // Mirrors of the upstream account — deleted, like the cascades say.
        foreach (array('provider_services', 'provider_sync_logs', 'provider_health_logs',
                       'provider_orders', 'provider_transactions') as $table) {
            if (!$this->ci->db->table_exists($table)) continue;
            $this->ci->db->where('provider_id', $id)->delete($table);
        }

        // History and sellable catalogue — kept, link removed. A panel service
        // that followed the provider's pricing stops following (there is
        // nothing left to follow); its rate is untouched.
        $unlink = array('orders', 'refills', 'cancellation_requests',
                        'subscriptions', 'service_transactions', 'vtu_products',
                        'number_products', 'identity_products', 'giftcard_products');
        foreach ($unlink as $table) {
            if (!$this->ci->db->table_exists($table)) continue;
            $this->ci->db->where('provider_id', $id)->update($table, array('provider_id' => null));
        }
        $this->ci->db->where('provider_id', $id)->update('services', array(
            'provider_id'     => null,
            'auto_price_sync' => 0,
        ));

        $this->ci->db->where('id', $id)->delete('providers');

        $this->ci->db->trans_complete();
        if ($this->ci->db->trans_status() === false) {
            return array('ok' => false, 'error' => 'The delete failed and was rolled back.');
        }

        return array('ok' => true, 'counts' => $counts, 'error' => null);
    }

    /**
     * What actually gets encrypted into providers.api_key_encrypted.
     *
     * Most vendors issue one key. VTpass issues three, split by method
     * (api-key always, public-key for GET, secret-key for POST), so those are
     * stored as a JSON blob in the same column rather than adding two more
     * secret columns for one integration. VtpassAdapter reads either shape.
     */
    private function credential_payload(array $input) {
        $type = strtoupper($input['api_type'] ?? '');
        if ($type === 'VTPASS') {
            return json_encode(array(
                'api_key'    => trim((string)$input['api_key']),
                'public_key' => trim((string)($input['public_key'] ?? '')),
                'secret_key' => trim((string)($input['secret_key'] ?? '')),
            ));
        }
        // Dojah signs every call with a secret key *and* an AppId; the AppId
        // is not really secret, but keeping the pair together in one blob
        // means a key rotation swaps one column and cannot leave the two
        // halves out of step.
        if ($type === 'DOJAH') {
            return json_encode(array(
                'api_key' => trim((string)$input['api_key']),
                'app_id'  => trim((string)($input['app_id'] ?? '')),
            ));
        }
        // Reloadly is OAuth2: the pair is exchanged for a bearer token rather
        // than sent on each call. Both halves are secret and useless apart, so
        // they travel in one blob for the same reason Dojah's do. The create
        // form reuses the api_key field for the client id, which keeps one
        // form working for every vendor — see validate().
        if ($type === 'RELOADLY') {
            return json_encode(array(
                'client_id'     => trim((string)$input['api_key']),
                'client_secret' => trim((string)($input['client_secret'] ?? '')),
            ));
        }
        return $input['api_key'];
    }

    /** Reloadly cannot mint a token without both halves of the pair. */
    private function reloadly_credential_errors(array $input) {
        $errors = array();
        if (trim((string)($input['client_secret'] ?? '')) === '') {
            $errors[] = 'Reloadly needs the client secret from your dashboard alongside the client id.';
        }
        return $errors;
    }

    /** Dojah returns 401 for every call if either half of the pair is missing. */
    private function dojah_credential_errors(array $input) {
        $errors = array();
        if (trim((string)($input['app_id'] ?? '')) === '') {
            $errors[] = 'Dojah needs the AppId from your dashboard alongside the secret key.';
        }
        return $errors;
    }

    /** VTpass is unusable without all three keys, so refuse to store a half-set. */
    private function vtpass_credential_errors(array $input) {
        $errors = array();
        $public = trim((string)($input['public_key'] ?? ''));
        $secret = trim((string)($input['secret_key'] ?? ''));
        if ($public === '') $errors[] = 'VTpass needs a public key (PK_...) for lookups.';
        elseif (stripos($public, 'PK_') !== 0) $errors[] = 'The VTpass public key should start with PK_.';
        if ($secret === '') $errors[] = 'VTpass needs a secret key (SK_...) for purchases.';
        elseif (stripos($secret, 'SK_') !== 0) $errors[] = 'The VTpass secret key should start with SK_.';
        return $errors;
    }

    private function validate(array $input) {
        $errors = array();
        if (empty($input['name']) || mb_strlen($input['name']) < 2) $errors[] = 'Name is required.';
        if (empty($input['api_url']) || !filter_var($input['api_url'], FILTER_VALIDATE_URL)) $errors[] = 'A valid API URL is required.';
        if (empty($input['api_key'])) $errors[] = 'API key is required.';
        // The whitelist is the registry, not a second hardcoded list: adding
        // an adapter must not require remembering to edit this line.
        if (isset($input['api_type'])
            && !in_array(strtoupper($input['api_type']), self::supported_types(), true)) {
            $errors[] = 'API type must be one of: '.implode(', ', self::supported_types()).'.';
        }
        if (isset($input['api_type']) && strtoupper($input['api_type']) === 'VTPASS') {
            foreach ($this->vtpass_credential_errors($input) as $e) $errors[] = $e;
        }
        if (isset($input['api_type']) && strtoupper($input['api_type']) === 'DOJAH') {
            foreach ($this->dojah_credential_errors($input) as $e) $errors[] = $e;
        }
        if (isset($input['api_type']) && strtoupper($input['api_type']) === 'RELOADLY') {
            foreach ($this->reloadly_credential_errors($input) as $e) $errors[] = $e;
        }
        if (isset($input['api_type']) && strtoupper($input['api_type']) === 'FIVESIM') {
            foreach ($this->fivesim_url_errors($input) as $e) $errors[] = $e;
        }
        if (isset($input['timeout_ms']) && ((int)$input['timeout_ms'] < 1000 || (int)$input['timeout_ms'] > 60000)) {
            $errors[] = 'Timeout must be between 1000 and 60000 ms.';
        }
        if (isset($input['rate_multiplier']) && $input['rate_multiplier'] !== '' && (float)$input['rate_multiplier'] <= 0) {
            $errors[] = 'Rate multiplier must be greater than zero.';
        }
        if (isset($input['markup']) && (float)$input['markup'] < 0) {
            $errors[] = 'Markup cannot be negative.';
        }
        return $errors;
    }

    /**
     * A 5sim URL must be on the current protocol before it is stored.
     *
     * This is the same check the adapter enforces at call time, run at save
     * time so a deprecated `handler_api.php` URL (or a docs example like
     * `/v1/user/profile`) is refused with a form error instead of surfacing
     * on the first customer purchase. The constructor's refusal message is
     * reused verbatim: it is written for operators, not for logs.
     */
    private function fivesim_url_errors(array $input) {
        if (empty($input['api_url'])) {
            return array('The 5sim API URL is required — use https://5sim.net/v1 '
                .'(the current 5sim protocol).');
        }
        // The adapter is loaded lazily by Provider_manager; reference it
        // explicitly so a plain provider save never trips over the class.
        if (!class_exists('FiveSimAdapter')) {
            require_once __DIR__.'/FiveSimAdapter.php';
        }
        try {
            FiveSimAdapter::current_protocol_base($input['api_url']);
        } catch (Exception $e) {
            return array($e->getMessage());
        }
        return array();
    }

    /**
     * Rotate a provider's credentials (API URL and key).
     *
     * The key is encrypted at rest exactly like a create. The URL is pinned
     * to the current protocol for the 5sim family before anything is written,
     * so a rotation can never quietly re-point the panel at the deprecated
     * API. Runs the same live probe as the "Test connection" button, so the
     * operator knows immediately whether the new key authenticates — but the
     * save succeeds either way, because a panel that refuses to store a key
     * it cannot verify yet (vendor outage) strands the operator mid-rotation.
     *
     * @return array{ok:bool,provider?:object,probe?:array,error?:string,errors?:array}
     */
    public function update_credentials($provider, array $input) {
        $input['name'] = $provider->name;
        $input['api_type'] = $provider->api_type;
        // An empty key field means "keep the stored key" (URL-only change).
        // The stored key is never echoed back, so rotation still requires
        // pasting the new one.
        $rotate_key = !empty($input['api_key']);
        if ($rotate_key) {
            $errors = $this->validate($input);
        } else {
            unset($input['api_key']);
            $errors = $this->validate($input);
            $errors = array_values(array_filter($errors, function ($e) {
                return stripos($e, 'API key') === false;
            }));
        }
        if ($errors) return array('ok' => false, 'errors' => $errors);

        $before = array('api_url' => $provider->api_url);

        $data = array(
            'api_url'    => rtrim(trim($input['api_url']), '/'),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        );
        if ($rotate_key) {
            $data['api_key_encrypted'] = $this->ci->encryptionservice->encrypt($this->credential_payload($input));
        }

        $this->ci->load->model('Audit_log_model');
        $this->ci->Provider_model->update_provider($provider->id, $data);
        $provider = $this->ci->Provider_model->find_by_id($provider->id);

        $this->ci->Audit_log_model->record(
            (isset($this->ci->authservice) && $this->ci->authservice) ? $this->ci->authservice->id() : null,
            'provider.credentials', 'providers', $provider->public_id,
            $before,
            array('api_url' => $provider->api_url, 'key_rotated' => $rotate_key), // the key itself is never recorded
            $this->ci->input->ip_address(), $this->ci->input->user_agent(),
            method_exists($this->ci, 'request_id') ? $this->ci->request_id() : null);

        $probe = $this->test_connection($provider);

        return array('ok' => true, 'provider' => $provider, 'probe' => $probe);
    }
}
