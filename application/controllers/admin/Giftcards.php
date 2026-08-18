<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Admin/Giftcards — the gift card queue (§23, §25).
 *
 * Same operational shape as Admin/Numbers and Admin/Identity, with one
 * difference that drives the whole screen: the payload here is *money*. A gift
 * card code is a bearer instrument — whoever reads it can spend it — and
 * unlike an identity record, a staff member who copies one has not committed a
 * privacy breach, they have committed theft.
 *
 * So the queue and the detail page are built to be useful without showing a
 * code: delivery state, attempt counts, the masked tail and the money are
 * everything needed to answer "did this work and should it be refunded". The
 * codes themselves sit behind a separate POST button, a separate permission
 * (`giftcards.reveal`, which STAFF does not get) and an audit entry naming the
 * operator — see GiftcardService::reveal().
 *
 * The other difference from Identity is that this screen has real *operational*
 * buttons: an order stuck in PLACED can be chased (collect) or written off
 * (abandon, which refunds). Both go through the service, so an admin pressing
 * a button and the cron sweep apply identical rules.
 *
 * Read requires `giftcards.view`; refunds require `giftcards.refund`. Every
 * mutation is POST-only, CSRF-protected and audit-logged, and all money moves
 * through TransactionEngine.
 */
class Giftcards extends Admin_Controller {

    const PER_PAGE = 25;
    const DOMAIN   = 'GIFTCARD';

    public function __construct() {
        parent::__construct();
        $this->require_perm('giftcards.view');
        $this->load->library(array('TransactionEngine', 'GiftcardService', 'DashboardStats'));
        $this->load->model(array(
            'Service_transaction_model', 'Giftcard_order_model', 'Giftcard_code_model',
            'Giftcard_product_model', 'Giftcard_brand_model',
            'Service_transaction_status_history_model', 'Provider_transaction_model',
            'Audit_log_model',
        ));
    }

    public function index() {
        $filters = array(
            'domain' => self::DOMAIN,
            'status' => $this->input->get('status', true),
            'search' => $this->input->get('q', true),
        );
        $page  = max(1, (int)$this->input->get('page'));
        $limit = self::PER_PAGE;

        $total = $this->Service_transaction_model->admin_count($filters);

        $this->load->view('layouts/app', array(
            'title'        => 'Gift cards',
            'nav_active'   => 'admin/giftcards',
            'content_view' => 'admin/giftcards/index',
            'current_user' => $this->current_user,
            'permissions'  => $this->auth->permissions(),
            'unread'       => $this->dashboardstats->unread_count($this->current_user->id),
            'transactions' => $this->Service_transaction_model->admin_search($filters, $limit, ($page - 1) * $limit),
            'counts'       => $this->Service_transaction_model->status_counts(self::DOMAIN),
            'order_counts' => $this->Giftcard_order_model->status_counts(),
            'filters'      => $filters,
            'page'         => $page,
            'total'        => $total,
            'total_pages'  => max(1, (int)ceil($total / $limit)),
        ));
    }

    /** One purchase — delivery and money, but not the codes. */
    public function detail($public_id) {
        $tx = $this->Service_transaction_model->admin_find($public_id, self::DOMAIN);
        if (!$tx) show_404();

        $this->render_detail($tx, null);
    }

    /**
     * POST /admin/giftcards/:id/collect — ask the vendor for the codes now.
     *
     * The sweep does this every two minutes; this is the button for a customer
     * on the phone who does not want to wait for the next tick. Identical
     * rules, because it is the identical call.
     */
    public function collect($public_id) {
        $tx = $this->guard($public_id, 'giftcards.manage');
        $order = $this->Giftcard_order_model->for_transaction($tx->id);
        if (!$order) show_404();

        $res = $this->giftcardservice->collect($order, 'ADMIN');
        if (empty($res['ok'])) {
            return $this->fail($tx, $res['error'] ?? 'Could not reach the vendor.');
        }

        $this->audit('giftcard.collected', $tx,
            array('status' => $order->status),
            array('status' => isset($res['order']) ? $res['order']->status : $order->status,
                  'ready' => !empty($res['ready'])));
        $this->session->set_flashdata(empty($res['ready']) ? 'error' : 'success',
            empty($res['ready'])
                ? 'The vendor has not issued the code yet. It will be collected automatically.'
                : 'Codes collected — the customer can now see them.');
        redirect('admin/giftcards/'.$tx->public_id);
    }

    /**
     * POST /admin/giftcards/:id/reveal — open a code, on the record.
     *
     * Separate permission, separate button, separate audit entry. The code is
     * rendered into this one response and nothing else: not flashed (that
     * would put a bearer instrument in the session store), not redirected to
     * (that would make it re-viewable by refresh without a second audit entry)
     * and not cached.
     */
    public function reveal($public_id, $card_id = null) {
        $tx = $this->guard($public_id, 'giftcards.reveal');
        $order = $this->Giftcard_order_model->for_transaction($tx->id);
        if (!$order) show_404();

        $card = $card_id
            ? $this->Giftcard_code_model->find_public_for_order($card_id, $order->id) : null;
        if (!$card) show_404();

        $res = $this->giftcardservice->reveal($card, $this->current_user, 'ADMIN');
        if (empty($res['ok'])) {
            return $this->fail($tx, $res['error']);
        }

        // The reveal is already audited inside the service, on the path that
        // does the decryption, so it cannot be bypassed by another caller.
        $this->render_detail($tx, array('card_id' => $card->id) + $res['card']);
    }

