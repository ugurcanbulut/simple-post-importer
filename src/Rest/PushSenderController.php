<?php

declare(strict_types=1);

namespace SimplePostImporter\Rest;

use SimplePostImporter\Push\BackgroundPusher;
use SimplePostImporter\Push\PushRepo;
use SimplePostImporter\Push\PushRunner;
use SimplePostImporter\Push\TargetClient;
use WP_Error;
use WP_Query;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Source-side REST endpoints for outbound pushes.
 */
final class PushSenderController
{
    public function __construct(private PushRepo $repo = new PushRepo())
    {
    }

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        $ns = ScanController::NAMESPACE;
        $perm = [Permissions::class, 'manageOptions'];

        register_rest_route($ns, '/push-sessions', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'list'],
                'permission_callback' => $perm,
            ],
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'create'],
                'permission_callback' => $perm,
                'args' => [
                    'target_url' => ['required' => true, 'type' => 'string'],
                    'token' => ['required' => true, 'type' => 'string'],
                ],
            ],
        ]);

        register_rest_route($ns, '/push-sessions/(?P<id>\d+)', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'get'],
                'permission_callback' => $perm,
            ],
            [
                'methods' => WP_REST_Server::DELETABLE,
                'callback' => [$this, 'delete'],
                'permission_callback' => $perm,
            ],
        ]);

        register_rest_route($ns, '/push-sessions/(?P<id>\d+)/items', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'listItems'],
                'permission_callback' => $perm,
            ],
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'addItems'],
                'permission_callback' => $perm,
                'args' => [
                    'post_ids' => ['type' => 'array'],
                    'all_posts' => ['type' => 'boolean'],
                    'post_type' => ['type' => 'string'],
                    'post_status' => ['type' => 'string'],
                ],
            ],
        ]);

        register_rest_route($ns, '/push-sessions/(?P<id>\d+)/start', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'start'],
            'permission_callback' => $perm,
        ]);

        register_rest_route($ns, '/push-sessions/(?P<id>\d+)/run', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'run'],
            'permission_callback' => $perm,
        ]);

        register_rest_route($ns, '/push-candidates', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'listCandidates'],
            'permission_callback' => $perm,
            'args' => [
                'page' => ['type' => 'integer', 'default' => 1],
                'per_page' => ['type' => 'integer', 'default' => 25],
                'post_type' => ['type' => 'string', 'default' => 'post'],
                'post_status' => ['type' => 'string', 'default' => 'publish'],
                'search' => ['type' => 'string'],
            ],
        ]);
    }

    public function list(WP_REST_Request $request): WP_REST_Response
    {
        $page = max(1, (int) $request->get_param('page'));
        $perPage = max(1, min(100, (int) ($request->get_param('per_page') ?: 20)));
        return new WP_REST_Response([
            'items' => $this->repo->listSessions($page, $perPage),
            'total' => $this->repo->countSessions(),
        ], 200);
    }

    public function create(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $targetUrl = esc_url_raw((string) $request->get_param('target_url'));
        $token = (string) $request->get_param('token');
        if ($targetUrl === '' || !wp_http_validate_url($targetUrl) || $token === '') {
            return new WP_Error('spi_invalid_input', __('Target URL and token are required.', 'simple-post-importer'), ['status' => 400]);
        }

        $client = new TargetClient($targetUrl, $token);
        $handshake = $client->handshake();
        if (is_wp_error($handshake)) {
            return new WP_Error('spi_handshake_failed', $handshake->get_error_message(), ['status' => 400]);
        }

        $siteName = (string) ($handshake['site_name'] ?? '');
        $id = $this->repo->createSession($targetUrl, $siteName, $token);
        $session = $this->repo->findSession($id);
        return new WP_REST_Response([
            'session' => $session,
            'handshake' => $handshake,
        ], 201);
    }

    public function get(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $id = (int) $request->get_param('id');
        $session = $this->repo->findSession($id);
        if (!$session) {
            return new WP_Error('spi_not_found', __('Session not found.', 'simple-post-importer'), ['status' => 404]);
        }
        $session['total'] = $this->repo->countTotal($id);
        $session['done'] = $this->repo->countPushed($id);
        $session['pending'] = $this->repo->countPending($id);
        return new WP_REST_Response($session, 200);
    }

    public function delete(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $id = (int) $request->get_param('id');
        if (!$this->repo->findSession($id)) {
            return new WP_Error('spi_not_found', __('Session not found.', 'simple-post-importer'), ['status' => 404]);
        }
        $this->repo->deleteSession($id);
        return new WP_REST_Response(['deleted' => true], 200);
    }

    public function listItems(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $id = (int) $request->get_param('id');
        if (!$this->repo->findSession($id)) {
            return new WP_Error('spi_not_found', __('Session not found.', 'simple-post-importer'), ['status' => 404]);
        }
        $page = max(1, (int) ($request->get_param('page') ?: 1));
        $perPage = max(1, min(100, (int) ($request->get_param('per_page') ?: 50)));
        $items = $this->repo->listItems($id, $page, $perPage);
        $hydrated = array_map(static function (array $row): array {
            $post = get_post((int) $row['local_post_id']);
            $row['id'] = (int) $row['id'];
            $row['session_id'] = (int) $row['session_id'];
            $row['local_post_id'] = (int) $row['local_post_id'];
            $row['pushed'] = (int) $row['pushed'] === 1;
            $row['target_post_id'] = $row['target_post_id'] !== null ? (int) $row['target_post_id'] : null;
            $row['title'] = $post ? $post->post_title : '';
            return $row;
        }, $items);
        return new WP_REST_Response([
            'items' => $hydrated,
            'total' => $this->repo->countTotal($id),
        ], 200);
    }

    public function addItems(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $id = (int) $request->get_param('id');
        if (!$this->repo->findSession($id)) {
            return new WP_Error('spi_not_found', __('Session not found.', 'simple-post-importer'), ['status' => 404]);
        }

        $postIds = (array) $request->get_param('post_ids');
        if ((bool) $request->get_param('all_posts')) {
            $postType = sanitize_key((string) $request->get_param('post_type')) ?: 'post';
            $postStatus = sanitize_key((string) $request->get_param('post_status')) ?: 'publish';
            $query = new WP_Query([
                'post_type' => $postType,
                'post_status' => $postStatus,
                'posts_per_page' => -1,
                'fields' => 'ids',
                'no_found_rows' => true,
            ]);
            $postIds = array_map('intval', $query->posts);
        }

        $added = $this->repo->addItems($id, array_map('intval', $postIds));
        $this->repo->updateSession($id, [
            'total' => $this->repo->countTotal($id),
        ]);

        return new WP_REST_Response([
            'added' => $added,
            'total' => $this->repo->countTotal($id),
        ], 200);
    }

    public function start(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $id = (int) $request->get_param('id');
        $session = $this->repo->findSession($id);
        if (!$session) {
            return new WP_Error('spi_not_found', __('Session not found.', 'simple-post-importer'), ['status' => 404]);
        }
        if ($this->repo->countPending($id) === 0) {
            return new WP_Error('spi_nothing_to_push', __('Add at least one post to push.', 'simple-post-importer'), ['status' => 400]);
        }

        $this->repo->updateSession($id, [
            'status' => 'running',
            'total' => $this->repo->countTotal($id),
            'error' => null,
        ]);
        BackgroundPusher::schedule($id);
        return new WP_REST_Response($this->repo->findSession($id), 200);
    }

    public function run(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $id = (int) $request->get_param('id');
        $runner = new PushRunner();
        $result = $runner->runChunk($id);
        if (is_wp_error($result)) {
            return new WP_Error($result->get_error_code(), $result->get_error_message(), ['status' => 500]);
        }
        return new WP_REST_Response($result, 200);
    }

    public function listCandidates(WP_REST_Request $request): WP_REST_Response
    {
        $page = max(1, (int) ($request->get_param('page') ?: 1));
        $perPage = max(1, min(100, (int) ($request->get_param('per_page') ?: 25)));
        $postType = sanitize_key((string) $request->get_param('post_type')) ?: 'post';
        $postStatus = sanitize_key((string) $request->get_param('post_status')) ?: 'publish';
        $search = (string) ($request->get_param('search') ?? '');

        $args = [
            'post_type' => $postType,
            'post_status' => $postStatus,
            'posts_per_page' => $perPage,
            'paged' => $page,
            'orderby' => 'date',
            'order' => 'DESC',
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ];
        if ($search !== '') {
            $args['s'] = $search;
        }

        $query = new WP_Query($args);
        $items = [];
        foreach ($query->posts as $post) {
            $thumbId = (int) get_post_thumbnail_id($post->ID);
            $thumbUrl = $thumbId ? wp_get_attachment_image_url($thumbId, 'thumbnail') : null;
            $items[] = [
                'id' => (int) $post->ID,
                'title' => $post->post_title,
                'excerpt' => wp_strip_all_tags((string) $post->post_excerpt, true),
                'post_status' => $post->post_status,
                'post_type' => $post->post_type,
                'date_gmt' => $post->post_date_gmt,
                'featured_image_url' => $thumbUrl ?: null,
                'author' => get_the_author_meta('display_name', (int) $post->post_author),
            ];
        }

        return new WP_REST_Response([
            'items' => $items,
            'total' => (int) $query->found_posts,
            'page' => $page,
            'per_page' => $perPage,
            'pages' => (int) $query->max_num_pages,
            'post_types' => $this->availablePostTypes(),
        ], 200);
    }

    /**
     * @return array<int, array{slug: string, label: string}>
     */
    private function availablePostTypes(): array
    {
        $types = get_post_types(['public' => true, 'show_in_rest' => true], 'objects');
        $out = [];
        foreach ($types as $type) {
            if (in_array($type->name, ['attachment'], true)) {
                continue;
            }
            $out[] = ['slug' => $type->name, 'label' => $type->labels->name ?? $type->name];
        }
        return $out;
    }
}
