<?php
defined('BASEPATH') OR exit('No direct script access allowed');

require_once __DIR__.'/Provider_manager.php';

/**
 * SmmServiceAdminService — the write boundary for the SMM service catalogue.
 *
 * Provider sync writes an upstream mirror to provider_services. This service
 * turns one of those rows (or a manual definition) into a customer-facing
 * service without trusting browser-supplied provider rates or snapshots.
 * Provider source snapshots are evidence: edits may replace the panel-facing
 * fields, but only a real provider row may create a new snapshot.
 */
class SmmServiceAdminService {

    const MAX_DESCRIPTION = 5000;
    const MAX_METADATA = 16384;

    private $ci;

    public function __construct() {
        $this->ci =& get_instance();
        $this->ci->load->model(array(
            'Service_model', 'Service_category_model', 'Provider_model',
            'Provider_service_model', 'Service_price_model',
            'User_service_price_model', 'User_model',
        ));
    }

    public static function types() {
        return array(
            'DEFAULT', 'CUSTOM_COMMENTS', 'CUSTOM', 'PACKAGE', 'SUBSCRIPTION',
            'MENTIONS_USER_FOLLOWERS', 'MENTIONS_HASHTAG', 'MENTIONS',
            'COMMENT_LIKES', 'POLL_VOTES',
        );
    }

    public static function statuses() {
        return array('ACTIVE', 'INACTIVE', 'ARCHIVED');
    }

    /** A bounded admin grid and matching count. */
    public function grid(array $filters, $limit, $offset) {
        $limit = max(1, min(100, (int)$limit));
        $offset = max(0, (int)$offset);
        return array(
            'rows' => $this->ci->Service_model->admin_search($filters, $limit, $offset),
            'total' => $this->ci->Service_model->admin_count($filters),
        );
    }

    public function find($public_id) {
        return $this->ci->Service_model->find_by_public_id((string)$public_id);
    }

    /** Bounded form choices. SMM providers only; other product families do not belong here. */
    public function options($selected = null) {
        $categories = $this->ci->Service_category_model->for_picker(200);
        $providers = $this->ci->Provider_model->smm_for_picker(200);

        // A bounded picker must not silently lose an existing choice that sorts
        // beyond its first page. Fetch only the selected row, with a safe
        // projection, and append it when necessary.
        if ($selected && !empty($selected->category_id)
                && !$this->contains_id($categories, $selected->category_id)) {
            $category = $this->ci->Service_category_model->picker_by_id($selected->category_id);
            if ($category) $categories[] = $category;
        }
        if ($selected && !empty($selected->provider_id)
                && !$this->contains_id($providers, $selected->provider_id)) {
            $provider = $this->ci->Provider_model->smm_picker_by_id($selected->provider_id);
            if ($provider) $providers[] = $provider;
        }

        return array(
            'categories' => $categories,
            'providers' => $providers,
            // The synced catalogue of the linked provider, so "Upstream
            // service" is a picker rather than a field that must be typed at.
            'provider_services' => $selected && !empty($selected->provider_id)
                ? $this->provider_service_options(
                    $selected->provider_public_id
                        ?: $this->provider_public_id_by_row($selected))['services']
                : array(),
            'price_groups' => $this->ci->db->order_by('name', 'ASC')
                ->limit(100)->get('price_groups')->result(),
            'types' => self::types(),
            'statuses' => self::statuses(),
        );
    }

    /** The public id of the provider a draft/service row is linked to. */
    private function provider_public_id_by_row($selected) {
        $provider = $this->ci->Provider_model->smm_picker_by_id((int)$selected->provider_id);
        return $provider ? $provider->public_id : '';
    }

