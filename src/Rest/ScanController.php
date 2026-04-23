<?php

declare(strict_types=1);

namespace SimplePostImporter\Rest;

use SimplePostImporter\Database\SessionsRepo;
use SimplePostImporter\Scanner\BackgroundScanner;
use SimplePostImporter\Scanner\ScanRunner;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class ScanController
{
    public const NAMESPACE = 'simple-post-importer/v1';

    public function __construct(
        private SessionsRepo $sessions = new SessionsRepo(),
        private ScanRunner $runner = new ScanRunner()
    ) {
    }

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        register_rest_route(self::NAMESPACE, '/scans', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'createScan'],
            'permission_callback' => [Permissions::class, 'manageOptions'],
            'args' => [
                'url' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'esc_url_raw',
                ],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/scans/(?P<id>\d+)/run', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'runScan'],
            'permission_callback' => [Permissions::class, 'manageOptions'],
            'args' => [
                'id' => [
                    'required' => true,
                    'validate_callback' => static fn ($v) => is_numeric($v),
                ],
                'page' => [
                    'required' => false,
                    'type' => 'integer',
                ],
            ],
        ]);
    }

    public function createScan(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $url = (string) $request->get_param('url');
        if ($url === '' || !wp_http_validate_url($url)) {
            return new WP_Error('spi_invalid_url', __('Please provide a valid URL.', 'simple-post-importer'), ['status' => 400]);
        }

        $parts = wp_parse_url($url);
        if (!isset($parts['host'])) {
            return new WP_Error('spi_invalid_url', __('URL has no host.', 'simple-post-importer'), ['status' => 400]);
        }

        $scheme = $parts['scheme'] ?? 'https';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $base = $scheme . '://' . $parts['host'] . $port;

        $sessionId = $this->sessions->create($base, $parts['host']);
        BackgroundScanner::schedule($sessionId);
        $session = $this->sessions->find($sessionId);

        return new WP_REST_Response($session, 201);
    }

    public function runScan(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $id = (int) $request->get_param('id');
        $page = $request->get_param('page');
        $page = $page !== null ? max(1, (int) $page) : null;

        $result = $this->runner->runChunk($id, $page);
        if (is_wp_error($result)) {
            return new WP_Error($result->get_error_code(), $result->get_error_message(), ['status' => 502]);
        }

        return new WP_REST_Response($result, 200);
    }
}
