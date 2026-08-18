<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * IdentityProviderInterface — contract for identity/KYC vendors (§22, §14).
 *
 * The fourth sibling alongside ProviderAdapterInterface (SMM),
 * VtuProviderInterface (VTU) and NumberProviderInterface (numbers), for the
 * same reason the others are siblings rather than subclasses: the verbs do not
 * overlap. There is nothing to poll, nothing to cancel and nothing to refill —
 * a lookup asks one question and gets one answer.
 *
 * Two obligations are specific to this family and are part of the contract,
 * not implementation detail:
 *
 *  1. **"Not found" is not an error.** An identifier that does not exist in
 *     NIMC or NIBSS is a successful, correctly-billed vendor call that
 *     happened to answer "nobody". Implementations must report it as
 *     ok:true with found:false, so the service layer can refund the customer
 *     for a lookup that told them nothing while still recording that the
 *     vendor answered. Returning ok:false there would make a legitimate
 *     answer indistinguishable from the vendor being down.
 *
 *  2. **The adapter returns only what it was asked for.** Vendors return a
 *     base64 photograph on these endpoints. Implementations must drop it —
 *     see DojahAdapter::entity() — because the panel has no product that
 *     needs a stranger's face and storing one multiplies the blast radius of
 *     a breach for no revenue.
 *
 * As in every family, no method throws for a vendor-side rejection; throwing
 * is reserved for transport failures.
 *
 * The shape returned by lookup():
 *   ok:        bool     the vendor answered at all
 *   found:     bool     it had a record (false is a valid, billed answer)
 *   reference: ?string  vendor's own id for the call, when it gives one
 *   entity:    array    the identity fields, photo stripped
 *   cost:      ?string  vendor charge in base currency, when it reports one
 *   error:     ?string  operator-readable reason, when ok is false
 */
interface IdentityProviderInterface {

    /**
     * Run one identity lookup.
     *
     * @param array $payload id_type (NIN|BVN), lookup_field (IDENTIFIER|PHONE),
     *                       identifier (raw — must not be logged),
     *                       provider_code?, reference (our transaction public_id)
     */
    public function lookup(array $payload);

    /** Vendor float, for the balance monitor. */
    public function balance();
}
