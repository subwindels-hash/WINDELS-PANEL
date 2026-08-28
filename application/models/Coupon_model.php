<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Coupon_model extends MY_Model {
    protected $table = 'coupons';

    /** A coupon that is active, within its date window, and not exhausted globally. */
    /**
     * A coupon that may be used right now — optionally, by this customer.
     *
     * `usage_limit_per_user` has existed on this table since the shop shipped,
     * the admin form sets it, it defaults to 1 … and **nothing enforced it**.
     * A "one per customer" code could be redeemed by the same customer on
     * every order they ever placed, for ever. That is the failure mode a
     * public discount code has within hours of being posted anywhere.
     *
     * Passing `$user_id` applies the per-customer cap; omitting it answers the
     * older question ("is this code live at all?") for surfaces such as the
     * public coupon list, which has no customer in hand.
     */
    public function find_valid($code, $user_id = null) {
        $code = strtoupper(trim((string)$code));
        if ($code === '') return null;
        // Escaped inline at the point of interpolation so the fragment can be
        // read (and linted) as safe without chasing a variable up the method.
        $now = $this->now_utc();
        $row = $this->db->where('code', $code)->where('is_active', 1)
            ->where('(starts_at IS NULL OR starts_at <= '.$this->db->escape($now).')', null, false)
            ->where('(ends_at IS NULL OR ends_at >= '.$this->db->escape($now).')', null, false)
            ->get($this->table)->row();
        if (!$row) return null;
        if ($row->usage_limit !== null && (int)$row->times_used >= (int)$row->usage_limit) return null;
        if ($user_id && !$this->within_user_limit($row, $user_id)) return null;
        return $row;
    }

    /** How many times this customer has already redeemed this coupon. */
    public function redemptions_by($coupon_id, $user_id) {
        return (int)$this->db->where('coupon_id', (int)$coupon_id)
            ->where('user_id', (int)$user_id)
            ->count_all_results('coupon_redemptions');
    }

    /** Whether one more redemption by this customer is allowed. */
    public function within_user_limit($coupon, $user_id) {
        $limit = $coupon->usage_limit_per_user ?? null;
        // NULL means unlimited per customer; 0 means the same, because the
        // admin form stores an empty box as NULL and a deliberate zero would
        // otherwise make a live coupon unusable by everyone.
        if ($limit === null || (int)$limit <= 0) return true;
        return $this->redemptions_by($coupon->id, $user_id) < (int)$limit;
    }

    public function find_public($public_id) {
        return $this->db->where('public_id', $public_id)->get($this->table)->row();
    }

    /**
     * Every coupon currently eligible AND flagged for discovery — the cart
     * page's "available coupons" list. Applies the exact same
     * active/date-window/usage-limit rules as find_valid(); listing a coupon
     * that would actually be refused at apply time would be worse than not
     * listing one at all.
     */
    public function public_valid($limit = 10) {
        // Escaped inline at the point of interpolation so the fragment can be
        // read (and linted) as safe without chasing a variable up the method.
        $now = $this->now_utc();
        $rows = $this->db->where('is_active', 1)->where('is_public', 1)
            ->where('(starts_at IS NULL OR starts_at <= '.$this->db->escape($now).')', null, false)
            ->where('(ends_at IS NULL OR ends_at >= '.$this->db->escape($now).')', null, false)
            ->order_by('created_at', 'DESC')->limit($limit)
            ->get($this->table)->result();
        return array_values(array_filter($rows, function ($row) {
            return $row->usage_limit === null || (int)$row->times_used < (int)$row->usage_limit;
        }));
    }

    /** How many times this user has already redeemed this coupon. */
    public function redemptions_by_user($coupon_id, $user_id) {
        return (int)$this->db->where('coupon_id', (int)$coupon_id)->where('user_id', (int)$user_id)
            ->count_all_results('coupon_redemptions');
    }

    /**
     * Take this customer's next redemption slot for this coupon, before any
     * money moves.
     *
     * The per-customer limit used to be a `COUNT(*)` taken moments before the
     * insert — a check-then-act race that two near-simultaneous checkouts (a
     * double-clicked Pay button, a retried request, two tabs) both win. Since
     * migration 030 the slot number is part of a UNIQUE index, so the database
     * decides: both attempts compute slot 1, one insert succeeds and the other
     * is refused. The loser is told they have already used the coupon instead
     * of being charged and reconciled by hand later.
     *
     * The row is written with no order and no discount yet; `attach()`
     * completes it once the charge lands and `release()` removes it if
     * checkout never completes, so an abandoned attempt does not burn the
     * customer's only use.
     *
     * @return array{ok:bool, id?:int, slot?:int, code?:string, error?:string}
     */
    public function reserve_redemption($coupon, $user_id) {
        $coupon_id = (int)(is_object($coupon) ? $coupon->id : $coupon);
        $user_id   = (int)$user_id;
        if ($coupon_id <= 0 || $user_id <= 0) {
            return array('ok' => false, 'code' => 'BAD_INPUT', 'error' => 'Coupon could not be applied.');
        }

        $limit = is_object($coupon) && isset($coupon->usage_limit_per_user)
            ? $coupon->usage_limit_per_user : null;
        $limit = ($limit === null || (int)$limit <= 0) ? null : (int)$limit;

        // Three attempts: an unlimited coupon has no cap to stop at, so two
        // customers-worth of concurrency on the SAME customer must be able to
        // walk up to the next free slot rather than fail outright. Three is
        // enough for any realistic double-submit and bounded so a pathological
        // loop cannot hold a checkout open.
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $slot = $this->redemptions_by($coupon_id, $user_id) + 1;
            if ($limit !== null && $slot > $limit) {
                return array('ok' => false, 'code' => 'PER_USER_LIMIT',
                             'error' => 'You have already used this coupon.');
            }

            // Both failure shapes are handled on purpose: with db_debug off
            // (production) a duplicate key returns FALSE, and with it on
            // (development, and some drivers) the same collision throws. This
            // path decides whether a customer is charged, so it must not
            // depend on which of the two the host happens to produce.
            try {
                $ok = $this->db->insert('coupon_redemptions', array(
                    'coupon_id'            => $coupon_id,
                    'user_id'              => $user_id,
                    'marketplace_order_id' => null,
                    'discount_amount'      => '0.00000000',
                    'redemption_slot'      => $slot,
                    'created_at'           => $this->now_utc(),
                ));
            } catch (Throwable $e) {
                $ok = false;
            }

            if ($ok) {
                $id = (int)$this->db->insert_id();
                // The global counter moves with the reservation, not with the
                // charge: a coupon capped at 100 uses must not be handed to
                // 120 people who are all mid-checkout.
                $this->db->set('times_used', 'times_used + 1', false)
                    ->where('id', $coupon_id)->update($this->table);
                return array('ok' => true, 'id' => $id, 'slot' => $slot);
            }
            // Someone else took that slot between the count and the insert.
            // Loop: recount, and either take the next one or hit the cap.
        }

        return array('ok' => false, 'code' => 'BUSY',
                     'error' => 'That coupon is being used on another order. Try again.');
    }

    /**
     * Complete a reserved redemption once the order exists and is charged.
     *
     * Shop checkouts keep the marketplace_order_id foreign key (domain SHOP).
     * Every other purchase domain — an SMM order, a VTU top-up, a number
     * rental, an identity check, a gift card — names what it discounted by
     * domain + the public_id reference instead, so the coupon's book of
     * record works across all of them without five extra foreign keys
     * (migration 034).
     */
    public function attach_redemption($redemption_id, $order_id, $discount_amount,
                                      $domain = 'SHOP', $reference = null) {
        $fields = array('discount_amount' => $discount_amount);
        if ((string)$domain === 'SHOP') {
            $fields['marketplace_order_id'] = $order_id ? (int)$order_id : null;
            $fields['domain'] = 'SHOP';
            $fields['reference'] = null;
        } else {
            $fields['marketplace_order_id'] = null;
            $fields['domain'] = substr(strtoupper(trim((string)$domain)), 0, 16);
            $fields['reference'] = $reference !== null ? substr((string)$reference, 0, 64) : null;
        }
        return $this->db->where('id', (int)$redemption_id)->update('coupon_redemptions', $fields);
    }

    /** The redemption(s) recorded against one domain purchase, if any. */
    public function for_reference($domain, $reference) {
        return $this->db->where('domain', strtoupper(trim((string)$domain)))
            ->where('reference', (string)$reference)
            ->get('coupon_redemptions')->result();
    }

    /**
     * Give a reserved slot back.
     *
     * Called when a checkout that reserved one does not complete. Without
     * this, a customer whose card was declined would have burned their single
     * use of a launch code on an order that never happened — the most annoying
     * possible way to lose a sale.
     */
    public function release_redemption($redemption_id, $coupon_id = null) {
        $redemption_id = (int)$redemption_id;
        if ($redemption_id <= 0) return false;

        if ($coupon_id === null) {
            $row = $this->db->where('id', $redemption_id)->get('coupon_redemptions')->row();
            $coupon_id = $row ? (int)$row->coupon_id : 0;
        }
        $this->db->where('id', $redemption_id)->delete('coupon_redemptions');

        if ($coupon_id > 0) {
            // Never below zero: a double release must not make a spent coupon
            // look fresh.
            $this->db->set('times_used', 'times_used - 1', false)
                ->where('id', $coupon_id)->where('times_used >', 0)->update($this->table);
        }
        return true;
    }

    /**
     * Reserve and complete in one step.
     *
     * Kept for callers that have already charged and simply need the
     * redemption recorded. Returns false when the customer's limit is already
     * taken, which is the answer the race used to be unable to give.
     */
    public function record_redemption($coupon_id, $user_id, $order_id, $discount_amount) {
        $coupon = is_object($coupon_id) ? $coupon_id : $this->db
            ->where('id', (int)$coupon_id)->get($this->table)->row();
        $res = $this->reserve_redemption($coupon ?: $coupon_id, $user_id);
        if (empty($res['ok'])) return false;
        $this->attach_redemption($res['id'], $order_id, $discount_amount);
        return true;
    }

    public function create(array $data) {
        $data['public_id'] = $this->new_public_id();
        $data['created_at'] = $this->now_utc();
        $data['updated_at'] = $this->now_utc();
        $this->db->insert($this->table, $data);
        return (int)$this->db->insert_id();
    }

    public function update_fields($id, array $fields) {
        $fields['updated_at'] = $this->now_utc();
        return $this->db->where('id', (int)$id)->update($this->table, $fields);
    }

    public function admin_search($limit = 50, $offset = 0) {
        return $this->db->order_by('created_at', 'DESC')->limit($limit, $offset)->get($this->table)->result();
    }

    public function admin_count() {
        return (int)$this->db->count_all($this->table);
    }
}
