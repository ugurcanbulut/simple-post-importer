<?php

declare(strict_types=1);

namespace SimplePostImporter\Importer;

/**
 * WP-Cron driven import processor. Same pattern as BackgroundScanner —
 * runs chunks until a time budget is exhausted, then reschedules.
 */
final class BackgroundImporter
{
    public const HOOK = 'spi_process_import';
    public const MAX_RUNTIME = 25;
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
            @set_time_limit(120);
        }

        $runner = new ImportRunner();
        $start = microtime(true);

        while (true) {
            $remaining = self::MAX_RUNTIME - (microtime(true) - $start);
            if ($remaining <= 2) {
                break;
            }
            $result = $runner->runChunk(
                $sessionId,
                (int) max(5, floor($remaining)),
                20
            );
            if (is_wp_error($result)) {
                return;
            }
            if (($result['status'] ?? '') !== 'running') {
                return;
            }
        }

        wp_schedule_single_event(time() + self::RESCHEDULE_DELAY, self::HOOK, [$sessionId]);
        self::tryImmediateTrigger();
    }

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
