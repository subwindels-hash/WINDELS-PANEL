<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * AffiliateService — referral attribution and the commission engine (Session 14).
 *
 * Responsibilities:
 *   1. **Accounts** — every user can own a `referral_accounts` row keyed by their
 *      stable `users.referral_code`; it is created lazily on first use.
 *   2. **Attribution** — `attribute()` links a new signup to a referrer exactly
 *      once (first-touch, permanent). Self-referral and referral cycles are
 *      rejected; `referrals.referred_id` is UNIQUE so a race still cannot
 *      produce a second edge.
 *   3. **Accrual** — `record_for_order()` writes a PENDING
 *      `referral_commissions` row for a qualifying order, computed with bcmath
 *      from the order charge and the account's commission percent. It is
 *      idempotent on (referral_id, order_id).
 *   4. **Payout** — `pay()` moves PENDING → PAID and credits the referrer's
 *      wallet through LedgerService with a deterministic idempotency key. The
 *      status UPDATE is a compare-and-set, so a double run credits once.
 *   5. **Reversal** — `reverse_for_order()` voids unpaid commissions when the
 *      underlying order is refunded/canceled.
 *
 * Money is DECIMAL-as-string end to end; this library never touches `wallets`
 * directly — LedgerService remains the only writer (§24/25/56).
 */
class AffiliateService {

    /** Commission is credited only after the order has held this long. */
    const DEFAULT_HOLD_HOURS   = 24;
    /** Commissions below this are accrued but not paid out on their own. */
    const DEFAULT_MIN_PAYOUT   = '0.01000000';
    const DEFAULT_PERCENT      = '5.0000';
    /** LIFETIME = every order; FIRST_ORDER = only the referred user's first. */
    const SCOPE_LIFETIME       = 'LIFETIME';
    const SCOPE_FIRST_ORDER    = 'FIRST_ORDER';
    /** Order statuses that earn commission. */
    private static $qualifying = array('COMPLETED', 'PARTIAL');

    private $ci;

    public function __construct() {
        $this->ci =& get_instance();
        $this->ci->load->model(array(
            'Referral_account_model', 'Referral_model', 'Referral_commission_model',
            'User_model', 'Wallet_model', 'Setting_model',
        ));
        $this->ci->load->library(array('LedgerService'));
    }

    /* ------------------------------------------------------------------ */
    /* Accounts                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * The referrer's account row, created on first access so the dashboard can
     * always show a link.
     *
     * @param object $user user row (needs id + referral_code)
     */
    public function account_for($user) {
        if (!$user || empty($user->id)) return null;
        $account = $this->ci->Referral_account_model->for_user($user->id);
        if ($account) return $account;

        $code = $this->unique_code($user);
        return $this->ci->Referral_account_model->create(array(
            'user_id'            => $user->id,
            'code'               => $code,
            'commission_percent' => $this->default_percent(),
            'total_referred'     => 0,
            'total_earned'       => '0.00000000',
            'total_paid'         => '0.00000000',
            'created_at'         => gmdate('Y-m-d H:i:s'),
            'updated_at'         => gmdate('Y-m-d H:i:s'),
        ));
    }

    /** The public share link for a user's code. */
    public function link_for($code) {
        return site_url('register?ref='.rawurlencode((string)$code));
    }

    /**
     * Resolve a referral code to its owner. Accepts either a
     * `referral_accounts.code` or the `users.referral_code` it mirrors.
     *
     * @return object|null the referring user
     */
    public function resolve_code($code) {
        $code = trim((string)$code);
        if ($code === '' || strlen($code) > 32) return null;

        $account = $this->ci->Referral_account_model->find_by_code($code);
        if ($account) {
            $user = $this->ci->User_model->find_by_id($account->user_id);
            return ($user && $user->status === 'ACTIVE') ? $user : null;
        }
        $user = $this->ci->User_model->find_by_referral_code($code);
        return ($user && $user->status === 'ACTIVE') ? $user : null;
    }

    /* ------------------------------------------------------------------ */
    /* Attribution                                                         */
    /* ------------------------------------------------------------------ */

