<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * PricingService — what one customer pays for one service (§17).
 *
 * The rule is a three-step fallback and has not changed: a rate set for this
 * customer beats a rate set for their price group, which beats the service's
 * list rate. This is the single source of truth for it — the order engine, the
 * pickers, the public catalogue and the reseller API all resolve prices here,
 * so a customer can never be shown one number and charged another.
 *
 * ## Why there are two methods
 *
 * `price_for()` answers for one service and costs two point queries. That is
 * right when a customer opens one service, and wrong the moment a caller has a
 * list: the mass-order picker, the public catalogue and `GET /api/v1/services`
 * all looped over every active service and asked one at a time.
 *
 *   - the mass-order page issued 49 queries against a 20-service demo
 *     catalogue, 40 of them pricing;
 *   - the reseller API — an endpoint meant to be polled — would issue over a
 *     thousand for a 500-service panel, on every call.
 *
 * `rates_for()` answers for a whole list in two queries, whatever its length.
 * Same rule, same numbers, one round trip per table.
 */
class PricingService {

    private $ci;

    public function __construct(){ $this->ci =& get_instance(); }

    /** The rate one customer pays for one service. */
    public function price_for($service, $user){
        if (!$service) return null;
        $rates = $this->rates_for(array($service), $user);
        return $rates[(int)$service->id] ?? $service->rate;
    }

    /**
     * The rate this customer pays for each of these services.
     *
     * @param array $services rows carrying at least `id` and `rate`
     * @return array service id => rate (string), every service present
     */
    public function rates_for(array $services, $user){
        $out = array();
        $ids = array();
        foreach ($services as $service) {
            if (!$service || empty($service->id)) continue;
            $id = (int)$service->id;
            $out[$id] = $service->rate;      // 3. the list rate, unless overridden
            $ids[] = $id;
        }
        if (!$ids || !$user) return $out;

        // 2. the customer's price group, in one query.
        if (!empty($user->price_group_id)) {
            $rows = $this->ci->db->select('service_id, rate', false)
                ->where('price_group_id', $user->price_group_id)
                ->where_in('service_id', $ids)
                ->get('service_prices')->result();
            foreach ($rows as $row) $out[(int)$row->service_id] = $row->rate;
        }

        // 1. a rate set for this customer specifically — applied last so it
        //    wins, which is the order the fallback has always documented.
        $rows = $this->ci->db->select('service_id, rate', false)
            ->where('user_id', $user->id)
            ->where_in('service_id', $ids)
            ->get('user_service_prices')->result();
        foreach ($rows as $row) $out[(int)$row->service_id] = $row->rate;

        return $out;
    }

    public function charge_for_quantity($rate, $quantity){
        // rate is per 1000 typically; use bcmath
        $per_unit = bcdiv((string)$rate, '1000', 8);
        return bcmul($per_unit, (string)$quantity, 8);
    }
}
