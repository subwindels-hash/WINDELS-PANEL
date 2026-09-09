<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Fundsvera_checkout_model — one row per secured-checkout attempt.
 *
 * This is the record the webhook is validated against. It holds the amount we
 * quoted at initiation, which is what makes "verify the amount" a real check
 * rather than trusting whatever the callback claims was paid.
 */
class Fundsvera_checkout_model extends MY_Model {

    protected $table = 'fundsvera_checkouts';

    /** Record a checkout we have just opened with the provider. */
    public function open(array $data) {
        $data['public_id']  = $this->new_public_id();
        $data['status']     = 'PENDING';
        $data['created_at'] = $this->now_utc();
        $data['updated_at'] = $this->now_utc();

        $this->db->insert($this->table, $data);
        return (int)$this->db->insert_id();
    }

    public function by_request_id($request_id) {
        return $this->db->where('request_id', $request_id)->get($this->table)->row();
    }

    public function by_trx_ref($trx_ref) {
        return $this->db->where('trx_ref', $trx_ref)->get($this->table)->row();
    }

    public function for_transaction($payment_transaction_id) {
        return $this->db->where('payment_transaction_id', (int)$payment_transaction_id)
                        ->order_by('id', 'DESC')->limit(1)
                        ->get($this->table)->row();
    }

    public function for_user($user_id, $limit = 25) {
        return $this->db->where('user_id', (int)$user_id)
                        ->order_by('id', 'DESC')->limit((int)$limit)
                        ->get($this->table)->result();
    }

    /**
     * Write what the webhook reported.
     *
     * Deliberately not guarded against being called twice: it is idempotent by
     * shape (it sets absolute values, never increments), and the caller that
     * matters — PaymentService — is already de-duplicated on the webhook event
     * id before this is reached.
     */
    public function record_result($id, array $data) {
        $data['updated_at'] = $this->now_utc();
        return $this->db->where('id', (int)$id)->update($this->table, $data);
    }

    /**
     * Checkouts whose 30-minute window has closed without payment.
     *
     * Expiring them is cosmetic for the customer but load-bearing for support:
     * a PENDING row from three weeks ago is noise that hides the one from ten
     * minutes ago that genuinely needs looking at.
     */
    public function expire_stale($limit = 200) {
        $rows = $this->db->where('status', 'PENDING')
                         ->where('expires_at IS NOT NULL', null, false)
                         ->where('expires_at <', $this->now_utc())
                         ->limit((int)$limit)
                         ->get($this->table)->result();
        foreach ($rows as $row) {
            $this->db->where('id', $row->id)->where('status', 'PENDING')
                     ->update($this->table, array('status' => 'EXPIRED', 'updated_at' => $this->now_utc()));
        }
        return count($rows);
    }

    /** Bounded admin grid. */
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

    private function apply_filters(array $f) {
        if (!empty($f['status'])) $this->db->where('status', $f['status']);
        if (!empty($f['user_id'])) $this->db->where('user_id', (int)$f['user_id']);
        if (!empty($f['reference'])) {
            $this->db->group_start()
                     ->where('request_id', $f['reference'])
                     ->or_where('trx_ref', $f['reference'])
                     ->group_end();
        }
    }
}
