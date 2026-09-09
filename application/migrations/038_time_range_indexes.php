<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Migration 038 — indexes for queries that filter by time alone.
 *
 * The index review (docs/module-index-review.md) found three read paths that
 * bound a scan by created_at with no column before it in the WHERE clause:
 *
 *   1. AdminStats::windowed_totals() — the dashboard's revenue windows:
 *        WHERE created_at >= <oldest window edge>
 *      over service_transactions and orders. The status split lives in
 *      CASE WHEN, not the WHERE, so the existing (status, created_at)
 *      indexes cannot answer it: MySQL falls back to a full table scan of
 *      the busiest table on the panel for the first screen staff open.
 *
 *   2. The admin transaction/order lists' default view — no filter,
 *        ORDER BY created_at DESC LIMIT 25. Unfiltered, the list can only
 *      come from a full scan plus a sort; with a created_at-leading index
 *      it is a bounded backward index scan that stops after 25 rows.
 *
 *   3. AdminStats::provider_performance() —
 *        WHERE created_at >= <7 days>  GROUP BY provider_id
 *      over provider_transactions, which has (provider_id, created_at)
 *      (wrong leading column for this query) and nothing else time-based.
 *
 * The indexes are covering for the queries above (status and the money/
 * latency columns ride along), so the windowed totals are index-only scans
 * on MySQL — no heap row lookups at all. The write cost is two small
 * columns per insert on the busiest tables; the trade is deliberate because
 * these are the hottest reads in the panel and the dev harness's SQLite
 * planner would never have surfaced the gap (docs/module-index-review.md).
 *
 * No backfill — indexes only, no schema shape change.
 */
class Migration_Time_range_indexes extends CI_Migration {

    public static function statements() {
        return array(
            // Dashboard revenue windows + unfiltered admin list ordering.
            // Covering: created_at (range) + status (the CASE split) +
            // amount/refunded_amount (the sums).
            "CREATE INDEX idx_stx_created
               ON service_transactions (created_at, status, amount, refunded_amount)",

            "CREATE INDEX idx_ord_created
               ON orders (created_at, status, charge, refunded_amount)",

            // Provider performance: created_at (range) + provider_id (the
            // group) + status/latency_ms (the aggregates).
            "CREATE INDEX idx_ptx_created
               ON provider_transactions (created_at, provider_id, status, latency_ms)",
        );
    }

    /** Adds no tables or columns; declared for the schema linter. */
    public static function tables() {
        return array();
    }

    public function up() {
        foreach (self::statements() as $sql) {
            if (preg_match('/^CREATE INDEX (\w+)\s+ON (\w+)/i', trim($sql), $m)
                && $this->index_exists($m[1], $m[2])) {
                continue;
            }
            $this->db->query($sql);
        }
    }

    /** Reversible — dropping an index only removes a speedup. */
    public function down() {
        foreach (array('idx_stx_created', 'idx_ord_created', 'idx_ptx_created') as $name) {
            $table = $this->table_for($name);
            if ($this->index_exists($name, $table)) {
                $this->db->query('DROP INDEX '.$name.' ON '.$table);
            }
        }
    }

    private function table_for($name) {
        $tables = array(
            'idx_stx_created' => 'service_transactions',
            'idx_ord_created' => 'orders',
            'idx_ptx_created' => 'provider_transactions',
        );
        return $tables[$name] ?? '';
    }

    private function index_exists($name, $table) {
        try {
            $rows = $this->db->query('SHOW INDEX FROM '.$table)->result();
            foreach ($rows as $row) {
                if (isset($row->Key_name) && $row->Key_name === $name) return true;
            }
        } catch (Exception $e) {
            return false;
        }
        return false;
    }
}
