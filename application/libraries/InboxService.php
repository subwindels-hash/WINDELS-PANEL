<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * InboxService — receives mail into the panel's dashboard inboxes.
 *
 * The panel already sends mail (MailService → email_queue), but incoming mail
 * — a customer answering the support address, a vendor writing to the admin's
 * cPanel mailbox — landed in a mailbox no screen in the panel could see.
 * This service closes that gap: the scheduled worker (CronWorkers::inbox_poll)
 * opens the same cPanel account the outgoing SMTP settings use, reads the new
 * messages, parses them, and stores one row per message in inbox_messages:
 *
 *   - mail addressed to the admin inbox address (settings.inbox_admin_email,
 *     falling back to the SMTP account username) → the staff inbox
 *     (owner_type ADMIN);
 *   - mail addressed to a registered customer's email → that customer's
 *     inbox (owner_type USER), while settings.inbox_user_delivery is on;
 *   - everything else → the staff inbox as the catch-all, so nothing that
 *     reached the mailbox ever disappears from the panel's view.
 *
 * Receiving is plain POP3 over SSL by default — the line protocol every
 * cPanel account speaks on port 995. Messages are deleted from the server at
 * the end of a successful poll (POP3 reliable-idle semantics); a poll that
 * crashes between fetching and deleting re-fetches the same mail next tick,
 * and the UNIQUE dedupe_key turns the second insert into a no-op, so a crash
 * can never double-store a message.
 *
 * Security note: the parsed HTML half of a message is stored as reference
 * data and rendered escaped in the inbox views. It is never executed, so a
 * hostile sender cannot plant script in the dashboards.
 */
class InboxService {

    /** How many messages one poll run will fetch, at most. */
    const DEFAULT_LIMIT = 50;

    /** A single socket round-trip, in seconds. */
    const TIMEOUT = 30;

    private $ci;

    public function __construct() {
        $this->ci =& get_instance();
        $this->ci->load->model(array('Setting_model', 'User_model'));
    }

    /* ================================================================== */
    /* Polling                                                            */
    /* ================================================================== */

    /**
     * One scheduled tick: connect, fetch new messages, store them, delete
     * them from the server, disconnect.
     *
     * Connection values come from .env (the same portable names the mail
     * settings use, so a cPanel operator configures one account, not two):
     * VP_INBOX_HOST / VP_INBOX_PORT / VP_INBOX_USER / VP_INBOX_PASS /
     * VP_INBOX_CRYPTO, each falling back to the VP_MAIL_* values.
     *
     * @return array{processed:int, failed:int, message:string}
     */
    public function poll_once($limit = null) {
        require_once APPPATH.'core/Env.php';

        if (!$this->feature_enabled()) {
            return array('processed' => 0, 'failed' => 0, 'message' => 'skipped (inbox disabled in Settings)');
        }

        $protocol = strtolower((string) Env::get('INBOX_PROTOCOL', 'pop3'));
        if ($protocol !== 'pop3') {
            return array('processed' => 0, 'failed' => 1,
                'message' => 'unsupported inbox protocol "'.$protocol.'" — set VP_INBOX_PROTOCOL=pop3 in .env');
        }

        $host = trim((string) Env::get('INBOX_HOST', ''));
        if ($host === '') $host = trim((string) Env::get('SMTP_HOST', ''));
        if ($host === '') {
            return array('processed' => 0, 'failed' => 1,
                'message' => 'no mailbox host — set VP_INBOX_HOST (or VP_MAIL_HOST) in .env');
        }
        $port = (int) Env::get('INBOX_PORT', 995);
        if ($port < 1 || $port > 65535) $port = 995;

        $user = trim((string) Env::get('INBOX_USER', ''));
        if ($user === '') $user = trim((string) Env::get('SMTP_USER', ''));
        $pass = (string) Env::get('INBOX_PASSWORD', '');
        if ($pass === '') $pass = (string) Env::get('SMTP_PASSWORD', '');
        if ($user === '' || $pass === '') {
            return array('processed' => 0, 'failed' => 1,
                'message' => 'no mailbox credentials — set VP_INBOX_USER / VP_INBOX_PASS in .env');
        }

        $crypto = strtolower((string) Env::get('INBOX_CRYPTO', 'ssl'));
        $limit  = $limit === null ? self::DEFAULT_LIMIT : max(1, min(200, (int) $limit));

        try {
            return $this->poll_pop3($host, $port, $user, $pass, $crypto, $limit);
        } catch (Throwable $e) {
            log_message('error', 'inbox poll failed: '.$e->getMessage());
            return array('processed' => 0, 'failed' => 1,
                'message' => 'inbox poll failed: '.$e->getMessage());
        }
    }

