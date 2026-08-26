<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * ReferralService — referral codes, campaign codes, attribution and qualifying.
 *
 * ## The trust boundary
 *
 * Nothing the browser sends is trusted to decide money. The frontend may say
 * "this visitor arrived with code JOHN8K24"; this service decides whether that
 * code exists, whether it is active, whether the visitor is allowed to use it,
 * whether the resulting signup ever qualifies, and how much it is worth. The
 * reward amount is read from the code's own configuration, never from the
 * request.
 *
 * ## Attribution is once, and permanent
 *
 * `referral_signups.referred_user_id` is UNIQUE. An account can be attributed
 * to exactly one referrer, on creation, forever. That single constraint kills
 * the whole class of "re-attribute an existing user to a new referrer to farm
 * another reward" attacks, without any code needing to remember to check.
 *
 * ## Qualification is an event, not a click
 *
 * A click earns nothing. A signup earns nothing. The configured qualifying
 * event (first deposit, first order, email verified) is what moves a signup to
 * QUALIFIED, and only then is an earning written — with an idempotency key
 * derived from the signup, so the event firing twice pays once.
 */
class ReferralService {

    const STATUS_PENDING      = 'PENDING';
    const STATUS_QUALIFIED    = 'QUALIFIED';
    const STATUS_REWARDED     = 'REWARDED';
    const STATUS_REJECTED     = 'REJECTED';
    const STATUS_FRAUD_REVIEW = 'FRAUD_REVIEW';

    /** Qualifying events a code or campaign may require. */
    const EVENTS = array('REGISTERED', 'EMAIL_VERIFIED', 'FIRST_DEPOSIT', 'FIRST_ORDER');

    /** Where the pending code lives between the click and the registration. */
    const SESSION_KEY = 'referral_pending';

    /** How long a click stays attributable. */
    const ATTRIBUTION_DAYS = 30;

    private $ci;

    public function __construct() {
        $this->ci =& get_instance();
        $this->ci->load->model(array(
            'Referral_code_model', 'Referral_campaign_model',
            'Referral_signup_model', 'Setting_model',
        ));
        $this->ci->load->library('EarningsService');
    }

    /* ------------------------------------------------------------------ */
    /* Codes                                                               */
    /* ------------------------------------------------------------------ */

    /**
     * This user's referral code, creating one on first use.
     *
     * The code is derived from the username so it is recognisable to the person
     * sharing it (JOHN8K24 rather than a7f3c1), with random characters to stop
     * it being guessable from the username alone.
     */
    public function code_for($user) {
        $existing = $this->ci->Referral_code_model->primary_for_user($user->id);
        if ($existing) return $existing;

        $code = $this->generate_code($user);
        $id = $this->ci->Referral_code_model->create(array(
            'user_id' => (int)$user->id,
            'code'    => $code,
            'label'   => 'Personal referral code',
        ));
        return $this->ci->Referral_code_model->find_by_id($id);
    }

    /** The shareable link for a code, built from the configured base URL. */
    public function link_for($code) {
        return site_url('register').'?ref='.rawurlencode((string)$code);
    }

    /**
     * Resolve a code to whatever it points at.
     *
     * @return array{ok:bool, kind?:string, code?:object, campaign?:object,
     *               owner_id?:int, error?:string, code_error?:string}
     */
    public function resolve($raw) {
        $code = $this->normalise($raw);
        if ($code === '') return $this->err('EMPTY', 'Enter a referral code.');

        $campaign = $this->ci->Referral_campaign_model->by_code($code);
        if ($campaign) {
            $state = $this->campaign_usable($campaign);
            if (!$state['ok']) return $state;
            return array('ok' => true, 'kind' => 'CAMPAIGN', 'campaign' => $campaign, 'owner_id' => null);
        }

        $row = $this->ci->Referral_code_model->by_code($code);
        if (!$row) return $this->err('UNKNOWN', 'That referral code was not recognised.');
        if ((int)$row->is_active !== 1) return $this->err('INACTIVE', 'That referral code is no longer active.');

        return array('ok' => true, 'kind' => 'USER', 'code' => $row, 'owner_id' => (int)$row->user_id);
    }

