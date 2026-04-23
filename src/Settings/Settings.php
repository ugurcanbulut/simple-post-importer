<?php

declare(strict_types=1);

namespace SimplePostImporter\Settings;

/**
 * Global plugin settings persisted in wp_options under a single key.
 * Kept intentionally small — one row, simple schema.
 */
final class Settings
{
    public const OPTION = 'spi_settings';

    /** @var array<string, mixed>|null */
    private static ?array $cache = null;

    public static function all(): array
    {
        if (self::$cache === null) {
            $raw = get_option(self::OPTION, []);
            self::$cache = is_array($raw) ? $raw : [];
        }
        return array_merge(self::defaults(), self::$cache);
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $all = self::all();
        return $all[$key] ?? $default;
    }

    public static function update(array $patch): array
    {
        $current = self::all();
        $next = array_merge($current, $patch);
        $next = self::sanitize($next);
        update_option(self::OPTION, $next, false);
        self::$cache = $next;
        return self::all();
    }

    public static function getDefaultAuthorId(): int
    {
        $id = (int) self::get('default_author_id', 0);
        if ($id > 0 && get_userdata($id)) {
            return $id;
        }
        // Fallback to the site's first administrator.
        $admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
        if (!empty($admins)) {
            return (int) $admins[0];
        }
        return (int) (get_current_user_id() ?: 1);
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'default_author_id' => 0,
            'on_missing_author' => 'default', // 'default' | 'create_placeholder'
        ];
    }

    private static function sanitize(array $data): array
    {
        $clean = [];
        if (isset($data['default_author_id'])) {
            $clean['default_author_id'] = max(0, (int) $data['default_author_id']);
        }
        if (isset($data['on_missing_author'])) {
            $v = (string) $data['on_missing_author'];
            $clean['on_missing_author'] = in_array($v, ['default', 'create_placeholder'], true)
                ? $v
                : 'default';
        }
        return array_merge(self::defaults(), $clean);
    }
}
