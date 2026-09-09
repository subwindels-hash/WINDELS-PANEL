<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * VtuProviderInterface — contract for VTU providers (§14).
 *
 * Deliberately a sibling of ProviderAdapterInterface rather than an extension:
 * that one is SMM-shaped (createOrder/requestRefill/getRefillStatus) and none
 * of those verbs mean anything for airtime. Provider_manager registers both.
 *
 * Every method returns an array with at least ok:bool, and on failure an
 * error:string. Implementations must never throw for a provider-side rejection
 * — a rejection is a normal result the engine refunds.
 */
interface VtuProviderInterface {

    /** Airtime top-up. $payload: msisdn, amount, network_code, reference. */
    public function airtime(array $payload);

    /** Data bundle. $payload: msisdn, network_code, variation_code, reference. */
    public function data(array $payload);

    /** Cable TV. $payload: smartcard, provider_code, variation_code, reference. */
    public function cable(array $payload);

    /** Electricity. $payload: meter, disco_code, meter_type, amount, reference. */
    public function electricity(array $payload);

    /** Exam PIN. $payload: variation_code, quantity, reference. */
    public function education(array $payload);

    /**
     * Resolve a customer name before purchase, where the provider supports it
     * (meter and smartcard lookups). Returns ok + name, or ok:false.
     */
    public function verify(array $payload);

    /** Re-check an async purchase. Returns ok + status + reference. */
    public function status($reference);

    /** Provider float, for the balance monitor. */
    public function balance();
}