    /** Whether a campaign is live, in budget and in date. */
    private function campaign_usable($campaign) {
        if ($campaign->status !== 'ACTIVE') {
            return $this->err('CAMPAIGN_INACTIVE', 'That campaign is not running.');
        }
        $now = gmdate('Y-m-d H:i:s');
        if (!empty($campaign->starts_at) && $campaign->starts_at > $now) {
            return $this->err('CAMPAIGN_NOT_STARTED', 'That campaign has not started yet.');
        }
        if (!empty($campaign->ends_at) && $campaign->ends_at < $now) {
            return $this->err('CAMPAIGN_ENDED', 'That campaign has ended.');
        }
        if ($campaign->budget !== null && bccomp((string)$campaign->spent, (string)$campaign->budget, 8) >= 0) {
            // Still lets the person register — it just will not pay a reward.
            return $this->err('CAMPAIGN_EXHAUSTED', 'That campaign has reached its budget.');
        }
        return array('ok' => true);
    }

    /* ------------------------------------------------------------------ */
    /* Click attribution                                                   */
    /* ------------------------------------------------------------------ */

    /**
     * Record a visit and hold the code for the registration that may follow.
     *
     * Stored in the session rather than a query string the form has to carry:
     * the requirement is that the referral survives a page refresh, a
     * validation error and a navigation away and back, and only server-side
     * session state does that reliably.
     */
    public function remember_visit($raw, $landing_path = null) {
        $resolved = $this->resolve($raw);
        if (empty($resolved['ok'])) return $resolved;

        $code = $this->normalise($raw);

        $this->ci->session->set_userdata(self::SESSION_KEY, array(
            'code'       => $code,
            'kind'       => $resolved['kind'],
            'seen_at'    => time(),
        ));

        $this->ci->load->model('Referral_visit_model');
        $this->ci->Referral_visit_model->record(array(
            'code'             => $code,
            'referral_code_id' => isset($resolved['code']) ? (int)$resolved['code']->id : null,
            'campaign_id'      => isset($resolved['campaign']) ? (int)$resolved['campaign']->id : null,
            'visitor_hash'     => $this->visitor_hash(),
            'landing_path'     => $landing_path ? mb_substr($landing_path, 0, 255) : null,
        ));

        if (isset($resolved['code'])) {
            $this->ci->Referral_code_model->bump($resolved['code']->id, 'total_visits');
        }
        if (isset($resolved['campaign'])) {
            $this->ci->Referral_campaign_model->bump($resolved['campaign']->id, 'total_visits');
        }

        return array('ok' => true, 'kind' => $resolved['kind']);
    }

    /** The code held for this visitor, if any. */
    public function pending_code() {
        $held = $this->ci->session->userdata(self::SESSION_KEY);
        if (!is_array($held) || empty($held['code'])) return null;

        // Expire a stale attribution rather than crediting a referrer for a
        // signup that happened months after the click.
        if (isset($held['seen_at']) && (time() - (int)$held['seen_at']) > (self::ATTRIBUTION_DAYS * 86400)) {
            $this->ci->session->unset_userdata(self::SESSION_KEY);
            return null;
        }
        return (string)$held['code'];
    }

    public function forget_pending() {
        $this->ci->session->unset_userdata(self::SESSION_KEY);
    }

    /* ------------------------------------------------------------------ */
    /* Attribution at registration                                          */
    /* ------------------------------------------------------------------ */

    /**
     * Permanently attach a new account to the code that brought it.
     *
     * Runs the fraud checks and records the outcome either way: a rejected or
     * flagged referral is still written down, because "we saw this and refused
     * it" is information staff need.
     *
     * @return array{ok:bool, signup?:object, status?:string, error?:string, code?:string}
     */
    public function attribute($referred_user, $raw_code = null) {
        $code = $this->normalise($raw_code !== null ? $raw_code : $this->pending_code());
        if ($code === '') return array('ok' => false, 'code' => 'NO_CODE');

        $resolved = $this->resolve($code);
        if (empty($resolved['ok'])) {
            $this->forget_pending();
            return $resolved;
        }

        // One attribution per account, ever.
        if ($this->ci->Referral_signup_model->for_referred($referred_user->id)) {
            $this->forget_pending();
            return array('ok' => false, 'code' => 'ALREADY_ATTRIBUTED',
                         'error' => 'This account is already attributed to a referrer.');
        }

        $referrer_id = $resolved['owner_id'];
        $flags = $this->fraud_flags($referrer_id, $referred_user);

        $status = self::STATUS_PENDING;
        if (in_array('SELF_REFERRAL', $flags, true)) {
            // Not a judgement call: referring yourself is never legitimate.
            $status = self::STATUS_REJECTED;
        } elseif ($flags) {
            $status = self::STATUS_FRAUD_REVIEW;
        }

        $id = $this->ci->Referral_signup_model->create(array(
            'referrer_user_id' => $referrer_id,
            'referred_user_id' => (int)$referred_user->id,
            'referral_code'    => $code,
            'referral_code_id' => isset($resolved['code']) ? (int)$resolved['code']->id : null,
            'campaign_id'      => isset($resolved['campaign']) ? (int)$resolved['campaign']->id : null,
            'status'           => $status,
            'fraud_flags'      => $flags ? implode(',', $flags) : null,
            'signup_ip_hash'   => $this->visitor_hash(),
        ));

        if (isset($resolved['code'])) {
            $this->ci->Referral_code_model->bump($resolved['code']->id, 'total_signups');
        }
        if (isset($resolved['campaign'])) {
            $this->ci->Referral_campaign_model->bump($resolved['campaign']->id, 'total_signups');
        }
        $this->ci->load->model('Referral_visit_model');
        $this->ci->Referral_visit_model->mark_converted($this->visitor_hash(), $referred_user->id);

        $this->forget_pending();

        $signup = $this->ci->Referral_signup_model->find_by_id($id);

        // REGISTERED-qualifying codes pay out immediately on signup.
        if ($status === self::STATUS_PENDING && $this->qualify_event_for($signup) === 'REGISTERED') {
            $this->qualify($signup, 'REGISTERED');
            $signup = $this->ci->Referral_signup_model->find_by_id($id);
        }

        return array('ok' => true, 'signup' => $signup, 'status' => $status);
    }

