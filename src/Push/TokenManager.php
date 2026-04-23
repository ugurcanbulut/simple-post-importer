<?php

declare(strict_types=1);

namespace SimplePostImporter\Push;

use SimplePostImporter\Database\Schema;

/**
 * Target-side token management. Tokens are stored as SHA-256 hashes; the
 * plaintext is returned exactly once at creation and never persisted.
 *
 * Verification: `hash('sha256', $plaintext)` is compared against `token_hash`.
 * Preview (first 8 chars of plaintext) is stored plainly to help users
 * identify which token they're looking at in lists.
 */
final class TokenManager
{
    public function create(string $name): array
    {
        global $wpdb;

        $plaintext = $this->generate();
        $hash = $this->hash($plaintext);
        $preview = substr($plaintext, 0, 8);
        $now = current_time('mysql', true);

        $wpdb->insert(
            Schema::tokensTable(),
            [
                'name' => sanitize_text_field($name !== '' ? $name : __('Unnamed token', 'simple-post-importer')),
                'token_hash' => $hash,
                'preview' => $preview,
                'revoked' => 0,
                'created_at' => $now,
            ],
            ['%s', '%s', '%s', '%d', '%s']
        );

        $id = (int) $wpdb->insert_id;

        return [
            'id' => $id,
            'name' => $name,
            'preview' => $preview,
            'plaintext' => $plaintext,
            'created_at' => $now,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function list(): array
    {
        global $wpdb;
        $rows = $wpdb->get_results(
            'SELECT id, name, preview, revoked, created_at, last_used_at FROM ' . Schema::tokensTable() . ' ORDER BY id DESC',
            ARRAY_A
        ) ?: [];
        return array_map(static function (array $row): array {
            $row['id'] = (int) $row['id'];
            $row['revoked'] = (int) $row['revoked'] === 1;
            return $row;
        }, $rows);
    }

    public function revoke(int $id): bool
    {
        global $wpdb;
        return (bool) $wpdb->update(
            Schema::tokensTable(),
            ['revoked' => 1],
            ['id' => $id],
            ['%d'],
            ['%d']
        );
    }

    public function delete(int $id): bool
    {
        global $wpdb;
        return (bool) $wpdb->delete(Schema::tokensTable(), ['id' => $id], ['%d']);
    }

    /**
     * Find a valid (non-revoked) token row matching the plaintext, marking
     * `last_used_at`. Returns null if no match.
     */
    public function verify(string $plaintext): ?array
    {
        global $wpdb;
        $hash = $this->hash($plaintext);
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . Schema::tokensTable() . ' WHERE token_hash = %s AND revoked = 0 LIMIT 1',
                $hash
            ),
            ARRAY_A
        );
        if (!$row) {
            return null;
        }
        $wpdb->update(
            Schema::tokensTable(),
            ['last_used_at' => current_time('mysql', true)],
            ['id' => (int) $row['id']],
            ['%s'],
            ['%d']
        );
        $row['id'] = (int) $row['id'];
        return $row;
    }

    public function generate(): string
    {
        // 48 bytes → 64 base64url chars → ~288 bits of entropy. Plenty.
        try {
            $raw = random_bytes(48);
        } catch (\Throwable $e) {
            $raw = wp_generate_password(48, true, true);
        }
        return 'spi_' . rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    public function hash(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }
}