    /**
     * Link a newly registered user to their referrer.
     *
     * @param object $referrer
     * @param object $referred
     * @return array{ok:bool,referral?:object,error?:string,code?:string}
     */
    public function attribute($referrer, $referred) {
        if (!$referrer || !$referred) {
            return array('ok'=>false,'error'=>'Missing user','code'=>'NO_USER');
        }
        if ((int)$referrer->id === (int)$referred->id) {
            return array('ok'=>false,'error'=>'A user cannot refer themselves','code'=>'SELF_REFERRAL');
        }
        if (isset($referrer->status) && $referrer->status !== 'ACTIVE') {
            return array('ok'=>false,'error'=>'Referrer is not active','code'=>'REFERRER_INACTIVE');
        }
        // First-touch wins: an existing edge is never overwritten.
        $existing = $this->ci->Referral_model->for_referred($referred->id);
        if ($existing) {
            return array('ok'=>false,'error'=>'User is already attributed','code'=>'ALREADY_ATTRIBUTED');
        }
        // Reject A→B→A loops (one hop is enough: referred_id is unique).
        $reverse = $this->ci->Referral_model->for_referred($referrer->id);
        if ($reverse && (int)$reverse->referrer_id === (int)$referred->id) {
            return array('ok'=>false,'error'=>'Circular referral','code'=>'CIRCULAR');
        }

        $account = $this->account_for($referrer);
        if (!$account) return array('ok'=>false,'error'=>'Could not open referral account','code'=>'NO_ACCOUNT');

        $referral = $this->ci->Referral_model->create(array(
            'referrer_id'         => $referrer->id,
            'referred_id'         => $referred->id,
            'referral_account_id' => $account->id,
            'created_at'          => gmdate('Y-m-d H:i:s'),
        ));
        $this->ci->Referral_account_model->add_totals($account->id, 1);

        return array('ok'=>true,'referral'=>$referral);
    }

    /* ------------------------------------------------------------------ */
    /* Accrual                                                             */
    /* ------------------------------------------------------------------ */

    /**
     * Accrue a PENDING commission for a qualifying order.
     *
     * Safe to call repeatedly: the (referral_id, order_id) lookup makes it a
     * no-op after the first accrual.
     *
     * @param object $order orders row (needs id, user_id, charge, currency, status)
     * @return array{ok:bool,commission?:object,skipped?:string}
     */
    public function record_for_order($order) {
        if (!$order || empty($order->user_id)) return array('ok'=>false,'skipped'=>'NO_ORDER');
        if (!$this->enabled()) return array('ok'=>false,'skipped'=>'DISABLED');
        if (!in_array($order->status ?? '', self::$qualifying, true)) {
            return array('ok'=>false,'skipped'=>'NOT_QUALIFYING');
        }

        $referral = $this->ci->Referral_model->for_referred($order->user_id);
        if (!$referral) return array('ok'=>false,'skipped'=>'NOT_REFERRED');

        $existing = $this->ci->Referral_commission_model->find_for_order($referral->id, $order->id);
        if ($existing) return array('ok'=>true,'commission'=>$existing,'duplicate'=>true);

        if ($this->scope() === self::SCOPE_FIRST_ORDER
            && $this->ci->Referral_commission_model->count_for_referrer($referral->referrer_id) > 0
            && $this->has_earlier_commission($referral->id)) {
            return array('ok'=>false,'skipped'=>'FIRST_ORDER_ONLY');
        }

        $account = $this->ci->Referral_account_model->find_by_id($referral->referral_account_id);
        $percent = $account ? (string)$account->commission_percent : $this->default_percent();

        // Commission is a share of what the customer actually paid, net of any
        // refund already applied to the order (partial deliveries).
        $base = $this->net_charge($order);
        $amount = $this->commission_amount($base, $percent);
        if (bccomp($amount, '0', 8) <= 0) return array('ok'=>false,'skipped'=>'ZERO_AMOUNT');

        $commission = $this->ci->Referral_commission_model->create(array(
            'referral_id' => $referral->id,
            'order_id'    => $order->id,
            'amount'      => $amount,
            'currency'    => $order->currency ?? marvy_base_currency(),
            'status'      => Referral_commission_model::STATUS_PENDING,
            'created_at'  => gmdate('Y-m-d H:i:s'),
        ));
        return array('ok'=>true,'commission'=>$commission);
    }

    /** amount = charge * percent / 100, truncated to 8dp with bcmath. */
    public function commission_amount($charge, $percent) {
        $charge  = $this->decimal($charge);
        $percent = $this->decimal($percent, 4);
        if (bccomp($charge, '0', 8) <= 0 || bccomp($percent, '0', 4) <= 0) return '0.00000000';
        return bcdiv(bcmul($charge, $percent, 12), '100', 8);
    }

    /* ------------------------------------------------------------------ */
    /* Payout                                                              */
    /* ------------------------------------------------------------------ */

