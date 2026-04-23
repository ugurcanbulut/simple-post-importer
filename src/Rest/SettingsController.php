<?php

declare(strict_types=1);

namespace SimplePostImporter\Rest;

use SimplePostImporter\Settings\Settings;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class SettingsController
{
    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        $ns = ScanController::NAMESPACE;

        register_rest_route($ns, '/settings', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'get'],
                'permission_callback' => [Permissions::class, 'manageOptions'],
            ],
            [
                'methods' => WP_REST_Server::EDITABLE,
                'callback' => [$this, 'update'],
                'permission_callback' => [Permissions::class, 'manageOptions'],
                'args' => [
                    'default_author_id' => ['type' => 'integer'],
                    'on_missing_author' => ['type' => 'string'],
                ],
            ],
        ]);
    }

    public function get(): WP_REST_Response
    {
        return new WP_REST_Response([
            'settings' => Settings::all(),
            'resolved_default_author_id' => Settings::getDefaultAuthorId(),
        ], 200);
    }

    public function update(WP_REST_Request $request): WP_REST_Response
    {
        $patch = [];
        if ($request->has_param('default_author_id')) {
            $patch['default_author_id'] = (int) $request->get_param('default_author_id');
        }
        if ($request->has_param('on_missing_author')) {
            $patch['on_missing_author'] = (string) $request->get_param('on_missing_author');
        }
        $settings = Settings::update($patch);

        return new WP_REST_Response([
            'settings' => $settings,
            'resolved_default_author_id' => Settings::getDefaultAuthorId(),
        ], 200);
    }
}
