<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Internal checkout-only shipping allocation. A scalar posted by a browser
 * must never be allowed to waive a carrier fee; ShopCheckoutService creates
 * these objects in PHP after it has resolved the active method itself.
 */
final class ShopShippingAllocation {
    private $chargeable;

    private function __construct($chargeable) {
        $this->chargeable = (bool)$chargeable;
    }

    public static function first() {
        return new self(true);
    }

    public static function subsequent() {
        return new self(false);
    }

    public function is_chargeable() {
        return $this->chargeable;
    }
}