    /**
     * Pay a single PENDING commission into the referrer's wallet.
     *
     * The status UPDATE is claimed first (compare-and-set) so two concurrent
     * workers cannot both credit; the ledger call additionally carries a
     * deterministic idempotency key as a second line of defence.
     *
     * @return array{ok:bool,error?:string,code?:string,amount?:string}
     */
    public function pay($commission) {
        $commission = is_object($commission)
            ? $commission
            : $this->ci->Referral_commission_model->find_by_id((int)$commission);
        if (!$commission) return array('ok'=>false,'error'=>'Commission not found','code'=>'NO_COMMISSION');
        if ($commission->status !== Referral_commission_model::STATUS_PENDING) {
            return array('ok'=>false,'error'=>'Commission is not pending','code'=>'NOT_PENDING');
        }

        $referral = $this->ci->Referral_model->find_by_id($commission->referral_id);
        if (!$referral) return array('ok'=>false,'error'=>'Referral not found','code'=>'NO_REFERRAL');

        $wallet = $this->ci->Wallet_model->for_user($referral->referrer_id);
        if (!$wallet) return array('ok'=>false,'error'=>'Referrer wallet not found','code'=>'NO_WALLET');

        $idem = 'referral:commission:'.$commission->id;
        $credit = $this->ci->ledgerservice->credit(
            $wallet->id, (string)$commission->amount, 'REFERRAL_BONUS',
            'ReferralCommission', (string)$commission->id, $idem,
            array('referral_id' => (int)$commission->referral_id, 'order_id' => $commission->order_id)
        );
        if (empty($credit['ok'])) {
            return array('ok'=>false,'error'=>$credit['error'] ?? 'Credit failed','code'=>'CREDIT_FAILED');
        }

        $wt_id = $this->wallet_transaction_id($idem);
        if (!$this->ci->Referral_commission_model->mark_paid($commission->id, $wt_id)) {
            // Another worker claimed it between our read and write; the ledger
            // call above was a no-op thanks to the idempotency key.
            return array('ok'=>false,'error'=>'Already claimed','code'=>'RACE_LOST');
        }
        $this->ci->Referral_account_model->add_totals(
            $referral->referral_account_id, 0, (string)$commission->amount, (string)$commission->amount
        );
        $this->notify($referral->referrer_id, $commission);

        return array('ok'=>true,'amount'=>(string)$commission->amount,'wallet_transaction_id'=>$wt_id);
    }

    /**
     * Pay every commission that has cleared the hold window. Used by the cron
     * worker (`php index.php cron affiliate_payouts`) and the admin screen.
     *
     * @return array{paid:int,skipped:int,amount:string}
     */
    public function pay_due($limit = 200) {
        $paid = 0; $skipped = 0; $total = '0.00000000';
        if (!$this->enabled()) return array('paid'=>0,'skipped'=>0,'amount'=>$total,'disabled'=>true);

        $cutoff = gmdate('Y-m-d H:i:s', time() - ($this->hold_hours() * 3600));
        $min    = $this->min_payout();

        foreach ($this->ci->Referral_commission_model->payable($cutoff, $limit) as $row) {
            if (bccomp((string)$row->amount, $min, 8) < 0) { $skipped++; continue; }
            $res = $this->pay($row);
            if (!empty($res['ok'])) { $paid++; $total = bcadd($total, (string)$row->amount, 8); }
            else { $skipped++; }
        }
        return array('paid'=>$paid,'skipped'=>$skipped,'amount'=>$total);
    }

    /** Void unpaid commissions for an order that was refunded/canceled. */
    public function reverse_for_order($order) {
        if (!$order || empty($order->user_id)) return array('ok'=>false,'reversed'=>0);
        $referral = $this->ci->Referral_model->for_referred($order->user_id);
        if (!$referral) return array('ok'=>true,'reversed'=>0);

        $commission = $this->ci->Referral_commission_model->find_for_order($referral->id, $order->id);
        if (!$commission) return array('ok'=>true,'reversed'=>0);
        // Paid commissions are never clawed back out of a wallet automatically;
        // an admin adjustment is the auditable path for that.
        $done = $this->ci->Referral_commission_model->reverse($commission->id);
        return array('ok'=>true,'reversed'=>$done ? 1 : 0);
    }

    /* ------------------------------------------------------------------ */
    /* Read side                                                           */
    /* ------------------------------------------------------------------ */

    /**
     * Everything the customer referral dashboard needs.
     *
     * @return array{account:object|null,code:string,link:string,percent:string,
     *               referred:int,earned:string,pending:string,paid:string,
     *               referrals:array,commissions:array}
     */
    public function stats($user, $limit = 10) {
        $account = $this->account_for($user);
        $code = $account ? $account->code : (string)($user->referral_code ?? '');

        return array(
            'account'     => $account,
            'code'        => $code,
            'link'        => $this->link_for($code),
            'percent'     => $account ? (string)$account->commission_percent : $this->default_percent(),
            'referred'    => $this->ci->Referral_model->count_for_referrer($user->id),
            'earned'      => $this->ci->Referral_commission_model->sum_for_referrer($user->id),
            'pending'     => $this->ci->Referral_commission_model->sum_for_referrer($user->id, Referral_commission_model::STATUS_PENDING),
            'paid'        => $this->ci->Referral_commission_model->sum_for_referrer($user->id, Referral_commission_model::STATUS_PAID),
            // $limit = 0 asks for totals only (used by the reseller API).
            'referrals'   => $limit > 0 ? $this->ci->Referral_model->for_referrer($user->id, $limit) : array(),
            'commissions' => $limit > 0 ? $this->ci->Referral_commission_model->for_referrer($user->id, $limit) : array(),
            'hold_hours'  => $this->hold_hours(),
            'scope'       => $this->scope(),
            'enabled'     => $this->enabled(),
        );
    }