    /**
     * Prefill a create form from a synced provider row. This is read-only: the
     * admin still chooses a category, selling rate and status before storing it.
     */
    public function draft_from_provider($provider_public_id, $provider_service_id) {
        $link = $this->provider_link($provider_public_id, $provider_service_id);
        if (empty($link['ok'])) return $link;

        $p = $link['provider'];
        $s = $link['source'];
        // When the panel already has a category whose slug matches the
        // provider's own category, preselect it: "add provider, create
        // services" then needs no detour through Admin → Categories.
        $category_id = null;
        $provider_category = trim((string)($s->category ?? ''));
        if ($provider_category !== '') {
            $match = $this->ci->Service_category_model->find_by_slug($this->slug($provider_category));
            if ($match) $category_id = (int)$match->id;
        }
        return array('ok' => true, 'draft' => (object)array(
            'id' => null,
            'public_id' => null,
            'name' => $s->name,
            'slug' => $this->slug($s->name.'-'.$s->provider_service_id),
            'category_id' => $category_id,
            'provider_category' => $provider_category !== '' ? $provider_category : null,
            'description' => null,
            'service_type' => $s->service_type,
            'rate' => $this->selling_rate($p, $s->rate),
            'min_quantity' => (int)$s->min_quantity,
            'max_quantity' => (int)$s->max_quantity,
            'increment_step' => 1,
            'average_time' => null,
            'average_time_minutes' => null,
            'provider_id' => $p->id,
            'provider_public_id' => $p->public_id,
            'provider_service_id' => $s->provider_service_id,
            'provider_rate' => $s->rate,
            'status' => 'INACTIVE',
            'cancel_supported' => (int)$s->cancel_supported,
            'refill_supported' => (int)$s->refill_supported,
            'refill_days' => null,
            'dripfeed_supported' => (int)$s->dripfeed_supported,
            'subscription_supported' => $s->service_type === 'SUBSCRIPTION' ? 1 : 0,
            'package_supported' => $s->service_type === 'PACKAGE' ? 1 : 0,
            'custom_comments_supported' => strpos($s->service_type, 'CUSTOM') === 0 ? 1 : 0,
            'sorting' => 0,
            'featured' => 0,
            'trending' => 0,
            'auto_price_sync' => 0,
            'metadata' => null,
        ));
    }

    /**
     * The value of the "create a category from the provider" option in the
     * service form. A constant rather than a magic string so the form, the
     * validator and the tests can never disagree about it.
     */
    const PROVIDER_CATEGORY_OPTION = '__provider_category__';

    /**
     * Bounded list of a provider's synced services for the form's picker.
     *
     * A provider can carry thousands of rows; the picker is capped and says so
     * in its label, so "choose a service" never quietly loses the tail of the
     * catalogue — the raw ID field remains for anything beyond the cap.
     */
    public function provider_service_options($provider_public_id, $limit = 1000) {
        $provider = $this->ci->Provider_model->find_by_public_id(trim((string)$provider_public_id));
        if (!$provider || !in_array(strtoupper((string)$provider->api_type),
                Provider_manager::supported_types(Provider_manager::FAMILY_SMM), true)) {
            return array('ok' => false, 'error' => 'Choose a valid SMM provider.', 'services' => array());
        }
        $limit = max(1, min(2000, (int)$limit));
        $rows = $this->ci->db
            ->select('provider_service_id, name, category, rate, min_quantity, max_quantity', false)
            ->where('provider_id', (int)$provider->id)
            ->order_by('provider_service_id', 'ASC')
            ->limit($limit + 1)
            ->get('provider_services')->result();
        $truncated = count($rows) > $limit;
        if ($truncated) array_pop($rows);
        $services = array();
        foreach ($rows as $r) {
            $services[] = array(
                'id' => (string)$r->provider_service_id,
                'name' => (string)$r->name,
                'category' => (string)($r->category ?? ''),
                'rate' => (string)$r->rate,
                'min' => (int)$r->min_quantity,
                'max' => (int)$r->max_quantity,
            );
        }
        return array('ok' => true, 'services' => $services, 'truncated' => $truncated,
            'error' => null);
    }