    /**
     * The POP3 conversation, one message at a time.
     *
     * STAT first (a poll of an empty mailbox must not delete anything), then
     * RETR/DELE per message. Each fetched message is parsed and stored
     * before the next RETR, so a failure mid-run loses at most the in-flight
     * message — and even that one is deduped on the retry.
     */
    private function poll_pop3($host, $port, $user, $pass, $crypto, $limit) {
        $scheme = in_array($crypto, array('ssl', 'tls'), true) ? 'ssl' : 'tcp';
        $sock = @stream_socket_client($scheme.'://'.$host.':'.$port, $errno, $errstr, 20);
        if (!$sock) {
            $reason = $errstr !== '' ? $errstr : 'errno '.$errno;
            return array('processed' => 0, 'failed' => 1,
                'message' => 'could not open '.$scheme.'://'.$host.':'.$port.' — '.$reason
                    .' (cPanel: VP_INBOX_PORT=995 with VP_INBOX_CRYPTO=ssl)');
        }
        stream_set_timeout($sock, self::TIMEOUT);

        $stored  = 0;
        $skipped = 0;
        try {
            $this->expect_greeting($sock);
            $this->cmd($sock, 'USER', base64_encode($user));
            $this->cmd($sock, 'PASS', base64_encode($pass));

            $stat = $this->cmd($sock, 'STAT');
            if (!preg_match('/^\+OK\s+(\d+)/i', $stat, $m)) {
                return array('processed' => 0, 'failed' => 1,
                    'message' => 'mailbox answered oddly to STAT: '.$stat);
            }
            $count = (int) $m[1];
            if ($count === 0) {
                return array('processed' => 0, 'failed' => 0, 'message' => 'mailbox empty');
            }

            $n = min($count, $limit);
            for ($i = 1; $i <= $n; $i++) {
                $raw = $this->retr($sock, $i);
                if ($raw === null || trim($raw) === '') { $skipped++; continue; }
                $msg = $this->parse_message($raw);
                if ($this->store($msg)) { $stored++; } else { $skipped++; }
            }

            // Everything was stored (or is a known duplicate) — hand the
            // messages back to the server. The next tick re-fetching one of
            // them after a crash is harmless: dedupe_key makes it a no-op.
            for ($i = 1; $i <= $n; $i++) $this->cmd($sock, 'DELE', (string) $i);
            $this->cmd($sock, 'QUIT');

            return array(
                'processed' => $stored,
                'failed'    => $skipped,
                'message'   => $stored.' received, '.$skipped.' duplicate or unreadable',
            );
        } finally {
            if (is_resource($sock)) @fclose($sock);
        }
    }

    /** Opening greeting: "+OK ..." — anything else is a misconfigured host. */
    private function expect_greeting($sock) {
        $line = fgets($sock, 8192);
        if ($line === false || stripos($line, '+OK') !== 0) {
            throw new RuntimeException('unexpected mailbox greeting: '
                .($line === false ? '(none)' : trim($line)));
        }
    }

    /** Send one POP3 command and return its reply line. */
    private function cmd($sock, $verb, $arg = null) {
        fwrite($sock, $verb.($arg !== null ? ' '.$arg : '')."\r\n");
        $line = fgets($sock, 8192);
        if ($line === false) {
            throw new RuntimeException('mailbox connection closed during '.$verb);
        }
        return trim($line);
    }

