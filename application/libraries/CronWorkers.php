<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * CronWorkers — the body of each scheduled job (Session 16).
 *
 * The Cron controller is a thin CLI entry point; the logic lives here so it can
 * be tested without a request. Every method returns
 * array('processed'=>int, 'failed'=>int, 'message'=>string) for JobRunner to
 * record in `job_runs`.
 *
 * Shared rules:
 *   - Workers never move money themselves. Refunds and credits go through
 *     OrderService / LedgerService so the ledger stays the single source of
 *     truth.
 *   - Every batch is bounded by a limit, so one run can never take unbounded
 *     time and overlap the next tick.
 *   - One failing row must not abort the batch: each item is wrapped, its error
 *     logged, and the loop continues.
 */
class CronWorkers {

    /** Provider statuses mapped onto our order state machine. */
    private static $status_map = array(
        'pending'     => 'PENDING',
        'inprogress'  => 'IN_PROGRESS',
        'in progress' => 'IN_PROGRESS',
        'in_progress' => 'IN_PROGRESS',
        'processing'  => 'PROCESSING',
        'completed'   => 'COMPLETED',
        'complete'    => 'COMPLETED',
        'partial'     => 'PARTIAL',
        'canceled'    => 'CANCELED',
        'cancelled'   => 'CANCELED',
        'refunded'    => 'REFUNDED',
        'failed'      => 'FAILED',
        'error'       => 'ERROR',
    );

    private $ci;

    public function __construct() {
        $this->ci =& get_instance();
    }

    /**
     * Load what a job needs, when it needs it.
     *
     * Each cron invocation runs exactly one job, so loading every model and
     * service up front would be wasted work on every tick.
     */
    private function need($models = array(), $libraries = array()) {
        if ($models)    $this->ci->load->model($models);
        if ($libraries) $this->ci->load->library($libraries);
    }

    /* ===================== order status synchronisation ==================== */

    /**
     * Poll providers for the status of in-flight orders.
     *
     * Orders are grouped per provider and queried in batches so one HTTP call
     * covers many orders. Each resulting status change goes through
     * OrderService::apply_status(), which owns the state machine, the history
     * log and any refund.
     */
    public function order_status($limit = 200, $batch_size = 50) {
        $this->need(array('Order_model', 'Provider_model'), array('ProviderSyncService', 'OrderService'));

        $orders = $this->ci->Order_model->pending_provider_sync($limit);
        if (!$orders) return array('processed'=>0, 'failed'=>0, 'message'=>'no orders awaiting sync');

        // Group by provider so each provider is called once per batch.
        $by_provider = array();
        foreach ($orders as $o) {
            if (!$o->provider_id || !$o->provider_order_id) continue;
            $by_provider[(int)$o->provider_id][] = $o;
        }

        $processed = 0; $failed = 0; $changed = 0;
        foreach ($by_provider as $provider_id => $rows) {
            $provider = $this->ci->Provider_model->find_by_id($provider_id);
            if (!$provider) { $failed += count($rows); continue; }

            try {
                $adapter = $this->ci->providersyncservice->adapter($provider);
            } catch (Exception $e) {
                log_message('error', "order_status: no adapter for provider {$provider_id}: ".$e->getMessage());
                $failed += count($rows);
                continue;
            }

            foreach (array_chunk($rows, $batch_size) as $chunk) {
                $ids = array();
                foreach ($chunk as $o) $ids[(string)$o->provider_order_id] = $o;

                try {
                    $res = $adapter->getMultipleOrderStatus(array_keys($ids));
                } catch (Exception $e) {
                    log_message('error', "order_status: provider {$provider_id} call failed: ".$e->getMessage());
                    $failed += count($chunk);
                    continue;
                }
                if (empty($res['ok']) || !is_array($res['data'] ?? null)) {
                    $failed += count($chunk);
                    continue;
                }

                foreach ($res['data'] as $key => $payload) {
                    // Providers key the response either by order id or as a list.
                    $order = $ids[(string)$key] ?? null;
                    if (!$order && is_array($payload) && isset($payload['order'])) {
                        $order = $ids[(string)$payload['order']] ?? null;
                    }
                    if (!$order || !is_array($payload)) continue;

                    $processed++;
                    if ($this->apply_provider_status($order, $payload)) $changed++;
                }
            }
        }

        return array(
            'processed' => $processed,
            'failed'    => $failed,
            'message'   => "{$processed} polled, {$changed} changed, {$failed} failed",
        );
    }

