<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Service_transaction_model — the universal transaction record (§19).
 *
 * One row per service purchase in any domain. Money columns are authoritative
 * here; domain tables (vtu_transactions, ...) hold only their own detail.
 */
class Service_transaction_model extends MY_Model {
    protected $table = 'service_transactions';

    public function create(array $data){
        $this->db->insert($this->table, $data);
        return (int)$this->db->insert_id();
    }

    public function update_fields($id, array $fields){
        $fields['updated_at'] = $this->now_utc();
        return $this->db->where('id',$id)->update($this->table, $fields);
    }

    public function find_by_idempotency_key($key){
        if (!$key) return null;
        return $this->db->where('idempotency_key',$key)->get($this->table)->row();
    }

    public function find_public_for_user($public_id, $user_id){
        return $this->db->where('public_id',$public_id)->where('user_id',$user_id)
                        ->get($this->table)->row();
    }

    /**
     * Unified history (§20). Filters are optional and combine.
     *
     * @param array $filters domain, type, status, from, to, search
     */
    public function history_for_user($user_id, array $filters = array(), $limit = 25, $offset = 0){
        $this->apply_history_filters($user_id, $filters);
        return $this->db->order_by('created_at','DESC')
                        ->limit($limit, $offset)
                        ->get($this->table)->result();
    }

    public function count_history_for_user($user_id, array $filters = array()){
        $this->apply_history_filters($user_id, $filters);
        return (int)$this->db->count_all_results($this->table);
    }

    private function apply_history_filters($user_id, array $f){
        $this->db->where('user_id',$user_id);
        if (!empty($f['domain'])) $this->db->where('service_domain',$f['domain']);
        if (!empty($f['type']))   $this->db->where('service_type',$f['type']);
        if (!empty($f['status'])) $this->db->where('status',$f['status']);
        if (!empty($f['from']))   $this->db->where('created_at >=',$f['from']);
        if (!empty($f['to']))     $this->db->where('created_at <=',$f['to']);
        if (!empty($f['reference'])) $this->db->where('provider_reference',$f['reference']);
    }

    /* ------------------------- admin queries ------------------------- */

    /**
     * The admin service-transaction queue. Deliberately unscoped, so it is
     * only ever reachable behind a permission check — the same contract as
     * Order_model::admin_search().
     *
     * @param array $filters domain|type|status|user_id|provider_id|source|from|to|search
     */
    public function admin_search(array $filters, $limit = 25, $offset = 0){
        $this->admin_filters($filters);
        $this->admin_projection($filters);
        return $this->db
            ->order_by('service_transactions.created_at', 'DESC')
            ->limit($limit, $offset)
            ->get()->result();
    }

    /**
     * The columns and joins one admin queue needs.
     *
     * Each domain keeps its detail in its own table, so the projection is
     * chosen by domain rather than joining every domain table on every query —
     * a LEFT JOIN per domain would grow with the catalogue and drag the queue
     * down for no benefit, and two domain tables both carrying `status` would
     * make the result ambiguous. An unfiltered search gets the universal
     * columns only, which is all a cross-domain view can honestly show.
     */
    private function admin_projection(array $filters){
        $domain = strtoupper((string)($filters['domain'] ?? ''));

        $columns = 'service_transactions.*, users.username, users.email,
                    providers.name AS provider_name';
        $this->db->join('users', 'users.id = service_transactions.user_id', 'left')
                 ->join('providers', 'providers.id = service_transactions.provider_id', 'left');

        if ($domain === 'VTU') {
            $columns .= ', vtu_transactions.recipient, vtu_transactions.recipient_name,
                          vtu_transactions.token, vtu_transactions.units';
            $this->db->join('vtu_transactions',
                'vtu_transactions.service_transaction_id = service_transactions.id', 'left');
        } elseif ($domain === 'NUMBER') {
            $columns .= ', virtual_numbers.msisdn, virtual_numbers.operator,
                          virtual_numbers.status AS reservation_status,
                          virtual_numbers.expires_at, virtual_numbers.sms_count,
                          virtual_numbers.last_code';
            $this->db->join('virtual_numbers',
                'virtual_numbers.service_transaction_id = service_transactions.id', 'left');
        } elseif ($domain === 'IDENTITY') {
            // Only the masked tail and the outcome. identifier_hash is not
            // selected — it is a lookup key, not something a list should carry
            // around — and result_encrypted is never joined into a list query
            // at all: a queue of 25 rows has no business dragging 25 encrypted
            // identity records through the app, and the only legitimate way to
            // read one is IdentityService::reveal(), which audits the access.
            $columns .= ', identity_checks.id_type, identity_checks.identifier_last4,
                          identity_checks.status AS check_status,
                          identity_checks.reveal_count, identity_checks.purged_at';
            $this->db->join('identity_checks',
                'identity_checks.service_transaction_id = service_transactions.id', 'left');
        }

        $this->db->select($columns, false);
    }

