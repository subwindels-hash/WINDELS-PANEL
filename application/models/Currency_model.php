<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Currency_model extends MY_Model {
    protected $table = 'currencies';

    protected $primary_key = 'code';
    public function active(){ return $this->db->where('is_active',1)->order_by('is_base','DESC')->order_by('code','ASC')->get($this->table)->result(); }
    public function base(){ return $this->db->where('is_base',1)->get($this->table)->row(); }

    /** Every configured currency, admin view (active or not). */
    public function all_rows(){
        return $this->db->select('*, (SELECT username FROM users WHERE users.id = currencies.rate_updated_by) AS rate_updated_by_username', false)
            ->order_by('is_base', 'DESC')->order_by('code', 'ASC')->get($this->table)->result();
    }

    public function find($code){
        return $this->db->select('*, (SELECT username FROM users WHERE users.id = currencies.rate_updated_by) AS rate_updated_by_username', false)
            ->where('code', strtoupper($code))->get($this->table)->row();
    }

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
        return true;
    }

    /** Convert an amount denominated in the base currency into `code`. */
    public function convert_from_base($amount, $code){
        $target = $this->db->where('code', strtoupper($code))->get($this->table)->row();
        if (!$target) return $amount;
        return bcmul((string)$amount, (string)$target->exchange_rate, 8);
    }
}