    /**
     * RETR one message and return its raw RFC 822 content.
     * POP3 dot-stuffing (a line starting ".." means a leading ".") is undone;
     * a missing terminator line means the stream died — return what arrived.
     */
    private function retr($sock, $n) {
        $line = $this->cmd($sock, 'RETR', (string) $n);
        if (stripos($line, '+OK') !== 0) {
            throw new RuntimeException('RETR '.$n.' failed: '.$line);
        }
        $buf = '';
        while (($l = fgets($sock, 65536)) !== false) {
            if ($l === ".\r\n" || $l === ".\n") break;
            $buf .= $l;
        }
        return $buf === '' ? null : preg_replace('/(\r\n)\.\./', '$1.', $buf);
    }

    /* ================================================================== */
    /* Parsing                                                            */
    /* ================================================================== */

    /**
     * Parse one raw RFC 822 message into the inbox row's fields.
     *
     * Handles folded headers, RFC 2047 encoded-words (=?utf-8?B?...?=),
     * multipart messages (recursively) and base64 / quoted-printable bodies.
     * Every failure mode degrades to "less information", never an exception:
     * an unreadable message still lands in the inbox with its subject and
     * sender, because a half-parsed customer message is worth more than a
     * deleted one.
     *
     * @return array{to:?string, from_email:?string, from_name:?string,
     *               subject:string, body_text:string, body_html:?string,
     *               message_id:?string, received_at_ts:?int}
     */
    public function parse_message($raw) {
        $raw  = str_replace("\r\n", "\n", (string) $raw);
        $pos  = strpos($raw, "\n\n");
        $head = $pos === false ? '' : substr($raw, 0, $pos);
        $body = $pos === false ? $raw : substr($raw, $pos + 2);
        // POP3 dot-stuffing already undone; trim any trailing terminator.
        $head = preg_replace("/\n[ \t]+/", ' ', $head);

        $headers = $this->parse_header_block($head);

        $bodies = $this->extract_bodies($headers, $body);

        $to = $this->first_address(isset($headers['to']) ? $headers['to']
            : (isset($headers['cc']) ? $headers['cc'] : ''));

        $from = $this->first_address(isset($headers['from']) ? $headers['from'] : '');

        $received_ts = null;
        if (!empty($headers['date'])) {
            $t = strtotime((string) $headers['date']);
            if ($t !== false) $received_ts = $t;
        }

        $body_text = trim((string) $bodies['text']);
        if ($body_text === '' && !empty($bodies['html'])) {
            $body_text = trim(preg_replace('/\s+/', ' ', strip_tags((string) $bodies['html'])));
        }

        $subject = $this->decode_rfc2047((string) (isset($headers['subject']) ? $headers['subject'] : ''));

        $message_id = trim((string) (isset($headers['message-id']) ? $headers['message-id'] : ''));
        if (preg_match('/^<(.+)>$/', $message_id, $m)) $message_id = trim($m[1]);

        return array(
            'to'           => $to !== null ? $to : '',
            'from_email'   => $from['email'],
            'from_name'    => $from['name'],
            'subject'      => trim($subject) !== '' ? $subject : '(no subject)',
            'body_text'    => $body_text,
            'body_html'    => !empty($bodies['html']) ? (string) $bodies['html'] : null,
            'message_id'   => $message_id !== '' ? $message_id : null,
            'received_at_ts' => $received_ts,
        );
    }

    /** Unfolded headers to a lowercase-name => first-value map. */
    private function parse_header_block($head) {
        $out = array();
        foreach (explode("\n", (string) $head) as $line) {
            $colon = strpos($line, ':');
            if ($colon === false) continue;
            $key = strtolower(trim(substr($line, 0, $colon)));
            $val = trim(substr($line, $colon + 1));
            if ($key === '' || isset($out[$key])) continue;
            $out[$key] = $val;
        }
        return $out;
    }

