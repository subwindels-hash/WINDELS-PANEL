<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * GiftcardProviderInterface — contract for gift card vendors (§23, §14).
 *
 * The fourth sibling alongside ProviderAdapterInterface (SMM),
 * VtuProviderInterface, NumberProviderInterface and IdentityProviderInterface,
 * and it exists for the same reason they do: the verbs do not overlap. A gift
 * card is *ordered* and then its codes are *collected*, and those are two
 * calls, not one — the vendor accepts the order first and can still be
 * assembling the cards when it answers. Squeezing that into purchase() would
 * mean either blocking on delivery or pretending an accepted order is a
 * delivered one, and the second mistake is the one that gets a customer
 * charged for nothing.
 *
 * Every method returns an array with at least ok:bool, and on failure an
 * error:string. A vendor-side rejection ("no stock for that denomination") is
 * a normal outcome and must be reported, not thrown; throwing is reserved for
 * transport failures, which TransactionEngine turns into a full refund.
 *
 * order() returns:
 *   ok:        bool
 *   reference: ?string  vendor transaction id — what codes() is keyed on
 *   status:    ?string  PLACED|DELIVERED — whether cards are ready now
 *   cost:      ?string  what the vendor billed us, in the panel's base
 *                       currency, or NULL when it cannot be established
 *   error:     ?string
 *
 * codes() returns:
 *   ok:    bool
 *   ready: bool    false = accepted but not yet issued; try again later
 *   cards: array   zero or more {card_number, pin, redemption_url, expires_on}
 *   error: ?string
 */
interface GiftcardProviderInterface {

    /**
     * Place one gift card order.
     *
     * @param array $payload product_id, quantity, unit_price, country_code,
     *                       recipient_email?, sender_name?,
     *                       reference (our transaction public_id — the vendor
     *                       idempotency key)
     */
    public function order(array $payload);

    /**
     * Collect the card numbers for an order the vendor already accepted.
     *
     * Separate from order() because it is genuinely a separate event: the
     * retry worker calls this on its own schedule for orders that came back
     * PLACED.
     *
     * @param string $reference vendor transaction id
     */
    public function codes($reference);

    /**
     * The vendor's own view of one order, for reconciliation.
     *
     * @return array{ok:bool,status?:string,reference?:string,cost?:?string,error?:string}
     */
    public function order_status($reference);

    /**
     * Catalogue and vendor pricing for the sync.
     *
     * @param string|null $country ISO-2, or NULL for the vendor's full list
     * @return array{ok:bool,products?:array,error?:string} products[] of
     *         {provider_product_id, name, brand_id, brand_name, country_code,
     *          denomination_type, recipient_currency, face_value, cost,
     *          logo_url, redeem_instructions}
     */
    public function products($country = null);

    /** Vendor float, for the balance monitor. */
    public function balance();
}