    /** Translate one provider payload into a status transition. */
    private function apply_provider_status($order, array $payload) {
        $raw = strtolower(trim((string)($payload['status'] ?? '')));
        if ($raw === '') return false;

        $new = self::$status_map[$raw] ?? null;
        if ($new === null) {
            log_message('error', "order_status: unknown provider status '{$raw}' for order {$order->public_id}");
            return false;
        }
        if ($new === $order->status) return false;

        $extra = array();
        // A partial delivery carries the undelivered remainder; apply_status()
        // refunds that share proportionally.
        if (isset($payload['remains']) && is_numeric($payload['remains'])) {
            $extra['remains'] = (int)$payload['remains'];
        }
        if ($new === 'PARTIAL' && !isset($extra['remains'])) {
            // Without a remainder we cannot compute the refund; leave it for a
            // human rather than guessing.
            log_message('error', "order_status: PARTIAL without remains for order {$order->public_id}");
            return false;
        }
        if (isset($payload['start_count']) && is_numeric($payload['start_count'])) {
            $this->ci->db->where('id', $order->id)
                ->update('orders', array('start_count' => (int)$payload['start_count']));
        }

        try {
            $res = $this->ci->orderservice->apply_status($order, $new, 'PROVIDER', 'Provider reported '.$raw, $extra);
            if (empty($res['ok'])) {
                log_message('error', "order_status: {$order->public_id} -> {$new} rejected: ".($res['error'] ?? '?'));
                return false;
            }
            return true;
        } catch (Exception $e) {
            log_message('error', "order_status: {$order->public_id} failed: ".$e->getMessage());
            return false;
        }
    }

    /* ============================== drip-feed ============================== */

    /**
     * Execute drip-feed runs that are due.
     *
     * The charge was already taken when the schedule was created, so each run
     * places its child order without charging again.
     */
    public function dripfeed($limit = 100) {
        $this->need(array('Dripfeed_order_model'), array('DripfeedService'));

        $due = $this->ci->Dripfeed_order_model->due_runs($limit);
        if (!$due) return array('processed'=>0, 'failed'=>0, 'message'=>'no runs due');

        $processed = 0; $failed = 0;
        foreach ($due as $drip) {
            try {
                $result = $this->ci->dripfeedservice->execute_due_run($drip);
                if (!empty($result['ok'])) {
                    $processed++;
                } elseif (!empty($result['skipped'])) {
                    continue;
                } else {
                    $failed++;
                    log_message('error', "dripfeed {$drip->public_id}: ".($result['error'] ?? 'unknown'));
                }
            } catch (Exception $e) {
                $failed++;
                log_message('error', "dripfeed {$drip->public_id} threw: ".$e->getMessage());
            }
        }
        return array(
            'processed' => $processed,
            'failed'    => $failed,
            'message'   => "{$processed} runs executed, {$failed} failed",
        );
    }

    /* ============================ subscriptions ============================ */

    /** Place the next order for subscriptions whose execution time has arrived. */
    public function subscriptions($limit = 100) {
        $this->need(array('Subscription_model'), array('SubscriptionService'));

        $due = $this->ci->Subscription_model->due($limit);
        if (!$due) return array('processed'=>0, 'failed'=>0, 'message'=>'no subscriptions due');

        $processed = 0; $failed = 0;
        foreach ($due as $sub) {
            try {
                $result = $this->ci->subscriptionservice->execute_due($sub);
                if (!empty($result['ok'])) $processed++;
                elseif (empty($result['skipped'])) {
                    $failed++;
                    log_message('error', "subscription {$sub->public_id}: ".($result['error'] ?? 'unknown'));
                }
            } catch (Exception $e) {
                $failed++;
                log_message('error', "subscription {$sub->public_id} threw: ".$e->getMessage());
            }
        }
        return array(
            'processed' => $processed,
            'failed'    => $failed,
            'message'   => "{$processed} executed, {$failed} failed",
        );
    }

    /* ============================= email queue ============================= */