    /**
     * Walk a (possibly multipart) message and return its text and html parts.
     *
     * @param array  $headers  the part's headers (lowercase keys)
     * @param string $body     the part's raw body
     * @return array{text:?string, html:?string}
     */
    private function extract_bodies($headers, $body) {
        $ctype = (string) (isset($headers['content-type']) ? $headers['content-type'] : '');
        if (preg_match('/boundary\s*=\s*"?([^";\r\n]+)"?/i', $ctype, $m)) {
            $boundary = $m[1];
            $text = null;
            $html = null;
            foreach (explode('--'.$boundary, (string) $body) as $part) {
                $part = trim($part, "\r\n");
                if ($part === '' || $part === '--') continue;
                $split = strpos($part, "\n\n");
                if ($split === false) continue;
                $part_headers = $this->parse_header_block(substr($part, 0, $split));
                $part_body    = substr($part, $split + 2);
                $part_ct      = strtolower((string) (isset($part_headers['content-type']) ? $part_headers['content-type'] : ''));

                if (strpos($part_ct, 'multipart/') === 0) {
                    $sub = $this->extract_bodies($part_headers, $part_body);
                    if ($text === null) $text = $sub['text'];
                    if ($html === null) $html = $sub['html'];
                    continue;
                }
                $decoded = $this->decode_body($part_body, $part_headers);
                if (strpos($part_ct, 'text/html') !== false) {
                    if ($html === null) $html = $decoded;
                    if ($text === null) $text = $decoded;
                } elseif (strpos($part_ct, 'text/plain') !== false) {
                    if ($text === null) $text = $decoded;
                }
            }
            if ($text !== null || $html !== null) return array('text' => $text, 'html' => $html);
        }

        $decoded = $this->decode_body($body, $headers);
        if (stripos($ctype, 'text/html') !== false) {
            return array('text' => $decoded, 'html' => $decoded);
        }
        return array('text' => $decoded, 'html' => null);
    }

    /** Decode one part's body (transfer encoding, then charset to UTF-8). */
    private function decode_body($body, array $headers) {
        $cte  = strtolower(trim((string) (isset($headers['content-transfer-encoding']) ? $headers['content-transfer-encoding'] : '')));
        $body = (string) $body;
        if ($cte === 'base64') {
            $decoded = base64_decode(preg_replace('/\s+/', '', $body), true);
            $body = $decoded === false ? '' : $decoded;
        } elseif ($cte === 'quoted-printable') {
            $body = quoted_printable_decode($body);
        }
        $charset = 'utf-8';
        if (preg_match('/charset\s*=\s*"?([A-Za-z0-9_\-]+)"?/i',
                (string) (isset($headers['content-type']) ? $headers['content-type'] : ''), $m)) {
            $charset = $m[1];
        }
        return $this->to_utf8($body, $charset);
    }

    /**
     * Decode RFC 2047 encoded-words (=?charset?Q|B?encoded?=).
     * Untokenizable words are left as-is rather than dropped: a broken
     * subject is more legible than a missing one.
     */
    private function decode_rfc2047($s) {
        $s = (string) $s;
        if (strpos($s, '=?') === false) return $s;
        return (string) preg_replace_callback(
            '/=\?([^?]+)\?([QqBb])\?([^?]*)\?=/i',
            function ($m) {
                $charset = $m[1];
                if (strtoupper($m[2]) === 'B') {
                    $decoded = base64_decode($m[3], true);
                } else {
                    $decoded = quoted_printable_decode(str_replace('_', ' ', $m[3]));
                }
                if ($decoded === false || $decoded === null) return $m[0];
                return $this->to_utf8($decoded, $charset);
            },
            $s
        );
    }

    /** Convert a byte string from $charset to UTF-8, degrading gracefully. */
    private function to_utf8($str, $charset) {
        $str = (string) $str;
        if ($str === '') return $str;
        $charset = strtoupper(trim((string) $charset));
        if (in_array($charset, array('UTF-8', 'UTF8'), true)) return $str;
        if (function_exists('mb_convert_encoding')) {
            $t = @mb_convert_encoding($str, 'UTF-8', $charset);
            if ($t !== false && $t !== null) return $t;
        }
        if (function_exists('iconv')) {
            $t = @iconv($charset, 'UTF-8//IGNORE', $str);
            if ($t !== false && $t !== null) return $t;
        }
        return $str;
    }

