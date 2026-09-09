<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Currency_model extends MY_Model {
    protected $table = 'currencies';

    protected $primary_key = 'code';

    /** Per-request cache of code => row (see find()). */
    private static $memo = array();
    public function active(){ return $this->db->where('is_active',1)->order_by('is_base','DESC')->order_by('code','ASC')->get($this->table)->result(); }
    public function base(){ return $this->db->where('is_base',1)->get($this->table)->row(); }

    /** Every configured currency, admin view (active or not). */
    public function all_rows(){
        return $this->db->select('*, (SELECT username FROM users WHERE users.id = currencies.rate_updated_by) AS rate_updated_by_username', false)
            ->order_by('is_base', 'DESC')->order_by('code', 'ASC')->get($this->table)->result();
    }

    /**
     * One currency, memoised for the request.
     *
     * Every formatted money value on a page asks for a currency row, and this
     * used to issue a query — with a correlated subquery over `users` for a
     * column only the admin currencies screen displays — for each one. A
     * services catalogue with 24 prices on it made 24 of these; a 100-row
     * catalogue made hundreds. Nothing about the row can change inside one
     * request, so it is read once per code, and the admin-only column stays in
     * all_rows() where it is actually rendered.
     */
    public function find($code){
        $code = strtoupper((string)$code);
        if (array_key_exists($code, self::$memo)) return self::$memo[$code];
        return self::$memo[$code] = $this->db->where('code', $code)->get($this->table)->row();
    }

    /** Drop the per-request memo after a write, so the next read is truthful. */
    public static function forget(){ self::$memo = array(); }

    /** Enable/disable a currency for display. The base currency can never be disabled. */
    public function set_active($code, $active){
        $code = strtoupper($code);
        $row = $this->db->where('code', $code)->get($this->table)->row();
        if (!$row) return false;
        if ((int)$row->is_base === 1 && !$active) return false; // base currency is always active
        $this->db->where('code', $code)->update($this->table, array(
            'is_active' => $active ? 1 : 0,
            'updated_at' => $this->now_utc(),
        ));
        self::forget();
        return true;
    }

    /**
     * Manually set an exchange rate (units of `code` per 1 unit of the base
     * currency), recording who set it and when for the admin audit trail.
     */
    public function set_rate($code, $rate, $actor_id, $source = 'MANUAL', $effective_at = null){
        $code = strtoupper($code);
        $row = $this->db->where('code', $code)->get($this->table)->row();
        if (!$row) return false;
        if ((int)$row->is_base === 1) return false; // the base currency is pinned at 1.0 by definition

        $this->db->where('code', $code)->update($this->table, array(
            'exchange_rate'      => number_format((float)$rate, 8, '.', ''),
            'rate_source'        => mb_substr((string)$source, 0, 32) ?: 'MANUAL',
            'rate_updated_by'    => $actor_id ? (int)$actor_id : null,
            'rate_updated_at'    => $this->now_utc(),
            'rate_effective_at'  => $effective_at ?: $this->now_utc(),
            'updated_at'         => $this->now_utc(),
        ));
        self::forget();
        return true;
    }

    /** Convert an amount denominated in the base currency into `code`. */
    public function convert_from_base($amount, $code){
        $target = $this->db->where('code', strtoupper($code))->get($this->table)->row();
        if (!$target) return $amount;
        return bcmul((string)$amount, (string)$target->exchange_rate, 8);
    }
}
