<?php

declare(strict_types=1);

namespace SimplePostImporter\Admin;

final class AdminPage
{
    public const SLUG = 'simple-post-importer';
    public const HANDLE = 'spi-admin';

    public function register(): void
    {
        add_action('admin_menu', [$this, 'registerMenu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
    }

    public function registerMenu(): void
    {
        add_management_page(
            __('Simple Post Importer', 'simple-post-importer'),
            __('Simple Post Importer', 'simple-post-importer'),
            'manage_options',
            self::SLUG,
            [$this, 'render']
        );
    }

    public function render(): void
    {
        echo '<div class="wrap"><div id="spi-admin-root"></div></div>';
    }

    public function enqueueAssets(string $hook): void
    {
        if ($hook !== 'tools_page_' . self::SLUG) {
            return;
        }

        $buildDir = SPI_PLUGIN_DIR . 'build/';
        $buildUrl = SPI_PLUGIN_URL . 'build/';
        $assetFile = $buildDir . 'index.asset.php';

        if (!file_exists($assetFile)) {
            add_action('admin_notices', static function (): void {
                echo '<div class="notice notice-error"><p>';
                echo esc_html__('Simple Post Importer: build assets are missing. Run `npm install && npm run build` in the plugin directory.', 'simple-post-importer');
                echo '</p></div>';
            });
            return;
        }

        $asset = include $assetFile;

        wp_enqueue_script(
            self::HANDLE,
            $buildUrl . 'index.js',
            $asset['dependencies'] ?? [],
            $asset['version'] ?? SPI_VERSION,
            true
        );

        $cssFile = null;
        foreach (['style-index.css', 'index.css'] as $candidate) {
            if (file_exists($buildDir . $candidate)) {
                $cssFile = $candidate;
                break;
            }
        }
        if ($cssFile !== null) {
            wp_enqueue_style(
                self::HANDLE,
                $buildUrl . $cssFile,
                ['wp-components'],
                $asset['version'] ?? SPI_VERSION
            );
            if (is_rtl()) {
                $rtl = str_replace('.css', '-rtl.css', $cssFile);
                if (file_exists($buildDir . $rtl)) {
                    wp_style_add_data(self::HANDLE, 'rtl', 'replace');
                }
            }
        }

        wp_enqueue_style('wp-components');

        wp_localize_script(self::HANDLE, 'SPI_CONFIG', [
            'rootURL' => esc_url_raw(rest_url()),
            'namespace' => 'simple-post-importer/v1',
            'nonce' => wp_create_nonce('wp_rest'),
            'pluginURL' => esc_url_raw(SPI_PLUGIN_URL),
            'adminURL' => esc_url_raw(admin_url()),
        ]);

        wp_set_script_translations(self::HANDLE, 'simple-post-importer');
    }
}
