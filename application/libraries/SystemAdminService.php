<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * SystemAdminService — service categories, the blacklist, and audit search.
 *
 * The three remaining system screens. Two are small; one has a sharp edge.
 *
 * **The blacklist is the sharp edge.** `Blacklist_model::text_contains_
 * blacklisted_link()` treats any entry wrapped in `/.../` as a regular
 * expression and runs it against user-supplied text on every registration and
 * every order. The model's own comment says it is "kept deliberately simple
 * to avoid ReDoS from untrusted patterns — only staff may add entries", which
 * was true only because there was no way for staff to add entries at all.
 * Building the form that adds them makes the assumption load-bearing, so this
 * service validates a pattern before it can ever be stored:
 *
 *   - it must compile (`preg_match` against a probe, warnings suppressed);
 *   - it must survive a pathological probe string without blowing the
 *     backtrack limit, which is what catches the classic `(a+)+$` shape;
 *   - it is length-capped, because a huge alternation is slow even when it is
 *     not catastrophic.
 *
 * A pattern that fails is refused with a message, not stored and left to take
 * the site down the next time somebody registers.
 *
 * Audit logs are **read-only here by construction** — there is no write path
 * on this class at all. An append-only trail that an admin can edit is not a
 * trail, and the one screen most likely to be visited by someone covering
 * their tracks is the one that must not offer a delete button.
 */
class SystemAdminService {

    /* ----------------------------- cron jobs ----------------------------- */

    /**
     * A five-field cron expression, in words.
     *
     * Operators who have written crontabs for a decade read the star-slash-two
     * form fluently; everybody else does not, and the whole point of the cron
     * screen is that somebody who has never installed a crontab can tell
     * whether the panel's background work is happening.
     */
    public static function describe_schedule($expr) {
        $expr = trim(preg_replace('/\s+/', ' ', (string)$expr));
        if ($expr === '') return 'not scheduled';
        $parts = explode(' ', $expr);
        if (count($parts) !== 5) return $expr;
        list($min, $hour, $dom, $mon, $dow) = $parts;

        if ($min === '*' && $hour === '*') return 'every minute';
        if (preg_match('#^\*/(\d+)$#', $min, $m) && $hour === '*') {
            return 'every '.$m[1].' minute'.($m[1] === '1' ? '' : 's');
        }
        if (preg_match('#^\*/(\d+)$#', $hour, $m) && $min === '0') {
            return 'every '.$m[1].' hour'.($m[1] === '1' ? '' : 's');
        }
        if ($min === '0' && $hour === '*') return 'hourly, on the hour';
        if (ctype_digit($min) && ctype_digit($hour) && $dom === '*' && $mon === '*' && $dow === '*') {
            return sprintf('daily at %02d:%02d UTC', (int)$hour, (int)$min);
        }
        if (ctype_digit($min) && $hour === '*') return 'hourly, at :'.sprintf('%02d', (int)$min);
        return $expr;
    }

    /**
     * How a scheduled job is doing: `ok`, `late`, `failing` or `never`.
     *
     * "Late" is judged against the job's own cadence with a generous multiple,
     * because a busy host running a two-minute job four minutes late is not a
     * fault — a job that has not run in an hour is.
     */
    public static function job_state($schedule, $last, $age_minutes) {
        if (!$last) return 'never';
        if (isset($last->status) && $last->status === 'FAILED') return 'failing';
        if ($age_minutes === null) return 'ok';

        $expected = self::cadence_minutes($schedule);
        // Three missed ticks, and never less than fifteen minutes of slack.
        $tolerance = max(15, $expected * 3);
        return $age_minutes > $tolerance ? 'late' : 'ok';
    }

    /** Roughly how many minutes there should be between two runs. */
    public static function cadence_minutes($schedule) {
        $expr = trim(preg_replace('/\s+/', ' ', (string)$schedule));
        $parts = explode(' ', $expr);
        if (count($parts) !== 5) return 60;
        list($min, $hour) = $parts;
        if (preg_match('#^\*/(\d+)$#', $min, $m)) return max(1, (int)$m[1]);
        if ($min === '*') return 1;
        if (preg_match('#^\*/(\d+)$#', $hour, $m)) return max(1, (int)$m[1]) * 60;
        if ($hour === '*') return 60;
        return 1440;
    }

