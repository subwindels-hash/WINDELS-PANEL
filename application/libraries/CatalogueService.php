<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * CatalogueService — pricing and shelf control for every product domain (§15).
 *
 * Every catalogue in the panel is imported the same deliberately unhelpful
 * way: a sync writes `is_active = 0, price = NULL`, because a vendor knows
 * what a product costs us and nothing at all about what we should sell it for.
 * That rule is right, and it left exactly one hole — until this service there
 * was no supported way to *fill in* the price. Putting a data bundle on sale
 * meant writing UPDATE statements by hand against production.
 *
 * So this is the other half of the sync: the place where a human decides a
 * price and turns a row on. Four domains, one set of rules, because the
 * failure modes are shared:
 *
 *   - An active row with no price is not a cheap product, it is a broken one.
 *     Every service re-checks (`NO_PRICE`) and refuses the purchase, so the
 *     customer meets an error at the checkout instead of a price on the shelf.
 *     Activation therefore requires a price.
 *   - Airtime, electricity and virtual numbers resolve to *one* row per
 *     network/pair and take the first by sort order. A second active row does
 *     not produce an error, it produces a silently different price — so a
 *     second active row is refused, naming the one already on the shelf.
 *   - Selling below cost is allowed and warned about. It is a legitimate
 *     promotion and an illegitimate typo, and only the operator knows which.
 *
 * Controllers stay thin: guard, call, audit. All the domain knowledge is here
 * so the rules cannot drift between four screens.
 */
class CatalogueService {

    /** domain key => [label, model, parent-label] */
    private static $domains = array(
        'vtu'       => 'VTU products',
        'numbers'   => 'Virtual numbers',
        'identity'  => 'Identity checks',
        'giftcards' => 'Gift cards',
    );

    private static $families = array(
        'vtu'       => 'VTU',
        'numbers'   => 'NUMBER',
        'identity'  => 'IDENTITY',
        'giftcards' => 'GIFTCARD',
    );

    /** VTU types whose price is the customer's amount less a discount. */
    private static $variable_types = array('AIRTIME', 'ELECTRICITY');

    private $ci;

    public function __construct() {
        $this->ci =& get_instance();
        $this->ci->load->model(array(
            'Vtu_product_model', 'Vtu_network_model',
            'Number_product_model', 'Number_country_model', 'Number_service_model',
            'Identity_product_model',
            'Giftcard_product_model', 'Giftcard_brand_model',
            'Provider_model',
        ));
        $this->ci->load->library('Provider_manager');
    }

    /* ----------------------------- reading ------------------------------ */

    public static function domains() { return self::$domains; }

    public static function is_domain($domain) {
        return is_string($domain) && isset(self::$domains[$domain]);
    }

    public static function label($domain) {
        return isset(self::$domains[$domain]) ? self::$domains[$domain] : $domain;
    }

    /** The product model behind one domain. */
    public function model($domain) {
        switch ($domain) {
            case 'vtu':       return $this->ci->Vtu_product_model;
            case 'numbers':   return $this->ci->Number_product_model;
            case 'identity':  return $this->ci->Identity_product_model;
            case 'giftcards': return $this->ci->Giftcard_product_model;
        }
        throw new InvalidArgumentException('Unknown catalogue domain: '.$domain);
    }

    /** One page of the grid plus its unpaged total. */
    public function grid($domain, array $filters, $limit, $offset) {
        $model = $this->model($domain);
        return array(
            'rows'  => $model->admin_search($filters, $limit, $offset),
            'total' => $model->admin_count($filters),
        );
    }

    public function find($domain, $public_id) {
        return $this->model($domain)->find_by_public_id($public_id);
    }

    /**
     * What the forms need: the parents a product hangs off, and the providers
     * that can serve this domain.
     *
     * Networks, countries, services and brands are reference data seeded by
     * Core_seeder, so these lists are small and bounded — no pagination, and
     * no reason for the form to make the operator type an id.
     */
    public function options($domain) {
        $out = array('providers' => $this->providers_for($domain));
        if ($domain === 'vtu') {
            $out['networks'] = $this->ci->Vtu_network_model->all_networks();
        } elseif ($domain === 'numbers') {
            $out['countries'] = $this->ci->Number_country_model->all();
            $out['services']  = $this->ci->Number_service_model->all();
        } elseif ($domain === 'giftcards') {
            $out['brands'] = $this->ci->Giftcard_brand_model->all_brands();
        }
        return $out;
    }

