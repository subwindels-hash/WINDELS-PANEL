<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Parse and place bounded batches of independent SMM orders.
 *
 * Each valid row is delegated to OrderService, so validation, pricing, wallet
 * charging, persistence, provider submission, and refunds retain the same
 * invariants as the single-order path. One row failing never rolls back a
 * different row that succeeded.
 */
class MassOrderService {

    const MAX_ROWS = 100;
    const MAX_BYTES = 65536;

    private $ci;

    public function __construct() {
        $this->ci =& get_instance();
        $this->ci->load->model('Mass_order_batch_model');
        $this->ci->load->library('OrderService');
    }

    /** Dashboard format: one service|link|quantity instruction per line. */
    public function process_text($user, $text, $batch_token) {
        $text = (string)$text;
        if (strlen($text) > self::MAX_BYTES) {
            return $this->batch_error('PAYLOAD_TOO_LARGE', 'Mass-order input must not exceed 64 KiB.');
        }

        $prepared = array();
        $line_number = 0;
        foreach (preg_split('/\R/', $text) as $line) {
            $line_number++;
            $line = trim($line);
            if ($line === '') continue;
            if (count($prepared) >= self::MAX_ROWS) {
                return $this->batch_error('TOO_MANY_ROWS', 'A mass order may contain at most 100 nonblank rows.');
            }
            $prepared[] = $this->parse_text_row($line, $line_number);
        }

        if (!$prepared) {
            return $this->batch_error('EMPTY_BATCH', 'Enter at least one order instruction.');
        }

        return $this->execute($user, $prepared, $batch_token, hash('sha256', $text));
    }

    /** API format: an array of {service, link, quantity, fields?, note?}. */
    public function process_instructions($user, array $instructions, $batch_token) {
        if (!$instructions) {
            return $this->batch_error('EMPTY_BATCH', 'Provide at least one order instruction.');
        }
        if (count($instructions) > self::MAX_ROWS) {
            return $this->batch_error('TOO_MANY_ROWS', 'A mass order may contain at most 100 instructions.');
        }

        $prepared = array();
        foreach (array_values($instructions) as $index => $instruction) {
            $prepared[] = $this->prepare_instruction($instruction, $index + 1);
        }

        // Hash the complete client instructions, not only their accepted
        // projection. Even changing an unknown or malformed value must not be
        // treated as an exact replay of a consumed token.
        $canonical = json_encode($this->canonicalise(array_values($instructions)), JSON_UNESCAPED_SLASHES);
        if ($canonical === false) {
            return $this->batch_error('BAD_BATCH_PAYLOAD', 'Mass-order instructions must contain valid UTF-8 values.');
        }
        if (strlen($canonical) > self::MAX_BYTES) {
            return $this->batch_error('PAYLOAD_TOO_LARGE', 'Mass-order instructions must not exceed 64 KiB.');
        }

        return $this->execute($user, $prepared, $batch_token, hash('sha256', $canonical));
    }

    private function parse_text_row($line, $row) {
        // Accept the panel form `service|link|quantity`, the same with
        // spaces around the pipes, or a tab-separated triple. A line that
        // cannot be split into exactly three fields is a format error —
        // never a silent skip — so a typo cannot become a charged order.
        $parts = preg_split('/\s*\|\s*/', $line);
        if (count($parts) !== 3) {
            $parts = preg_split('/\t+/', $line);
        }
        if (count($parts) !== 3) {
            return $this->invalid_row($row, 'BAD_FORMAT', 'Use service|link|quantity.');
        }

        return $this->prepare_instruction(array(
            'service' => trim($parts[0]),
            'link' => trim($parts[1]),
            'quantity' => trim($parts[2]),
        ), $row);
    }

    private function prepare_instruction($instruction, $row) {
        if (!is_array($instruction)) {
            return $this->invalid_row($row, 'BAD_FORMAT', 'Each instruction must be an object.');
        }

        $service = $instruction['service'] ?? ($instruction['service_public_id'] ?? null);
        $link = $instruction['link'] ?? null;
        $quantity = $instruction['quantity'] ?? null;
        if (!is_scalar($service) || trim((string)$service) === '') {
            return $this->invalid_row($row, 'NO_SERVICE', 'A service ID or slug is required.');
        }
        if (!is_scalar($link) || trim((string)$link) === '') {
            return $this->invalid_row($row, 'BAD_LINK', 'A target link is required.');
        }
        if (!is_scalar($quantity) || !preg_match('/^[0-9]+$/', trim((string)$quantity))) {
            return $this->invalid_row($row, 'BAD_QUANTITY', 'Quantity must be a positive whole number.');
        }
        if (isset($instruction['fields']) && !is_array($instruction['fields'])) {
            return $this->invalid_row($row, 'BAD_FIELDS', 'Fields must be an object or array.');
        }
        if (isset($instruction['note']) && !is_scalar($instruction['note'])) {
            return $this->invalid_row($row, 'BAD_NOTE', 'Note must be text.');
        }

        return array('row' => (int)$row, 'payload' => array(
            'service' => trim((string)$service),
            'link' => trim((string)$link),
            'quantity' => (int)$quantity,
            'fields' => isset($instruction['fields']) ? $instruction['fields'] : null,
            'note' => isset($instruction['note']) ? trim((string)$instruction['note']) : null,
        ));
    }

