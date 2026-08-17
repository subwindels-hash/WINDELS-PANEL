<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Dashboard/Referrals — the customer affiliate dashboard (Session 14).
 *
 * Read-only: the link/code, live signup and commission stats, the referred-user
 * list and the commission ledger. All money and commission mutations live in
 * AffiliateService; this controller never writes.
 */
class Referrals extends Auth_Controller {

    const PER_PAGE = 20;

    public function __construct() {
        parent::__construct();
        $this->load->library(array('DashboardStats', 'AffiliateService'));
        $this->load->model(array('Referral_model', 'Referral_commission_model'));
    }

    public function index() {
        $stats = $this->affiliateservice->stats($this->current_user, self::PER_PAGE);

        $this->load->view('layouts/app', array(
            'title'        => 'Referrals',
            'nav_active'   => 'dashboard/referrals',
            'content_view' => 'dashboard/referrals/index',
            'current_user' => $this->current_user,
            'permissions'  => $this->auth->permissions(),
            'unread'       => $this->dashboardstats->unread_count($this->current_user->id),
            'stats'        => $stats,
            'code'         => $stats['code'],
            'link'         => $stats['link'],
        ));
    }

    /** Paginated commission ledger. */
    public function commissions() {
        $page   = max(1, (int)$this->input->get('page'));
        $status = strtoupper((string)$this->input->get('status', true));
        $status = in_array($status, array('PENDING','PAID','REVERSED','REJECTED'), true) ? $status : null;
        $limit  = self::PER_PAGE;

        $rows  = $this->Referral_commission_model->for_referrer(
            $this->current_user->id, $limit, ($page - 1) * $limit, $status
        );
        $total = $this->Referral_commission_model->count_for_referrer($this->current_user->id, $status);

        $this->load->view('layouts/app', array(
            'title'        => 'Referral commissions',
            'nav_active'   => 'dashboard/referrals',
            'content_view' => 'dashboard/referrals/commissions',
            'current_user' => $this->current_user,
            'permissions'  => $this->auth->permissions(),
            'unread'       => $this->dashboardstats->unread_count($this->current_user->id),
            'commissions'  => $rows,
            'status'       => $status,
            'page'         => $page,
            'total'        => $total,
            'total_pages'  => max(1, (int)ceil($total / $limit)),
        ));
    }
}
