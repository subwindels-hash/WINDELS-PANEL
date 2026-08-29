<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * ShopShippingService — the state machine for a physical marketplace order.
 *
 * A shipment is not a second order lifecycle. The marketplace order owns the
 * money and escrow; this service owns the carrier-facing status and changes
 * the escrow state at the one boundary where the two meet:
 *
 *   PENDING → PROCESSING → SHIPPED → DELIVERED
 *                                  ↘ RETURNED
 *
 * Marking a shipment DELIVERED changes its marketplace order from PAID to
 * DELIVERED and starts the normal inspection window. A returned shipment
 * clears that window (and freezes a delivered order as DISPUTED). Cancellation
 * is deliberately not a status-edit shortcut: the refund action must return
 * the money first, then mark the shipment CANCELLED.
 *
 * All status changes are compare-and-set updates inside one database
 * transaction. A stale admin page therefore cannot move a shipment backwards
 * or mark an order delivered after another operator has refunded it.
 */
class ShopShippingService {

    /** Valid forward/terminal transitions. Same-state updates edit tracking. */
    const TRANSITIONS = array(
        'PENDING'    => array('PENDING', 'PROCESSING', 'SHIPPED'),
        'PROCESSING' => array('PROCESSING', 'SHIPPED'),
        'SHIPPED'    => array('SHIPPED', 'DELIVERED', 'RETURNED'),
        'DELIVERED'  => array('DELIVERED', 'RETURNED'),
        'RETURNED'   => array('RETURNED'),
        'CANCELLED'  => array('CANCELLED'),
    );

    private $ci;

    public function __construct() {
        $this->ci =& get_instance();
        $this->ci->load->model(array(
            'Shop_order_shipment_model', 'Marketplace_order_model', 'Setting_model',
        ));
        $this->ci->load->library('MarketplaceService');
    }

    /** Status values the admin may choose for this particular shipment. */
    public function statuses_for($status) {
        $status = strtoupper((string)$status);
        return self::TRANSITIONS[$status] ?? array();
    }

    /**
     * Update one shipment and, where necessary, its escrow order.
     *
     * @param string $public_id shipment public id
     * @param array  $input     status, carrier, tracking_number, tracking_url
     * @param int    $actor_id  operator responsible for the change
     */
    public function update($public_id, array $input, $actor_id = null) {
        $shipment = $this->ci->Shop_order_shipment_model->find_public($public_id);
        if (!$shipment) return $this->err('Shipment not found', 'NOT_FOUND');

        $from = strtoupper((string)$shipment->status);
        $raw_status = $input['status'] ?? null;
        if (!is_scalar($raw_status)) return $this->err('Choose a valid shipment status', 'BAD_STATUS');
        $to = strtoupper(trim((string)$raw_status));
        if (!in_array($to, $this->statuses_for($from), true)) {
            return $this->err('That shipment status change is not allowed', 'BAD_TRANSITION');
        }
        // Cancellation is only the consequence of the refund action. Letting
        // the generic status form set it would make it possible to strand a
        // paid order in CANCELLED with its money still in escrow.
        if ($to === 'CANCELLED' && $from !== 'CANCELLED') {
            return $this->err('Refund the order before cancelling its shipment', 'REFUND_REQUIRED');
        }

        $carrier = trim($this->input_string($input, 'carrier'));
        $tracking = trim($this->input_string($input, 'tracking_number'));
        $tracking_url = trim($this->input_string($input, 'tracking_url'));
        if ($carrier === '') $carrier = trim((string)($shipment->carrier ?? ''));
        if ($tracking === '') $tracking = trim((string)($shipment->tracking_number ?? ''));
        if ($tracking_url === '') $tracking_url = trim((string)($shipment->tracking_url ?? ''));

        if ($to === 'SHIPPED' && $tracking === '') {
            return $this->err('A tracking number is required before marking the shipment shipped', 'TRACKING_REQUIRED');
        }
        if (mb_strlen($carrier) > 80 || mb_strlen($tracking) > 120) {
            return $this->err('Carrier or tracking number is too long', 'BAD_TRACKING');
        }
        if (mb_strlen($tracking_url) > 500 || ($tracking_url !== '' && !$this->valid_url($tracking_url))) {
            return $this->err('Tracking URL must be a valid http(s) URL', 'BAD_TRACKING_URL');
        }

        $order = $this->ci->Marketplace_order_model->find_id($shipment->marketplace_order_id);
        if (!$order) return $this->err('The order behind this shipment could not be found', 'ORDER_NOT_FOUND');
        if ($from !== $to && in_array((string)$order->status, array('REFUNDED', 'CANCELLED', 'COMPLETED'), true)) {
            return $this->err('This order is already resolved and cannot change shipment state', 'ORDER_RESOLVED');
        }

        if (in_array($to, array('PENDING', 'PROCESSING', 'SHIPPED'), true)
            && !in_array((string)$order->status, array('PAID', 'PARTIALLY_REFUNDED'), true)) {
            return $this->err('Only an open paid order can be prepared or shipped', 'BAD_ORDER_STATE');
        }
        if ($to === 'DELIVERED' && $from !== 'DELIVERED' && (string)$order->status !== 'PAID') {
            return $this->err('Only a fully paid open order can be marked delivered', 'BAD_ORDER_STATE');
        }
        if ($to === 'DELIVERED' && $from === 'DELIVERED'
            && !in_array((string)$order->status, array('DELIVERED', 'DISPUTED'), true)) {
            return $this->err('The delivered shipment no longer matches its escrow order', 'BAD_ORDER_STATE');
        }

        $now = gmdate('Y-m-d H:i:s');
        $extra = array(
            'carrier' => $carrier !== '' ? mb_substr($carrier, 0, 80) : null,
            'tracking_number' => $tracking !== '' ? mb_substr($tracking, 0, 120) : null,
            'tracking_url' => $tracking_url !== '' ? $tracking_url : null,
        );
        if ($to === 'SHIPPED' && empty($shipment->shipped_at)) $extra['shipped_at'] = $now;
        if ($to === 'DELIVERED' && empty($shipment->delivered_at)) $extra['delivered_at'] = $now;

        if (!$this->ci->db->trans_begin()) return $this->err('Shipping transaction could not start', 'DB_ERROR');
        try {
            // Claim the shipment row first. This prevents two stale admin pages
            // from both applying different next states.
            if (!$this->ci->Shop_order_shipment_model->transition($shipment->id, $from, $to, $extra)) {
                $this->ci->db->trans_rollback();
                return $this->err('Shipment changed before it could be updated', 'CONFLICT');
            }

            if ($to === 'DELIVERED' && $from !== 'DELIVERED') {
                $hours = max(1, min(720, (int)$this->ci->Setting_model->get(
                    'marketplace_auto_release_hours', 72
                )));
                $due = gmdate('Y-m-d H:i:s', time() + ($hours * 3600));
                if (!$this->ci->Marketplace_order_model->transition($order->id, 'PAID', 'DELIVERED', array(
                    'delivered_at' => $now,
                    'release_due_at' => $due,
                ))) {
                    $this->ci->db->trans_rollback();
                    return $this->err('The order changed before delivery could be recorded', 'CONFLICT');
                }
                $this->ci->Marketplace_order_model->record_event(
                    $order->id, $actor_id, 'DELIVERED', 'PAID', 'DELIVERED', 'Shipment delivered'
                );
            } elseif ($to === 'RETURNED' && in_array((string)$order->status, array('PAID', 'DELIVERED'), true)) {
                // A returned parcel must never pass the auto-release worker
                // while it is back with fulfilment staff. Freeze escrow in the
                // same transaction as the carrier state. A parcel can return
                // before delivery (PAID) or after the carrier reported
                // delivery (DELIVERED); both outcomes need staff resolution.
                $from_order = (string)$order->status;
                if (!$this->ci->Marketplace_order_model->transition($order->id, $from_order, 'DISPUTED', array(
                    'dispute_reason' => 'Shipment returned before escrow was released',
                    'disputed_at' => $now,
                    'release_due_at' => null,
                ))) {
                    $this->ci->db->trans_rollback();
                    return $this->err('The order changed before the return could be recorded', 'CONFLICT');
                }
                $this->ci->Marketplace_order_model->record_event(
                    $order->id, $actor_id, 'RETURNED', $from_order, 'DISPUTED', 'Shipment returned'
                );
            }

            if ($this->ci->db->trans_status() === false || !$this->ci->db->trans_commit()) {
                $this->ci->db->trans_rollback();
                return $this->err('Shipping update could not be committed', 'DB_ERROR');
            }
        } catch (Throwable $e) {
            $this->ci->db->trans_rollback();
            log_message('error', 'Shop shipping update failed: '.$e->getMessage());
            return $this->err('Shipping update could not be completed', 'DB_ERROR');
        }

        return array(
            'ok' => true,
            'shipment' => $this->ci->Shop_order_shipment_model->find_public($public_id),
            'order' => $this->ci->Marketplace_order_model->find_id($order->id),
        );
    }

