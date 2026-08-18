<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Dashboard/History — everything the customer has bought, in one list (§20).
 *
 * Before this page, "what have I bought?" had five answers on five pages —
 * `dashboard/orders`, `/vtu`, `/numbers`, `/identity`, `/giftcards` — and none
 * of them was the whole list. A customer who bought airtime on Monday and a
 * gift card on Tuesday had no single place that showed both, which also made
 * "where did my money go?" unanswerable without checking the wallet ledger and
 * mentally joining it back to purchases.
 *
 * `ActivityFeed` does the merging; this controller filters and renders. It is
 * deliberately read-only: every action still lives on the domain page, so
 * there is exactly one place that can cancel a number or reveal a code, and it
 * is the one with the domain's rules in it.
 */
class History extends Auth_Controller {

    const PER_PAGE = 20;

    public function __construct() {
        parent::__construct();
        $this->load->library(array('ActivityFeed', 'DashboardStats'));
    }

    public function index() {
        $page   = max(1, (int)$this->input->get('page'));
        $limit  = self::PER_PAGE;
        $offset = ($page - 1) * $limit;

        $filters = array(
            'domain' => strtoupper((string)$this->input->get('domain', true)),
            'status' => (string)$this->input->get('status', true),
        );
        // An unknown domain in the query string must not silently become "all"
        // — a bookmarked typo would then look like the filter had worked.
        if ($filters['domain'] !== '' && !in_array($filters['domain'], ActivityFeed::domains(), true)) {
            show_404();
        }

        $feed  = $this->activityfeed->for_user($this->current_user->id, $filters, $limit, $offset);
        $total = (int)$feed['total'];

        $this->load->view('layouts/app', array(
            'title'        => 'Purchase history',
            'nav_active'   => 'dashboard/history',
            'content_view' => 'dashboard/history/index',
            'current_user' => $this->current_user,
            'permissions'  => $this->auth->permissions(),
            'unread'       => $this->dashboardstats->unread_count($this->current_user->id),
            'rows'         => $feed['rows'],
            'domains'      => ActivityFeed::domains(),
            'filters'      => $filters,
            'page'         => $page,
            'per_page'     => $limit,
            'total'        => $total,
            'total_pages'  => max(1, (int)ceil($total / $limit)),
        ));
    }
}