    /**
     * POST /admin/giftcards/:id/abandon — write off an undelivered order.
     *
     * Refunds the customer. Refuses once codes exist, because those are
     * spendable and returning the money as well would be giving the card away
     * — the service enforces that, not this controller.
     */
    public function abandon($public_id) {
        $tx = $this->guard($public_id, 'giftcards.refund');
        $order = $this->Giftcard_order_model->for_transaction($tx->id);
        if (!$order) show_404();

        $reason = trim((string)$this->input->post('reason', true));
        $before = array('status' => $order->status, 'tx_status' => $tx->status);

        $res = $this->giftcardservice->abandon($order, 'ADMIN',
            $reason ?: 'Written off by staff — the vendor never issued a code');
        if (empty($res['ok'])) {
            return $this->fail($tx, $res['error'] ?? 'Could not write off this order.');
        }

        $refunded = $res['refunded'] ?? null;
        $this->audit('giftcard.abandoned', $tx, $before,
            array('status' => 'FAILED', 'refunded' => $refunded, 'reason' => $reason));
        $this->session->set_flashdata('success', $refunded
            ? 'Order written off — '.windels_money($refunded).' returned to the wallet.'
            : 'Order written off. No money moved: nothing was charged.');
        redirect('admin/giftcards/'.$tx->public_id);
    }

    /** POST /admin/giftcards/:id/refund — return the charge to the wallet. */
    public function refund($public_id) {
        $tx = $this->guard($public_id, 'giftcards.refund');
        $reason = trim((string)$this->input->post('reason', true));

        $before = array('status' => $tx->status, 'refunded_amount' => $tx->refunded_amount);
        $result = $this->transactionengine->transition(
            $tx->id, 'REFUNDED', 'ADMIN', $reason ?: 'Refunded by staff'
        );
        if (empty($result['ok'])) {
            return $this->fail($tx, $result['error'] ?? 'Could not refund this purchase.');
        }

        $refunded = $result['refunded'] ?? null;
        $this->audit('giftcard.refunded', $tx, $before,
            array('status' => 'REFUNDED', 'refunded' => $refunded, 'reason' => $reason));
        $this->session->set_flashdata('success', $refunded
            ? 'Purchase refunded — '.windels_money($refunded).' returned to the wallet.'
            : 'Purchase marked refunded. No money moved: nothing was charged.');
        redirect('admin/giftcards/'.$tx->public_id);
    }

    /* ----------------------------- helpers ----------------------------- */

    /**
     * @param array|null $plain decrypted card, only on the reveal path
     */
    private function render_detail($tx, $plain) {
        $order = $this->Giftcard_order_model->for_transaction($tx->id);

        $this->load->view('layouts/app', array(
            'title'          => 'Gift card '.$tx->public_id,
            'nav_active'     => 'admin/giftcards',
            'content_view'   => 'admin/giftcards/detail',
            'current_user'   => $this->current_user,
            'permissions'    => $this->auth->permissions(),
            'unread'         => $this->dashboardstats->unread_count($this->current_user->id),
            'tx'             => $tx,
            'order'          => $order,
            'plain'          => $plain,
            'cards'          => $order ? $this->Giftcard_code_model->for_order($order->id) : array(),
            'product'        => $order && $order->product_id
                ? $this->Giftcard_product_model->find_by_id($order->product_id) : null,
            'brand'          => $order && $order->brand_id
                ? $this->Giftcard_brand_model->find_by_id($order->brand_id) : null,
            'give_up_minutes'=> $this->giftcardservice->give_up_minutes(),
            'history'        => $this->Service_transaction_status_history_model->for_transaction($tx->id),
            'provider_calls' => $this->Provider_transaction_model->for_transaction($tx->id),
        ));
    }

    /** POST-only + permission + existence, shared by every mutation. */
    private function guard($public_id, $perm) {
        if ($this->input->method(true) !== 'POST') show_404();
        $this->require_perm($perm);
        $tx = $this->Service_transaction_model->admin_find($public_id, self::DOMAIN);
        if (!$tx) show_404();
        return $tx;
    }

    private function fail($tx, $message) {
        $this->session->set_flashdata('error', $message);
        redirect('admin/giftcards/'.$tx->public_id);
    }

    private function audit($action, $tx, $before, $after) {
        $this->Audit_log_model->record(
            $this->current_user->id, $action, 'service_transactions', (string)$tx->id,
            $before, $after,
            $this->input->ip_address(), $this->input->user_agent(), $this->request_id
        );
    }
}
