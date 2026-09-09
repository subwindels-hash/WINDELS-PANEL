<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * GatewayInterface — payment gateway adapter contract (Session 11).
 *
 * Each gateway turns an initialized PaymentTransaction into a redirect/checkout
 * payload (or marks it waiting for manual confirmation) and can verify incoming
 * webhook signatures. Credentials are read from env and never logged.
 */
interface GatewayInterface {
    /**
     * Initiate payment for a transaction row.
     * @return array{ok:bool, redirect_url?:string, checkout?:array, status?:string, error?:string}
     */
    public function initiate($transaction, $user);

    /**
     * Verify a webhook signature for the raw body and headers.
     * @return bool
     */
    public function verify_webhook($raw_body, array $headers);

    /**
     * Parse the gateway event into a normalised shape.
     * @return array{event_id:string, type:string, provider_tx_id?:string, status?:string, amount?:string, currency?:string}
     */
    public function parse_event($raw_body);
}
