<?php
/**
 * Fires when the plugin is uninstalled.
 * Drops custom tables and clears plugin options.
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

$tables = [
    $wpdb->prefix . 'spi_push_items',
    $wpdb->prefix . 'spi_push_sessions',
    $wpdb->prefix . 'spi_tokens',
    $wpdb->prefix . 'spi_remote_posts',
    $wpdb->prefix . 'spi_sessions',
];

foreach ($tables as $table) {
    $wpdb->query("DROP TABLE IF EXISTS {$table}");
}

delete_option('spi_db_version');
delete_option('spi_settings');
