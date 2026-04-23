<?php

declare(strict_types=1);

namespace SimplePostImporter\Rest;

use SimplePostImporter\Push\TokenManager;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class TokenController
{
    public function __construct(private TokenManager $tokens = new TokenManager())
    {
    }

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        $ns = ScanController::NAMESPACE;

        register_rest_route($ns, '/tokens', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'list'],
                'permission_callback' => [Permissions::class, 'manageOptions'],
            ],
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'create'],
                'permission_callback' => [Permissions::class, 'manageOptions'],
                'args' => [
                    'name' => ['required' => true, 'type' => 'string'],
                ],
            ],
        ]);

        register_rest_route($ns, '/tokens/(?P<id>\d+)', [
            [
                'methods' => WP_REST_Server::EDITABLE,
                'callback' => [$this, 'revoke'],
                'permission_callback' => [Permissions::class, 'manageOptions'],
            ],
            [
                'methods' => WP_REST_Server::DELETABLE,
                'callback' => [$this, 'delete'],
                'permission_callback' => [Permissions::class, 'manageOptions'],
            ],
        ]);
    }

    public function list(): WP_REST_Response
    {
        return new WP_REST_Response(['items' => $this->tokens->list()], 200);
    }

    public function create(WP_REST_Request $request): WP_REST_Response
    {
        $name = sanitize_text_field((string) $request->get_param('name'));
        $token = $this->tokens->create($name);
        return new WP_REST_Response($token, 201);
    }

    public function revoke(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $id = (int) $request->get_param('id');
        $ok = $this->tokens->revoke($id);
        if (!$ok) {
            return new WP_Error('spi_not_found', __('Token not found.', 'simple-post-importer'), ['status' => 404]);
        }
        return new WP_REST_Response(['revoked' => true, 'id' => $id], 200);
    }

    public function delete(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $id = (int) $request->get_param('id');
        $ok = $this->tokens->delete($id);
        if (!$ok) {
            return new WP_Error('spi_not_found', __('Token not found.', 'simple-post-importer'), ['status' => 404]);
        }
        return new WP_REST_Response(['deleted' => true, 'id' => $id], 200);
    }
}
