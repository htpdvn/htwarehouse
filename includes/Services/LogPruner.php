<?php

namespace HTWarehouse\Services;

defined('ABSPATH') || exit;

/**
 * LogPruner — enforces the activity-log retention policy (Plan C):
 *
 *  1. Delete all records older than MAX_AGE_DAYS days.
 *  2. If the total count still exceeds MAX_ROWS, delete the oldest records
 *     until only MAX_ROWS remain.
 *
 * Runs once daily via WP-Cron. Fails silently so it never disrupts
 * core plugin operations.
 */
class LogPruner
{
    /** Maximum age to keep (days). */
    public const MAX_AGE_DAYS = 365;

    /** Absolute ceiling on total rows. */
    public const MAX_ROWS = 50000;

    private const CRON_HOOK  = 'htw_log_pruner_daily';
    private const CRON_RECUR = 'daily';

    private static ?LogPruner $instance = null;

    public static function get_instance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // ── Hook registration ──────────────────────────────────────────────────
    public function register_hooks(): void
    {
        add_action(self::CRON_HOOK, [$this, 'run']);

        if (! wp_next_scheduled(self::CRON_HOOK)) {
            // Schedule first run for midnight (site time)
            $midnight = strtotime('tomorrow midnight');
            wp_schedule_event($midnight, self::CRON_RECUR, self::CRON_HOOK);
        }
    }

    /**
     * Called on plugin deactivation to clean up the cron event.
     */
    public static function deactivate(): void
    {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
        }
    }

    // ── Core pruning logic ─────────────────────────────────────────────────
    public function run(): void
    {
        try {
            global $wpdb;
            $table = $wpdb->prefix . 'htw_activity_logs';

            // ── Step 1: Delete records older than MAX_AGE_DAYS ────────────
            $cutoff = date('Y-m-d H:i:s', strtotime('-' . self::MAX_AGE_DAYS . ' days'));
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$table} WHERE created_at < %s",
                $cutoff
            ));

            // ── Step 2: Trim to MAX_ROWS (keep newest) ────────────────────
            $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");

            if ($total > self::MAX_ROWS) {
                $excess = $total - self::MAX_ROWS;

                // Find the id threshold — delete the $excess oldest rows
                $threshold_id = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$table} ORDER BY id ASC LIMIT 1 OFFSET %d",
                    $excess - 1
                ));

                if ($threshold_id > 0) {
                    $wpdb->query($wpdb->prepare(
                        "DELETE FROM {$table} WHERE id <= %d",
                        $threshold_id
                    ));
                }
            }

        } catch (\Throwable $e) {
            // Never surface errors — log to error_log for server admins only
            error_log('[HTWarehouse] LogPruner error: ' . $e->getMessage());
        }
    }

    // ── Manual trigger (for admin "Run now" button) ────────────────────────
    public static function run_now(): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'htw_activity_logs';

        $before = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");

        self::get_instance()->run();

        $after   = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        $deleted = $before - $after;

        return [
            'before'  => $before,
            'after'   => $after,
            'deleted' => $deleted,
        ];
    }
}