    /**
     * Import every synced service of a provider as a panel service in one go.
     *
     * This is the missing half of "add provider → sell its services": a sync
     * mirrors the upstream catalogue into provider_services, and until now the
     * only bridge to the customer-facing catalogue was one hand-built service
     * at a time. The import reuses exactly the mapping the single create form
     * uses (trusted provider rate, provider pricing rule, capability flags),
     * so there is no second, weaker definition of "a panel service" to drift.
     *
     * Options (all from the operator, none guessed):
     *   status           ACTIVE (customers can order immediately) or INACTIVE
     *   create_categories  create panel categories named after the provider's
     *   auto_price_sync    follow the provider's pricing rule on every sync
     *
     * Idempotent by construction: a provider service already linked to a panel
     * service is skipped, never duplicated, so re-running after a sync only
     * ever adds what is new.
     */
    public function import_provider_services($provider, array $input) {
        if (!$provider || !in_array(strtoupper((string)$provider->api_type),
                Provider_manager::supported_types(Provider_manager::FAMILY_SMM), true)) {
            return $this->err('INVALID_PROVIDER', 'Choose a valid SMM provider.');
        }

        $status = strtoupper($this->scalar($input, 'status', 'INACTIVE'));
        if (!in_array($status, self::statuses(), true) || $status === 'ARCHIVED') {
            return $this->err('INVALID_STATUS', 'Choose ACTIVE or INACTIVE for the imported services.');
        }
        $create_categories = $this->checked($input, 'create_categories');
        $auto = $this->checked($input, 'auto_price_sync');

        $sources = $this->ci->Provider_service_model->for_provider((int)$provider->id);
        if (!$sources) {
            return $this->err('NOTHING_SYNCED',
                'This provider has no synced services yet. Run "Sync services" first.');
        }

        // Already-linked provider services: the bridge exists, skip them.
        $linked = array();
        foreach ($this->ci->db
                ->select('provider_service_id', false)
                ->where('provider_id', (int)$provider->id)
                ->where('provider_service_id IS NOT NULL', null, false)
                ->get('services')->result() as $row) {
            $linked[(string)$row->provider_service_id] = true;
        }

        $summary = array(
            'ok' => true, 'error' => null, 'code' => null, 'warnings' => array(),
            'created' => 0, 'skipped_linked' => 0, 'skipped_category' => 0,
            'skipped_rate' => 0, 'limits_adjusted' => 0,
            'categories_created' => 0, 'category_ids' => array(),
        );

        $this->ci->db->trans_start();
        foreach ($sources as $s) {
            if (isset($linked[(string)$s->provider_service_id])) {
                $summary['skipped_linked']++;
                continue;
            }

            // A selling rate the schema cannot hold (or that rounds to zero)
            // is a provider-pricing problem, not something to import blind.
            $rate = $this->decimal($this->selling_rate($provider, $s->rate));
            if ($rate === null || bccomp($rate, '0', 8) <= 0) {
                $summary['skipped_rate']++;
                continue;
            }

            $category = $this->category_for_source($s, $create_categories, $summary);
            if (!$category) {
                $summary['skipped_category']++;
                continue;
            }

            $min = max(1, (int)$s->min_quantity);
            $max = max($min, (int)$s->max_quantity);
            if ($min !== (int)$s->min_quantity || $max !== (int)$s->max_quantity) {
                $summary['limits_adjusted']++;
            }

            $slug = $this->unique_slug($this->slug($s->name.'-'.$s->provider_service_id));
            if ($slug === '') { $summary['skipped_rate']++; continue; }

            $this->ci->db->insert('services', array(
                'public_id' => marvy_public_id(),
                'name' => mb_substr((string)$s->name, 0, 255),
                'slug' => $slug,
                'category_id' => (int)$category->id,
                'description' => null,
                'service_type' => in_array((string)$s->service_type, self::types(), true)
                    ? (string)$s->service_type : 'DEFAULT',
                'rate' => $rate,
                'min_quantity' => $min,
                'max_quantity' => $max,
                'increment_step' => 1,
                'average_time' => null,
                'average_time_minutes' => null,
                'provider_id' => (int)$provider->id,
                'provider_service_id' => (string)$s->provider_service_id,
                'provider_rate' => (string)$s->rate,
                'status' => $status,
                'cancel_supported' => (int)$s->cancel_supported,
                'refill_supported' => (int)$s->refill_supported,
                'refill_days' => null,
                'dripfeed_supported' => (int)$s->dripfeed_supported,
                'subscription_supported' => $s->service_type === 'SUBSCRIPTION' ? 1 : 0,
                'package_supported' => $s->service_type === 'PACKAGE' ? 1 : 0,
                'custom_comments_supported' => strpos((string)$s->service_type, 'CUSTOM') === 0 ? 1 : 0,
                'sorting' => 0,
                'featured' => 0,
                'trending' => 0,
                'auto_price_sync' => $auto ? 1 : 0,
                'metadata' => null,
                'provider_source_snapshot' => $this->snapshot($s),
                'created_at' => gmdate('Y-m-d H:i:s'),
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ));
            $summary['created']++;
        }
        $this->ci->db->trans_complete();
        if (!$this->ci->db->trans_status()) {
            return $this->err('SAVE_FAILED', 'The import could not be saved.');
        }

        if ($summary['created'] > 0 && strtoupper((string)$provider->status) !== 'ACTIVE') {
            $summary['warnings'][] = 'The provider itself is not ACTIVE — new orders may stay pending until it is enabled.';
        }
        if ($status === 'ACTIVE') {
            $summary['warnings'][] = $summary['created'] > 0
                ? $summary['created'].' services are live: customers can see and order them now.'
                : 'Nothing new to import — already-imported services keep their current status.';
        }
        if ($summary['skipped_rate'] > 0) {
            $summary['warnings'][] = $summary['skipped_rate'].' service(s) were skipped because the provider pricing rule produced an unusable selling rate.';
        }
        if ($summary['skipped_category'] > 0) {
            $summary['warnings'][] = $summary['skipped_category'].' service(s) were skipped because no panel category matched and category creation was switched off.';
        }
        return $summary;
    }

