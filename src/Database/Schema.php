<?php

declare(strict_types=1);

namespace SimplePostImporter\Database;

final class Schema
{
    public const DB_VERSION = '1.1.0';
    public const OPTION_KEY = 'spi_db_version';

    public static function sessionsTable(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'spi_sessions';
    }

    public static function remotePostsTable(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'spi_remote_posts';
    }

    public static function tokensTable(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'spi_tokens';
    }

    public static function pushSessionsTable(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'spi_push_sessions';
    }

    public static function pushItemsTable(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'spi_push_items';
    }

    public static function activate(): void
    {
        self::install();
    }

    public static function deactivate(): void
    {
    }

    public static function maybeUpgrade(): void
    {
        $current = get_option(self::OPTION_KEY);
        if ($current !== self::DB_VERSION) {
            self::install();
        }
    }

    public static function install(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $sessions = self::sessionsTable();
        $remotePosts = self::remotePostsTable();

        $sessionsSql = "CREATE TABLE {$sessions} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            source_url VARCHAR(500) NOT NULL,
            source_host VARCHAR(255) NOT NULL DEFAULT '',
            scan_status VARCHAR(20) NOT NULL DEFAULT 'pending',
            scan_current_page INT UNSIGNED NOT NULL DEFAULT 0,
            scan_total_pages INT UNSIGNED NOT NULL DEFAULT 0,
            scan_total_posts INT UNSIGNED NOT NULL DEFAULT 0,
            scan_error TEXT NULL,
            import_status VARCHAR(20) NOT NULL DEFAULT 'idle',
            import_total INT UNSIGNED NOT NULL DEFAULT 0,
            import_done INT UNSIGNED NOT NULL DEFAULT 0,
            import_error TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY scan_status (scan_status)
        ) {$charset};";

        $remotePostsSql = "CREATE TABLE {$remotePosts} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            session_id BIGINT UNSIGNED NOT NULL,
            remote_id BIGINT UNSIGNED NOT NULL,
            title TEXT NULL,
            excerpt TEXT NULL,
            content LONGTEXT NULL,
            post_status VARCHAR(50) NOT NULL DEFAULT 'publish',
            published_date DATETIME NULL,
            featured_image_url TEXT NULL,
            author_data LONGTEXT NULL,
            terms_data LONGTEXT NULL,
            selected TINYINT(1) NOT NULL DEFAULT 0,
            imported TINYINT(1) NOT NULL DEFAULT 0,
            local_post_id BIGINT UNSIGNED NULL,
            local_attachment_ids LONGTEXT NULL,
            imported_at DATETIME NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY uniq_session_remote (session_id, remote_id),
            KEY session_selected_imported (session_id, selected, imported),
            KEY local_post_id (local_post_id)
        ) {$charset};";

        $tokensTable = self::tokensTable();
        $pushSessionsTable = self::pushSessionsTable();
        $pushItemsTable = self::pushItemsTable();

        $tokensSql = "CREATE TABLE {$tokensTable} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(120) NOT NULL DEFAULT '',
            token_hash VARCHAR(255) NOT NULL,
            preview VARCHAR(16) NOT NULL DEFAULT '',
            revoked TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            last_used_at DATETIME NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY token_hash (token_hash),
            KEY revoked (revoked)
        ) {$charset};";

        $pushSessionsSql = "CREATE TABLE {$pushSessionsTable} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            target_url VARCHAR(500) NOT NULL,
            target_site_name VARCHAR(255) NOT NULL DEFAULT '',
            token_preview VARCHAR(16) NOT NULL DEFAULT '',
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            total INT UNSIGNED NOT NULL DEFAULT 0,
            done INT UNSIGNED NOT NULL DEFAULT 0,
            error TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY status (status)
        ) {$charset};";

        $pushItemsSql = "CREATE TABLE {$pushItemsTable} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            session_id BIGINT UNSIGNED NOT NULL,
            local_post_id BIGINT UNSIGNED NOT NULL,
            pushed TINYINT(1) NOT NULL DEFAULT 0,
            target_post_id BIGINT UNSIGNED NULL,
            target_post_url VARCHAR(500) NULL,
            error TEXT NULL,
            pushed_at DATETIME NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY uniq_session_post (session_id, local_post_id),
            KEY session_pushed (session_id, pushed),
            KEY local_post_id (local_post_id)
        ) {$charset};";

        dbDelta($sessionsSql);
        dbDelta($remotePostsSql);
        dbDelta($tokensSql);
        dbDelta($pushSessionsSql);
        dbDelta($pushItemsSql);

        update_option(self::OPTION_KEY, self::DB_VERSION, false);
    }
}