    /**
     * Deliver queued email.
     *
     * A message is claimed with a compare-and-set on its status before any send
     * attempt, so two overlapping runs cannot deliver the same email twice.
     * Failures back off exponentially and are abandoned after max_attempts.
     */
    public function email_queue($limit = 50, $max_attempts = 5) {
        $this->need(array(), array('MailService'));

        $rows = $this->ci->db
            ->where('status', 'QUEUED')
            ->where('scheduled_at <=', gmdate('Y-m-d H:i:s'))
            ->order_by('scheduled_at', 'ASC')
            ->limit($limit)
            ->get('email_queue')->result();
        if (!$rows) return array('processed'=>0, 'failed'=>0, 'message'=>'queue empty');

        $sent = 0; $failed = 0;
        foreach ($rows as $mail) {
            // Claim: only the run that flips QUEUED -> SENDING owns this row.
            $this->ci->db->where('id', $mail->id)->where('status', 'QUEUED')
                ->update('email_queue', array('status' => 'SENDING'));
            if ((int)$this->ci->db->affected_rows() !== 1) continue;

            $attempts = (int)$mail->attempts + 1;
            try {
                $ok = $this->ci->mailservice->deliver($mail);
            } catch (Exception $e) {
                $ok = array('ok' => false, 'error' => $e->getMessage());
            }

            if (!empty($ok['ok'])) {
                $sent++;
                $this->ci->db->where('id', $mail->id)->update('email_queue', array(
                    'status'   => 'SENT',
                    'attempts' => $attempts,
                    'sent_at'  => gmdate('Y-m-d H:i:s'),
                    'last_error' => null,
                ));
                continue;
            }

            $failed++;
            $error = substr((string)($ok['error'] ?? 'send failed'), 0, 1000);
            if ($attempts >= $max_attempts) {
                $this->ci->db->where('id', $mail->id)->update('email_queue', array(
                    'status' => 'FAILED', 'attempts' => $attempts, 'last_error' => $error,
                ));
            } else {
                // Exponential backoff: 2^attempts minutes.
                $this->ci->db->where('id', $mail->id)->update('email_queue', array(
                    'status'       => 'QUEUED',
                    'attempts'     => $attempts,
                    'last_error'   => $error,
                    'scheduled_at' => gmdate('Y-m-d H:i:s', time() + (60 * pow(2, $attempts))),
                ));
            }
        }
        return array(
            'processed' => $sent,
            'failed'    => $failed,
            'message'   => "{$sent} sent, {$failed} failed",
        );
    }

    /* =========================== provider health =========================== */

    /** Ping every active provider and record its health. */
    public function provider_health() {
        $this->need(array('Provider_model'), array('ProviderSyncService'));

        $providers = $this->ci->Provider_model->active();
        if (!$providers) return array('processed'=>0, 'failed'=>0, 'message'=>'no active providers');

        $ok = 0; $bad = 0;
        foreach ($providers as $provider) {
            try {
                $res = $this->ci->providersyncservice->test_connection($provider);
                if (!empty($res['ok'])) $ok++; else $bad++;
            } catch (Exception $e) {
                $bad++;
                log_message('error', "provider_health {$provider->id}: ".$e->getMessage());
            }
        }
        return array('processed'=>$ok, 'failed'=>$bad, 'message'=>"{$ok} healthy, {$bad} unhealthy");
    }

    /** Refresh the service catalogue for providers whose interval has elapsed. */
    public function provider_sync() {
        $this->need(array('Provider_model'), array('ProviderSyncService'));

        $providers = $this->ci->Provider_model->due_for_sync();
        if (!$providers) return array('processed'=>0, 'failed'=>0, 'message'=>'no providers due');

        $ok = 0; $bad = 0; $items = 0;
        foreach ($providers as $provider) {
            try {
                $res = $this->ci->providersyncservice->sync_services($provider);
                if (!empty($res['ok'])) { $ok++; $items += (int)($res['total'] ?? 0); }
                else $bad++;
            } catch (Exception $e) {
                $bad++;
                log_message('error', "provider_sync {$provider->id}: ".$e->getMessage());
            }
        }
        return array('processed'=>$ok, 'failed'=>$bad, 'message'=>"{$ok} providers synced ({$items} services), {$bad} failed");
    }

    /* ======================== payment reconciliation ======================= */