    /**
     * The panel category a provider service belongs in: an existing one whose
     * slug matches the provider's category, or (when allowed) a new active
     * category named after it. Provider services with no category at all land
     * in "Imported", so an import never silently drops them.
     */
    private function category_for_source($s, $create, array &$summary) {
        $name = trim((string)($s->category ?? ''));
        if ($name === '') $name = 'Imported';
        $name = mb_substr($name, 0, 128);

        $existing = $this->ci->Service_category_model->find_by_slug($this->slug($name));
        if ($existing) return $existing;
        if (!$create) return null;

        $row = array(
            'public_id' => marvy_public_id(),
            'name' => $name,
            'slug' => $this->unique_category_slug($this->slug($name)),
            'parent_id' => null,
            'description' => null,
            'icon' => null,
            'platform' => null,
            'sorting' => 0,
            'is_active' => 1,
            'created_at' => gmdate('Y-m-d H:i:s'),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        );
        $this->ci->db->insert('service_categories', $row);
        $summary['categories_created']++;
        $category = $this->ci->Service_category_model->find_by_id((int)$this->ci->db->insert_id());
        if ($category) $summary['category_ids'][] = (int)$category->id;
        return $category;
    }

    /** A services.slug that is not taken: -2, -3, … before giving up. */
    private function unique_slug($base) {
        if ($base === '') return '';
        if (!$this->ci->db->where('slug', $base)->get('services')->row()) return $base;
        for ($i = 2; $i < 100; $i++) {
            $candidate = substr($base, 0, 253).'-'.$i;
            if (!$this->ci->db->where('slug', $candidate)->get('services')->row()) return $candidate;
        }
        return '';
    }

    /** A service_categories.slug that is not taken: -2, -3, … */
    private function unique_category_slug($base) {
        if ($base === '') $base = 'category';
        if (!$this->ci->db->where('slug', $base)->get('service_categories')->row()) return $base;
        for ($i = 2; $i < 100; $i++) {
            $candidate = substr($base, 0, 126).'-'.$i;
            if (!$this->ci->db->where('slug', $candidate)->get('service_categories')->row()) return $candidate;
        }
        return 'category-'.bin2hex(random_bytes(4));
    }

    /** Create the panel category named after the linked provider service's own. */
    private function create_provider_category($source) {
        $name = mb_substr(trim((string)($source->category ?? '')), 0, 128);
        if ($name === '') return null;
        $row = array(
            'public_id' => marvy_public_id(),
            'name' => $name,
            'slug' => $this->unique_category_slug($this->slug($name)),
            'parent_id' => null,
            'description' => null,
            'icon' => null,
            'platform' => null,
            'sorting' => 0,
            'is_active' => 1,
            'created_at' => gmdate('Y-m-d H:i:s'),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        );
        $this->ci->db->insert('service_categories', $row);
        $category = $this->ci->Service_category_model->find_by_id((int)$this->ci->db->insert_id());
        return $category ?: null;
    }