    /** Providers whose api_type this build can actually drive for a domain. */
    public function providers_for($domain) {
        $types = Provider_manager::supported_types(self::$families[$domain]);
        $out = array();
        foreach ($this->ci->Provider_model->active() as $p) {
            if (in_array(strtoupper((string)$p->api_type), $types, true)) $out[] = $p;
        }
        return $out;
    }

    /* ----------------------------- writing ------------------------------ */

    /**
     * Create or update one product.
     *
     * @param string      $domain
     * @param object|null $existing row being edited, NULL to create
     * @param array       $input    raw POST
     * @return array{ok:bool,error?:string,code?:string,warnings:array,
     *               product?:object,before?:array,created?:bool}
     */
    public function save($domain, $existing, array $input) {
        if (!self::is_domain($domain)) return $this->err('Unknown catalogue', 'NO_DOMAIN');

        $parsed = $this->parse($domain, $input, $existing);
        if (!empty($parsed['errors'])) {
            return $this->err(implode(' ', $parsed['errors']), 'INVALID', $parsed['warnings']);
        }

        $fields   = $parsed['fields'];
        $warnings = $parsed['warnings'];
        $model    = $this->model($domain);

        // Activation is the moment a row becomes a promise to a customer, so
        // it is checked here rather than trusted from the form.
        $active = !empty($fields['is_active']);
        if ($active) {
            $blocked = $this->activation_error($domain, $fields, $existing);
            if ($blocked) return $this->err($blocked, 'NOT_SELLABLE', $warnings);
        }

        $clash = $this->duplicate_error($domain, $fields, $existing);
        if ($clash) return $this->err($clash, 'DUPLICATE', $warnings);

        foreach ($this->sale_warnings($domain, $fields) as $w) $warnings[] = $w;

        if ($existing) {
            $before = $this->snapshot($existing);
            $model->update_fields($existing->id, $fields);
            return array('ok' => true, 'warnings' => $warnings, 'created' => false,
                         'before' => $before,
                         'product' => $model->find_by_id($existing->id));
        }

        $id = $model->create($fields);
        return array('ok' => true, 'warnings' => $warnings, 'created' => true,
                     'before' => null, 'product' => $model->find_by_id($id));
    }

    /**
     * Switch one product on or off.
     *
     * Switching off is always allowed — it is the emergency brake, and a rule
     * that could refuse it would be a rule that keeps a broken product on
     * sale. Switching on runs the full sellability check.
     */
    public function set_status($domain, $product, $active) {
        if (!self::is_domain($domain)) return $this->err('Unknown catalogue', 'NO_DOMAIN');
        $active = (bool)$active;

        if ($active) {
            $fields = $this->snapshot($product) + array('is_active' => 1);
            $fields['is_active'] = 1;
            $blocked = $this->activation_error($domain, $fields, $product);
            if ($blocked) return $this->err($blocked, 'NOT_SELLABLE');
        }

        $model = $this->model($domain);
        $model->update_fields($product->id, array('is_active' => $active ? 1 : 0));
        return array('ok' => true, 'warnings' => $active ? $this->sale_warnings($domain, $this->snapshot($product)) : array(),
                     'product' => $model->find_by_id($product->id));
    }

    /* --------------------------- domain rules --------------------------- */

