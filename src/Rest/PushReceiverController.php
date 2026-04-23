<?php

declare(strict_types=1);

namespace SimplePostImporter\Rest;

use SimplePostImporter\Push\PostDeserializer;
use SimplePostImporter\Push\TokenManager;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Receiver-side REST endpoints for server-to-server push.
 * Auth: Bearer token against `spi_tokens` table.
 */
final class PushReceiverController
{
    public function __construct(
        private TokenManager $tokens = new TokenManager(),
        private PostDeserializer $deserializer = new PostDeserializer()
    ) {
    }

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        $ns = ScanController::NAMESPACE;
        $authCallback = [$this, 'checkToken'];

        register_rest_route($ns, '/push/handshake', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'handshake'],
            'permission_callback' => $authCallback,
        ]);

        register_rest_route($ns, '/push/batch', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'batch'],
            'permission_callback' => $authCallback,
            'args' => [
                'site_url' => ['required' => true, 'type' => 'string'],
                'posts' => ['required' => true, 'type' => 'array'],
            ],
        ]);
    }

    public function checkToken(WP_REST_Request $request): bool|WP_Error
    {
        $auth = (string) $request->get_header('authorization');
        // Some Apache setups move Authorization into REDIRECT_HTTP_AUTHORIZATION.
        if ($auth === '') {
            $auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        }
        // Last resort: explicit X-SPI-Token fallback header (clients can send both).
        $plaintext = '';
        if (stripos($auth, 'bearer ') === 0) {
            $plaintext = trim(substr($auth, 7));
        } else {
            $plaintext = trim((string) $request->get_header('x-spi-token'));
        }
        if ($plaintext === '') {
            return new WP_Error(
                'spi_missing_token',
                __('Bearer token required.', 'simple-post-importer'),
                ['status' => 401]
            );
        }

        $row = $this->tokens->verify($plaintext);
        if (!$row) {
            return new WP_Error(
                'spi_invalid_token',
                __('Invalid or revoked token.', 'simple-post-importer'),
                ['status' => 401]
            );
        }
        return true;
    }

    public function handshake(WP_REST_Request $request): WP_REST_Response
    {
        global $wp_version;
        return new WP_REST_Response([
            'ok' => true,
            'site_url' => home_url(),
            'site_name' => get_bloginfo('name'),
            'wp_version' => $wp_version ?? '',
            'plugin_version' => defined('SPI_VERSION') ? SPI_VERSION : '0',
            'server_time' => current_time('mysql', true),
        ], 200);
    }

    public function batch(WP_REST_Request $request): WP_REST_Response
    {
        $siteUrl = esc_url_raw((string) $request->get_param('site_url'));
        $posts = (array) $request->get_param('posts');

        $results = [];
        foreach ($posts as $payload) {
            if (!is_array($payload)) {
                continue;
            }
            $sourceId = (int) ($payload['source_post_id'] ?? 0);
            $result = $this->deserializer->import($payload, $siteUrl);
            if (is_wp_error($result)) {
                $results[] = [
                    'source_post_id' => $sourceId,
                    'status' => 'error',
                    'error' => $result->get_error_message(),
                ];
                continue;
            }
            $results[] = [
                'source_post_id' => $sourceId,
                'status' => 'ok',
                'created' => $result['created'],
                'local_post_id' => $result['post_id'],
                'local_post_url' => get_permalink($result['post_id']) ?: null,
            ];
        }

        return new WP_REST_Response(['results' => $results], 200);
    }
}