    /* ------------------------------------------------------------------ */
    /* Qualification                                                        */
    /* ------------------------------------------------------------------ */

    /**
     * Tell the referral system that a user did something.
     *
     * Called from the places those things actually happen (email verified, a
     * deposit confirmed, an order placed). Does nothing unless the user was
     * referred and the event is the one their code requires, so callers can
     * fire it unconditionally.
     */
    public function record_event($user_id, $event) {
        $event = strtoupper((string)$event);
        if (!in_array($event, self::EVENTS, true)) return array('ok' => false, 'code' => 'UNKNOWN_EVENT');

        $signup = $this->ci->Referral_signup_model->for_referred((int)$user_id);
        if (!$signup) return array('ok' => false, 'code' => 'NOT_REFERRED');
        if ($signup->status !== self::STATUS_PENDING) {
            return array('ok' => false, 'code' => 'NOT_PENDING', 'status' => $signup->status);
        }
        if ($this->qualify_event_for($signup) !== $event) {
            return array('ok' => false, 'code' => 'WRONG_EVENT');
        }

        return $this->qualify($signup, $event);
    }

    /**
     * Move a signup to QUALIFIED and write the earning.
     *
     * The earning's idempotency key is the signup's public id, so however many
     * times the qualifying event fires, exactly one earning exists.
     */
    public function qualify($signup, $event = null) {
        if (!$signup) return array('ok' => false, 'code' => 'NO_SIGNUP');

        // Compare-and-set so a double-fired event cannot qualify twice.
        $moved = $this->ci->Referral_signup_model->transition(
            $signup->id, self::STATUS_PENDING, self::STATUS_QUALIFIED,
            array('qualified_at' => gmdate('Y-m-d H:i:s'))
        );
        if (!$moved) {
            return array('ok' => false, 'code' => 'ALREADY_HANDLED');
        }

        $reward = $this->reward_for($signup);
        if ($reward === null || bccomp($reward, '0', 8) <= 0) {
            // Qualified but worth nothing (a campaign with no reward, or an
            // exhausted budget). The referral still counts for reporting.
            return array('ok' => true, 'qualified' => true, 'rewarded' => false);
        }

        // A campaign signup with no referrer pays nobody — it is an
        // acquisition metric, not a commission.
        if (!$signup->referrer_user_id) {
            $this->ci->Referral_signup_model->transition(
                $signup->id, self::STATUS_QUALIFIED, self::STATUS_REWARDED,
                array('rewarded_at' => gmdate('Y-m-d H:i:s'))
            );
            $this->count_qualified($signup);
            return array('ok' => true, 'qualified' => true, 'rewarded' => false, 'reason' => 'CAMPAIGN_NO_REFERRER');
        }

        $credit = $this->ci->earningsservice->credit(array(
            'user_id'            => (int)$signup->referrer_user_id,
            'source'             => $signup->campaign_id ? 'CAMPAIGN' : 'REFERRAL',
            'amount'             => $reward,
            'referral_signup_id' => (int)$signup->id,
            'campaign_id'        => $signup->campaign_id ? (int)$signup->campaign_id : null,
            'hold_hours'         => $this->hold_hours_for($signup),
            'description'        => 'Referral reward for '.$signup->referral_code,
            // Derived from the signup, not the clock: this is what makes a
            // repeated qualifying event pay exactly once.
            'idempotency_key'    => 'referral:'.$signup->public_id,
        ));

        if (empty($credit['ok'])) {
            log_message('error', 'referral: could not credit reward: '.($credit['error'] ?? ''));
            return array('ok' => false, 'code' => 'CREDIT_FAILED');
        }

        $this->ci->Referral_signup_model->transition(
            $signup->id, self::STATUS_QUALIFIED, self::STATUS_REWARDED,
            array('rewarded_at' => gmdate('Y-m-d H:i:s'))
        );
        $this->count_qualified($signup);

        if ($signup->campaign_id) {
            $this->ci->Referral_campaign_model->add_spend($signup->campaign_id, $reward);
        }

        return array('ok' => true, 'qualified' => true, 'rewarded' => true,
                     'amount' => $reward, 'duplicate' => !empty($credit['duplicate']));
    }

