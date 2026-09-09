<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * On-site assistant HTTP API.
 *
 * POST /assistant/chat  — JSON { message, history? }
 * GET  /assistant       — full-page chat (progressive enhancement)
 *
 * No third-party AI. Rate-limited. CSRF required on POST via the global filter.
 */
class Chat extends Public_Controller {

    public function __construct() {
        parent::__construct();
        $this->load->library(array('SiteOperatorEngine', 'RateLimiter'));
    }

    public function index() {
        $welcome = $this->siteoperatorengine->welcome();
        $this->render_public('public/assistant', array(
            'title' => 'Site assistant',
            'meta_description' => 'Ask MarvySocials’s on-site assistant about services, pricing, accounts and navigation. No external AI API is used.',
            'meta_robots' => 'noindex',
            'welcome' => $welcome,
        ));
    }

    public function message() {
        if ($this->input->method(true) !== 'POST') {
            $this->json_error('METHOD_NOT_ALLOWED', 'Use POST.', 405);
            return;
        }

        $ip = $this->input->ip_address();
        $bucket = RateLimiter::scope('assistant', $ip);
        if ($this->ratelimiter->too_many_failures($ip, $bucket, 20, 3600)) {
            $retry = $this->ratelimiter->retry_after($ip, $bucket, 3600, 20);
            $this->json_error(
                'RATE_LIMITED',
                'Too many assistant questions from this network. Try again in '.max(1, (int)ceil($retry / 60)).' minute(s).',
                429
            );
            return;
        }

        $payload = $this->read_json_body();
        $message = isset($payload['message']) ? $payload['message'] : $this->input->post('message');
        $history = isset($payload['history']) && is_array($payload['history']) ? $payload['history'] : array();
        $history = $this->sanitize_history($history);

        $result = $this->siteoperatorengine->reply($message, $history);

        // Count every question, successful or not, so the hourly cap is real.
        $this->ratelimiter->record(
            $bucket,
            $ip,
            false,
            empty($result['ok']) ? ($result['error'] ?? 'ASSISTANT') : 'ASSISTANT_OK',
            $this->input->user_agent()
        );

        if (empty($result['ok'])) {
            $this->json_error($result['error'] ?? 'BAD_REQUEST', $result['reply'], 400);
            return;
        }

        // Auto-escalation — the recorded design of this assistant: it answers
        // from the local knowledge base, and anything outside that file
        // becomes a ticket. A signed-in customer whose question the engine
        // could not answer gets a support ticket opened on the spot (one per
        // customer per 24 hours — repeats join the open ticket instead). A
        // visitor has no account to hang a ticket on, so the visitor keeps
        // the contact-form hand-off.
        $links = $result['links'];
        $reply = $result['reply'];
        $ticket = null;
        $ticket_action = null;

        if (!empty($result['unanswered'])) {
            $user = $this->current_user();
            if ($user) {
                $this->load->library('TicketService');
                $ticket = $this->ticketservice->recent_assistant_ticket($user, 24);
                if ($ticket) {
                    $ticket_action = 'existing';
                } else {
                    $open = $this->ticketservice->open($user, array(
                        'subject'    => $this->assistant_subject($message),
                        'message'    => $this->assistant_ticket_body($message),
                        'department' => 'other',
                        'priority'   => 'MEDIUM',
                        'source'     => 'assistant',
                    ));
                    if (!empty($open['ok'])) {
                        $ticket = $open['ticket'];
                        $ticket_action = 'created';
                    } else {
                        log_message('error', 'assistant escalation failed for user '.$user->id.': '.($open['code'] ?? 'UNKNOWN'));
                    }
                }
            }

            if ($ticket) {
                $reply = $ticket_action === 'created'
                    ? 'I could not answer that from the site knowledge, so I opened a support ticket with your question — ticket '.$ticket->public_id.'. Staff will reply there; you can follow it from Your tickets.'
                    : 'I could not answer that from the site knowledge. That question is already with support — ticket '.$ticket->public_id.' — so I did not open a duplicate. Follow it from Your tickets.';
                array_unshift($links, array(
                    'label' => 'Ticket '.$ticket->public_id,
                    'href'  => 'dashboard/tickets/'.$ticket->public_id,
                ));
            } else {
                $reply .= ' If you want a person on this, the contact form takes it up as a support message.';
                if (!in_array('contact', array_column($links, 'href'), true)) {
                    $links[] = array('label' => 'Contact', 'href' => 'contact');
                }
            }
        }

        $this->json_success(array(
            'reply' => $reply,
            'intent' => $result['intent'],
            'links' => $this->absolutize_links($links),
            'suggestions' => $result['suggestions'],
            'ticket' => $ticket ? $ticket->public_id : null,
            'ticket_action' => $ticket_action,
            'disclaimer' => ($result['intent'] === 'capabilities' || $result['intent'] === 'welcome')
                ? SiteOperatorKnowledge::assistant_disclaimer()
                : null,
        ));
    }

    /** Ticket subject: where it came from plus the question, kept short. */
    private function assistant_subject($message) {
        $q = preg_replace('/\s+/', ' ', trim((string)$message));
        if (function_exists('mb_substr') && mb_strlen($q) > 80) {
            $q = mb_substr($q, 0, 80).'…';
        }
        return $q === '' ? 'Site assistant question' : 'Site assistant: '.$q;
    }

    /** The ticket body: the question verbatim, plus how it got here. */
    private function assistant_ticket_body($message) {
        return trim((string)$message)."

— Sent through the site assistant, which could not answer from the site knowledge base and opened this ticket automatically.";
    }

    public function welcome() {
        if ($this->input->method(true) !== 'GET') {
            $this->json_error('METHOD_NOT_ALLOWED', 'Use GET.', 405);
            return;
        }
        $welcome = $this->siteoperatorengine->welcome();
        $this->json_success(array(
            'reply' => $welcome['reply'],
            'intent' => $welcome['intent'],
            'links' => $this->absolutize_links($welcome['links']),
            'suggestions' => $welcome['suggestions'],
        ));
    }

    /* ------------------------------------------------------------------ */

    private function read_json_body() {
        $raw = $this->input->raw_input_stream;
        if (!is_string($raw) || $raw === '') return array();
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : array();
    }

    private function sanitize_history(array $history) {
        $out = array();
        foreach (array_slice($history, -8) as $row) {
            if (!is_array($row)) continue;
            $role = isset($row['role']) ? strtolower((string)$row['role']) : '';
            if ($role !== 'user' && $role !== 'assistant') continue;
            $content = isset($row['content']) ? trim((string)$row['content']) : '';
            if ($content === '') continue;
            if (function_exists('mb_substr')) {
                $content = mb_substr($content, 0, 1000);
            } else {
                $content = substr($content, 0, 1000);
            }
            $out[] = array('role' => $role, 'content' => $content);
        }
        return $out;
    }

    private function absolutize_links(array $links) {
        $out = array();
        foreach ($links as $link) {
            if (empty($link['href']) || empty($link['label'])) continue;
            $href = (string)$link['href'];
            if (strpos($href, 'http://') !== 0 && strpos($href, 'https://') !== 0) {
                $href = site_url($href);
            }
            $out[] = array('label' => (string)$link['label'], 'href' => $href);
        }
        return $out;
    }
}