    /**
     * Why this row may not go on sale, or NULL if it may.
     *
     * Each branch mirrors a check the buying path already makes, because the
     * alternative is a product that looks available and fails at checkout.
     */
    private function activation_error($domain, array $fields, $existing) {
        $id = $existing ? (int)$existing->id : null;

        if ($domain === 'vtu') {
            $type = isset($fields['service_type']) ? $fields['service_type']
                  : ($existing ? $existing->service_type : '');
            $network_id = isset($fields['network_id']) ? (int)$fields['network_id']
                        : ($existing ? (int)$existing->network_id : 0);

            if (in_array($type, self::$variable_types, true)) {
                // VtuService::variable_product() takes active_for(...)[0]: a
                // second active row would quietly decide the discount and the
                // amount limits for every purchase on this network.
                $others = $this->ci->Vtu_product_model->other_active($network_id, $type, $id);
                if ($others) {
                    return 'This network already has an active '.strtolower($type)
                        .' product ("'.$others[0]->name.'"). Switch that one off first —'
                        .' only one variable-amount product per network can be live,'
                        .' and the second would silently override its rates.';
                }
                return null; // price is per-purchase, not on the row
            }
            if ($this->blank($fields, $existing, 'price')) {
                return 'Set a price before switching this product on: the purchase'
                    .' would be refused at checkout as unpriced.';
            }
            return null;
        }

        if ($domain === 'numbers') {
            if ($this->blank($fields, $existing, 'price')) {
                return 'Set a price before switching this number on: a rental with'
                    .' no price is refused at checkout.';
            }
            $country_id = isset($fields['country_id']) ? (int)$fields['country_id']
                        : ($existing ? (int)$existing->country_id : 0);
            $service_id = isset($fields['service_id']) ? (int)$fields['service_id']
                        : ($existing ? (int)$existing->service_id : 0);
            $others = $this->ci->Number_product_model->other_active($country_id, $service_id, $id);
            if ($others) {
                return 'That country and service already have an active product'
                    .' ("'.$others[0]->code.'"). Rentals resolve to one row per pair,'
                    .' so the second would never be reached — or would replace the'
                    .' first depending on sort order.';
            }
            return null;
        }

        if ($domain === 'identity') {
            if ($this->blank($fields, $existing, 'price')) {
                return 'Set a price before switching this check on: an unpriced'
                    .' lookup still costs us a vendor call.';
            }
            return null;
        }

        // giftcards
        if ($this->blank($fields, $existing, 'price')) {
            return 'Set a price before switching this card on: an unpriced'
                .' denomination is not sellable.';
        }
        $type = isset($fields['denomination_type']) ? $fields['denomination_type']
              : ($existing ? $existing->denomination_type : 'FIXED');
        if ($type === 'FIXED' && $this->blank($fields, $existing, 'face_value')) {
            return 'A fixed-denomination card needs a face value.';
        }
        return null;
    }

    /** Non-blocking notes worth putting in front of the operator. */
    private function sale_warnings($domain, array $fields) {
        $out = array();
        $price = isset($fields['price']) ? $fields['price'] : null;
        $cost  = isset($fields['provider_cost']) ? $fields['provider_cost'] : null;

        if ($price !== null && $cost !== null && bccomp((string)$price, (string)$cost, 8) < 0) {
            $out[] = 'Heads up: this sells below the vendor cost ('
                .marvy_money($price).' against '.marvy_money($cost).').';
        }
        if ($domain === 'giftcards') {
            if (isset($fields['denomination_type']) && $fields['denomination_type'] === 'RANGE') {
                $out[] = 'Custom-amount cards are not on the storefront yet — this row'
                    .' stays invisible to customers until range pricing ships.';
            }
            if (!empty($fields['brand_id'])) {
                $brand = $this->ci->Giftcard_brand_model->find_by_id((int)$fields['brand_id']);
                if ($brand && !$brand->is_active) {
                    $out[] = 'The "'.$brand->name.'" brand is switched off, so this card'
                        .' stays hidden until the brand is switched back on.';
                }
            }
        }
        return $out;
    }

    /** A friendlier message than the database's UNIQUE violation. */
    private function duplicate_error($domain, array $fields, $existing) {
        $id = $existing ? (int)$existing->id : null;
        $row = null;

        if ($domain === 'vtu') {
            $row = $this->ci->Vtu_product_model->find_by_code(
                (int)$fields['network_id'], $fields['service_type'], $fields['code']);
        } elseif ($domain === 'numbers') {
            $row = $this->ci->Number_product_model->find_by_code(
                (int)$fields['country_id'], (int)$fields['service_id'], $fields['code']);
        } elseif ($domain === 'identity') {
            $row = $this->ci->Identity_product_model->find_by_code($fields['code']);
        } elseif ($domain === 'giftcards') {
            $row = $this->ci->Giftcard_product_model->find_by_code($fields['code']);
        }

        if ($row && (int)$row->id !== $id) {
            return 'The code "'.$fields['code'].'" is already used by "'.$row->name.'".';
        }
        return null;
    }

    /* ------------------------------ parsing ----------------------------- */

    private function parse($domain, array $input, $existing) {
        if ($domain === 'vtu')       return $this->parse_vtu($input, $existing);
        if ($domain === 'numbers')   return $this->parse_number($input, $existing);
        if ($domain === 'identity')  return $this->parse_identity($input, $existing);
        return $this->parse_giftcard($input, $existing);
    }

