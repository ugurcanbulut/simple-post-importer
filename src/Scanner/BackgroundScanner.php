<?php

declare(strict_types=1);

namespace SimplePostImporter\Scanner;

/**
 * WP-Cron driven scan processor. Each firing runs chunks for up to
 * MAX_RUNTIME seconds, then either completes the session or reschedules
 * itself for another pass.
 */
final class BackgroundScanner
{
    public const HOOK = 'spi_process_scan';
    public const MAX_RUNTIME = 20;
    public const RESCHEDULE_DELAY = 2;

    public static function register(): void
    {
        add_action(self::HOOK, [self::class, 'process']);
    }

    public static function schedule(int $sessionId): void
    {
        $args = [$sessionId];
        if (!wp_next_scheduled(self::HOOK, $args)) {
            wp_schedule_single_event(time(), self::HOOK, $args);
        }
        self::tryImmediateTrigger();
    }

    public static function process(int $sessionId): void
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(60);
        }

        $runner = new ScanRunner();
        $start = microtime(true);

        while ((microtime(true) - $start) < self::MAX_RUNTIME) {
            $result = $runner->runChunk($sessionId);
            if (is_wp_error($result)) {
                return;
            }
            $status = $result['status'] ?? '';
            if ($status !== 'running' && $status !== 'pending') {
                return;
            }
        }

        wp_schedule_single_event(time() + self::RESCHEDULE_DELAY, self::HOOK, [$sessionId]);
        self::tryImmediateTrigger();
    }

    /**
     * Nudge WP-Cron to fire on the current request when possible.
     * spawn_cron() is a WP core helper that's safe to call; it no-ops
     * when cron is already running or disabled.
     */
    private static function tryImmediateTrigger(): void
    {
        if (defined('DOING_CRON') && DOING_CRON) {
            return;
        }
        if (function_exists('spawn_cron')) {
            spawn_cron();
        }
    }
}
