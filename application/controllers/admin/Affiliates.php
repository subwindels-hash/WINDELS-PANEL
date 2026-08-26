<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Admin/Affiliates — affiliate overview, per-account commission rate and
 * manual payout runs (Session 14).
 *
 * Read requires `affiliates.view`; every mutation requires `affiliates.manage`,
 * is POST-only, CSRF-protected and audit-logged. Payouts go through
 * AffiliateService → LedgerService; this controller never writes to wallets.
 */
class Affiliates extends Admin_Controller {

    const PER_PAGE = 25;

    public function __construct() {
        parent::__construct();
        $this->require_perm('affiliates.view');
        $this->load->library(array('AffiliateService'));
        $this->load->model(array(
            'Referral_account_model', 'Referral_model', 'Referral_commission_model', 'Audit_log_model',
        ));
    }

    public function index() {
        $page  = max(1, (int)$this->input->get('page'));
        $limit = self::PER_PAGE;

        $accounts = $this->Referral_account_model->paginated($limit, ($page - 1) * $limit);
        $total    = $this->Referral_account_model->count_all_accounts();

        $this->load->view('layouts/app', array(
            'title'        => 'Affiliates',
            'nav_active'   => 'admin/affiliates',
            'content_view' => 'admin/affiliates/index',
            'current_user' => $this->current_user,
            'permissions'  => $this->auth->permissions(),
            'unread'       => 0,
            'accounts'     => $accounts,
            'totals'       => $this->program_totals(),
            'settings'     => array(
                'percent'    => $this->affiliateservice->default_percent(),
                'hold_hours' => $this->affiliateservice->hold_hours(),
                'min_payout' => $this->affiliateservice->min_payout(),
                'scope'      => $this->affiliateservice->scope(),
                'enabled'    => $this->affiliateservice->enabled(),
            ),
            'page'         => $page,
            'total'        => $total,
            'total_pages'  => max(1, (int)ceil($total / $limit)),
        ));
    }

    /** POST /admin/affiliates/:id/rate — override one account's percentage. */
    public function rate($account_id) {
        if ($this->input->method(true) !== 'POST') show_404();
        $this->require_perm('affiliates.manage');

        $account = $this->Referral_account_model->find_by_id((int)$account_id);
        if (!$account) show_404();

        $percent = (string)$this->input->post('commission_percent', true);
        if (!preg_match('/^\d{1,3}(\.\d{1,4})?$/', $percent) || (float)$percent > 100) {
            $this->session->set_flashdata('error', 'Commission must be a percentage between 0 and 100.');
            return redirect('admin/affiliates');
        }

        $this->Referral_account_model->set_percent($account->id, $percent);
        $this->Audit_log_model->record(
            $this->current_user->id, 'affiliate.rate_changed', 'referral_accounts', (string)$account->id,
            array('commission_percent' => (string)$account->commission_percent),
            array('commission_percent' => $percent),
            $this->input->ip_address(), $this->input->user_agent(), $this->request_id
        );
        $this->session->set_flashdata('success', 'Commission rate updated.');
        redirect('admin/affiliates');
    }

    /** POST /admin/affiliates/payout — run the due-commission payout now. */
    public function payout() {
        if ($this->input->method(true) !== 'POST') show_404();
        $this->require_perm('affiliates.manage');

        $result = $this->affiliateservice->pay_due(500);
        $this->Audit_log_model->record(
            $this->current_user->id, 'affiliate.payout_run', 'referral_commissions', null,
            null, $result, $this->input->ip_address(), $this->input->user_agent(), $this->request_id
        );

        if (!empty($result['disabled'])) {
            $this->session->set_flashdata('error', 'The affiliate program is disabled.');
        } else {
            $this->session->set_flashdata('success', sprintf(
                'Paid %d commission(s) totalling %s. %d skipped.',
                $result['paid'], marvy_money($result['amount']), $result['skipped']
            ));
        }
        redirect('admin/affiliates');
    }

    /** Program-wide totals for the header cards. */
    private function program_totals() {
        $row = $this->db
            ->select("COUNT(*) AS commissions", false)
            ->select("COALESCE(SUM(amount),0) AS accrued", false)
            ->select("COALESCE(SUM(CASE WHEN status='PENDING' THEN amount ELSE 0 END),0) AS pending", false)
            ->select("COALESCE(SUM(CASE WHEN status='PAID' THEN amount ELSE 0 END),0) AS paid", false)
            ->get('referral_commissions')->row();

        return array(
            'accounts'    => $this->Referral_account_model->count_all_accounts(),
            'referrals'   => (int)$this->db->count_all_results('referrals'),
            'commissions' => (int)($row->commissions ?? 0),
            'accrued'     => (string)($row->accrued ?? '0.00000000'),
            'pending'     => (string)($row->pending ?? '0.00000000'),
            'paid'        => (string)($row->paid ?? '0.00000000'),
        );
    }
}