    /** Create or update one internal service. */
    public function save($existing, array $input) {
        $name = trim($this->scalar($input, 'name'));
        if ($name === '') return $this->err('INVALID', 'A service name is required.');
        if (mb_strlen($name) > 255) return $this->err('INVALID', 'Service names are limited to 255 characters.');

        $slug = $this->slug($this->scalar($input, 'slug', $name));
        if ($slug === '') return $this->err('INVALID', 'Enter a usable service slug.');
        $clash = $this->ci->db->where('slug', $slug);
        if ($existing) $clash->where('id !=', (int)$existing->id);
        if ($clash->get('services')->row()) {
            return $this->err('DUPLICATE', 'Another service already uses the slug "'.$slug.'".');
        }

        // The provider link is resolved before the category: the category
        // choice may be "create one named after the provider's own", which is
        // only meaningful for a real synced provider service.
        $provider_public_id = trim($this->scalar($input, 'provider'));
        $provider_service_id = trim($this->scalar($input, 'provider_service_id'));
        if (($provider_public_id === '') !== ($provider_service_id === '')) {
            return $this->err('BAD_PROVIDER_LINK', 'Choose both a provider and its service ID, or leave both blank.');
        }

        $provider = null;
        $source = null;
        if ($provider_public_id !== '') {
            $link = $this->provider_link($provider_public_id, $provider_service_id);
            if (empty($link['ok'])) return $link;
            $provider = $link['provider'];
            $source = $link['source'];
        }

        $category_value = $this->scalar($input, 'category');
        $wants_provider_category = $category_value === self::PROVIDER_CATEGORY_OPTION;
        if ($wants_provider_category) {
            if (!$source) {
                return $this->err('INVALID_CATEGORY',
                    'Choose a synced provider service before creating a category from it.');
            }
            if (trim((string)($source->category ?? '')) === '') {
                return $this->err('INVALID_CATEGORY',
                    'That provider service has no category of its own — pick an existing category instead.');
            }
            $category = null;
        } else {
            $category = $this->ci->Service_category_model->find_by_public_id($category_value);
            if (!$category) return $this->err('INVALID_CATEGORY', 'Choose a valid category.');
        }

        $type = strtoupper($this->scalar($input, 'service_type', 'DEFAULT'));
        if (!in_array($type, self::types(), true)) {
            return $this->err('INVALID_TYPE', 'Choose a supported service type.');
        }

        $status = strtoupper($this->scalar($input, 'status', 'INACTIVE'));
        if (!in_array($status, self::statuses(), true)) {
            return $this->err('INVALID_STATUS', 'Choose a valid service status.');
        }

        $min = $this->whole($input, 'min_quantity');
        $max = $this->whole($input, 'max_quantity');
        $step = $this->whole($input, 'increment_step', 1);
        if ($min < 1 || $max < $min) {
            return $this->err('BAD_LIMITS', 'Maximum quantity must be at least the minimum, and minimum must be 1 or more.');
        }
        if ($step < 1 || $step > $max) {
            return $this->err('BAD_STEP', 'Increment step must be between 1 and the maximum quantity.');
        }

        $auto = $this->checked($input, 'auto_price_sync');
        if ($auto && !$source) {
            return $this->err('BAD_AUTO_PRICE', 'Auto-price sync requires a synced provider service.');
        }
        $rate = $this->decimal($auto && $source
            ? $this->selling_rate($provider, $source->rate)
            : $this->scalar($input, 'rate'));
        if ($rate === null || bccomp($rate, '0', 8) <= 0) {
            return $this->err('BAD_RATE', 'Selling rate must be a positive amount with no more than 8 decimal places.');
        }

        $description = trim(strip_tags($this->scalar($input, 'description')));
        if (mb_strlen($description) > self::MAX_DESCRIPTION) {
            return $this->err('TOO_LONG', 'Description is limited to '.self::MAX_DESCRIPTION.' characters.');
        }

        $metadata = $this->metadata($this->scalar($input, 'metadata'));
        if ($metadata === false) {
            return $this->err('BAD_METADATA', 'Metadata must be a JSON object or array no larger than '.self::MAX_METADATA.' bytes.');
        }

        $refill = $this->checked($input, 'refill_supported');
        $refill_days = $refill ? $this->nullable_whole($input, 'refill_days') : null;
        if ($refill_days !== null && $refill_days < 1) {
            return $this->err('BAD_REFILL', 'Refill days must be 1 or more.');
        }

        $same_link = $existing && $provider
            && (int)$existing->provider_id === (int)$provider->id
            && (string)$existing->provider_service_id === (string)$source->provider_service_id;
        $snapshot = $source
            ? ($same_link && !empty($existing->provider_source_snapshot)
                ? $existing->provider_source_snapshot : $this->snapshot($source))
            : null;
        $average_time = trim($this->scalar($input, 'average_time'));
        if (mb_strlen($average_time) > 64) {
            return $this->err('BAD_TIME', 'The average-time label is limited to 64 characters.');
        }

        $this->ci->db->trans_start();
        // "Create a category from the provider" is resolved here, inside the
        // transaction and after every other rule has passed: a validation
        // failure earlier must not leave an orphaned category behind, and a
        // failed service write must not leave one either.
        if ($wants_provider_category) {
            $category = $this->create_provider_category($source);
            if (!$category) {
                $this->ci->db->trans_rollback();
                return $this->err('SAVE_FAILED', 'The category could not be created.');
            }
        }

        $row = array(
            'name' => $name,
            'slug' => $slug,
            'category_id' => $category->id,
            'description' => $description !== '' ? $description : null,
            'service_type' => $type,
            'rate' => $rate,
            'min_quantity' => $min,
            'max_quantity' => $max,
            'increment_step' => $step,
            'average_time' => $average_time !== '' ? $average_time : null,
            'average_time_minutes' => $this->nullable_whole($input, 'average_time_minutes'),
            'provider_id' => $provider ? $provider->id : null,
            'provider_service_id' => $source ? (string)$source->provider_service_id : null,
            // The source rate is vendor evidence, never a browser field.
            'provider_rate' => $source ? (string)$source->rate : null,
            'status' => $status,
            'cancel_supported' => $this->checked($input, 'cancel_supported'),
            'refill_supported' => $refill,
            'refill_days' => $refill_days,
            'dripfeed_supported' => $this->checked($input, 'dripfeed_supported'),
            'subscription_supported' => $this->checked($input, 'subscription_supported'),
            'package_supported' => $this->checked($input, 'package_supported'),
            'custom_comments_supported' => $this->checked($input, 'custom_comments_supported'),
            'sorting' => $this->whole($input, 'sorting', 0),
            'featured' => $this->checked($input, 'featured'),
            'trending' => $this->checked($input, 'trending'),
            'auto_price_sync' => $auto,
            'metadata' => $metadata,
            'provider_source_snapshot' => $snapshot,
            'updated_at' => gmdate('Y-m-d H:i:s'),
        );

        if ($row['average_time_minutes'] !== null && $row['average_time_minutes'] < 0) {
            $this->ci->db->trans_rollback();
            return $this->err('BAD_TIME', 'Average time in minutes cannot be negative.');
        }

        $warnings = array();
        if ($status === 'ACTIVE' && empty($category->is_active)) {
            $warnings[] = 'This service is active but its category is off, so customers still cannot see it.';
        }
        if ($provider && strtoupper((string)$provider->status) !== 'ACTIVE' && $status === 'ACTIVE') {
            $warnings[] = 'This service is active but its provider is not active; new orders may stay pending.';
        }
        if ($source && bccomp($rate, (string)$source->rate, 8) < 0) {
            $warnings[] = 'The selling rate is below the upstream rate before currency conversion or provider pricing rules.';
        }

        if ($existing) {
            $before = get_object_vars($existing);
            $this->ci->db->where('id', $existing->id)->update('services', $row);
            $id = $existing->id;
        } else {
            $before = null;
            $row['public_id'] = marvy_public_id();
            $row['created_at'] = gmdate('Y-m-d H:i:s');
            $this->ci->db->insert('services', $row);
            $id = $this->ci->db->insert_id();
        }
        $this->ci->db->trans_complete();
        if (!$this->ci->db->trans_status()) {
            return $this->err('SAVE_FAILED', 'The service could not be saved.');
        }

        return array(
            'ok' => true,
            'error' => null,
            'code' => null,
            'created' => !$existing,
            'before' => $before,
            'service' => $this->ci->Service_model->find_by_id($id),
            'warnings' => $warnings,
            // Set when the operator chose "create a category from the
            // provider": the controller audits it like any other category
            // write, with the same before/after shape.
            'category_created' => $wants_provider_category ? $category : null,
        );
    }

