<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * DashboardStats — read-side aggregates for the customer dashboard overview.
 *
 * All money is summed in SQL using DECIMAL (never cast to float in PHP); the
 * returned strings are safe for bccomp/number_format. This library is read-only
 * and never mutates wallet/order state — that stays in LedgerService.
 */
class DashboardStats {

    private $ci;

    public function __construct() {
        $this->ci =& get_instance();
        $this->ci->load->model(array('Order_model', 'Wallet_model', 'Notification_model'));
    }

    /**
     * Full overview bundle for a user.
     *
     * @return array{
     *   wallet: object|null,
     *   totals: array{spent:string, deposited:string, orders:int, active:int, completed:int, pending:int},
     *   recent_orders: array,
     *   recent_transactions: array,
     *   unread_notifications: int,
     *   unread: array
     * }
     */
    public function overview($user_id) {
        $wallet = $this->ci->Wallet_model->for_user($user_id);

        return array(
            'wallet'              => $wallet,
            'totals'              => $this->totals($user_id),
            'recent_orders'       => $this->ci->Order_model->for_user($user_id, 5),
            'recent_transactions' => $this->recent_transactions($wallet->id ?? 0, 5),
            'unread_notifications'=> $this->unread_count($user_id),
            'unread'              => $this->ci->Notification_model->unread_for_user($user_id, 5),
        );
    }

    /**
     * Aggregate counts and sums for a user's orders.
     */
    public function totals($user_id) {
        $row = $this->ci->db
            ->select('COUNT(*) AS orders', false)
            ->select("COALESCE(SUM(CASE WHEN status = 'COMPLETED' THEN 1 ELSE 0 END),0) AS completed", false)
            ->select("COALESCE(SUM(CASE WHEN status IN ('PENDING','PROCESSING','IN_PROGRESS') THEN 1 ELSE 0 END),0) AS active", false)
            ->select("COALESCE(SUM(CASE WHEN status = 'PENDING' THEN 1 ELSE 0 END),0) AS pending", false)
            ->select("COALESCE(SUM(CASE WHEN status IN ('COMPLETED','PARTIAL') THEN charge ELSE 0 END),0) AS spent", false)
            ->where('user_id', $user_id)
            ->get('orders')->row();

        // Sum deposits via a join so we don't need to materialise a wallet.
        $dep = $this->ci->db
            ->select("COALESCE(SUM(wt.amount),0) AS total", false)
            ->from('wallet_transactions wt')
            ->join('wallets w', 'w.id = wt.wallet_id', 'inner')
            ->where('w.user_id', $user_id)
            ->where('wt.direction', 'CREDIT')
            ->where('wt.type', 'DEPOSIT')
            ->get()->row();
        $deposited = $dep ? (string)$dep->total : '0.00000000';

        return array(
            'orders'    => (int)($row->orders ?? 0),
            'completed' => (int)($row->completed ?? 0),
            'active'    => (int)($row->active ?? 0),
            'pending'   => (int)($row->pending ?? 0),
            'spent'     => (string)($row->spent ?? '0.00000000'),
            'deposited' => $deposited,
        );
    }

    /**
     * Recent wallet transactions, joining the order reference when present.
     */
    public function recent_transactions($wallet_id, $limit = 10) {
        if (!$wallet_id) return array();
        return $this->ci->db->where('wallet_id', $wallet_id)
            ->order_by('created_at', 'DESC')
            ->limit($limit)
            ->get('wallet_transactions')->result();
    }

    public function unread_count($user_id) {
        return (int)$this->ci->db->where(array('user_id' => $user_id, 'is_read' => 0))
            ->count_all_results('notifications');
    }

    /**
     * Human-readable label for a wallet transaction type/direction.
     */
    public static function transaction_label($tx) {
        $map = array(
            'DEPOSIT'        => 'Deposit',
            'ORDER_CHARGE'   => 'Order charge',
            'REFUND'         => 'Refund',
            'REFERRAL_BONUS' => 'Referral bonus',
            'ADJUSTMENT'     => 'Adjustment',
        );
        $type = $tx->type ?? '';
        return $map[$type] ?? ucwords(strtolower(str_replace('_', ' ', $type)));
    }

    /**
     * Tailwind badge class for an order status (kept here so views stay thin).
     */
    public static function status_badge($status) {
        $map = array(
            'PENDING'     => 'badge badge-default',
            'PROCESSING'  => 'badge badge-info',
            'IN_PROGRESS' => 'badge badge-info badge-dot',
            'COMPLETED'   => 'badge badge-success badge-dot',
            // Service transactions (VTU and every later domain) end in
            // SUCCESSFUL rather than COMPLETED.
            'SUCCESSFUL'  => 'badge badge-success badge-dot',
            'PARTIAL'     => 'badge badge-warning badge-dot',
            'CANCELED'    => 'badge badge-default',
            'CANCELLED'   => 'badge badge-default',
            'FAILED'      => 'badge badge-danger',
            'REFUNDED'    => 'badge badge-default',
        );
        return $map[$status] ?? 'badge badge-default';
    }
}