    /* ------------------------------------------------------------------ */
    /* Fraud                                                                */
    /* ------------------------------------------------------------------ */

    /**
     * Reasons this referral looks wrong.
     *
     * Returns flags rather than a boolean so staff see *why* something was held,
     * and so a soft signal (same IP — could be a shared household or an office)
     * can queue for review while a hard signal (self-referral) is refused
     * outright.
     */
    public function fraud_flags($referrer_id, $referred_user) {
        $flags = array();

        if ($referrer_id && (int)$referrer_id === (int)$referred_user->id) {
            $flags[] = 'SELF_REFERRAL';
        }

        if ($referrer_id) {
            $referrer = $this->ci->db->where('id', (int)$referrer_id)->get('users')->row();

            if ($referrer) {
                // Same email local-part with a plus-tag: alice+1@x.com signing
                // up under alice@x.com.
                if ($this->same_email_identity($referrer->email, $referred_user->email)) {
                    $flags[] = 'SAME_EMAIL_IDENTITY';
                }
                if ($referrer->status !== 'ACTIVE') {
                    $flags[] = 'REFERRER_INACTIVE';
                }
                // A → B → A.
                $reverse = $this->ci->Referral_signup_model->for_referred((int)$referrer_id);
                if ($reverse && (int)$reverse->referrer_user_id === (int)$referred_user->id) {
                    $flags[] = 'REFERRAL_LOOP';
                }
            }
        }

        // Several accounts from one device/IP in a short window.
        $hash = $this->visitor_hash();
        if ($hash) {
            $recent = $this->ci->Referral_signup_model->count_by_ip_hash($hash, 24);
            if ($recent >= $this->max_per_ip_per_day()) {
                $flags[] = 'IP_VELOCITY';
            }
        }

        // The referrer has hit their configured cap.
        if ($referrer_id) {
            $max = $this->max_referrals_per_user();
            if ($max > 0) {
                $count = $this->ci->Referral_signup_model->count_for_referrer((int)$referrer_id);
                if ($count >= $max) $flags[] = 'REFERRER_CAP_REACHED';
            }
        }

        return $flags;
    }

    /** Staff decision on a flagged referral. */
    public function review($signup, $decision, $actor, $note = null) {
        if (!$signup) return array('ok' => false, 'code' => 'NO_SIGNUP');
        $decision = strtoupper((string)$decision);

        if ($decision === 'APPROVE') {
            $this->ci->Referral_signup_model->transition(
                $signup->id, self::STATUS_FRAUD_REVIEW, self::STATUS_PENDING,
                array('fraud_flags' => null)
            );
            return array('ok' => true, 'status' => self::STATUS_PENDING);
        }

        if ($decision === 'REJECT') {
            $this->ci->Referral_signup_model->transition(
                $signup->id, $signup->status, self::STATUS_REJECTED
            );
            return array('ok' => true, 'status' => self::STATUS_REJECTED);
        }

        return array('ok' => false, 'code' => 'UNKNOWN_DECISION');
    }

    /* ------------------------------------------------------------------ */
    /* Reporting                                                            */
    /* ------------------------------------------------------------------ */

    public function dashboard_for($user) {
        $code = $this->code_for($user);
        $counts = $this->ci->Referral_signup_model->counts_for_referrer($user->id);
        $balance = $this->ci->earningsservice->balance($user->id);

        return array(
            'code'      => $code->code,
            'link'      => $this->link_for($code->code),
            'visits'    => (int)$code->total_visits,
            'signups'   => (int)$code->total_signups,
            'qualified' => (int)$code->total_qualified,
            'counts'    => $counts,
            'earnings'  => $balance,
            'by_source' => $this->ci->earningsservice->by_source($user->id),
        );
    }

    /* ------------------------------------------------------------------ */