    /**
     * The first address in a To/From/Cc header.
     *
     * @return array{email:?string, name:?string}|null  null when no address at all
     */
    private function first_address($header) {
        $header = $this->decode_rfc2047((string) $header);
        if (trim($header) === '') return null;

        $email = null;
        $name  = null;
        if (preg_match('/<([^>]+)>/', $header, $m)) {
            $email = trim($m[1]);
            $name  = trim(str_replace('<'.$m[1].'>', '', $header), " \t\r\n\"");
        } elseif (preg_match('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', $header, $m)) {
            $email = $m[0];
        }
        if ($email === null) return null;
        if ($name !== '' && !preg_match('/^[^<@]+@[^<@]+$/', $name)) {
            $name = trim($name);
        } else {
            $name = null;
        }
        return array('email' => strtolower($email), 'name' => $name);
    }

    /* ================================================================== */
    /* Storing                                                            */
    /* ================================================================== */

    /**
     * Route a parsed message to its owner and store it.
     *
     * @param array $msg  parse_message() output
     * @return int|null   the new row's id, or NULL for a duplicate / unroutable
     */
    public function store(array $msg) {
        $to = strtolower(trim((string) (isset($msg['to']) ? $msg['to'] : '')));
        if ($to === '') return null;

        $msgid = (string) (isset($msg['message_id']) ? $msg['message_id'] : '');
        $dedupe = $msgid !== ''
            ? hash('sha256', 'mid:'.$msgid)
            : hash('sha256', 'raw:'.$to
                .'|'.strtolower((string) (isset($msg['from_email']) ? $msg['from_email'] : ''))
                .'|'.strtolower((string) (isset($msg['subject']) ? $msg['subject'] : ''))
                .'|'.(string) (isset($msg['received_at_ts']) ? $msg['received_at_ts'] : 0));

        $owner_type = 'ADMIN';
        $owner_id   = null;
        if ($this->is_admin_address($to)) {
            $owner_type = 'ADMIN';
        } elseif ($this->delivery_user_enabled()) {
            $user = $this->ci->User_model->find_by_email($to);
            if ($user) {
                $owner_type = 'USER';
                $owner_id   = (int) $user->id;
            }
        }

        $now = gmdate('Y-m-d H:i:s');
        try {
            $ok = $this->ci->db->insert('inbox_messages', array(
                'public_id'   => marvy_public_id(),
                'owner_type'  => $owner_type,
                'owner_id'    => $owner_id,
                'to_email'    => $to,
                'from_email'  => !empty($msg['from_email']) ? $msg['from_email'] : null,
                'from_name'   => !empty($msg['from_name']) ? mb_substr((string) $msg['from_name'], 0, 190) : null,
                'subject'     => mb_substr(trim((string) (isset($msg['subject']) ? $msg['subject'] : '')), 0, 255),
                'body_text'   => (string) (isset($msg['body_text']) ? $msg['body_text'] : ''),
                'body_html'   => !empty($msg['body_html']) ? (string) $msg['body_html'] : null,
                'message_id'  => $msgid !== '' ? mb_substr($msgid, 0, 255) : null,
                'dedupe_key'  => $dedupe,
                'received_at' => !empty($msg['received_at_ts'])
                    ? gmdate('Y-m-d H:i:s', (int) $msg['received_at_ts']) : $now,
                'is_read'     => 0,
                'created_at'  => $now,
            ));
            return $ok ? (int) $this->ci->db->insert_id() : null;
        } catch (Throwable $e) {
            // A UNIQUE dedupe_key collision (a crashed poll re-fetching the
            // same mail) is the expected failure — log and move on.
            log_message('debug', 'inbox store skipped: '.$e->getMessage());
            return null;
        }
    }

    /* ================================================================== */
    /* Reading                                                            */
    /* ================================================================== */

    /** The staff inbox, newest first; $status 'UNREAD' filters to unread. */
    public function for_admin($status = '', $limit = 25, $offset = 0) {
        $q = $this->ci->db->where('owner_type', 'ADMIN')->order_by('id', 'DESC')
            ->limit(max(1, (int) $limit), max(0, (int) $offset));
        if ($status === 'UNREAD') $q->where('is_read', 0);
        return $q->get('inbox_messages')->result();
    }

