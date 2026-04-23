<?php

declare(strict_types=1);

namespace SimplePostImporter\Database;

final class SessionsRepo
{
    public function create(string $sourceUrl, string $sourceHost): int
    {
        global $wpdb;
        $now = current_time('mysql', true);
        $wpdb->insert(
            Schema::sessionsTable(),
            [
                'source_url' => $sourceUrl,
                'source_host' => $sourceHost,
                'scan_status' => 'pending',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['%s', '%s', '%s', '%s', '%s']
        );
        return (int) $wpdb->insert_id;
    }

    public function find(int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM ' . Schema::sessionsTable() . ' WHERE id = %d', $id),
            ARRAY_A
        );
        return $row ?: null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function paginated(int $page, int $perPage): array
    {
        global $wpdb;
        $offset = max(0, ($page - 1) * $perPage);
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM ' . Schema::sessionsTable() . ' ORDER BY id DESC LIMIT %d OFFSET %d',
                $perPage,
                $offset
            ),
            ARRAY_A
        );
        return $rows ?: [];
    }

    public function count(): int
    {
        global $wpdb;
        return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Schema::sessionsTable());
    }

    public function update(int $id, array $data): bool
    {
        global $wpdb;
        $data['updated_at'] = current_time('mysql', true);
        $formats = array_map(static function ($value): string {
            if (is_int($value)) {
                return '%d';
            }
            if (is_float($value)) {
                return '%f';
            }
            return '%s';
        }, $data);
        return (bool) $wpdb->update(Schema::sessionsTable(), $data, ['id' => $id], $formats, ['%d']);
    }

    public function delete(int $id): bool
    {
        global $wpdb;
        return (bool) $wpdb->delete(Schema::sessionsTable(), ['id' => $id], ['%d']);
    }
}