    /** Which event this signup's code requires to qualify. */
    private function qualify_event_for($signup) {
        if ($signup->campaign_id) {
            $c = $this->ci->Referral_campaign_model->find_by_id($signup->campaign_id);
            if ($c && in_array($c->qualify_event, self::EVENTS, true)) return $c->qualify_event;
        }
        $v = strtoupper((string)$this->setting('referral_qualify_event', 'FIRST_ORDER'));
        return in_array($v, self::EVENTS, true) ? $v : 'FIRST_ORDER';
    }

    /** What this signup is worth. */
    private function reward_for($signup) {
        if ($signup->campaign_id) {
            $c = $this->ci->Referral_campaign_model->find_by_id($signup->campaign_id);
            if (!$c) return null;
            if ($c->budget !== null) {
                $remaining = bcsub((string)$c->budget, (string)$c->spent, 8);
                if (bccomp($remaining, '0', 8) <= 0) return null;
                // Never overspend the budget on the last reward.
                if (bccomp((string)$c->reward_amount, $remaining, 8) > 0) return $remaining;
            }
            if ($c->max_rewards !== null && (int)$c->total_qualified >= (int)$c->max_rewards) return null;
            return (string)$c->reward_amount;
        }
        return (string)$this->setting('referral_signup_reward', '0.00000000');
    }

    private function hold_hours_for($signup) {
        if ($signup->campaign_id) {
            $c = $this->ci->Referral_campaign_model->find_by_id($signup->campaign_id);
            if ($c) return max(0, (int)$c->hold_hours);
        }
        return $this->ci->earningsservice->default_hold_hours();
    }

    private function count_qualified($signup) {
        if ($signup->referral_code_id) {
            $this->ci->Referral_code_model->bump($signup->referral_code_id, 'total_qualified');
        }
        if ($signup->campaign_id) {
            $this->ci->Referral_campaign_model->bump($signup->campaign_id, 'total_qualified');
        }
    }

    /**
     * A stable pseudonym for the visitor.
     *
     * IP and user agent, salted with the app secret and hashed. Enough to spot
     * ten signups from one machine; not a stored IP address, so the fraud
     * signal does not become a second copy of everyone's personal data.
     */
    private function visitor_hash() {
        if (!isset($this->ci->input)) return str_repeat('0', 64);
        $ip = (string)$this->ci->input->ip_address();
        $ua = (string)$this->ci->input->user_agent();
        $salt = (string)(getenv('VP_AUTH_SECRET') ?: getenv('APP_KEY') ?: 'marvy-referral');
        return hash('sha256', $salt.'|'.$ip.'|'.$ua);
    }

    private function same_email_identity($a, $b) {
        $norm = function ($email) {
            $email = strtolower(trim((string)$email));
            $at = strpos($email, '@');
            if ($at === false) return $email;
            $local = substr($email, 0, $at);
            $domain = substr($email, $at);
            $plus = strpos($local, '+');
            if ($plus !== false) $local = substr($local, 0, $plus);
            // Gmail ignores dots in the local part.
            if ($domain === '@gmail.com' || $domain === '@googlemail.com') {
                $local = str_replace('.', '', $local);
            }
            return $local.$domain;
        };
        return $norm($a) === $norm($b);
    }

    /** JOHN8K24 — recognisable, but not guessable from the username alone. */
    private function generate_code($user) {
        $base = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)$user->username));
        $base = $base !== '' ? substr($base, 0, 6) : 'MARVY';

        for ($attempt = 0; $attempt < 40; $attempt++) {
            $suffix = strtoupper(substr(str_replace(
                array('0', 'O', '1', 'I', 'L'), array('2', '3', '4', '5', '6'),
                bin2hex(random_bytes(3))
            ), 0, 4));
            $code = $base.$suffix;
            if (!$this->ci->Referral_code_model->by_code($code)
                && !$this->ci->Referral_campaign_model->by_code($code)) {
                return $code;
            }
        }
        return 'MVS'.strtoupper(bin2hex(random_bytes(5)));
    }

    private function normalise($raw) {
        return strtoupper(trim((string)$raw));
    }

    public function max_referrals_per_user() {
        return (int)$this->setting('referral_max_per_user', 0);
    }

    private function max_per_ip_per_day() {
        return max(1, (int)$this->setting('referral_max_per_ip_day', 3));
    }

    private function setting($key, $default) {
        try {
            $v = $this->ci->Setting_model->get($key, $default);
            return ($v === null || $v === '') ? $default : $v;
        } catch (Throwable $e) {
            return $default;
        }
    }

    private function err($code, $message) {
        return array('ok' => false, 'code' => $code, 'error' => $message);
    }
}