    /**
     * The crontab an operator can paste, built from the same schedule table
     * the application reads — so it cannot drift from what the panel expects.
     */
    public static function crontab_lines(array $schedules) {
        $lines = array(
            '# MarvySocials background jobs — paste into: crontab -e',
            '# Replace /home/USER/public_html with your document root.',
            'MYPANEL=/home/USER/public_html',
            '',
        );
        foreach ($schedules as $job => $expr) {
            $lines[] = sprintf('%-16s cd $MYPANEL && php index.php cron %s', $expr, $job);
        }
        return $lines;
    }

    /** Blacklist kinds, mapped to their table and value column. */
    public static function lists() {
        return array(
            'emails' => array('table' => 'blacklisted_emails', 'column' => 'email',
                              'label' => 'Email addresses'),
            'ips'    => array('table' => 'blacklisted_ips',    'column' => 'ip',
                              'label' => 'IP addresses'),
            'links'  => array('table' => 'blacklisted_links',  'column' => 'pattern',
                              'label' => 'Link patterns'),
        );
    }

    /** Longest pattern we will evaluate on every registration. */
    const MAX_PATTERN = 200;

    private $ci;

    public function __construct() {
        $this->ci =& get_instance();
        $this->ci->load->model(array('Service_category_model', 'Blacklist_model', 'Audit_log_model'));
    }

    /* --------------------------- blacklist ------------------------------ */

    public function list_entries($kind, $limit = 100, $offset = 0) {
        $spec = $this->list_spec($kind);
        return $this->ci->db->order_by('created_at', 'DESC')
            ->limit($limit, $offset)->get($spec['table'])->result();
    }

    public function count_entries($kind) {
        $spec = $this->list_spec($kind);
        return (int)$this->ci->db->from($spec['table'])->count_all_results();
    }

    /**
     * Add an entry.
     *
     * Emails are lower-cased, IPs validated, link patterns compiled and
     * timed — see the class comment for why the last one matters.
     */
    public function blacklist_add($kind, $value, $reason = null) {
        $spec  = $this->list_spec($kind);
        $value = trim((string)$value);
        if ($value === '') return $this->err('INVALID', 'Enter a value to block.');

        if ($kind === 'emails') {
            $value = strtolower($value);
            if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                return $this->err('INVALID', '"'.$value.'" is not a valid email address.');
            }
        } elseif ($kind === 'ips') {
            if (!filter_var($value, FILTER_VALIDATE_IP)) {
                return $this->err('INVALID', '"'.$value.'" is not a valid IP address.');
            }
        } else {
            $check = $this->validate_pattern($value);
            if ($check !== true) return $this->err('BAD_PATTERN', $check);
        }

        $exists = $this->ci->db->where($spec['column'], $value)->get($spec['table'])->row();
        if ($exists) return $this->err('DUPLICATE', 'That entry is already blocked.');

        $this->ci->db->insert($spec['table'], array(
            $spec['column'] => $value,
            'reason'        => $reason ? mb_substr(trim($reason), 0, 500) : null,
            'created_at'    => gmdate('Y-m-d H:i:s'),
        ));
        $id = $this->ci->db->insert_id();

