<?php

declare(strict_types=1);

namespace SimplePostImporter\Rest;

use SimplePostImporter\Database\RemotePostsRepo;
use SimplePostImporter\Database\SessionsRepo;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class SessionController
{
    public function __construct(
        private SessionsRepo $sessions = new SessionsRepo(),
        private RemotePostsRepo $remotePosts = new RemotePostsRepo()
    ) {
    }

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        $ns = ScanController::NAMESPACE;

        register_rest_route($ns, '/sessions', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'listSessions'],
            'permission_callback' => [Permissions::class, 'manageOptions'],
            'args' => [
                'page' => ['required' => false, 'type' => 'integer', 'default' => 1],
                'per_page' => ['required' => false, 'type' => 'integer', 'default' => 20],
            ],
        ]);

        register_rest_route($ns, '/sessions/(?P<id>\d+)', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'getSession'],
                'permission_callback' => [Permissions::class, 'manageOptions'],
            ],
            [
                'methods' => WP_REST_Server::DELETABLE,
                'callback' => [$this, 'deleteSession'],
                'permission_callback' => [Permissions::class, 'manageOptions'],
            ],
        ]);

        register_rest_route($ns, '/sessions/(?P<id>\d+)/posts', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'listPosts'],
            'permission_callback' => [Permissions::class, 'manageOptions'],
            'args' => [
                'page' => ['required' => false, 'type' => 'integer', 'default' => 1],
                'per_page' => ['required' => false, 'type' => 'integer', 'default' => 50],
                'only_selected' => ['required' => false, 'type' => 'string'],
            ],
        ]);

        register_rest_route($ns, '/sessions/(?P<id>\d+)/posts/(?P<postId>\d+)', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'getPost'],
                'permission_callback' => [Permissions::class, 'manageOptions'],
            ],
            [
                'methods' => WP_REST_Server::EDITABLE,
                'callback' => [$this, 'patchPost'],
                'permission_callback' => [Permissions::class, 'manageOptions'],
                'args' => [
                    'selected' => ['required' => true, 'type' => 'boolean'],
                ],
            ],
        ]);

        register_rest_route($ns, '/sessions/(?P<id>\d+)/posts/bulk-select', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'bulkSelect'],
            'permission_callback' => [Permissions::class, 'manageOptions'],
            'args' => [
                'selected' => ['required' => true, 'type' => 'boolean'],
                'ids' => ['required' => false, 'type' => 'array'],
                'all' => ['required' => false, 'type' => 'boolean', 'default' => false],
            ],
        ]);
    }

    public function listSessions(WP_REST_Request $request): WP_REST_Response
    {
        $page = max(1, (int) $request->get_param('page'));
        $perPage = max(1, min(100, (int) $request->get_param('per_page')));

        $rows = $this->sessions->paginated($page, $perPage);
        $total = $this->sessions->count();

        return new WP_REST_Response([
            'items' => $rows,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ], 200);
    }

    public function getSession(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $id = (int) $request->get_param('id');
        $session = $this->sessions->find($id);
        if (!$session) {
            return new WP_Error('spi_not_found', __('Session not found.', 'simple-post-importer'), ['status' => 404]);
        }

        $session['posts_imported'] = $this->remotePosts->countImported($id);
        $session['posts_selected_pending'] = $this->remotePosts->countSelectedPending($id);
        $session['posts_total'] = $this->remotePosts->countForSession($id);

        return new WP_REST_Response($session, 200);
    }

    public function deleteSession(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $id = (int) $request->get_param('id');
        $session = $this->sessions->find($id);
        if (!$session) {
            return new WP_Error('spi_not_found', __('Session not found.', 'simple-post-importer'), ['status' => 404]);
        }

        $this->remotePosts->deleteForSession($id);
        $this->sessions->delete($id);

        return new WP_REST_Response(['deleted' => true], 200);
    }

    public function listPosts(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $id = (int) $request->get_param('id');
        if (!$this->sessions->find($id)) {
            return new WP_Error('spi_not_found', __('Session not found.', 'simple-post-importer'), ['status' => 404]);
        }

        $page = max(1, (int) $request->get_param('page'));
        $perPage = max(1, min(100, (int) $request->get_param('per_page')));

        $onlySelectedParam = $request->get_param('only_selected');
        $onlySelected = null;
        if ($onlySelectedParam === 'true' || $onlySelectedParam === '1') {
            $onlySelected = true;
        } elseif ($onlySelectedParam === 'false' || $onlySelectedParam === '0') {
            $onlySelected = false;
        }

        $rows = $this->remotePosts->listForSession($id, $page, $perPage, $onlySelected);
        $total = $this->remotePosts->countForSession($id, $onlySelected);

        $items = array_map([$this, 'serializeListItem'], $rows);

        return new WP_REST_Response([
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ], 200);
    }

    public function getPost(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $postId = (int) $request->get_param('postId');
        $row = $this->remotePosts->find($postId);
        if (!$row || (int) $row['session_id'] !== (int) $request->get_param('id')) {
            return new WP_Error('spi_not_found', __('Post not found.', 'simple-post-importer'), ['status' => 404]);
        }

        $row['content_safe'] = wp_kses_post((string) ($row['content'] ?? ''));
        unset($row['content']);

        return new WP_REST_Response($row, 200);
    }

    public function patchPost(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $postId = (int) $request->get_param('postId');
        $row = $this->remotePosts->find($postId);
        if (!$row || (int) $row['session_id'] !== (int) $request->get_param('id')) {
            return new WP_Error('spi_not_found', __('Post not found.', 'simple-post-importer'), ['status' => 404]);
        }

        $selected = (bool) $request->get_param('selected');
        $this->remotePosts->setSelected($postId, $selected);

        return new WP_REST_Response(['id' => $postId, 'selected' => $selected], 200);
    }

    public function bulkSelect(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $id = (int) $request->get_param('id');
        if (!$this->sessions->find($id)) {
            return new WP_Error('spi_not_found', __('Session not found.', 'simple-post-importer'), ['status' => 404]);
        }

        $selected = (bool) $request->get_param('selected');
        $all = (bool) $request->get_param('all');

        if ($all) {
            $count = $this->remotePosts->setAllSelected($id, $selected);
        } else {
            $ids = (array) $request->get_param('ids');
            $ids = array_map('intval', $ids);
            $count = $this->remotePosts->setSelectedBulk($id, $ids, $selected);
        }

        return new WP_REST_Response(['updated' => $count, 'selected' => $selected], 200);
    }

    private function serializeListItem(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'session_id' => (int) $row['session_id'],
            'remote_id' => (int) $row['remote_id'],
            'title' => (string) $row['title'],
            'excerpt' => (string) $row['excerpt'],
            'post_status' => (string) $row['post_status'],
            'published_date' => $row['published_date'],
            'featured_image_url' => $row['featured_image_url'],
            'author_data' => $row['author_data'],
            'terms_data' => $row['terms_data'],
            'selected' => (bool) $row['selected'],
            'imported' => (bool) $row['imported'],
            'local_post_id' => $row['local_post_id'] !== null ? (int) $row['local_post_id'] : null,
            'imported_at' => $row['imported_at'],
        ];
    }
}