    /**
     * Refund the order behind a shipment, then close the carrier record.
     * MarketplaceService remains the only implementation that moves money.
     */
    public function refund($public_id, $actor_id = null, $reason = null) {
        $shipment = $this->ci->Shop_order_shipment_model->find_public($public_id);
        if (!$shipment) return $this->err('Shipment not found', 'NOT_FOUND');
        $order = $this->ci->Marketplace_order_model->find_id($shipment->marketplace_order_id);
        if (!$order) return $this->err('The order behind this shipment could not be found', 'ORDER_NOT_FOUND');

        $res = $this->ci->marketplaceservice->refund($order, $actor_id, $reason);
        if (empty($res['ok'])) return $res;

        // The order is already atomically REFUNDED at this point. This update
        // cannot be allowed to undo it; if a database error occurs, the admin
        // queue still shows the shipment's prior state and the refund audit
        // gives operations enough information to reconcile it.
        if (!$this->ci->Shop_order_shipment_model->cancel_after_refund($shipment->id)) {
            // A second refund attempt may find an already-cancelled shipment;
            // that is harmless. Any other failed compare-and-set is a
            // reconciliation signal, not a reason to reverse the refund.
            $fresh = $this->ci->Shop_order_shipment_model->find_public($public_id);
            if (!$fresh || $fresh->status !== 'CANCELLED') {
                log_message('error', 'Refunded order '.$order->public_id.' but shipment '.$public_id.' could not be cancelled');
                $res['shipment_warning'] = 'The refund succeeded, but the shipment status needs reconciliation.';
            }
        }
        $res['shipment'] = $this->ci->Shop_order_shipment_model->find_public($public_id);
        return $res;
    }

    private function input_string(array $input, $key, $default = '') {
        $value = $input[$key] ?? $default;
        return is_scalar($value) ? (string)$value : (string)$default;
    }

    private function valid_url($url) {
        $parts = parse_url($url);
        if (!$parts || empty($parts['host']) || empty($parts['scheme'])) return false;
        return in_array(strtolower($parts['scheme']), array('http', 'https'), true);
    }

    private function err($message, $code) {
        return array('ok' => false, 'error' => $message, 'code' => $code);
    }
}
