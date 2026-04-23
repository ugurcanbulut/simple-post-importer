<?php
/**
 * Plugin Name:       Simple Post Importer
 * Plugin URI:        https://github.com/ugurcanbulut/simple-post-importer
 * Description:       Scan a remote WordPress site via its public REST API, preview posts, and import selected ones (with featured and inline images, categories, tags, and authors) into the local site.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Ugurcan Bulut
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       simple-post-importer
 * Domain Path:       /languages
 */

declare(strict_types=1);

namespace SimplePostImporter;

if (!defined('ABSPATH')) {
    exit;
}

define('SPI_VERSION', '0.1.0');
define('SPI_PLUGIN_FILE', __FILE__);
define('SPI_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SPI_PLUGIN_URL', plugin_dir_url(__FILE__));
define('SPI_PLUGIN_BASENAME', plugin_basename(__FILE__));

$autoload = __DIR__ . '/vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
} else {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'SimplePostImporter\\';
        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
            return;
        }
        $relative = substr($class, strlen($prefix));
        $file = SPI_PLUGIN_DIR . 'src/' . str_replace('\\', '/', $relative) . '.php';
        if (is_readable($file)) {
            require_once $file;
        }
    });
}

register_activation_hook(__FILE__, [Database\Schema::class, 'activate']);
register_deactivation_hook(__FILE__, [Database\Schema::class, 'deactivate']);

add_action('plugins_loaded', static function (): void {
    Plugin::instance()->boot();
});