    /** Archive rather than delete: old orders retain a valid service FK. */
    public function archive($service) {
        if ((string)$service->status === 'ARCHIVED') {
            return array('ok' => true, 'service' => $service,
                'before' => get_object_vars($service), 'warnings' => array());
        }
        $before = get_object_vars($service);
        $this->ci->db->where('id', $service->id)->update('services', array(
            'status' => 'ARCHIVED', 'updated_at' => gmdate('Y-m-d H:i:s'),
        ));
        return array('ok' => true, 'before' => $before,
            'service' => $this->ci->Service_model->find_by_id($service->id),
            'warnings' => array());
    }

    public function delete($public_id) {
        $service = $this->find($public_id);
        if (!$service) return array('ok' => false, 'error' => 'Service not found');
        if ($service->status === 'ARCHIVED') {
            return array('ok' => false, 'error' => 'Cannot delete an archived service. Unarchive it first.');
        }
        // Check for active orders using this service
        $order_count = $this->ci->db->where('service_id', $service->id)->where('status !=', 'ARCHIVED')->count_all_results('orders');
        if ($order_count > 0) {
            return array('ok' => false, 'error' => 'Cannot delete service that has active orders. Archive or reassign orders first.');
        }
        $before = get_object_vars($service);
        $this->ci->db->where('id', $service->id)->delete('services');
        $this->ci->db->where('service_id', $service->id)->delete('service_prices'); // remove pricing
        $this->audit('service.deleted', $service, $before, null);
        return array('ok' => true, 'before' => $before, 'service' => null, 'warnings' => array());
    }