    /**
     * Age out deposits that were started but never paid.
     *
     * Successful payments are credited by the gateway webhook or by an admin
     * approving a manual transfer — this worker deliberately credits nothing.
     * It only closes rows that have sat unpaid past the window so the pending
     * queue reflects reality.
     */
    public function payment_reconciliation($stale_days = 7, $limit = 500) {
        $this->need(array('Payment_transaction_model'), array('PaymentService'));

        $cutoff = gmdate('Y-m-d H:i:s', time() - ($stale_days * 86400));
        $stale = $this->ci->db
            ->where_in('status', array('CREATED', 'PENDING'))
            ->where('created_at <', $cutoff)
            ->limit($limit)
            ->get('payment_transactions')->result();
        if (!$stale) return array('processed'=>0, 'failed'=>0, 'message'=>'nothing to reconcile');

        $expired = 0; $failed = 0;
        foreach ($stale as $tx) {
            try {
                // PaymentService owns the transition; it refuses to touch a row
                // that has already reached a terminal state.
                $this->ci->paymentservice->mark_failed(
                    $tx->id, "Expired: no payment received within {$stale_days} days"
                );
                $expired++;
            } catch (Exception $e) {
                $failed++;
                log_message('error', "payment_reconciliation {$tx->id}: ".$e->getMessage());
            }
        }
        return array(
            'processed' => $expired,
            'failed'    => $failed,
            'message'   => "{$expired} stale deposits expired",
        );
    }

    /* ============================= housekeeping ============================ */

    /**
     * Prune high-volume logs that would otherwise grow without bound.
     *
     * Retention is deliberately conservative and audit_logs are never touched —
     * those are the compliance trail and must survive.
     */
    public function analytics() {
        $windows = array(
            array('api_usage_logs',       'created_at', 30),
            array('provider_health_logs', 'created_at', 30),
            array('provider_sync_logs',   'created_at', 90),
            array('job_runs',             'started_at', 90),
        );

        $deleted = 0; $failed = 0; $detail = array();
        foreach ($windows as $w) {
            list($table, $column, $days) = $w;
            try {
                $this->ci->db
                    ->where($column.' <', gmdate('Y-m-d H:i:s', time() - ($days * 86400)))
                    ->limit(5000)
                    ->delete($table);
                $n = (int)$this->ci->db->affected_rows();
                $deleted += $n;
                if ($n) $detail[] = "{$table}:{$n}";
            } catch (Exception $e) {
                $failed++;
                log_message('error', "analytics prune {$table}: ".$e->getMessage());
            }
        }
        return array(
            'processed' => $deleted,
            'failed'    => $failed,
            'message'   => $deleted ? "pruned ".implode(', ', $detail) : 'nothing to prune',
        );
    }

    /* ============================ refill status ============================ */

    /** Poll providers for the status of in-flight refills. */
    public function refill_status($limit = 100) {
        $this->need(array('Refill_model', 'Provider_model', 'Refill_status_history_model'), array('ProviderSyncService'));

        $refills = $this->ci->Refill_model->pending_provider_sync($limit);
        if (!$refills) return array('processed'=>0, 'failed'=>0, 'message'=>'no refills awaiting sync');

        $processed = 0; $failed = 0;
        foreach ($refills as $refill) {
            try {
                $provider = $refill->provider_id ? $this->ci->Provider_model->find_by_id($refill->provider_id) : null;
                if (!$provider) { $failed++; continue; }

                $res = $this->ci->providersyncservice->adapter($provider)
                    ->getRefillStatus($refill->provider_refill_id);
                $raw = strtolower(trim((string)($res['data']['status'] ?? '')));
                if ($raw === '') { $failed++; continue; }

                $new = self::$status_map[$raw] ?? null;
                if ($new === null || $new === $refill->status) continue;

                $this->ci->Refill_status_history_model->record(
                    $refill->id, $refill->status, $new, 'PROVIDER'
                );
                $this->ci->db->where('id', $refill->id)->update('refills', array(
                    'status'          => $new,
                    'last_checked_at' => gmdate('Y-m-d H:i:s'),
                ));
                $processed++;
            } catch (Exception $e) {
                $failed++;
                log_message('error', "refill_status {$refill->id}: ".$e->getMessage());
            }
        }
        return array('processed'=>$processed, 'failed'=>$failed, 'message'=>"{$processed} updated, {$failed} failed");
    }
}