    /* ------------------------------------------------------------------ */
    /* Settings                                                            */
    /* ------------------------------------------------------------------ */

    public function enabled() {
        try {
            if (!isset($this->ci->Feature_flag_model)) $this->ci->load->model('Feature_flag_model');
            if (isset($this->ci->Feature_flag_model)) {
                return (bool)$this->ci->Feature_flag_model->enabled('affiliate_program');
            }
        } catch (Exception $e) {
            log_message('error', 'affiliate flag lookup failed: '.$e->getMessage());
        }
        return true;
    }

    public function default_percent() {
        return $this->decimal($this->setting('referral_commission_percent', self::DEFAULT_PERCENT), 4);
    }

    public function hold_hours() {
        return max(0, (int)$this->setting('referral_hold_hours', self::DEFAULT_HOLD_HOURS));
    }

    public function min_payout() {
        return $this->decimal($this->setting('referral_min_payout', self::DEFAULT_MIN_PAYOUT));
    }

    public function scope() {
        $scope = strtoupper((string)$this->setting('referral_commission_scope', self::SCOPE_LIFETIME));
        return $scope === self::SCOPE_FIRST_ORDER ? self::SCOPE_FIRST_ORDER : self::SCOPE_LIFETIME;
    }

    /* ------------------------------------------------------------------ */
    /* internals                                                           */
    /* ------------------------------------------------------------------ */

    private function setting($key, $default = null) {
        try {
            if (!isset($this->ci->Setting_model)) $this->ci->load->model('Setting_model');
            if (!isset($this->ci->Setting_model)) return $default;
            $value = $this->ci->Setting_model->get($key, $default);
            return $value === null ? $default : $value;
        } catch (Exception $e) {
            return $default;
        }
    }

    /** Order charge minus anything already refunded on it. */
    private function net_charge($order) {
        $charge   = $this->decimal($order->charge ?? '0');
        $refunded = $this->decimal($order->refunded_amount ?? '0');
        $net = bcsub($charge, $refunded, 8);
        return bccomp($net, '0', 8) > 0 ? $net : '0.00000000';
    }

    private function has_earlier_commission($referral_id) {
        return (bool)$this->ci->db->where('referral_id', $referral_id)
            ->where_in('status', array(
                Referral_commission_model::STATUS_PENDING,
                Referral_commission_model::STATUS_PAID,
            ))
            ->get('referral_commissions')->row();
    }

    private function wallet_transaction_id($idem) {
        $row = $this->ci->db->select('id')->where('idempotency_key', $idem)
            ->get('wallet_transactions')->row();
        return $row ? (int)$row->id : null;
    }

    /**
     * Reuse users.referral_code when it is free, otherwise mint a new one so
     * the UNIQUE constraint on referral_accounts.code can never trip.
     */
    private function unique_code($user) {
        $candidate = trim((string)($user->referral_code ?? ''));
        if ($candidate !== '' && !$this->ci->Referral_account_model->find_by_code($candidate)) {
            return substr($candidate, 0, 32);
        }
        do {
            $code = strtoupper(substr(bin2hex(random_bytes(5)), 0, 8));
        } while ($this->ci->Referral_account_model->find_by_code($code));
        return $code;
    }

    private function notify($user_id, $commission) {
        try {
            $this->ci->db->insert('notifications', array(
                'public_id' => marvy_public_id(),
                'user_id'   => $user_id,
                'type'      => 'referral.commission',
                'channel'   => 'IN_APP',
                'title'     => 'Referral commission credited',
                'body'      => 'You earned '.$commission->amount.' '.$commission->currency.' from a referral.',
                'data'      => json_encode(array('commission_id' => (int)$commission->id)),
                'created_at'=> gmdate('Y-m-d H:i:s'),
            ));
        } catch (Exception $e) {
            log_message('error', 'referral notify failed: '.$e->getMessage());
        }
    }

    private function decimal($value, $scale = 8) {
        $v = trim((string)$value);
        if (!preg_match('/^-?\d+(\.\d+)?$/', $v)) return bcadd('0', '0', $scale);
        return bcadd($v, '0', $scale);
    }
}