    public function group_rates($service_id) {
        return $this->ci->Service_price_model->for_service_with_groups($service_id, 100);
    }

    public function user_rates($service_id) {
        return $this->ci->User_service_price_model->for_service_with_users($service_id, 50);
    }

    /** Empty rate removes the override and falls back to the default service price. */
    public function set_group_rate($service, $price_group_id, $value) {
        $group = $this->ci->db->where('id', (int)$price_group_id)->get('price_groups')->row();
        if (!$group) return $this->err('NO_GROUP', 'Price group not found.');

        $before = $this->ci->Service_price_model->for_group($service->id, $group->id);
        if (trim((string)$value) === '') {
            $this->ci->Service_price_model->remove($service->id, $group->id);
            return array('ok' => true, 'before' => $before ? get_object_vars($before) : null,
                'after' => null, 'group' => $group, 'warnings' => array());
        }
        $rate = $this->decimal($value);
        if ($rate === null || bccomp($rate, '0', 8) <= 0) {
            return $this->err('BAD_RATE', 'Group rate must be a positive amount with no more than 8 decimal places.');
        }
        $row = $this->ci->Service_price_model->put($service->id, $group->id, $rate);
        return array('ok' => true, 'before' => $before ? get_object_vars($before) : null,
            'after' => get_object_vars($row), 'group' => $group,
            'warnings' => $service->provider_rate !== null
                && bccomp($rate, (string)$service->provider_rate, 8) < 0
                ? array('This group rate is below the stored provider rate.') : array());
    }

    /** User is addressed by public ID; internal user ids never appear in the form. */
    public function set_user_rate($service, $user_public_id, $value) {
        $user = $this->ci->User_model->find_by_public_id(trim((string)$user_public_id));
        if (!$user) return $this->err('NO_USER', 'Customer not found. Use their public ID.');

        $before = $this->ci->User_service_price_model->for_user($user->id, $service->id);
        if (trim((string)$value) === '') {
            $this->ci->User_service_price_model->remove($user->id, $service->id);
            return array('ok' => true, 'before' => $before ? get_object_vars($before) : null,
                'after' => null, 'user' => $user, 'warnings' => array());
        }
        $rate = $this->decimal($value);
        if ($rate === null || bccomp($rate, '0', 8) <= 0) {
            return $this->err('BAD_RATE', 'Customer rate must be a positive amount with no more than 8 decimal places.');
        }
        $row = $this->ci->User_service_price_model->put($user->id, $service->id, $rate);
        return array('ok' => true, 'before' => $before ? get_object_vars($before) : null,
            'after' => get_object_vars($row), 'user' => $user,
            'warnings' => $service->provider_rate !== null
                && bccomp($rate, (string)$service->provider_rate, 8) < 0
                ? array('This customer rate is below the stored provider rate.') : array());
    }

