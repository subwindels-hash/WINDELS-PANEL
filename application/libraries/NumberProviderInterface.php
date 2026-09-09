<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * NumberProviderInterface — contract for virtual-number / OTP vendors (§10, §14).
 *
 * The third sibling alongside ProviderAdapterInterface (SMM) and
 * VtuProviderInterface (VTU), for the same reason the second one exists: the
 * verbs do not overlap. An SMM order is submitted and progresses; a VTU
 * purchase is dispatched and settles; a number is *reserved*, polled while it
 * is alive, and then finished, cancelled or banned. Forcing that into
 * createOrder()/getOrderStatus() would lose the one thing that matters here —
 * the deadline.
 *
 * Every method returns an array with at least ok:bool, and on failure an
 * error:string. Implementations must never throw for a vendor-side rejection
 * ("no free phones" is a normal Tuesday, and the engine refunds it); throwing
 * is reserved for transport failures.
 *
 * The reservation shape returned by reserve() and status():
 *   ok:         bool
 *   reference:  string   vendor order id — what every later call is keyed on
 *   msisdn:     string   the rented number, E.164
 *   operator:   ?string
 *   cost:       ?string  vendor price in the panel's base currency
 *   expires_at: ?string  'Y-m-d H:i:s' UTC — the vendor's deadline, not ours
 *   state:      ?string  RESERVED|RECEIVED|COMPLETED|CANCELLED|EXPIRED|BANNED
 *   messages:   array    zero or more {id, sender, text, code, received_at}
 */
interface NumberProviderInterface {

    /**
     * Rent a number.
     *
     * @param array $payload country, service, operator?, product?, max_price?,
     *                       reference (our transaction public_id)
     */
    public function reserve(array $payload);

    /** Poll one reservation for SMS and its current vendor state. */
    public function status($reference);

    /** Tell the vendor we used the number successfully; releases the hold. */
    public function finish($reference);

    /** Release a reservation that never received a code. */
    public function cancel($reference);

    /** Report a number as unusable (already registered, blocked, ...). */
    public function ban($reference);

    /**
     * Availability and vendor pricing for the catalogue sync.
     *
     * @param string $country our country code; the adapter maps it
     * @return array ok + products[] of
     *               {service, provider_product, operator, cost, stock}
     */
    public function products($country);

    /** Vendor float, for the balance monitor. */
    public function balance();
}
