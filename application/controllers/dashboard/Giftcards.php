<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Dashboard/Giftcards — the customer's gift card storefront (§23, §17).
 *
 * The controller validates shape and renders; GiftcardService owns the vendor
 * call, the charge, the delivery and the refund. Two behaviours here are
 * specific to this domain rather than copied from the other dashboards:
 *
 *  - **A code is never rendered until it is asked for.** The detail page shows
 *    the masked tail of each card. Pressing Show goes through
 *    GiftcardService::reveal(), which decrypts, stamps the card and audits the
 *    access. Casual page loads — a refresh, a back button, a bookmarked link —
 *    stay out of the access trail, so a reveal in the log means somebody
 *    genuinely looked.
 *
 *  - **The plaintext is rendered into that one response and nowhere else.** It
 *    is never flashed and never redirected to: a gift card code in the session
 *    store is a gift card code in whatever backs the session, and a redirect
 *    would make it re-viewable by refresh without a second audit entry.
 */
class Giftcards extends Auth_Controller {

    const PER_PAGE = 15;

    public function __construct() {
        parent::__construct();
        $this->load->model(array(
            'Giftcard_brand_model', 'Giftcard_product_model',
            'Giftcard_order_model', 'Giftcard_code_model',
            'Service_transaction_model', 'Wallet_model',
        ));
        $this->load->library(array('GiftcardService', 'DashboardStats'));
    }

    /** The storefront. */
    public function index() {
        // Wallet_model::for_user() is a single-row accessor, not a list; bound
        // to a local so it reads that way to a human and to PerformanceTest,
        // which otherwise cannot tell it from an unpaginated for_user() query.
        $wallet = $this->Wallet_model->for_user($this->current_user->id);

        $this->view('index', 'Gift cards', array(
            'products' => $this->Giftcard_product_model->active(),
            'brands'   => $this->Giftcard_brand_model->sellable(),
            'wallet'   => $wallet,
            'selected' => $this->input->get('product', true),
        ));
    }

    /** POST — buy a card. */
    public function buy() {
        if ($this->input->method() !== 'post') show_404();

        $result = $this->giftcardservice->purchase($this->current_user, array(
            'product'         => $this->input->post('product', true),
            'quantity'        => (int)$this->input->post('quantity', true),
            'coupon_code'     => $this->input->post('coupon_code', true),
            'recipient_email' => $this->input->post('recipient_email', true),
            'idempotency_key' => 'gc:'.$this->current_user->id.':'
                                 .substr(sha1((string)$this->input->post('form_token', true)), 0, 32),
            'source'          => 'WEB',
        ));

        if (empty($result['ok'])) {
            $this->session->set_flashdata('error', $result['error']);
            // A rejected order that still produced a transaction has a receipt
            // worth showing — it was refunded, and the customer should see
            // that rather than a red banner on an empty form.
            if (!empty($result['transaction'])) {
                return redirect('dashboard/giftcards/'.$result['transaction']->public_id);
            }
            return redirect('dashboard/giftcards');
        }

        $savings = !empty($result['coupon_code']) && bccomp((string)$result['discount'], '0', 8) > 0
            ? ' Coupon '.htmlspecialchars((string)$result['coupon_code']).' applied — you saved '
              .htmlspecialchars(marvy_money($result['discount'])).'.'
            : '';
        $this->session->set_flashdata('success', empty($result['cards'])
            ? 'Payment taken. Your code is being issued and will appear here shortly.'.$savings
            : 'Gift card purchased.'.$savings);
        redirect('dashboard/giftcards/'.$result['transaction']->public_id);
    }

    /** One purchase, with its cards masked. */
    public function detail($public_id) {
        list($tx, $order) = $this->owned($public_id);

        $this->view('detail', 'Gift card', array(
            'tx'      => $tx,
            'order'   => $order,
            'brand'   => $order && $order->brand_id
                ? $this->Giftcard_brand_model->find_by_id($order->brand_id) : null,
            'product' => $order && $order->product_id
                ? $this->Giftcard_product_model->find_by_id($order->product_id) : null,
            'cards'   => $order ? $this->Giftcard_code_model->for_order($order->id) : array(),
            'plain'   => null,
        ));
    }

    /**
     * POST — show me this card. Audited, and counted on the order.
     *
     * @param string $public_id transaction public id
     * @param string $card_id   card public id
     */
    public function reveal($public_id, $card_id = null) {
        if ($this->input->method() !== 'post') show_404();
        list($tx, $order) = $this->owned($public_id);
        if (!$order) show_404();

        $card = $card_id
            ? $this->Giftcard_code_model->find_public_for_order($card_id, $order->id) : null;
        if (!$card) show_404();

        $res = $this->giftcardservice->reveal($card, $this->current_user, 'CUSTOMER');
        if (empty($res['ok'])) {
            $this->session->set_flashdata('error', $res['error']);
            return redirect('dashboard/giftcards/'.$public_id);
        }

        // Rendered straight into this response rather than flashed: a gift
        // card code must not be written to the session store.
        $this->view('detail', 'Gift card', array(
            'tx'      => $tx,
            'order'   => $this->Giftcard_order_model->find_by_id($order->id),
            'brand'   => $order->brand_id
                ? $this->Giftcard_brand_model->find_by_id($order->brand_id) : null,
            'product' => $order->product_id
                ? $this->Giftcard_product_model->find_by_id($order->product_id) : null,
            'cards'   => $this->Giftcard_code_model->for_order($order->id),
            'plain'   => array('card_id' => $card->id) + $res['card'],
        ));
    }

    /** Every card this customer has bought. */
    public function history() {
        $page = max(1, (int)$this->input->get('page'));
        $offset = ($page - 1) * self::PER_PAGE;
        $filters = array_filter(array(
            'domain' => 'GIFTCARD',
            'status' => $this->input->get('status', true),
        ));

        $transactions = $this->Service_transaction_model->history_for_user(
            $this->current_user->id, $filters, self::PER_PAGE, $offset);

        $this->view('history', 'Gift card history', array(
            'transactions' => $transactions,
            // One query for the page, not one per row.
            'orders'       => $this->Giftcard_order_model->for_transactions(
                array_map(function ($t) { return $t->id; }, $transactions)),
            'total'        => $this->Service_transaction_model->count_history_for_user(
                $this->current_user->id, $filters),
            'page'         => $page,
            'per_page'     => self::PER_PAGE,
            'filters'      => $filters,
        ));
    }

    /* ------------------------------------------------------------------ */

    /** A purchase of this customer's, or a 404. Never another customer's. */
    private function owned($public_id) {
        $tx = $this->Service_transaction_model->find_public_for_user(
            $public_id, $this->current_user->id);
        if (!$tx || $tx->service_domain !== 'GIFTCARD') show_404();
        return array($tx, $this->Giftcard_order_model->for_transaction($tx->id));
    }

    private function view($view, $title, array $data) {
        $this->load->view('layouts/app', array_merge(array(
            'title'        => $title,
            'nav_active'   => 'dashboard/giftcards',
            'unread'       => $this->dashboardstats->unread_count($this->current_user->id),
            'content_view' => 'dashboard/giftcards/'.$view,
            'current_user' => $this->current_user,
            'permissions'  => $this->auth->permissions(),
        ), $data));
    }
}
