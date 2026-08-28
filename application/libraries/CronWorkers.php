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

    /* ======================== VTU settlement ============================= */

    /**
     * Settle VTU purchases the provider accepted but had not completed.
     *
     * Airtime and data usually settle instantly; electricity and cable can sit
     * in PROCESSING for minutes. Until a purchase reaches a terminal state the
     * customer has paid and received nothing, so this must run often — and a
     * provider-side FAILED has to refund, which TransactionEngine handles.
     */
    public function vtu_status($limit = 200) {
        $this->need(
            array('Service_transaction_model', 'Provider_model'),
            array('TransactionEngine', 'Provider_manager')
        );

        $pending = $this->ci->Service_transaction_model->pending_provider_sync('VTU', $limit);
        if (!$pending) return array('processed'=>0, 'failed'=>0, 'message'=>'no VTU transactions awaiting settlement');

        $processed = 0; $failed = 0; $settled = 0;
        foreach ($pending as $tx) {
            $provider = $tx->provider_id ? $this->ci->Provider_model->find_by_id($tx->provider_id) : null;
            if (!$provider) { $failed++; continue; }

            try {
                $adapter = $this->ci->provider_manager->adapter($provider, Provider_manager::FAMILY_VTU);
                $res = $adapter->status($tx->provider_reference);
            } catch (Exception $e) {
                log_message('error', 'vtu_status: '.$e->getMessage());
                $failed++;
                continue;
            }
            $processed++;

            if (empty($res['ok']) || empty($res['status'])) continue;
            $status = strtoupper($res['status']);
            if ($status === 'PROCESSING' || $status === $tx->status) continue;

            if (in_array($status, array('SUCCESSFUL', 'FAILED'), true)) {
                // FAILED refunds automatically inside the engine.
                $this->ci->transactionengine->transition(
                    $tx->id, $status, 'PROVIDER',
                    $status === 'FAILED' ? 'Provider reported failure' : null
                );
                $settled++;
            }
        }

        return array(
            'processed' => $processed,
            'failed'    => $failed,
            'message'   => $settled.' settled of '.$processed.' checked',
        );
    }

    /* ================= virtual number settlement (§10, §11) ================ */

    /**
     * Poll live virtual-number reservations for their OTP, and expire the ones
     * whose deadline has passed.
     *
     * This is the worker the whole domain hinges on. A virtual number is the
     * first thing this panel sells where doing nothing is the expensive
     * option: the customer has already paid, the vendor is holding a number
     * for a handful of minutes, and if nobody checks, the reservation dies
     * unfulfilled with the charge still taken. So it must run often — and
     * every outcome, including "the deadline passed", is settled through
     * NumberService, which refunds via TransactionEngine.
     *
     * Expiry runs *after* polling on purpose: a code that arrived in the last
     * few seconds before the deadline still counts, and checking first means
     * the customer keeps a number they actually received a code on.
     */
    public function numbers_status($limit = 200) {
        $this->need(array('Virtual_number_model', 'Service_transaction_model'),
                    array('NumberService'));

        $live = $this->ci->Virtual_number_model->awaiting_sms($limit);
        $expired = $this->ci->Virtual_number_model->expired(null, $limit);
        if (!$live && !$expired) {
            return array('processed'=>0, 'failed'=>0, 'message'=>'no live number reservations');
        }

        $processed = 0; $failed = 0; $received = 0; $released = 0;
        $handled = array();

        foreach ($live as $number) {
            $handled[(int)$number->id] = true;
            try {
                $res = $this->ci->numberservice->poll($number, 'CRON');
            } catch (Exception $e) {
                log_message('error', 'numbers_status poll: '.$e->getMessage());
                $failed++;
                continue;
            }
            $processed++;
            if (empty($res['ok'])) { $failed++; continue; }
            if (!empty($res['new_messages'])) $received++;
            if (in_array($res['state'] ?? '', array('EXPIRED','CANCELLED','BANNED'), true)) $released++;
        }

        // Reservations the poll did not reach — no vendor reference, or the
        // list was truncated by $limit — still have to be settled, or a
        // customer stays charged for a number nobody will ever check again.
        foreach ($expired as $number) {
            if (isset($handled[(int)$number->id])) continue;
            try {
                $res = $this->ci->numberservice->expire($number, 'CRON');
            } catch (Exception $e) {
                log_message('error', 'numbers_status expire: '.$e->getMessage());
                $failed++;
                continue;
            }
            $processed++;
            if (empty($res['ok'])) { $failed++; continue; }
            $released++;
        }

        return array(
            'processed' => $processed,
            'failed'    => $failed,
            'message'   => $received.' received a code, '.$released.' released of '.$processed.' checked',
        );
    }

    /* ======================== identity retention =========================== */

    /**
     * Delete identity results that have outlived their retention window (§22).
     *
     * This is the job that makes the promise on the customer-facing page true.
     * It is scheduled nightly rather than hourly because retention is measured
     * in days, and a sweep that runs while staff are working is a sweep that
     * deletes a record somebody has open.
     *
     * The work itself lives in IdentityService::purge_expired(), so the
     * scheduled sweep and the admin's "delete this now" button clear exactly
     * the same fields. Only the payload goes; the row, the money and the audit
     * trail stay.
     */
    public function identity_purge($limit = 500) {
        $this->need(array('Identity_check_model'), array('IdentityService'));
        return $this->ci->identityservice->purge_expired(null, $limit);
    }

    /* ===================== gift card delivery (§23) ======================== */

    /**
     * Chase gift card orders the vendor accepted but has not issued codes for.
     *
     * This is the worker that closes the gap the domain is built around: a
     * gift card order is accepted in one call and delivered in another, and
     * between them the customer has paid for a code that does not exist yet.
     * Doing nothing leaves them charged indefinitely, so this runs every two
     * minutes — often enough that the usual case (a card issued seconds later,
     * already collected inline by the purchase itself) is a no-op, and slow
     * enough not to hammer a vendor that is genuinely still minting.
     *
     * The work lives in GiftcardService::settle_open_orders(), so the sweep,
     * the purchase path and the admin's "check now" button apply identical
     * rules — including the one that decides when an undelivered order stops
     * being worth retrying and becomes a refund.
     */
    public function giftcard_codes($limit = 100) {
        $this->need(array('Giftcard_order_model'), array('GiftcardService'));
        return $this->ci->giftcardservice->settle_open_orders($limit);
    }

    /* ===================== marketplace escrow release ===================== */

    /**
     * Release delivered marketplace orders after their inspection window.
     * Disputes never enter this query: opening one changes status and clears
     * release_due_at. The limit bounds both runtime and wallet lock pressure.
     */
    public function marketplace_release($limit = 100) {
        $this->need(array('Marketplace_order_model'), array('MarketplaceService'));
        $due = $this->ci->Marketplace_order_model->due_for_release($limit);
        if (!$due) return array('processed'=>0, 'failed'=>0, 'message'=>'no marketplace escrow due');

        $processed = 0; $failed = 0; $released = 0;
        foreach ($due as $order) {
            try {
                $res = $this->ci->marketplaceservice->release($order, 'CRON', null);
            } catch (Exception $e) {
                log_message('error', 'marketplace_release: '.$e->getMessage());
                $failed++;
                continue;
            }
            $processed++;
            if (empty($res['ok'])) $failed++; else $released++;
        }
        return array(
            'processed' => $processed,
            'failed' => $failed,
            'message' => $released.' marketplace escrow order(s) released',
        );
    }

    /* ======================= earnings maintenance ========================= */

    /**
     * Release earnings whose holding period has elapsed.
     *
     * Without this an earning created with a hold would sit PENDING forever and
     * could never be withdrawn — the holding period would be a life sentence
     * rather than a delay. EarningsService::release_due() moves each row with a
     * compare-and-set, so two overlapping runs cannot release the same earning
     * twice.
     */
    public function earnings_release($limit = 500) {
        $this->need(array('Earning_model'), array('EarningsService'));
        $released = $this->ci->earningsservice->release_due($limit);

        return array(
            'processed' => $released,
            'failed'    => 0,
            'message'   => $released
                ? $released.' earning(s) became available'
                : 'no earnings due for release',
        );
    }

    /**
     * Close out bank-transfer checkouts whose 30-minute window has passed.
     *
     * Cosmetic for the customer, load-bearing for support: a PENDING checkout
     * from three weeks ago is noise that hides the one from ten minutes ago
     * that genuinely needs attention. A late webhook still reconciles, because
     * the payment transaction itself is matched by reference, not by this row's
     * status.
     */
    public function fundsvera_expire($limit = 200) {
        $this->need(array('Fundsvera_checkout_model'));
        $expired = $this->ci->Fundsvera_checkout_model->expire_stale($limit);

        return array(
            'processed' => $expired,
            'failed'    => 0,
            'message'   => $expired
                ? $expired.' expired bank transfer checkout(s) closed'
                : 'no stale checkouts',
        );
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
        if (!marvy_feature_enabled('dripfeed', true)) {
            return array('processed'=>0, 'failed'=>0, 'message'=>'dripfeed feature disabled');
        }
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
        if (!marvy_feature_enabled('subscriptions', true)) {
            return array('processed'=>0, 'failed'=>0, 'message'=>'subscriptions feature disabled');
        }
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
     * Reconcile deposits the gateway never told us about, then age out the
     * ones that were genuinely never paid.
     *
     * This used to do only the second half: after seven days every PENDING
     * deposit was marked FAILED without anyone ever asking the provider
     * whether the money had arrived. A single lost webhook — a deploy during
     * the callback, a 500, a firewall blip, a signature refused because the
     * secret was rotated — therefore turned a real payment into a written-off
     * deposit, and the customer's money simply vanished from their point of
     * view.
     *
     * The order of work matters:
     *
     *   1. Replay callbacks we stored but never finished processing. They are
     *      already signature-verified; something transient stopped them.
     *   2. Ask each gateway directly about deposits that have been pending
     *      longer than the grace period. A provider-confirmed payment is
     *      credited (through PaymentService::confirm, so exactly once); a
     *      provider-confirmed failure is closed.
     *   3. Only then expire what is past the window AND could not be verified.
     *      A deposit whose gateway cannot be reached is never expired — an
     *      outage on our side must not cost a customer their money.
     *
     * @param int $stale_days     age at which an unverifiable deposit is closed
     * @param int $limit          rows examined per tick
     * @param int $grace_minutes  how long to let the webhook arrive on its own
     */
    public function payment_reconciliation($stale_days = null, $limit = 500, $grace_minutes = null) {
        $this->need(array('Payment_transaction_model', 'Payment_webhook_model', 'Wallet_model', 'Setting_model'),
                    array('PaymentService'));

        // Operators tune both windows from Admin → Settings → Deposits; the
        // arguments stay for tests and for a one-off manual run.
        if ($stale_days === null)    $stale_days    = $this->setting_int('deposit_expiry_days', 7, 1, 365);
        if ($grace_minutes === null) $grace_minutes = $this->setting_int('deposit_grace_minutes', 20, 1, 1440);

        $replayed = $this->replay_unprocessed_webhooks($limit);

        $grace  = gmdate('Y-m-d H:i:s', time() - ($grace_minutes * 60));
        $cutoff = gmdate('Y-m-d H:i:s', time() - ($stale_days * 86400));

        $pending = $this->ci->db
            ->where_in('status', array('CREATED', 'PENDING'))
            ->where('created_at <', $grace)
            ->order_by('created_at', 'ASC')
            ->limit($limit)
            ->get('payment_transactions')->result();

        $credited = 0; $closed = 0; $expired = 0; $unknown = 0; $failed = 0;

        foreach ($pending as $tx) {
            try {
                $verdict = $this->verify_with_gateway($tx);

                if ($verdict['status'] === 'SUCCESS') {
                    $res = $this->ci->paymentservice->confirm($tx, 'RECONCILIATION', $verdict['provider_tx_id']);
                    if (!empty($res['ok'])) {
                        $credited++;
                        log_message('error', 'payment_reconciliation: credited '.$tx->public_id
                            .' from a provider check — its webhook never arrived');
                    } else {
                        $failed++;
                    }
                    continue;
                }

                if ($verdict['status'] === 'FAILED') {
                    $this->ci->paymentservice->mark_failed($tx->id,
                        'Closed by reconciliation: the gateway reports this payment as '
                        .strtolower((string)($verdict['detail'] ?: 'failed')).'.');
                    $closed++;
                    continue;
                }

                // Still pending, or nobody to ask. Expire only when it is past
                // the window and the answer was neither "the provider is down"
                // nor "they paid, but short" — both of those need a human, and
                // writing either off would be writing off real money.
                $never_expire = in_array($verdict['status'], array('UNREACHABLE', 'UNDERPAID'), true);
                if (!$never_expire && (string)($tx->created_at ?? '') < $cutoff) {
                    $this->ci->paymentservice->mark_failed($tx->id,
                        "Expired: no payment received within {$stale_days} days");
                    $expired++;
                } else {
                    $unknown++;
                }
            } catch (Throwable $e) {
                $failed++;
                log_message('error', 'payment_reconciliation '.$tx->id.': '.$e->getMessage());
            }
        }

        $message = sprintf(
            '%d webhook(s) replayed, %d deposit(s) credited from a provider check, '
            .'%d closed as failed, %d expired, %d still open',
            $replayed['processed'], $credited, $closed, $expired, $unknown
        );

        return array(
            'processed' => $replayed['processed'] + $credited + $closed + $expired,
            'failed'    => $failed + $replayed['failed'],
            'credited'  => $credited,
            'closed'    => $closed,
            'expired'   => $expired,
            'pending'   => $unknown,
            'message'   => $message,
        );
    }

    /** A bounded integer setting, with the documented default when unset. */
    private function setting_int($key, $default, $min, $max) {
        try {
            if (!isset($this->ci->Setting_model)) return $default;
            $value = $this->ci->Setting_model->get($key, $default);
        } catch (Throwable $e) {
            return $default;
        }
        if (!is_numeric($value)) return $default;
        return (int)min($max, max($min, (int)$value));
    }

    /**
     * Ask the gateway what actually happened to one deposit.
     *
     * @return array{status:string, provider_tx_id:?string, detail:?string}
     *         status is SUCCESS | FAILED | PENDING | UNREACHABLE | NO_VERIFIER
     */
    private function verify_with_gateway($tx) {
        $none = array('status' => 'NO_VERIFIER', 'provider_tx_id' => null, 'detail' => null);

        $method = isset($tx->payment_method_id)
            ? $this->ci->db->where('id', (int)$tx->payment_method_id)->get('payment_methods')->row()
            : null;
        $code = strtolower((string)($method->code ?? ($tx->provider ?? '')));
        if ($code === '' || $code === 'manual') return $none;

        $gateway = $this->ci->paymentservice->adapter_for($method ?: (object)array('code' => $code));
        // Only adapters that can ask the provider a question take part; the
        // rest fall through to the age-out rule below.
        if (!method_exists($gateway, 'verify')) return $none;
        if (method_exists($gateway, 'is_configured') && !$gateway->is_configured()) return $none;

        // The reference we gave the provider at initiation. Without one there
        // is nothing to look up.
        $reference = $tx->provider_tx_id ?: $tx->internal_reference;
        if (!$reference) return $none;

        $res = $gateway->verify($reference);
        if (empty($res['ok'])) {
            // "Could not reach" must never become "expired": treat every
            // provider-side error as unknown and try again next tick.
            return array('status' => 'UNREACHABLE', 'provider_tx_id' => null,
                         'detail' => $res['error'] ?? null);
        }

        $status = strtoupper((string)($res['status'] ?? 'PENDING'));
        if ($status === 'SUCCESS' && !$this->amount_covers_deposit($tx, $res)) {
            // Paid, but short. Crediting the full figure would hand out money
            // that never arrived; staff resolve it from the payment screen.
            $this->ci->Payment_transaction_model->update_status($tx->id, array(
                'metadata' => $this->merge_metadata($tx, array('reconciliation' => array(
                    'underpaid'       => true,
                    'expected'        => (string)$tx->amount,
                    'provider_amount' => (string)($res['amount'] ?? ''),
                    'checked_at'      => gmdate('Y-m-d H:i:s'),
                ))),
            ));
            log_message('error', 'payment_reconciliation: '.$tx->public_id.' is short — provider reports '
                .($res['amount'] ?? '?').' against '.$tx->amount.'; left for staff');
            // Never expired: the money did arrive, just not all of it.
            return array('status' => 'UNDERPAID', 'provider_tx_id' => $res['provider_tx_id'] ?? null,
                         'detail' => 'underpaid');
        }

        return array(
            'status'         => in_array($status, array('SUCCESS', 'FAILED'), true) ? $status : 'PENDING',
            'provider_tx_id' => $res['provider_tx_id'] ?? null,
            'detail'         => $res['status'] ?? null,
        );
    }

    /** Whether what the provider says arrived covers what we expected. */
    private function amount_covers_deposit($tx, array $res) {
        if (!isset($res['amount']) || $res['amount'] === null || $res['amount'] === '') {
            // The gateway did not report an amount; the deposit row is the
            // only figure we have and the webhook path trusts it too.
            return true;
        }
        return bccomp((string)$res['amount'], (string)$tx->amount, 8) >= 0;
    }

    /** Merge a key into a transaction's metadata JSON without losing the rest. */
    private function merge_metadata($tx, array $extra) {
        $meta = json_decode((string)$tx->metadata, true);
        $meta = is_array($meta) ? $meta : array();
        return json_encode(array_merge($meta, $extra), JSON_UNESCAPED_SLASHES);
    }

    /**
     * Re-run callbacks that were stored but never finished processing.
     *
     * record_webhook() leaves a row unprocessed when the credit itself failed
     * (a ledger rollback, a database blip). The gateway usually retries, but
     * not every gateway does and not forever — so the panel retries too. Only
     * signature-verified rows are replayed: an unverified event is evidence of
     * a misconfiguration, not a payment.
     */
    private function replay_unprocessed_webhooks($limit = 100) {
        $rows = $this->ci->db
            ->where('processed', 0)
            ->where('signature_valid', 1)
            ->order_by('created_at', 'ASC')
            ->limit($limit)
            ->get('payment_webhooks')->result();

        $done = 0; $failed = 0;
        foreach ($rows as $row) {
            try {
                // Not record_webhook(): the signature headers are long gone, so
                // re-verifying would close a verified event as forged.
                $res = $this->ci->paymentservice->reprocess_stored_webhook($row);
                if (!empty($res['ok'])) $done++; else $failed++;
            } catch (Throwable $e) {
                $failed++;
                log_message('error', 'payment_reconciliation replay '.$row->id.': '.$e->getMessage());
            }
        }
        return array('processed' => $done, 'failed' => $failed);
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

    /* ========================= security PIN rotation ======================= */

    /**
     * Automatically rotate any transaction PIN older than the configured
     * window (24 hours by default; Admin → Settings → pin_rotation_hours).
     *
     * Applies uniformly to every account that has a PIN set, including ones
     * created long before this worker existed — pin_set_at has been populated
     * since migration 020, so a pre-existing PIN is simply overdue on the
     * first sweep after this job is deployed rather than being grandfathered
     * in. Each user is issued a brand-new random PIN (PinService::rotate()),
     * notified in-app and by email, and the change is audited. Disabling
     * `pin_auto_rotation_enabled` turns the sweep into a no-op without
     * touching any PIN already set.
     */
    public function pin_rotation($limit = 200) {
        $this->need(array(), array('PinService'));

        if (!$this->ci->pinservice->rotation_enabled()) {
            return array('processed'=>0, 'failed'=>0, 'message'=>'automatic PIN rotation is disabled');
        }

        $due = $this->ci->pinservice->due_for_rotation($limit);
        if (!$due) return array('processed'=>0, 'failed'=>0, 'message'=>'no PINs due for rotation');

        $processed = 0; $failed = 0;
        foreach ($due as $user) {
            try {
                $res = $this->ci->pinservice->rotate($user);
                if (!empty($res['ok'])) {
                    $processed++;
                } else {
                    $failed++;
                    log_message('error', "pin_rotation user {$user->id}: ".($res['error'] ?? 'unknown'));
                }
            } catch (Exception $e) {
                $failed++;
                log_message('error', "pin_rotation user {$user->id} threw: ".$e->getMessage());
            }
        }
        return array(
            'processed' => $processed,
            'failed'    => $failed,
            'message'   => "{$processed} PIN(s) rotated, {$failed} failed",
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