    public function admin_count(array $filters){
        $this->admin_filters($filters);
        return (int)$this->db->count_all_results();
    }

    /** Shared WHERE builder so a list and its count can never disagree. */
    private function admin_filters(array $f){
        $this->db->from($this->table);
        if (!empty($f['domain']))      $this->db->where('service_transactions.service_domain', $f['domain']);
        if (!empty($f['type']))        $this->db->where('service_transactions.service_type', $f['type']);
        if (!empty($f['status']))      $this->db->where('service_transactions.status', $f['status']);
        if (!empty($f['user_id']))     $this->db->where('service_transactions.user_id', (int)$f['user_id']);
        if (!empty($f['provider_id'])) $this->db->where('service_transactions.provider_id', (int)$f['provider_id']);
        if (!empty($f['source']))      $this->db->where('service_transactions.source', $f['source']);
        if (!empty($f['from']))        $this->db->where('service_transactions.created_at >=', $f['from']);
        if (!empty($f['to']))          $this->db->where('service_transactions.created_at <=', $f['to']);
        if (!empty($f['search'])) {
            $term = trim((string)$f['search']);
            $this->db->group_start()
                ->like('service_transactions.public_id', $term)
                ->or_like('service_transactions.provider_reference', $term)
                ->group_end();
        }
    }

    /**
     * Count per status within one domain, for the queue header cards.
     *
     * Scoped to a domain because the admin screens are per-domain: a VTU queue
     * showing SMM counts would be actively misleading.
     */
    public function status_counts($domain = null){
        $this->db->select('status, COUNT(*) AS c', false)->from($this->table);
        if ($domain) $this->db->where('service_domain', $domain);
        $rows = $this->db->group_by('status')->get()->result();
        $out = array();
        foreach ($rows as $r) $out[$r->status] = (int)$r->c;
        return $out;
    }

    /** One transaction joined with the context an admin needs, by public id. */
    public function admin_find($public_id, $domain = null){
        $this->db
            ->select('service_transactions.*, users.username, users.email,
                      users.public_id AS user_public_id,
                      providers.name AS provider_name, providers.api_type AS provider_api_type', false)
            ->from($this->table)
            ->join('users', 'users.id = service_transactions.user_id', 'left')
            ->join('providers', 'providers.id = service_transactions.provider_id', 'left')
            ->where('service_transactions.public_id', $public_id);
        if ($domain) $this->db->where('service_transactions.service_domain', $domain);
        return $this->db->get()->row();
    }

    /** Transactions a status worker should re-check. */
    public function pending_provider_sync($domain, $limit = 100){
        return $this->db->where('service_domain',$domain)
                        ->where('status','PROCESSING')
                        ->where('provider_reference IS NOT NULL', null, false)
                        ->order_by('created_at','ASC')->limit($limit)
                        ->get($this->table)->result();
    }
}
