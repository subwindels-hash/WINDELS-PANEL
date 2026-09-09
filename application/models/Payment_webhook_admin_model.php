<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Payment_webhook_admin_model — read access to stored gateway callbacks.
 *
 * Separate from Payment_webhook_model, which is the write path used during
 * request handling and is deliberately minimal. This is the support view: what
 * arrived, whether its signature verified, whether it was processed, and why
 * not when it was not.
 */
class Payment_webhook_admin_model extends MY_Model {

    protected $table = 'payment_webhooks';

    public function admin_search(array $filters = array(), $limit = 25, $offset = 0) {
        $this->apply_filters($filters);
        return $this->db->order_by('id', 'DESC')
                        ->limit(max(1, min(200, (int)$limit)), max(0, (int)$offset))
                        ->get($this->table)->result();
    }

    public function admin_count(array $filters = array()) {
        $this->apply_filters($filters);
        return (int)$this->db->count_all_results($this->table);
    }

    public function find($id) {
        return $this->db->where('id', (int)$id)->get($this->table)->row();
    }

    /**
     * Counts that tell an operator whether the integration is healthy.
     *
     * `unverified` is the one that matters: callbacks arriving with a signature
     * we cannot check mean money is not being credited, and that is invisible
     * unless it is counted.
     */
    public function health() {
        $row = $this->db->select(
            "COUNT(*) AS total,
             SUM(CASE WHEN signature_valid = 1 THEN 1 ELSE 0 END) AS verified,
             SUM(CASE WHEN signature_valid = 0 THEN 1 ELSE 0 END) AS rejected,
             SUM(CASE WHEN signature_valid IS NULL THEN 1 ELSE 0 END) AS unverified,
             SUM(CASE WHEN processed = 0 THEN 1 ELSE 0 END) AS unprocessed", false
        )->get($this->table)->row();

        return array(
            'total'       => (int)($row->total ?? 0),
            'verified'    => (int)($row->verified ?? 0),
            'rejected'    => (int)($row->rejected ?? 0),
            'unverified'  => (int)($row->unverified ?? 0),
            'unprocessed' => (int)($row->unprocessed ?? 0),
        );
    }

    private function apply_filters(array $f) {
        if (!empty($f['gateway'])) $this->db->where('gateway_type', $f['gateway']);
        if (isset($f['processed']) && $f['processed'] !== '') {
            $this->db->where('processed', (int)$f['processed']);
        }
        if (isset($f['signature']) && $f['signature'] !== '') {
            if ($f['signature'] === 'unverified') $this->db->where('signature_valid IS NULL', null, false);
            else $this->db->where('signature_valid', (int)$f['signature']);
        }
        if (!empty($f['search'])) {
            $this->db->group_start()
                     ->like('event_id', $f['search'])
                     ->or_like('event_type', $f['search'])
                     ->group_end();
        }
    }
}