    private function parse_vtu(array $in, $existing) {
        $errors = array(); $warnings = array();

        $network = $this->ci->Vtu_network_model->find_by_id((int)($in['network_id'] ?? 0));
        if (!$network) {
            $errors[] = 'Choose a network.';
            return array('fields' => array(), 'errors' => $errors, 'warnings' => $warnings);
        }
        // The service type belongs to the network, not the form: VtuService
        // rejects a product whose type does not match its network, so letting
        // the two be chosen independently would only create dead rows.
        $type = strtoupper((string)$network->service_type);

        $name = $this->text($in, 'name', 128);
        if ($name === null) $errors[] = 'Give the product a name.';

        $code = $this->code($in['code'] ?? '', 64) ?: $this->code($name ?: '', 64);
        if ($code === null || $code === '') $errors[] = 'Give the product a code.';

        $fields = array(
            'network_id'    => (int)$network->id,
            'service_type'  => $type,
            'code'          => $code,
            'name'          => $name,
            'provider_code' => $this->text($in, 'provider_code', 64),
            'description'   => $this->text($in, 'description', 255),
            'product_type'  => $this->text($in, 'product_type', 32),
            'validity'      => $this->text($in, 'validity', 32),
            'sorting'       => (int)($in['sorting'] ?? 0),
            'is_active'     => !empty($in['is_active']) ? 1 : 0,
        );

        $provider = $this->provider($in, 'vtu', $errors);
        $fields['provider_id'] = $provider;

        $fields['provider_cost'] = $this->money($in, 'provider_cost', $errors, 'Vendor cost');

        if (in_array($type, self::$variable_types, true)) {
            // The customer names the amount, so there is no row price at all;
            // storing one would look authoritative and never be charged.
            $fields['price']            = null;
            $fields['face_value']       = null;
            $fields['discount_percent'] = $this->percent($in, 'discount_percent', $errors);
            $fields['min_amount']       = $this->money($in, 'min_amount', $errors, 'Minimum');
            $fields['max_amount']       = $this->money($in, 'max_amount', $errors, 'Maximum');
            if ($fields['min_amount'] !== null && $fields['max_amount'] !== null
                && bccomp($fields['min_amount'], $fields['max_amount'], 8) > 0) {
                $errors[] = 'The minimum amount is above the maximum.';
            }
            if ($fields['min_amount'] === null || $fields['max_amount'] === null) {
                $warnings[] = 'No amount limits set — customers may top up any amount'
                    .' their wallet covers.';
            }
        } else {
            $fields['discount_percent'] = '0.0000';
            $fields['price']            = $this->money($in, 'price', $errors, 'Price');
            $fields['face_value']       = $this->money($in, 'face_value', $errors, 'Face value');
            $fields['min_amount']       = null;
            $fields['max_amount']       = null;
        }

        return array('fields' => $fields, 'errors' => $errors, 'warnings' => $warnings);
    }

    private function parse_number(array $in, $existing) {
        $errors = array(); $warnings = array();

        $country = $this->ci->Number_country_model->find_by_id((int)($in['country_id'] ?? 0));
        $service = $this->ci->Number_service_model->find_by_id((int)($in['service_id'] ?? 0));
        if (!$country) $errors[] = 'Choose a country.';
        if (!$service) $errors[] = 'Choose a service.';
        if (!$country || !$service) {
            return array('fields' => array(), 'errors' => $errors, 'warnings' => $warnings);
        }

        $code = $this->code($in['code'] ?? '', 96);
        if ($code === null || $code === '') $code = $country->code.'-'.$service->code;

        $ttl = (int)($in['ttl_minutes'] ?? 0);
        if ($ttl < 1) $ttl = 15;
        if ($ttl > 1440) $errors[] = 'A hold longer than a day is not a rental.';

        $stock = ($in['stock'] ?? '') === '' ? null : (int)$in['stock'];

        $fields = array(
            'country_id'        => (int)$country->id,
            'service_id'        => (int)$service->id,
            'code'              => $code,
            'provider_country'  => $this->text($in, 'provider_country', 48),
            'provider_operator' => $this->text($in, 'provider_operator', 48) ?: 'any',
            'provider_product'  => $this->text($in, 'provider_product', 48),
            'price'             => $this->money($in, 'price', $errors, 'Price'),
            'provider_cost'     => $this->money($in, 'provider_cost', $errors, 'Vendor cost'),
            'stock'             => $stock,
            'ttl_minutes'       => $ttl,
            'sorting'           => (int)($in['sorting'] ?? 0),
            'is_active'         => !empty($in['is_active']) ? 1 : 0,
            'provider_id'       => $this->provider($in, 'numbers', $errors),
        );

        if ($stock !== null && $stock <= 0 && !empty($fields['is_active'])) {
            $warnings[] = 'Stock is zero, so reservations will be refused until the'
                .' next catalogue sync raises it.';
        }
        return array('fields' => $fields, 'errors' => $errors, 'warnings' => $warnings);
    }