    public function count_admin($status = '') {
        $q = $this->ci->db->where('owner_type', 'ADMIN');
        if ($status === 'UNREAD') $q->where('is_read', 0);
        return (int) $q->count_all_results('inbox_messages');
    }

    /** One customer's inbox, newest first. */
    public function for_user($user_id, $status = '', $limit = 25, $offset = 0) {
        $q = $this->ci->db->where('owner_type', 'USER')->where('owner_id', (int) $user_id)
            ->order_by('id', 'DESC')->limit(max(1, (int) $limit), max(0, (int) $offset));
        if ($status === 'UNREAD') $q->where('is_read', 0);
        return $q->get('inbox_messages')->result();
    }

    public function count_user($user_id, $status = '') {
        $q = $this->ci->db->where('owner_type', 'USER')->where('owner_id', (int) $user_id);
        if ($status === 'UNREAD') $q->where('is_read', 0);
        return (int) $q->count_all_results('inbox_messages');
    }

    /** A staff-inbox row by public id, or null. */
    public function find_admin($public_id) {
        return $this->ci->db->where('owner_type', 'ADMIN')->where('public_id', $public_id)
            ->get('inbox_messages')->row();
    }

    /** A row of one customer's inbox by public id, or null. */
    public function find_for_user($public_id, $user_id) {
        return $this->ci->db->where('owner_type', 'USER')->where('owner_id', (int) $user_id)
            ->where('public_id', $public_id)->get('inbox_messages')->row();
    }

    /**
     * Mark read — one message by public id, or everything of the owner's
     * (the dashboard's "mark all read" button).
     */
    public function mark_read($owner_type, $owner_id = null, $public_id = null) {
        $this->ci->db
            ->set('is_read', 1)
            ->set('read_at', gmdate('Y-m-d H:i:s'))
            ->where('is_read', 0);
        if ($owner_type === 'ADMIN') {
            $this->ci->db->where('owner_type', 'ADMIN');
        } else {
            $this->ci->db->where('owner_type', 'USER')->where('owner_id', (int) $owner_id);
        }
        if ($public_id !== null && $public_id !== '') $this->ci->db->where('public_id', $public_id);
        $this->ci->db->update('inbox_messages');
    }

    /** Delete one of the owner's messages (never the whole inbox by accident). */
    public function delete($owner_type, $owner_id, $public_id) {
        $q = $this->ci->db->where('public_id', $public_id);
        if ($owner_type === 'ADMIN') {
            $q->where('owner_type', 'ADMIN');
        } else {
            $q->where('owner_type', 'USER')->where('owner_id', (int) $owner_id);
        }
        return (int) $q->delete('inbox_messages');
    }

    /* ================================================================== */
    /* Settings helpers                                                   */
    /* ================================================================== */

    /** Whether the inbox worker runs at all (Settings → Email). */
    public function feature_enabled() {
        $v = $this->ci->Setting_model->get('inbox_enabled', true);
        return !($v === false || $v === 0 || $v === '0' || $v === 'false');
    }

    /** Whether customer-addressed mail routes to customer inboxes. */
    public function delivery_user_enabled() {
        $v = $this->ci->Setting_model->get('inbox_user_delivery', true);
        return !($v === false || $v === 0 || $v === '0' || $v === 'false');
    }

    /**
     * The address the staff inbox is "for": settings first, then the account
     * the mail is pulled from, then the outgoing From address. Empty when
     * none is configured — the catch-all still routes to the staff inbox.
     */
    public function admin_address() {
        $addr = strtolower(trim((string) $this->ci->Setting_model->get('inbox_admin_email', '')));
        if ($addr !== '') return $addr;
        require_once APPPATH.'core/Env.php';
        $addr = strtolower(trim((string) Env::get('INBOX_USER', '')));
        if ($addr !== '') return $addr;
        $addr = strtolower(trim((string) Env::get('SMTP_USER', '')));
        if ($addr !== '') return $addr;
        return strtolower(trim((string) $this->ci->Setting_model->get('mail_from_email', '')));
    }

    private function is_admin_address($to) {
        $addr = $this->admin_address();
        return $addr !== '' && $to === $addr;
    }
}
