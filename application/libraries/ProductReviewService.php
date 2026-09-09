<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * ProductReviewService — verified-purchase reviews on shop listings.
 *
 * A review always names the completed order it is attached to
 * (marketplace_order_id, UNIQUE — one review per purchase), so "reviews" here
 * can never be submitted by someone who never bought the item. New reviews
 * start PENDING and only ever show on the storefront once a moderator with
 * `marketplace.moderate_listings` approves them — the same trust boundary the
 * listings themselves already use.
 */
class ProductReviewService {

    private $ci;

    public function __construct() {
        $this->ci =& get_instance();
        $this->ci->load->model(array('Product_review_model', 'Marketplace_order_model'));
    }

    /** Whether this user may review this order: it is theirs, it settled, and it has no review yet. */
    public function can_review($order, $user_id) {
        if (!$order || (int)$order->buyer_id !== (int)$user_id) return false;
        if (!in_array($order->status, array('DELIVERED', 'COMPLETED'), true)) return false;
        return $this->ci->Product_review_model->for_order($order->id) === null;
    }

    public function submit($order, $user_id, array $input) {
        if (!$this->can_review($order, $user_id)) {
            return array('ok' => false, 'error' => 'You can only review an item after your order is delivered, and only once.');
        }
        $rating = (int)($input['rating'] ?? 0);
        if ($rating < 1 || $rating > 5) return array('ok' => false, 'error' => 'Choose a rating between 1 and 5.');

        $body = trim((string)($input['body'] ?? ''));
        if (mb_strlen($body) > 4000) $body = mb_substr($body, 0, 4000);

        $id = $this->ci->Product_review_model->create(array(
            'listing_id' => $order->listing_id,
            'marketplace_order_id' => $order->id,
            'user_id' => (int)$user_id,
            'rating' => $rating,
            'title' => mb_substr(trim((string)($input['title'] ?? '')), 0, 160) ?: null,
            'body' => $body ?: null,
            'status' => 'PENDING',
        ));
        return array('ok' => true, 'review_id' => $id);
    }

    public function moderate($public_id, $decision, $actor_id) {
        $decision = strtoupper($decision) === 'APPROVED' ? 'APPROVED' : 'REJECTED';
        $review = $this->ci->db->where('public_id', $public_id)->get('product_reviews')->row();
        if (!$review) return array('ok' => false, 'error' => 'Review not found.');
        $this->ci->Product_review_model->moderate($review->id, $decision, $actor_id);
        return array('ok' => true);
    }
}
