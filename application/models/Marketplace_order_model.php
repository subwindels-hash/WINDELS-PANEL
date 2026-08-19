<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Marketplace orders bind a buyer to a platform-owned listing. There is no
 * seller side: the counterpart to every order is the platform itself, so the
 * model carries no seller id, fee split or payout reference.
 */
class Marketplace_order_model extends MY_Model {
    protected $table = 'marketplace_orders';

    public function create(array $data) {
        $this->db->insert($this->table, $data);
        return (int)$this->db->insert_id();
    }

    public function find_id($id) {
        return $this->db->where('id', (int)$id)->get($this->table)->row();
    }

    public function find_public($public_id) {
        return $this->db->where('public_id', $public_id)->get($this->table)->row();
    }

    public function find_by_transaction($transaction_id) {
        return $this->db->where('service_transaction_id', (int)$transaction_id)
            ->get($this->table)->row();
    }

    public function detail_public($public_id) {
        return $this->db
            ->select('marketplace_orders.*, marketplace_listings.title AS listing_title, marketplace_listings.public_id AS listing_public_id, users.username AS buyer_username', false)
            ->from($this->table)
            ->join('marketplace_listings', 'marketplace_listings.id = marketplace_orders.listing_id', 'left')
            ->join('users', 'users.id = marketplace_orders.buyer_id', 'left')
            ->where('marketplace_orders.public_id', $public_id)->get()->row();
    }

    /** Buyers only ever see orders they bought. */
    public function for_user($user_id, $limit = 25, $offset = 0) {
        return $this->db
            ->select($this->list_projection().', marketplace_listings.title AS listing_title', false)
            ->from($this->table)
            ->join('marketplace_listings', 'marketplace_listings.id = marketplace_orders.listing_id', 'left')
            ->where('marketplace_orders.buyer_id', (int)$user_id)
            ->order_by('marketplace_orders.created_at', 'DESC')
            ->limit($limit, $offset)->get()->result();
    }

    public function update_fields($id, array $fields) {
        $fields['updated_at'] = $this->now_utc();
        return $this->db->where('id', (int)$id)->update($this->table, $fields);
    }

    public function transition($id, $from, $to, array $fields = array()) {
        $fields['status'] = $to;
        $fields['updated_at'] = $this->now_utc();
        $this->db->where('id', (int)$id)->where('status', $from)
            ->update($this->table, $fields);
        return $this->db->affected_rows() === 1;
    }

    public function record_event($order_id, $actor_id, $event_type, $from, $to, $note = null) {
        $this->db->insert('marketplace_order_events', array(
            'order_id' => (int)$order_id,
            'actor_id' => $actor_id ? (int)$actor_id : null,
            'event_type' => substr(strtoupper($event_type), 0, 32),
            'from_status' => $from,
            'to_status' => $to,
            'note' => $note === null ? null : substr($note, 0, 500),
            'created_at' => $this->now_utc(),
        ));
        return (int)$this->db->insert_id();
    }

    public function events($order_id) {
        return $this->db->where('order_id', (int)$order_id)
            ->order_by('created_at', 'ASC')->get('marketplace_order_events')->result();
    }

    public function due_for_release($limit = 50) {
        return $this->db->select($this->list_projection(), false)
            ->where('status', 'DELIVERED')
            ->where('release_due_at IS NOT NULL', null, false)
            ->where('release_due_at <=', gmdate('Y-m-d H:i:s'))
            ->order_by('release_due_at', 'ASC')->limit((int)$limit)
            ->get($this->table)->result();
    }

    public function admin_search(array $filters = array(), $limit = 25, $offset = 0) {
        $this->admin_filters($filters);
        return $this->db
            ->select($this->list_projection().', marketplace_listings.title AS listing_title, users.username AS buyer_username', false)
            ->join('marketplace_listings', 'marketplace_listings.id = marketplace_orders.listing_id', 'left')
            ->join('users', 'users.id = marketplace_orders.buyer_id', 'left')
            ->order_by('marketplace_orders.created_at', 'DESC')
            ->limit($limit, $offset)->get()->result();
    }

    public function admin_count(array $filters = array()) {
        $this->admin_filters($filters);
        return (int)$this->db->count_all_results();
    }

    /**
     * List/worker projection deliberately excludes delivery_encrypted. Queue
     * pages and cron release do not need fulfilment ciphertext in memory.
     */
    private function list_projection() {
        $columns = array(
            'id', 'public_id', 'service_transaction_id', 'listing_id',
            'buyer_id', 'quantity', 'unit_price', 'gross_amount',
            'status', 'delivered_at',
            'release_due_at', 'released_at',
            'dispute_reason', 'disputed_at', 'resolved_at', 'resolved_by',
            'created_at', 'updated_at',
        );
        return implode(', ', array_map(function ($column) {
            return 'marketplace_orders.'.$column;
        }, $columns));
    }

    private function admin_filters(array $filters) {
        $this->db->from($this->table);
        if (!empty($filters['status'])) {
            $this->db->where('status', strtoupper($filters['status']));
        }
        if (!empty($filters['search'])) {
            $this->db->group_start()
                ->like('public_id', trim($filters['search']))
                ->group_end();
        }
    }
}
