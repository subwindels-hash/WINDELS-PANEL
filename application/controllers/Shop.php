<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Shop — the public storefront (/shop, /shop/product/:slug, /shop/gift-cards).
 *
 * Reuses the marketplace catalogue and models exactly as the signed-in
 * dashboard/Marketplace storefront does — this is a public-facing wrapper
 * around the same platform-owned listings, not a second catalogue. Buying
 * still requires an account (Add to Cart/Buy Now redirect to login for an
 * anonymous visitor, same as every other purchase path in this panel).
 */
class Shop extends Public_Controller {

    const PER_PAGE = 24;

    public function __construct() {
        parent::__construct();
        if (!marvy_feature_enabled('marketplace', true)) show_404();
        $this->load->model(array(
            'Marketplace_listing_model', 'Marketplace_category_model', 'Product_review_model',
        ));
        if ($this->db_ready) {
            try { $this->load->library('CartService'); } catch (Throwable $e) {}
        }
    }

    /** GET /shop */
    public function index() {
        $page = max(1, (int)$this->input->get('page'));
        $filters = array(
            'category' => $this->input->get('category', true),
            'search'   => $this->input->get('q', true),
            'type'     => strtoupper((string)$this->input->get('type', true)),
        );
        $offset = ($page - 1) * self::PER_PAGE;

        $this->render_public('public/shop/index', array(
            'title' => 'Shop',
            'meta_description' => 'Digital products, physical goods and gift cards from the MarvySocials shop — one prepaid wallet, one checkout.',
            'listings'   => $this->Marketplace_listing_model->catalogue($filters, self::PER_PAGE, $offset),
            'total'      => $this->Marketplace_listing_model->catalogue_count($filters),
            'featured'   => $this->Marketplace_listing_model->featured(6),
            'categories' => $this->Marketplace_category_model->active(),
            'page' => $page, 'per_page' => self::PER_PAGE, 'filters' => $filters,
            'cart_count' => $this->cart_count(),
        ));
    }

    /** GET /shop/gift-cards — the gift-card storefront, backed by the real gift-card catalogue. */
    public function gift_cards() {
        $this->load->model(array('Giftcard_brand_model', 'Giftcard_product_model'));
        $this->render_public('public/shop/gift_cards', array(
            'title' => 'Gift cards',
            'meta_description' => 'Buy gift cards from top brands, paid from your MarvySocials wallet and delivered instantly.',
            'brands' => $this->Giftcard_brand_model->sellable(),
            'cart_count' => $this->cart_count(),
        ));
    }

    /** GET /shop/product/:slug — reuses the same listing lookup the dashboard product page uses. */
    public function product($slug) {
        // Listings do not carry a slug column (marketplace listings are
        // addressed by public_id everywhere else in the app); accept either
        // so a link copied from the dashboard's /dashboard/marketplace/{id}
        // still resolves here.
        $listing = $this->Marketplace_listing_model->find_public($slug, true);
        if (!$listing) show_404();

        $this->render_public('public/shop/product', array(
            'title' => $listing->title,
            'meta_description' => trim(strip_tags((string)$listing->description)) ?: 'Buy '.$listing->title.' on MarvySocials.',
            'listing' => $listing,
            'reviews' => $this->Product_review_model->approved_for_listing($listing->id, 10),
            'rating'  => $this->Product_review_model->rating_summary($listing->id),
            'cart_count' => $this->cart_count(),
        ));
    }

    private function cart_count() {
        if (!isset($this->cartservice)) return 0;
        $user = $this->current_user();
        if (!$user) return 0;
        try { return $this->cartservice->count_for($user->id); } catch (Throwable $e) { return 0; }
    }
}