    private function provider_link($provider_public_id, $provider_service_id) {
        $provider = $this->ci->Provider_model->find_by_public_id(trim((string)$provider_public_id));
        if (!$provider || !in_array(strtoupper((string)$provider->api_type),
                Provider_manager::supported_types(Provider_manager::FAMILY_SMM), true)) {
            return $this->err('INVALID_PROVIDER', 'Choose a valid SMM provider.');
        }
        $provider_service_id = trim((string)$provider_service_id);
        if ($provider_service_id === '' || strlen($provider_service_id) > 64) {
            return $this->err('INVALID_PROVIDER_SERVICE', 'Enter a valid upstream service ID.');
        }
        $source = $this->ci->Provider_service_model->find_provider_service(
            $provider->id, $provider_service_id);
        if (!$source) {
            return $this->err('INVALID_PROVIDER_SERVICE',
                'That upstream service was not found for this provider. Sync the provider first.');
        }
        return array('ok' => true, 'provider' => $provider, 'source' => $source,
            'error' => null, 'code' => null, 'warnings' => array());
    }

    /** Provider rules produce the auto-synced customer rate. */
    private function selling_rate($provider, $source_rate) {
        $multiplier = isset($provider->rate_multiplier) ? (string)$provider->rate_multiplier : '1';
        $markup = isset($provider->markup) ? (string)$provider->markup : '0';
        return bcadd(bcmul((string)$source_rate, $multiplier, 8), $markup, 8);
    }

    private function snapshot($source) {
        return json_encode(array(
            'provider_service_id' => (string)$source->provider_service_id,
            'name' => (string)$source->name,
            'category' => $source->category,
            'rate' => (string)$source->rate,
            'min_quantity' => (int)$source->min_quantity,
            'max_quantity' => (int)$source->max_quantity,
            'service_type' => (string)$source->service_type,
            'cancel_supported' => (int)$source->cancel_supported,
            'refill_supported' => (int)$source->refill_supported,
            'dripfeed_supported' => (int)$source->dripfeed_supported,
            'last_synced_at' => $source->last_synced_at,
        ), JSON_UNESCAPED_SLASHES);
    }

    private function metadata($raw) {
        $raw = trim((string)$raw);
        if ($raw === '') return null;
        if (strlen($raw) > self::MAX_METADATA) return false;
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) return false;
        return json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** DECIMAL(20,8) as a string, with no float conversion. */
    private function decimal($value) {
        if (!is_scalar($value)) return null;
        $value = trim((string)$value);
        if (!preg_match('/^(?:0|[1-9][0-9]{0,11})(?:\.([0-9]{1,8}))?$/', $value, $m)) return null;
        list($whole) = explode('.', $value, 2);
        $fraction = isset($m[1]) ? str_pad($m[1], 8, '0') : '00000000';
        return $whole.'.'.$fraction;
    }

    private function slug($value) {
        $value = strtolower(trim((string)$value));
        $value = preg_replace('~[^a-z0-9]+~', '-', $value);
        return substr(trim(preg_replace('~-+~', '-', $value), '-'), 0, 255);
    }

    private function contains_id(array $rows, $id) {
        foreach ($rows as $row) {
            if ((int)$row->id === (int)$id) return true;
        }
        return false;
    }

    private function scalar(array $input, $key, $default = '') {
        if (!array_key_exists($key, $input) || !is_scalar($input[$key])) return (string)$default;
        return (string)$input[$key];
    }

    private function checked(array $input, $key) {
        return isset($input[$key]) && is_scalar($input[$key])
            && in_array(strtolower((string)$input[$key]), array('1', 'true', 'yes', 'on'), true) ? 1 : 0;
    }

    private function whole(array $input, $key, $default = 0) {
        $raw = $this->scalar($input, $key, (string)$default);
        if (!preg_match('/^-?[0-9]+$/', $raw)) return (int)$default;
        return (int)$raw;
    }

    private function nullable_whole(array $input, $key) {
        $raw = trim($this->scalar($input, $key));
        if ($raw === '') return null;
        if (!preg_match('/^-?[0-9]+$/', $raw)) return -1;
        return (int)$raw;
    }

    private function err($code, $message) {
        return array('ok' => false, 'error' => $message, 'code' => $code, 'warnings' => array());
    }
}
