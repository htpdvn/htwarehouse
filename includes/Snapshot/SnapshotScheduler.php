<?php

namespace HTWarehouse\Snapshot;

defined('ABSPATH') || exit;

class SnapshotScheduler
{

    const HOOK_NAME = 'htw_daily_snapshot';
    const STATUS_OPTION = 'htw_snapshot_last_status';

    private static ?SnapshotScheduler $instance = null;

    public static function get_instance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Register WordPress hooks.
     */
    public function register_hooks(): void
    {
        add_filter('cron_schedules', [$this, 'add_daily_schedule']);
        add_action('init',          [$this, 'schedule']);
        add_action(self::HOOK_NAME, [$this, 'run_scheduled_snapshot']);
    }

    /**
     * Add 'htw_daily' (once per day) to available cron schedules.
     * WordPress core only provides 'hourly', 'twicedaily', 'daily'.
     */
    public function add_daily_schedule(array $schedules): array
    {
        $schedules['htw_daily'] = [
            'interval' => 86400, // seconds in a day
            'display'  => __('Once Daily (HTWarehouse)', 'htwarehouse'),
        ];
        return $schedules;
    }

    /**
     * Schedule the daily snapshot event if not already scheduled.
     * Also fires immediately on first activation (hook registered after init).
     */
    public function schedule(): void
    {
        if (wp_next_scheduled(self::HOOK_NAME)) {
            return;
        }

        // Guard: ensure 'htw_daily' interval is registered before scheduling.
        // On plugin activation, init hasn't fired yet so cron_schedules filter
        // may not be present. Register it inline to prevent silent failures.
        add_filter('cron_schedules', [$this, 'add_daily_schedule']);

        // First run: tomorrow at 02:00 AM local time (server time), then repeat daily.
        // MUST use a future timestamp — passing time() (past by the time the page
        // renders) causes WordPress to treat it as a one-time event with no recurrence.
        $first_run = strtotime('tomorrow 02:00');
        wp_schedule_event($first_run, 'htw_daily', self::HOOK_NAME);
    }

    /**
     * Unschedule the daily snapshot event.
     */
    public function clear_schedule(): void
    {
        wp_clear_scheduled_hook(self::HOOK_NAME);
    }

    /**
     * Check if the snapshot cron is currently scheduled.
     */
    public function is_scheduled(): bool
    {
        return (bool) wp_next_scheduled(self::HOOK_NAME);
    }

    /**
     * Get the timestamp of the next scheduled snapshot.
     *
     * @return int|false Unix timestamp or false if not scheduled.
     */
    public function next_run(): int|false
    {
        return wp_next_scheduled(self::HOOK_NAME);
    }

    /**
     * Run the scheduled snapshot: create + cleanup old ones.
     * Called by WordPress cron.
     */
    public function run_scheduled_snapshot(): void
    {
        $start = microtime(true);
        $errors = [];

        try {
            $result = SnapshotService::create();
        } catch (\Throwable $e) {
            $errors[] = 'create: ' . $e->getMessage();
            SnapshotService::create(); // try once more in case of transient error
        }

        try {
            SnapshotService::cleanup(7);
        } catch (\Throwable $e) {
            $errors[] = 'cleanup: ' . $e->getMessage();
        }

        $duration_ms = round((microtime(true) - $start) * 1000);

        update_option(self::STATUS_OPTION, [
            'run_at'    => current_time('mysql'),
            'success'   => empty($errors),
            'errors'    => $errors,
            'duration_ms' => $duration_ms,
        ]);
    }

    /**
     * Get the last scheduled run status.
     *
     * @return array|false
     */
    public static function get_last_status(): array|false
    {
        return get_option(self::STATUS_OPTION, false);
    }
}
