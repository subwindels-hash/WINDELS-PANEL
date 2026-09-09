<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Referral_campaign_model — advertising and promotional codes.
 *
 * A campaign is a referral code with no owner: nobody earns a commission, but
 * every click, signup and qualification is attributed to it so the operator can
 * tell which advert actually brought users.
 */
class Referral_campaign_model extends MY_Model {

    protected $table = 'referral_campaigns';

    public function by_code($code) {
        return $this->db->where('code', strtoupper(trim((string)$code)))->get($this->table)->row();
    }

    public function create(array $data) {
        $data['public_id']  = $this->new_public_id();
        $data['code']       = strtoupper(trim((string)$data['code']));
        $data['created_at'] = $this->now_utc();
        $data['updated_at'] = $this->now_utc();
        $this->db->insert($this->table, $data);
        return (int)$this->db->insert_id();
    }

    public function update_row($id, array $data) {
        $data['updated_at'] = $this->now_utc();
        if (isset($data['code'])) $data['code'] = strtoupper(trim((string)$data['code']));
        return $this->db->where('id', (int)$id)->update($this->table, $data);
    }

    public function bump($id, $column) {
        $allowed = array('total_visits', 'total_signups', 'total_qualified');
        if (!in_array($column, $allowed, true)) return false;
        $this->db->set($column, $column.' + 1', false)
                 ->where('id', (int)$id)->update($this->table);
        return true;
    }

    /**
     * Add to the campaign's spend.
     *
     * Done as a SQL expression so concurrent rewards cannot both read the same
     * starting value and under-report what the campaign has cost.
     */
    public function add_spend($id, $amount) {
        $this->db->set('spent', 'spent + '.$this->db->escape((string)$amount), false)
                 ->where('id', (int)$id)->update($this->table);
        return true;
    }

    public function active() {
        $now = $this->now_utc();
        return $this->db->where('status', 'ACTIVE')
                        ->group_start()->where('starts_at IS NULL', null, false)
                            ->or_where('starts_at <=', $now)->group_end()
                        ->group_start()->where('ends_at IS NULL', null, false)
                            ->or_where('ends_at >=', $now)->group_end()
                        ->order_by('id', 'DESC')->get($this->table)->result();
    }

    public function all_rows($limit = 100, $offset = 0) {
        return $this->db->order_by('id', 'DESC')
                        ->limit(max(1, min(200, (int)$limit)), max(0, (int)$offset))
                        ->get($this->table)->result();
    }

    /**
     * Performance per campaign, including conversion rate and cost per user.
     *
     * Computed on read rather than stored: a stored rate goes stale the moment
     * one more person clicks.
     */
    public function performance() {
        $rows = $this->all_rows(200);
        foreach ($rows as $row) {
            $visits = max(0, (int)$row->total_visits);
            $row->conversion_rate = $visits > 0
                ? round(((int)$row->total_signups / $visits) * 100, 2) : 0.0;
            $row->qualify_rate = (int)$row->total_signups > 0
                ? round(((int)$row->total_qualified / (int)$row->total_signups) * 100, 2) : 0.0;
            $row->cost_per_signup = ($row->cost !== null && (int)$row->total_signups > 0)
                ? number_format((float)$row->cost / (int)$row->total_signups, 2, '.', '') : null;
        }
        return $rows;
    }
}
