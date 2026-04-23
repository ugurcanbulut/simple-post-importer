<?php

declare(strict_types=1);

namespace SimplePostImporter\Database;

final class RemotePostsRepo
{
    public function upsert(int $sessionId, array $data): int
    {
        global $wpdb;
        $table = Schema::remotePostsTable();

        $row = [
            'session_id' => $sessionId,
            'remote_id' => (int) $data['remote_id'],
            'title' => $data['title'] ?? '',
            'excerpt' => $data['excerpt'] ?? '',
            'content' => $data['content'] ?? '',
            'post_status' => $data['post_status'] ?? 'publish',
            'published_date' => $data['published_date'] ?? null,
            'featured_image_url' => $data['featured_image_url'] ?? null,
            'author_data' => isset($data['author_data']) ? wp_json_encode($data['author_data']) : null,
            'terms_data' => isset($data['terms_data']) ? wp_json_encode($data['terms_data']) : null,
        ];

        $existing = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$table} WHERE session_id = %d AND remote_id = %d",
                $sessionId,
                (int) $data['remote_id']
            )
        );

        if ($existing) {
            $wpdb->update(
                $table,
                $row,
                ['id' => (int) $existing],
                null,
                ['%d']
            );
            return (int) $existing;
        }

        $wpdb->insert($table, $row);
        return (int) $wpdb->insert_id;
    }

    public function find(int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM ' . Schema::remotePostsTable() . ' WHERE id = %d', $id),
            ARRAY_A
        );
        return $row ? $this->hydrate($row) : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listForSession(int $sessionId, int $page, int $perPage, ?bool $onlySelected = null): array
    {
        global $wpdb;
        $offset = max(0, ($page - 1) * $perPage);
        $where = 'session_id = %d';
        $params = [$sessionId];
        if ($onlySelected === true) {
            $where .= ' AND selected = 1';
        } elseif ($onlySelected === false) {
            $where .= ' AND selected = 0';
        }
        $params[] = $perPage;
        $params[] = $offset;

        $sql = 'SELECT * FROM ' . Schema::remotePostsTable() . ' WHERE ' . $where . ' ORDER BY published_date DESC, id DESC LIMIT %d OFFSET %d';
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A);
        return array_map([$this, 'hydrate'], $rows ?: []);
    }

    public function countForSession(int $sessionId, ?bool $onlySelected = null): int
    {
        global $wpdb;
        $where = 'session_id = %d';
        $params = [$sessionId];
        if ($onlySelected === true) {
            $where .= ' AND selected = 1';
        } elseif ($onlySelected === false) {
            $where .= ' AND selected = 0';
        }
        $sql = 'SELECT COUNT(*) FROM ' . Schema::remotePostsTable() . ' WHERE ' . $where;
        return (int) $wpdb->get_var($wpdb->prepare($sql, ...$params));
    }

    public function setSelected(int $id, bool $selected): bool
    {
        global $wpdb;
        return (bool) $wpdb->update(
            Schema::remotePostsTable(),
            ['selected' => $selected ? 1 : 0],
            ['id' => $id],
            ['%d'],
            ['%d']
        );
    }

    /**
     * @param int[] $ids
     */
    public function setSelectedBulk(int $sessionId, array $ids, bool $selected): int
    {
        global $wpdb;
        if ($ids === []) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $sql = 'UPDATE ' . Schema::remotePostsTable() .
               ' SET selected = %d WHERE session_id = %d AND id IN (' . $placeholders . ')';
        $params = array_merge([$selected ? 1 : 0, $sessionId], array_map('intval', $ids));
        return (int) $wpdb->query($wpdb->prepare($sql, ...$params));
    }

    public function setAllSelected(int $sessionId, bool $selected): int
    {
        global $wpdb;
        return (int) $wpdb->query(
            $wpdb->prepare(
                'UPDATE ' . Schema::remotePostsTable() . ' SET selected = %d WHERE session_id = %d',
                $selected ? 1 : 0,
                $sessionId
            )
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function nextPendingImportBatch(int $sessionId, int $limit): array
    {
        global $wpdb;
        $sql = 'SELECT * FROM ' . Schema::remotePostsTable() .
               ' WHERE session_id = %d AND selected = 1 AND imported = 0 ORDER BY id ASC LIMIT %d';
        $rows = $wpdb->get_results($wpdb->prepare($sql, $sessionId, $limit), ARRAY_A);
        return array_map([$this, 'hydrate'], $rows ?: []);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function allImportedForSession(int $sessionId): array
    {
        global $wpdb;
        $sql = 'SELECT * FROM ' . Schema::remotePostsTable() .
               ' WHERE session_id = %d AND imported = 1';
        $rows = $wpdb->get_results($wpdb->prepare($sql, $sessionId), ARRAY_A);
        return array_map([$this, 'hydrate'], $rows ?: []);
    }

    public function markImported(int $id, int $localPostId, array $attachmentIds): bool
    {
        global $wpdb;
        return (bool) $wpdb->update(
            Schema::remotePostsTable(),
            [
                'imported' => 1,
                'local_post_id' => $localPostId,
                'local_attachment_ids' => wp_json_encode(array_values(array_unique(array_map('intval', $attachmentIds)))),
                'imported_at' => current_time('mysql', true),
            ],
            ['id' => $id],
            ['%d', '%d', '%s', '%s'],
            ['%d']
        );
    }

    public function resetImport(int $id): bool
    {
        global $wpdb;
        return (bool) $wpdb->update(
            Schema::remotePostsTable(),
            [
                'imported' => 0,
                'local_post_id' => null,
                'local_attachment_ids' => null,
                'imported_at' => null,
            ],
            ['id' => $id],
            ['%d', '%d', '%s', '%s'],
            ['%d']
        );
    }

    public function countSelectedPending(int $sessionId): int
    {
        global $wpdb;
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM ' . Schema::remotePostsTable() .
                ' WHERE session_id = %d AND selected = 1 AND imported = 0',
                $sessionId
            )
        );
    }

    public function countImported(int $sessionId): int
    {
        global $wpdb;
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM ' . Schema::remotePostsTable() .
                ' WHERE session_id = %d AND imported = 1',
                $sessionId
            )
        );
    }

    public function deleteForSession(int $sessionId): int
    {
        global $wpdb;
        return (int) $wpdb->delete(Schema::remotePostsTable(), ['session_id' => $sessionId], ['%d']);
    }

    private function hydrate(array $row): array
    {
        if (isset($row['author_data']) && is_string($row['author_data']) && $row['author_data'] !== '') {
            $row['author_data'] = json_decode($row['author_data'], true) ?: null;
        }
        if (isset($row['terms_data']) && is_string($row['terms_data']) && $row['terms_data'] !== '') {
            $row['terms_data'] = json_decode($row['terms_data'], true) ?: null;
        }
        if (isset($row['local_attachment_ids']) && is_string($row['local_attachment_ids']) && $row['local_attachment_ids'] !== '') {
            $row['local_attachment_ids'] = json_decode($row['local_attachment_ids'], true) ?: [];
        } else {
            $row['local_attachment_ids'] = [];
        }
        $row['selected'] = (int) ($row['selected'] ?? 0) === 1;
        $row['imported'] = (int) ($row['imported'] ?? 0) === 1;
        return $row;
    }
}
