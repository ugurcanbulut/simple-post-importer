<?php

declare(strict_types=1);

namespace SimplePostImporter\Importer;

use SimplePostImporter\Settings\Settings;
use WP_Error;

final class UserImporter
{
    private const META_KEY = 'spi_remote_user_slug';

    /**
     * Resolve a local WP user id for a remote author.
     * - If author_data is missing/synthetic/no slug → use the configured default author.
     * - Else if a user already exists with matching remote slug → reuse.
     * - Else create a new subscriber user with a placeholder .invalid email.
     */
    public function resolve(?array $authorData): int
    {
        if (
            !$authorData
            || !empty($authorData['synthetic'])
            || empty($authorData['slug'])
        ) {
            return Settings::getDefaultAuthorId();
        }

        $slug = sanitize_user($authorData['slug'], true);
        $existing = $this->findByRemoteSlug($slug);
        if ($existing !== null) {
            return $existing;
        }

        $login = $this->uniqueLogin($slug);
        $email = apply_filters(
            'spi/placeholder_email',
            $slug . '@imported.invalid',
            $slug,
            $authorData
        );

        if (email_exists($email)) {
            $email = $slug . '.' . wp_generate_password(6, false) . '@imported.invalid';
        }

        $userId = wp_insert_user([
            'user_login' => $login,
            'user_email' => $email,
            'user_pass' => wp_generate_password(32, true, true),
            'display_name' => (string) ($authorData['name'] ?? $login),
            'first_name' => (string) ($authorData['name'] ?? ''),
            'role' => 'subscriber',
            'description' => (string) ($authorData['description'] ?? ''),
            'user_url' => (string) ($authorData['url'] ?? ''),
        ]);

        if ($userId instanceof WP_Error) {
            return Settings::getDefaultAuthorId();
        }

        update_user_meta($userId, self::META_KEY, $slug);
        return (int) $userId;
    }

    private function findByRemoteSlug(string $slug): ?int
    {
        $users = get_users([
            'meta_key' => self::META_KEY,
            'meta_value' => $slug,
            'number' => 1,
            'fields' => 'ID',
        ]);
        if ($users) {
            return (int) $users[0];
        }
        $byLogin = get_user_by('login', $slug);
        if ($byLogin) {
            update_user_meta((int) $byLogin->ID, self::META_KEY, $slug);
            return (int) $byLogin->ID;
        }
        return null;
    }

    private function uniqueLogin(string $slug): string
    {
        $base = $slug !== '' ? $slug : 'user';
        $candidate = $base;
        $i = 1;
        while (username_exists($candidate)) {
            $candidate = $base . '-' . ++$i;
            if ($i > 100) {
                $candidate = $base . '-' . wp_generate_password(6, false);
                break;
            }
        }
        return $candidate;
    }
}