    private function execute($user, array $prepared, $batch_token, $request_hash) {
        $user_id = is_object($user) ? (int)($user->id ?? 0) : (int)$user;
        $batch_token = trim((string)$batch_token);
        if ($user_id <= 0) {
            return $this->batch_error('NO_USER', 'User not found.');
        }
        if (!preg_match('/^[a-zA-Z0-9._:\-]{16,128}$/', $batch_token)) {
            return $this->batch_error('BAD_BATCH_TOKEN', 'The submission token is missing or invalid. Refresh and try again.');
        }

        $token_hash = hash('sha256', $batch_token);
        $claim = $this->ci->Mass_order_batch_model->claim($user_id, $token_hash, $request_hash);
        if ($claim['state'] === 'CONFLICT') {
            return $this->batch_error(
                'BATCH_TOKEN_CONFLICT',
                'This submission token was already used for different instructions. Refresh before submitting a new batch.'
            );
        }
        if ($claim['state'] === 'IN_PROGRESS') {
            return $this->batch_error('BATCH_IN_PROGRESS', 'This batch is already being processed. Try again shortly.');
        }
        if ($claim['state'] === 'REPLAY') {
            $result = $claim['batch']->decoded_result;
            $result['replayed'] = true;
            return $result;
        }

        $successful = array();
        $failed = array();
        foreach ($prepared as $entry) {
            $row = (int)$entry['row'];
            if (isset($entry['error'])) {
                $failed[] = $entry['error'];
                continue;
            }

            $payload = $entry['payload'];
            $payload['source'] = 'MASS';
            $payload['idempotency_key'] = 'mass-'.$user_id.'-'.substr($token_hash, 0, 40).'-'.$row;
            try {
                $placed = $this->ci->orderservice->place($user, $payload);
            } catch (Throwable $e) {
                log_message('error', 'mass order row '.$row.' failed: '.$e->getMessage());
                $failed[] = array('row' => $row, 'code' => 'ORDER_FAILED', 'error' => 'Could not place this order.');
                continue;
            }

            if (empty($placed['ok'])) {
                $failure = array(
                    'row' => $row,
                    'code' => $placed['code'] ?? 'ORDER_FAILED',
                    'error' => $placed['error'] ?? 'Could not place this order.',
                );
                if (!empty($placed['order']->public_id)) $failure['order'] = $placed['order']->public_id;
                $failed[] = $failure;
                continue;
            }

            $order = $placed['order'];
            $successful[] = array(
                'row' => $row,
                'order' => $order->public_id,
                'status' => $order->status,
                'charge' => (string)$order->charge,
                'currency' => (string)$order->currency,
                'duplicate' => !empty($placed['duplicate']),
            );
        }

        $result = array(
            'ok' => true,
            'successful' => $successful,
            'failed' => $failed,
            'successful_count' => count($successful),
            'failed_count' => count($failed),
            'replayed' => false,
        );
        if (!$this->ci->Mass_order_batch_model->complete($claim['batch']->id, $result)) {
            log_message('error', 'could not persist result for mass-order batch '.$claim['batch']->public_id);
        }
        return $result;
    }

    private function invalid_row($row, $code, $message) {
        return array('row' => (int)$row, 'error' => array(
            'row' => (int)$row,
            'code' => (string)$code,
            'error' => (string)$message,
        ));
    }

    private function batch_error($code, $message) {
        return array('ok' => false, 'code' => $code, 'error' => $message);
    }

    private function canonicalise($value) {
        if (!is_array($value)) return $value;
        if (array_keys($value) !== range(0, count($value) - 1)) ksort($value);
        foreach ($value as $key => $item) $value[$key] = $this->canonicalise($item);
        return $value;
    }
}