    private function parse_identity(array $in, $existing) {
        $errors = array(); $warnings = array();

        $name = $this->text($in, 'name', 96);
        if ($name === null) $errors[] = 'Give the check a name.';

        $code = $this->code($in['code'] ?? '', 48) ?: $this->code($name ?: '', 48);
        if ($code === null || $code === '') $errors[] = 'Give the check a code.';

        $id_type = strtoupper((string)($in['id_type'] ?? ''));
        if (!in_array($id_type, array('NIN', 'BVN'), true)) $errors[] = 'Choose NIN or BVN.';

        $lookup = strtoupper((string)($in['lookup_field'] ?? 'IDENTIFIER'));
        if (!in_array($lookup, array('IDENTIFIER', 'PHONE'), true)) $lookup = 'IDENTIFIER';
        if ($lookup === 'PHONE' && $id_type === 'BVN') {
            // Dojah has no BVN-by-phone endpoint; a row like this would sell a
            // lookup that always 404s, which is a billed answer of "no".
            $errors[] = 'BVN cannot be looked up by phone number.';
        }

        $fields = array(
            'code'          => $code,
            'name'          => $name,
            'id_type'       => $id_type,
            'lookup_field'  => $lookup,
            'provider_code' => $this->text($in, 'provider_code', 64),
            'description'   => $this->text($in, 'description', 255),
            'price'         => $this->money($in, 'price', $errors, 'Price'),
            'provider_cost' => $this->money($in, 'provider_cost', $errors, 'Vendor cost'),
            'sorting'       => (int)($in['sorting'] ?? 0),
            'is_active'     => !empty($in['is_active']) ? 1 : 0,
            'provider_id'   => $this->provider($in, 'identity', $errors),
        );

        if ($fields['provider_code'] === null) {
            $warnings[] = 'No vendor endpoint set — the adapter will fall back to its'
                .' default path for this ID type.';
        }
        return array('fields' => $fields, 'errors' => $errors, 'warnings' => $warnings);
    }

    private function parse_giftcard(array $in, $existing) {
        $errors = array(); $warnings = array();

        $brand = $this->ci->Giftcard_brand_model->find_by_id((int)($in['brand_id'] ?? 0));
        if (!$brand) {
            $errors[] = 'Choose a brand.';
            return array('fields' => array(), 'errors' => $errors, 'warnings' => $warnings);
        }

        $name = $this->text($in, 'name', 160);
        if ($name === null) $errors[] = 'Give the card a name.';

        $type = strtoupper((string)($in['denomination_type'] ?? 'FIXED'));
        if (!in_array($type, array('FIXED', 'RANGE'), true)) $type = 'FIXED';

        $country = strtoupper(substr(trim((string)($in['country_code'] ?? 'US')), 0, 2));
        if (!preg_match('/^[A-Z]{2}$/', $country)) $errors[] = 'Country must be a two-letter code.';

        // Never defaulted: a card whose denomination currency we guessed would
        // be a dollar card sold as a naira one. See migration 014.
        $currency = strtoupper(substr(trim((string)($in['recipient_currency'] ?? '')), 0, 3));
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            $errors[] = 'Give the currency the card itself is denominated in (USD, GBP, ...).';
        }

        $face = $this->money($in, 'face_value', $errors, 'Face value');
        if ($type === 'FIXED' && $face === null) $errors[] = 'A fixed card needs a face value.';

        $code = $this->code($in['code'] ?? '', 96);
        if ($code === null || $code === '') {
            // Denominations of one vendor product share a productId, so the
            // code has to carry the face value to stay unique.
            $code = $this->code($brand->code.'-'.$country.'-'
                .($type === 'RANGE' ? 'RANGE' : rtrim(rtrim((string)$face, '0'), '.')), 96);
        }

