<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Wallet_model extends MY_Model {
    protected $table = 'wallets';

    /** Wallet for a user, creating it lazily on first access. */
    public function for_user($user_id){
        $row = $this->db->where('user_id',$user_id)->get($this->table)->row();
        if ($row) return $row;
        $this->db->insert($this->table, array(
            'public_id'=>$this->new_public_id(), 'user_id'=>$user_id,
            'balance'=>'0.00000000', 'currency'=>marvy_base_currency(),
            'created_at'=>$this->now_utc(), 'updated_at'=>$this->now_utc(),
        ));
        return $this->find_by_id($this->db->insert_id());
    }

    /**
     * Choose the currency an empty, never-used wallet will hold.
     *
     * The one hard rule: a wallet that has ever moved money cannot change
     * currency. Rewriting the label on a wallet with history re-denominates
     * every balance and movement on it — the exact silent-devaluation failure
     * migration 011 documents for the base currency, and this panel does not
     * do it twice. "Never used" means balance zero AND no wallet_transactions
     * row: counters alone are not trusted (they were wrong once before —
     * migration 027 had to backfill them).
     */
    public function choose_currency($user_id, $code, $actor_id = null){
        $code = strtoupper(trim((string)$code));
        $base = marvy_base_currency();

        $currency_ok = false;
        if ($code === $base) {
            $currency_ok = true; // the base currency is always a valid holding
        } else {
            $row = $this->db->where('code', $code)->get('currencies')->row();
            $currency_ok = ($row && (int)$row->is_active === 1);
        }
        if (!$currency_ok) {
            return array('ok'=>false, 'code'=>'UNKNOWN_CURRENCY',
                'error'=>'That currency is not available to hold.');
        }

        $wallet = $this->db->where('user_id', (int)$user_id)->get($this->table)->row();
        if (!$wallet) {
            return array('ok'=>false, 'code'=>'NO_WALLET', 'error'=>'This customer has no wallet yet.');
        }
        if (!$this->is_virgin($wallet)) {
            return array('ok'=>false, 'code'=>'NOT_EMPTY',
                'error'=>'A wallet that has already held money cannot change currency.');
        }
        if (strtoupper((string)$wallet->currency) === $code) {
            return array('ok'=>true, 'unchanged'=>true, 'wallet'=>$wallet);
        }

        $this->db->where('id', $wallet->id)->update($this->table, array(
            'currency' => $code,
            'updated_at' => $this->now_utc(),
        ));

        // The choice is an auditable money decision, not a preference: it
        // decides what every future figure on this account means.
        try {
            $ci = function_exists('get_instance') ? @get_instance() : null;
            if ($ci) {
                if (!isset($ci->Audit_log_model)) $ci->load->model('Audit_log_model');
                $ci->Audit_log_model->record(
                    $actor_id ?: null, 'wallet.currency_chosen', 'wallets', (string)$wallet->public_id,
                    array('currency' => $wallet->currency), array('currency' => $code),
                    isset($ci->input) ? $ci->input->ip_address() : null,
                    isset($ci->input) ? $ci->input->user_agent() : null
                );
            }
        } catch (Throwable $e) { log_message('error', 'wallet currency audit failed'); }
        return array('ok'=>true, 'wallet'=>$this->find_by_id($wallet->id));
    }

    /** May this wallet still choose a currency? (empty AND no movements) */
    public function is_virgin($wallet){
        if (!$wallet) return false;
        if (bccomp((string)$wallet->balance, '0', 8) !== 0) return false;
        $n = $this->db->where('wallet_id', $wallet->id)->count_all_results('wallet_transactions');
        return (int)$n === 0;
    }

    /**
     * Float held across every wallet, for the admin wallets view.
     *
     * This is a liability, not revenue: money customers have paid in and not
     * yet spent. Reconciliation starts by comparing it to the bank.
     *
     * Grouped by currency since module 37: a naira figure and a dollar figure
     * added together is a number that means nothing, so each currency is
     * reported as itself and the caller renders one line per currency (the
     * base currency first).
     */
    public function totals(){
        $rows = $this->db
            ->select('currency, COALESCE(SUM(balance),0) AS held,
                      COALESCE(SUM(total_deposited),0) AS deposited,
                      COALESCE(SUM(total_spent),0) AS spent,
                      COUNT(*) AS wallets', false)
            ->group_by('currency')
            ->order_by('currency', 'ASC')
            ->get($this->table)->result();

        $out = array('by_currency' => array(), 'held' => '0', 'deposited' => '0',
                     'spent' => '0', 'wallets' => 0);
        $base = marvy_base_currency();
        foreach ($rows as $r) {
            $cur = strtoupper((string)$r->currency);
            $out['by_currency'][$cur] = array(
                'held' => (string)$r->held, 'deposited' => (string)$r->deposited,
                'spent' => (string)$r->spent, 'wallets' => (int)$r->wallets,
            );
            $out['wallets'] += (int)$r->wallets;
            if ($cur === $base) {
                $out['held'] = (string)$r->held;
                $out['deposited'] = (string)$r->deposited;
                $out['spent'] = (string)$r->spent;
            }
        }
        return $out;
    }
}
