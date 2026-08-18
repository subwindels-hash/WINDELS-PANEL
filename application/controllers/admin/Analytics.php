<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Admin/Analytics — where the money came from, per domain (§25/§26; phase G).
 *
 * The admin overview answers "what needs attention right now". This page
 * answers the slower question the overview cannot: **which of the six things
 * this panel sells is actually worth selling.**
 *
 * That question only became askable once the domains existed, which is why it
 * is phase G rather than phase A. It is also the page that exposed the bug
 * this session opens with: until now every revenue figure in the admin read
 * only the `orders` table, so VTU, numbers, identity and gift cards were
 * reported as zero revenue no matter how much they sold.
 *
 * Read-only and permission-gated on `reports.view`, like the overview. There
 * are no actions here at all — no refunds, no retries, nothing that writes —
 * so an operator can hand this page to someone who should see the numbers and
 * touch nothing.
 */
class Analytics extends Admin_Controller {

    /** Windows the range selector offers, in days. */
    private static $ranges = array(7, 30, 90);

    public function __construct() {
        parent::__construct();
        $this->require_perm('reports.view');
        $this->load->library(array('AdminStats', 'ActivityFeed', 'DashboardStats'));
    }

    public function index() {
        $days = (int)$this->input->get('days');
        if (!in_array($days, self::$ranges, true)) $days = 30;

        $domains = $this->adminstats->revenue_by_domain($days);

        $this->load->view('layouts/app', array(
            'title'        => 'Analytics',
            'nav_active'   => 'admin/analytics',
            'content_view' => 'admin/analytics/index',
            'current_user' => $this->current_user,
            'permissions'  => $this->auth->permissions(),
            'unread'       => $this->dashboardstats->unread_count($this->current_user->id),
            'days'         => $days,
            'ranges'       => self::$ranges,
            'summary'      => $this->adminstats->revenue($days),
            'domains'      => $domains,
            'totals'       => $this->totals($domains),
            'health'       => $this->adminstats->domain_health(),
            'providers'    => $this->adminstats->provider_performance(min($days, 30)),
            'series'       => $this->adminstats->revenue_series(14),
        ));
    }

    /* ------------------------------------------------------------------ */

    /**
     * Column totals for the breakdown table.
     *
     * Summed here rather than in a seventh query: the rows are already in
     * memory and adding them up again in SQL would be a second answer to a
     * question already answered, which is how a footer ends up disagreeing
     * with the column above it.
     */
    private function totals(array $domains) {
        $out = array('sales' => 0, 'gross' => '0.00000000', 'refunded' => '0.00000000',
                     'net' => '0.00000000', 'margin' => '0.00000000', 'costed' => 0);
        foreach ($domains as $d) {
            $out['sales']    += (int)$d['sales'];
            $out['costed']   += (int)$d['costed'];
            $out['gross']     = bcadd($out['gross'], $d['gross'], 8);
            $out['refunded']  = bcadd($out['refunded'], $d['refunded'], 8);
            $out['net']       = bcadd($out['net'], $d['net'], 8);
            // Only domains that actually reported a cost contribute to margin,
            // so a vendor that bills its own wallet cannot inflate the figure.
            if ($d['margin'] !== null) $out['margin'] = bcadd($out['margin'], $d['margin'], 8);
        }
        if ($out['costed'] === 0) $out['margin'] = null;
        return $out;
    }
}
