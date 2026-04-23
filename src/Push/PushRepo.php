<?php

declare(strict_types=1);

namespace SimplePostImporter\Push;

use SimplePostImporter\Database\Schema;

/**
 * CRUD for push_sessions + push_items. Also brokers the secret token:
 * plaintext is stored in a dedicated option (not in the sessions table)
 * so it never shows in list endpoints. Session only keeps a display preview.
 */
final class PushRepo
{
    private const TOKEN_OPTION_PREFIX = '_spi_push_token_';

    public function createSession(string $targetUrl, string $targetSiteName, string $token): int
    {
        global $wpdb;
        $now = current_time('mysql', true);
        $wpdb->insert(
            Schema::pushSessionsTable(),
            [
                'target_url' => $targetUrl,
                'target_site_name' => $targetSiteName,
                'token_preview' => substr($token, 0, 8),
                'status' => 'pending',
                'total' => 0,
                'done' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s']
        );
        $id = (int) $wpdb->insert_id;
        $this->storeToken($id, $token);
        return $id;
    }

    public function findSession(int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM ' . Schema::pushSessionsTable() . ' WHERE id = %d', $id),
            ARRAY_A
        );
        return $row ?: null;
    }

    public function updateSession(int $id, array $data): bool
    {
        global $wpdb;
        $data['updated_at'] = current_time('mysql', true);
        $formats = array_map(
            static fn ($v) => is_int($v) ? '%d' : (is_float($v) ? '%f' : '%s'),
            $data
        );
        return (bool) $wpdb->update(Schema::pushSessionsTable(), $data, ['id' => $id], $formats, ['%d']);
    }

    public function deleteSession(int $id): bool
    {
        global $wpdb;
        $wpdb->delete(Schema::pushItemsTable(), ['session_id' => $id], ['%d']);
        $this->forgetToken($id);
        return (bool) $wpdb->delete(Schema::pushSessionsTable(), ['id' => $id], ['%d']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listSessions(int $page, int $perPage): array
    {
        global $wpdb;
        $offset = max(0, ($page - 1) * $perPage);
        return $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM ' . Schema::pushSessionsTable() . ' ORDER BY id DESC LIMIT %d OFFSET %d',
                $perPage,
                $offset
            ),
            ARRAY_A
        ) ?: [];
    }

    public function countSessions(): int
    {
        global $wpdb;
        return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Schema::pushSessionsTable());
    }

    /**
     * @param int[] $localPostIds
     */
    public function addItems(int $sessionId, array $localPostIds): int
    {
        global $wpdb;
        if ($localPostIds === []) {
            return 0;
        }
        $count = 0;
        foreach (array_unique(array_map('intval', $localPostIds)) as $postId) {
            if ($postId <= 0) {
                continue;
            }
            $inserted = $wpdb->query(
                $wpdb->prepare(
                    'INSERT IGNORE INTO ' . Schema::pushItemsTable() .
                    ' (session_id, local_post_id, pushed) VALUES (%d, %d, 0)',
                    $sessionId,
                    $postId
                )
            );
            if ($inserted) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function nextPendingItems(int $sessionId, int $limit): array
    {
        global $wpdb;
        return $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM ' . Schema::pushItemsTable() .
                ' WHERE session_id = %d AND pushed = 0 ORDER BY id ASC LIMIT %d',
                $sessionId,
                $limit
            ),
            ARRAY_A
        ) ?: [];
    }

    public function markItemPushed(int $id, int $targetPostId, ?string $targetPostUrl): bool
    {
        global $wpdb;
        return (bool) $wpdb->update(
            Schema::pushItemsTable(),
            [
                'pushed' => 1,
                'target_post_id' => $targetPostId,
                'target_post_url' => $targetPostUrl,
                'error' => null,
                'pushed_at' => current_time('mysql', true),
            ],
            ['id' => $id],
            ['%d', '%d', '%s', '%s', '%s'],
            ['%d']
        );
    }

    public function markItemError(int $id, string $error): bool
    {
        global $wpdb;
        return (bool) $wpdb->update(
            Schema::pushItemsTable(),
            ['error' => $error],
            ['id' => $id],
            ['%s'],
            ['%d']
        );
    }

    public function countPending(int $sessionId): int
    {
        global $wpdb;
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM ' . Schema::pushItemsTable() . ' WHERE session_id = %d AND pushed = 0',
                $sessionId
            )
        );
    }

    public function countPushed(int $sessionId): int
    {
        global $wpdb;
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM ' . Schema::pushItemsTable() . ' WHERE session_id = %d AND pushed = 1',
                $sessionId
            )
        );
    }

    public function countTotal(int $sessionId): int
    {
        global $wpdb;
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM ' . Schema::pushItemsTable() . ' WHERE session_id = %d',
                $sessionId
            )
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listItems(int $sessionId, int $page, int $perPage): array
    {
        global $wpdb;
        $offset = max(0, ($page - 1) * $perPage);
        return $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM ' . Schema::pushItemsTable() .
                ' WHERE session_id = %d ORDER BY id ASC LIMIT %d OFFSET %d',
                $sessionId,
                $perPage,
                $offset
            ),
            ARRAY_A
        ) ?: [];
    }

    public function getToken(int $sessionId): ?string
    {
        $value = get_option(self::TOKEN_OPTION_PREFIX . $sessionId, '');
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function storeToken(int $sessionId, string $token): void
    {
        update_option(self::TOKEN_OPTION_PREFIX . $sessionId, $token, false);
    }

    private function forgetToken(int $sessionId): void
    {
        delete_option(self::TOKEN_OPTION_PREFIX . $sessionId);
    }
}
