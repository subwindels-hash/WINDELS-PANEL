<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * CoinpaymentsGateway — CoinPayments hosted checkout (crypto).
 *
 * Documented API (https://www.coinpayments.net/apidoc):
 *
 *   POST https://www.coinpayments.net/api.php  cmd=create_transaction
 *        — form-encoded, signed with HMAC-SHA512 of the POST body using the
 *          private key, sent in the HMAC header. Returns checkout_url and a
 *          deposit address.
 *   IPN  — same HMAC-SHA512 scheme over the raw IPN body, but keyed with the
 *          IPN secret the merchant sets, in the HMAC header.
 *
 * Crypto settles slowly, so status 1 ("funds received, awaiting confirms") is
 * deliberately NOT a success: only status >= 100 (or 2, "queued for payout")
 * credits a wallet. Treating "seen in the mempool" as paid is how crypto
 * merchants get double-spent.
 */
class CoinpaymentsGateway extends HostedGateway {

    const DEFAULT_API_URL  = 'https://www.coinpayments.net/api.php';
    const SIGNATURE_HEADER = 'HMAC';
    const API_VERSION      = 1;

    public function code() { return 'coinpayments'; }

    public function config() {
        return array(
            'enabled'     => $this->flag('COINPAYMENTS_ENABLED', 'coinpayments_enabled', true),
            'api_url'     => $this->secret('COINPAYMENTS_API_URL', 'coinpayments_api_url') ?: self::DEFAULT_API_URL,
            'public_key'  => $this->secret('COINPAYMENTS_PUBLIC_KEY', 'coinpayments_public_key'),
            'private_key' => $this->secret('COINPAYMENTS_PRIVATE_KEY', 'coinpayments_private_key'),
            'ipn_secret'  => $this->secret('COINPAYMENTS_IPN_SECRET', 'coinpayments_ipn_secret'),
            'merchant_id' => $this->secret('COINPAYMENTS_MERCHANT_ID', 'coinpayments_merchant_id'),
            // What the customer pays in. The deposit is still denominated in
            // the panel's own currency; CoinPayments does the conversion.
            'accept_coin' => strtoupper((string)($this->secret('COINPAYMENTS_ACCEPT_COIN', 'coinpayments_accept_coin') ?: 'BTC')),
        );
    }

    public function is_configured() {
        $cfg = $this->config();
        return !empty($cfg['public_key']) && !empty($cfg['private_key']) && !empty($cfg['merchant_id']);
    }

    public function initiate($transaction, $user) {
        $cfg = $this->config();
        if (empty($cfg['enabled']))  return $this->fail('PROVIDER_DISABLED', 'Crypto payments are currently unavailable.');
        if (!$this->is_configured()) return $this->not_configured();

        $reference = $this->reference($transaction);
        $payload = array(
            'version'    => self::API_VERSION,
            'cmd'        => 'create_transaction',
            'key'        => $cfg['public_key'],
            'format'     => 'json',
            'amount'     => number_format((float)$transaction->amount, 8, '.', ''),
            'currency1'  => strtoupper((string)$transaction->currency), // what we price in
            'currency2'  => $cfg['accept_coin'],                        // what they pay in
            'buyer_email'=> (string)$user->email,
            'item_name'  => 'Wallet deposit',
            'invoice'    => $reference,
            'custom'     => $reference,
            'ipn_url'    => $this->webhook_url(),
            'success_url'=> $this->return_url($transaction).'?paid=1',
            'cancel_url' => $this->return_url($transaction).'?cancelled=1',
        );

        $body = http_build_query($payload);
        $res = $this->post_form($cfg['api_url'], $payload, array(
            'HMAC: '.hash_hmac('sha512', $body, $cfg['private_key']),
        ));
        if (empty($res['ok'])) return $this->fail('PROVIDER_ERROR', $res['error']);

        // CoinPayments answers HTTP 200 even for errors; the envelope carries
        // the real outcome, and its success value is the literal string "ok".
        // Casting it to int (0 for any message) would read every rejection as
        // a success and hand the customer a checkout that does not exist.
        $error = trim((string)($res['body']['error'] ?? ''));
        if (strtolower($error) !== 'ok') {
            $message = $error !== '' ? $error : 'CoinPayments rejected the request.';
            log_message('error', 'coinpayments: create_transaction: '.$message);
            return $this->fail('PROVIDER_ERROR', $message);
        }

        $result = $res['body']['result'] ?? array();
        $url = $result['checkout_url'] ?? ($result['status_url'] ?? null);
        if (!$url) {
            log_message('error', 'coinpayments: create_transaction returned no checkout_url');
            return $this->fail('PROVIDER_ERROR', 'CoinPayments did not return a checkout link. Try again shortly.');
        }

        return array(
            'ok'             => true,
            'status'         => 'PENDING',
            'redirect_url'   => $url,
            'provider_tx_id' => $reference,
            'checkout'       => array(
                'provider'      => 'coinpayments',
                'method'        => 'crypto',
                'reference'     => $reference,
                'txn_id'        => $result['txn_id'] ?? null,
                'address'       => $result['address'] ?? null,
                'coin'          => $cfg['accept_coin'],
                'coin_amount'   => isset($result['amount']) ? (string)$result['amount'] : null,
                'confirms_needed' => $result['confirms_needed'] ?? null,
                'expires_in'    => isset($result['timeout']) ? ((int)$result['timeout'] / 60).' minutes' : null,
                'amount'        => (string)$transaction->amount,
                'currency'      => strtoupper((string)$transaction->currency),
                'instructions'  => 'Send exactly the coin amount shown to the address CoinPayments displays. '
                                   .'Your wallet is credited after the network confirmations complete — this can '
                                   .'take several minutes.',
            ),
        );
    }

    public function verify_webhook($raw_body, array $headers) {
        $cfg = $this->config();
        if (empty($cfg['ipn_secret'])) return null; // store, never credit
        $signature = $this->header($headers, self::SIGNATURE_HEADER);
        if ($signature === '') return false;

        if (!hash_equals(hash_hmac('sha512', (string)$raw_body, $cfg['ipn_secret']), $signature)) {
            return false;
        }
        // The merchant id in the IPN must be ours: a valid HMAC from another
        // merchant's IPN secret is not possible, but a replayed body from a
        // different account would be.
        parse_str((string)$raw_body, $fields);
        if (!empty($cfg['merchant_id']) && !empty($fields['merchant'])
            && !hash_equals((string)$cfg['merchant_id'], (string)$fields['merchant'])) {
            log_message('error', 'coinpayments: IPN merchant id mismatch');
            return false;
        }
        return true;
    }

    public function parse_event($raw_body) {
        // IPNs are form-encoded, not JSON.
        $fields = array();
        parse_str((string)$raw_body, $fields);

        $status = (int)($fields['status'] ?? 0);
        // >= 100 complete, 2 queued for payout — anything else is either
        // pending confirmations (0/1) or an error (negative).
        $normalised = $status >= 100 || $status === 2 ? 'SUCCESS' : ($status < 0 ? 'FAILED' : 'PENDING');

        $reference = (string)($fields['custom'] ?? ($fields['invoice'] ?? ''));
        return array(
            'event_id'       => (string)(($fields['txn_id'] ?? $reference).':'.$status),
            'type'           => 'coinpayments_ipn_status_'.$status,
            'provider_tx_id' => $reference,
            'status'         => $normalised,
            'amount'         => isset($fields['amount1']) ? (string)$fields['amount1'] : null,
            'currency'       => isset($fields['currency1']) ? strtoupper((string)$fields['currency1']) : null,
            'metadata'       => array('txn_id' => $fields['txn_id'] ?? null, 'status_text' => $fields['status_text'] ?? null),
        );
    }
}