        $max_qty = (int)($in['max_quantity'] ?? 1);
        if ($max_qty < 1) $max_qty = 1;
        if ($max_qty > 50) $errors[] = 'Cap the quantity at 50 cards per order or fewer.';

        $fields = array(
            'brand_id'            => (int)$brand->id,
            'code'                => $code,
            'name'                => $name,
            'country_code'        => $country,
            'provider_product_id' => $this->text($in, 'provider_product_id', 48),
            'denomination_type'   => $type,
            'recipient_currency'  => $currency,
            'face_value'          => $type === 'RANGE' ? null : $face,
            'min_face_value'      => $type === 'RANGE' ? $this->money($in, 'min_face_value', $errors, 'Minimum') : null,
            'max_face_value'      => $type === 'RANGE' ? $this->money($in, 'max_face_value', $errors, 'Maximum') : null,
            'price'               => $this->money($in, 'price', $errors, 'Price'),
            'provider_cost'       => $this->money($in, 'provider_cost', $errors, 'Vendor cost'),
            'max_quantity'        => $max_qty,
            'sorting'             => (int)($in['sorting'] ?? 0),
            'is_active'           => !empty($in['is_active']) ? 1 : 0,
            'provider_id'         => $this->provider($in, 'giftcards', $errors),
        );
        return array('fields' => $fields, 'errors' => $errors, 'warnings' => $warnings);
    }

    /* ------------------------------ helpers ----------------------------- */

    /** An optional provider, checked against the adapters this build has. */
    private function provider(array $in, $domain, array &$errors) {
        $id = (int)($in['provider_id'] ?? 0);
        if ($id <= 0) return null;

        $provider = $this->ci->Provider_model->find_by_id($id);
        if (!$provider) { $errors[] = 'That provider does not exist.'; return null; }

        $types = Provider_manager::supported_types(self::$families[$domain]);
        if (!in_array(strtoupper((string)$provider->api_type), $types, true)) {
            $errors[] = '"'.$provider->name.'" cannot serve '.self::label($domain)
                .' — its API type is '.$provider->api_type.'.';
            return null;
        }
        return (int)$provider->id;
    }

    /** '' becomes NULL; anything non-numeric or negative is an error. */
    private function money(array $in, $key, array &$errors, $label) {
        $raw = isset($in[$key]) ? trim((string)$in[$key]) : '';
        if ($raw === '') return null;
        if (!is_numeric($raw)) { $errors[] = $label.' must be a number.'; return null; }
        if ((float)$raw < 0)   { $errors[] = $label.' cannot be negative.'; return null; }
        return number_format((float)$raw, 8, '.', '');
    }

    private function percent(array $in, $key, array &$errors) {
        $raw = isset($in[$key]) ? trim((string)$in[$key]) : '';
        if ($raw === '') return '0.0000';
        if (!is_numeric($raw)) { $errors[] = 'The discount must be a number.'; return '0.0000'; }
        $v = (float)$raw;
        if ($v < 0 || $v > 100) {
            // discounted() floors at zero, so >100% would sell airtime for
            // nothing rather than paying the customer — still not a sale.
            $errors[] = 'The discount must be between 0 and 100 percent.';
            return '0.0000';
        }
        return number_format($v, 4, '.', '');
    }

    private function text(array $in, $key, $max) {
        $v = isset($in[$key]) ? trim((string)$in[$key]) : '';
        return $v === '' ? null : mb_substr($v, 0, $max);
    }

    /** The same normalisation the vendor syncs use, so codes cannot diverge. */
    private function code($raw, $max) {
        $v = trim((string)$raw);
        if ($v === '') return null;
        return mb_substr(strtoupper(preg_replace('/[^A-Za-z0-9._-]+/', '-', $v)), 0, $max);
    }

    /** Is a column blank on the submitted row, falling back to the stored one? */
    private function blank(array $fields, $existing, $column) {
        if (array_key_exists($column, $fields)) {
            return $fields[$column] === null || $fields[$column] === '';
        }
        if (!$existing) return true;
        return !isset($existing->$column) || $existing->$column === null || $existing->$column === '';
    }

    /** The row as an array, for the audit trail's before/after. */
    private function snapshot($row) {
        return $row ? get_object_vars($row) : array();
    }

    private function err($message, $code, array $warnings = array()) {
        return array('ok' => false, 'error' => $message, 'code' => $code,
                     'warnings' => $warnings);
    }
}
