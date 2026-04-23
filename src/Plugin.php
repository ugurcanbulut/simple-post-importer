<?php

declare(strict_types=1);

namespace SimplePostImporter;

use SimplePostImporter\Admin\AdminPage;
use SimplePostImporter\CLI\Commands;
use SimplePostImporter\Database\Schema;
use SimplePostImporter\Importer\BackgroundImporter;
use SimplePostImporter\Push\BackgroundPusher;
use SimplePostImporter\Rest\ImportController;
use SimplePostImporter\Rest\PushReceiverController;
use SimplePostImporter\Rest\PushSenderController;
use SimplePostImporter\Rest\ScanController;
use SimplePostImporter\Rest\SessionController;
use SimplePostImporter\Rest\SettingsController;
use SimplePostImporter\Rest\TokenController;
use SimplePostImporter\Scanner\BackgroundScanner;

final class Plugin
{
    private static ?self $instance = null;

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
    }

    public function boot(): void
    {
        add_action('init', [Schema::class, 'maybeUpgrade']);

        BackgroundScanner::register();
        BackgroundImporter::register();
        BackgroundPusher::register();

        (new AdminPage())->register();
        (new ScanController())->register();
        (new SessionController())->register();
        (new ImportController())->register();
        (new SettingsController())->register();
        (new TokenController())->register();
        (new PushReceiverController())->register();
        (new PushSenderController())->register();

        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::add_command('spi', Commands::class);
        }
    }
}