        return array('ok' => true, 'error' => null, 'code' => null,
                     'entry' => $this->ci->db->where('id', $id)->get($spec['table'])->row(),
                     'table' => $spec['table']);
    }

    public function blacklist_remove($kind, $id) {
        $spec  = $this->list_spec($kind);
        $entry = $this->ci->db->where('id', (int)$id)->get($spec['table'])->row();
        if (!$entry) return $this->err('NOT_FOUND', 'That entry no longer exists.');

        $this->ci->db->where('id', (int)$id)->delete($spec['table']);
        return array('ok' => true, 'error' => null, 'code' => null,
                     'entry' => $entry, 'table' => $spec['table']);
    }

    /**
     * Is this link pattern safe to run against untrusted text?
     *
     * Returns true, or a message explaining the refusal. A plain substring is
     * always fine; only `/.../` entries reach the regex checks.
     */
    public function validate_pattern($pattern) {
        if (mb_strlen($pattern) > self::MAX_PATTERN) {
            return 'Patterns are limited to '.self::MAX_PATTERN.' characters — this one runs on every signup.';
        }
        if (!preg_match('#^/.*/[a-z]*$#i', $pattern)) {
            return true; // A literal substring match; nothing to compile.
        }

        // Compiles at all?
        $probe = @preg_match($pattern, 'https://example.test/probe');
        if ($probe === false) {
            return 'That regular expression is not valid: '.$this->last_pcre_error().'.';
        }

        // Catastrophic backtracking check. A pathological pattern such as
        // /(a+)+$/ exhausts the backtrack limit on this input; a sane one
        // does not notice it.
        $bomb = str_repeat('a', 60).'!';
        $ok   = @preg_match($pattern, $bomb);
        if ($ok === false && preg_last_error() === PREG_BACKTRACK_LIMIT_ERROR) {
            return 'That pattern backtracks catastrophically and would hang every signup. '
                  .'Use a plain domain instead of a regular expression.';
        }
        return true;
    }

    private function last_pcre_error() {
        if (function_exists('preg_last_error_msg')) return preg_last_error_msg();
        return 'error '.preg_last_error();
    }

    /* ------------------------- service categories ----------------------- */

    public function categories() {
        return $this->ci->db->select('service_categories.*,
                (SELECT COUNT(*) FROM services WHERE services.category_id = service_categories.id) AS service_count', false)
            ->order_by('sorting', 'ASC')->order_by('name', 'ASC')
            ->get('service_categories')->result();
    }

    public function find_category($public_id) {
        return $this->ci->Service_category_model->find_by_public_id($public_id);
    }

    public function save_category($existing, array $in) {
        $name = trim((string)($in['name'] ?? ''));
        if ($name === '') return $this->err('INVALID', 'A category name is required.');

        $slug = $this->slug($in['slug'] ?? $name);
        if ($slug === '') return $this->err('INVALID', 'The slug could not be derived from the name.');

        $clash = $this->ci->db->where('slug', $slug);
        if ($existing) $clash->where('id !=', (int)$existing->id);
        if ($clash->get('service_categories')->row()) {
            return $this->err('DUPLICATE', 'Another category already uses the slug "'.$slug.'".');
        }

        $parent_id = ($in['parent_id'] ?? '') === '' ? null : (int)$in['parent_id'];
        if ($parent_id && $existing && $parent_id === (int)$existing->id) {
            return $this->err('INVALID', 'A category cannot be its own parent.');
        }

        $row = array(
            'name'        => mb_substr($name, 0, 128),
            'slug'        => $slug,
            'parent_id'   => $parent_id,
            'description' => trim(strip_tags((string)($in['description'] ?? ''))) ?: null,
            'platform'    => trim((string)($in['platform'] ?? '')) ?: null,
            'sorting'     => (int)($in['sorting'] ?? 0),
            'is_active'   => !empty($in['is_active']) ? 1 : 0,
            'updated_at'  => gmdate('Y-m-d H:i:s'),
        );

        $warnings = array();
        if ($existing) {
            $before = get_object_vars($existing);
            $this->ci->db->where('id', $existing->id)->update('service_categories', $row);
            $id = $existing->id;
            // Switching a category off hides its services from the storefront,
            // which is easy to do by accident while renaming something.
            if (empty($row['is_active']) && !empty($existing->is_active)) {
                $n = (int)$this->ci->db->where('category_id', $existing->id)
                    ->from('services')->count_all_results();
                if ($n) $warnings[] = $n.' service'.($n === 1 ? '' : 's')
                    .' in this category will disappear from the storefront.';
            }
        } else {
            $before = null;
            $row['public_id']  = strtoupper(bin2hex(random_bytes(13)));
            $row['created_at'] = gmdate('Y-m-d H:i:s');
            $this->ci->db->insert('service_categories', $row);
            $id = $this->ci->db->insert_id();
        }

        return array('ok' => true, 'error' => null, 'code' => null, 'warnings' => $warnings,
                     'before' => $before,
                     'category' => $this->ci->Service_category_model->find_by_id($id),
                     'created' => !$existing);
    }

    /**
     * Delete a category.
     *
     * Refused while services still point at it: the FK is ON DELETE SET NULL,
     * so deleting would not error — it would quietly orphan every service into
     * an uncategorised limbo where the storefront cannot show them.
     */
    public function delete_category($category) {
        $n = (int)$this->ci->db->where('category_id', $category->id)
            ->from('services')->count_all_results();
        if ($n) {
            return $this->err('IN_USE', 'This category still holds '.$n.' service'.($n === 1 ? '' : 's')
                .'. Move them first, or switch the category off instead.');
        }
        $kids = (int)$this->ci->db->where('parent_id', $category->id)
            ->from('service_categories')->count_all_results();
        if ($kids) {
            return $this->err('IN_USE', 'This category has '.$kids.' sub-categor'.($kids === 1 ? 'y' : 'ies').'.');
        }
        $this->ci->db->where('id', $category->id)->delete('service_categories');
        return array('ok' => true, 'error' => null, 'code' => null, 'warnings' => array());
    }

    /* ---------------------------- audit trail --------------------------- */

    /**
     * Search the audit trail. Read-only: this class has no way to write here.
     */
    public function audit_search(array $filters, $limit = 50, $offset = 0) {
        $this->audit_filters($filters);
        return $this->ci->db
            ->select('audit_logs.*, users.username AS actor_name, users.public_id AS actor_public_id', false)
            ->join('users', 'users.id = audit_logs.actor_id', 'left')
            ->order_by('audit_logs.created_at', 'DESC')
            ->limit($limit, $offset)
            ->get()->result();
    }

    public function audit_count(array $filters) {
        $this->audit_filters($filters);
        return (int)$this->ci->db->count_all_results();
    }

    private function audit_filters(array $f) {
        $this->ci->db->from('audit_logs');
        if (!empty($f['actor_id'])) $this->ci->db->where('audit_logs.actor_id', (int)$f['actor_id']);
        if (!empty($f['resource'])) $this->ci->db->where('audit_logs.resource', $f['resource']);
        if (!empty($f['from']))     $this->ci->db->where('audit_logs.created_at >=', $f['from']);
        if (!empty($f['to']))       $this->ci->db->where('audit_logs.created_at <=', $f['to']);
        if (!empty($f['search'])) {
            $term = trim((string)$f['search']);
            $this->ci->db->group_start()
                ->like('audit_logs.action', $term)
                ->or_like('audit_logs.resource_id', $term)
                ->group_end();
        }
    }

    /** Distinct resources present in the trail, for the filter dropdown. */
    public function audit_resources() {
        $out = array();
        foreach ($this->ci->db->select('resource')->distinct()
                 ->order_by('resource', 'ASC')->get('audit_logs')->result() as $r) {
            if (!empty($r->resource)) $out[] = $r->resource;
        }
        return $out;
    }

    /* ------------------------------ helpers ----------------------------- */

    private function list_spec($kind) {
        $lists = self::lists();
        if (!isset($lists[$kind])) throw new InvalidArgumentException('Unknown blacklist: '.$kind);
        return $lists[$kind];
    }

    private function slug($v) {
        $v = strtolower(trim((string)$v));
        $v = preg_replace('~[^a-z0-9]+~', '-', $v);
        return trim(preg_replace('~-+~', '-', $v), '-');
    }

    private function err($code, $message) {
        return array('ok' => false, 'error' => $message, 'code' => $code, 'warnings' => array());
    }
}
