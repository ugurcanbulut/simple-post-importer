<?php

declare(strict_types=1);

namespace SimplePostImporter\Rest;

use SimplePostImporter\Database\RemotePostsRepo;
use SimplePostImporter\Database\SessionsRepo;
use SimplePostImporter\Importer\BackgroundImporter;
use SimplePostImporter\Importer\ImportCleaner;
use SimplePostImporter\Importer\ImportRunner;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class ImportController
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

        register_rest_route($ns, '/sessions/(?P<id>\d+)/import', [
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'startImport'],
                'permission_callback' => [Permissions::class, 'manageOptions'],
            ],
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'getImportStatus'],
                'permission_callback' => [Permissions::class, 'manageOptions'],
            ],
        ]);

        register_rest_route($ns, '/sessions/(?P<id>\d+)/import/run', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'runImportChunk'],
            'permission_callback' => [Permissions::class, 'manageOptions'],
        ]);

        register_rest_route($ns, '/sessions/(?P<id>\d+)/imports', [
            'methods' => WP_REST_Server::DELETABLE,
            'callback' => [$this, 'clearImports'],
            'permission_callback' => [Permissions::class, 'manageOptions'],
        ]);
    }

    public function startImport(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $id = (int) $request->get_param('id');
        $session = $this->sessions->find($id);
        if (!$session) {
            return new WP_Error('spi_not_found', __('Session not found.', 'simple-post-importer'), ['status' => 404]);
        }

        $pending = $this->remotePosts->countSelectedPending($id);
        if ($pending === 0) {
            return new WP_Error('spi_nothing_selected', __('Select at least one post to import.', 'simple-post-importer'), ['status' => 400]);
        }

        $this->sessions->update($id, [
            'import_status' => 'running',
            'import_total' => $pending,
            'import_done' => 0,
            'import_error' => null,
        ]);

        BackgroundImporter::schedule($id);

        return new WP_REST_Response($this->statusPayload($id), 200);
    }

    public function getImportStatus(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $id = (int) $request->get_param('id');
        if (!$this->sessions->find($id)) {
            return new WP_Error('spi_not_found', __('Session not found.', 'simple-post-importer'), ['status' => 404]);
        }
        return new WP_REST_Response($this->statusPayload($id), 200);
    }

    public function runImportChunk(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $id = (int) $request->get_param('id');
        $session = $this->sessions->find($id);
        if (!$session) {
            return new WP_Error('spi_not_found', __('Session not found.', 'simple-post-importer'), ['status' => 404]);
        }

        if ($session['import_status'] !== 'running') {
            return new WP_REST_Response($this->statusPayload($id), 200);
        }

        $runner = new ImportRunner();
        $result = $runner->runChunk($id);
        if (is_wp_error($result)) {
            $this->sessions->update($id, [
                'import_status' => 'failed',
                'import_error' => $result->get_error_message(),
            ]);
            return new WP_Error($result->get_error_code(), $result->get_error_message(), ['status' => 500]);
        }

        return new WP_REST_Response($this->statusPayload($id), 200);
    }

    public function clearImports(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $id = (int) $request->get_param('id');
        $session = $this->sessions->find($id);
        if (!$session) {
            return new WP_Error('spi_not_found', __('Session not found.', 'simple-post-importer'), ['status' => 404]);
        }

        $cleaner = new ImportCleaner();
        $summary = $cleaner->clearSession($id);

        $this->sessions->update($id, [
            'import_status' => 'idle',
            'import_total' => 0,
            'import_done' => 0,
            'import_error' => null,
        ]);

        return new WP_REST_Response($summary, 200);
    }

    private function statusPayload(int $sessionId): array
    {
        $session = $this->sessions->find($sessionId) ?? [];
        return [
            'session' => $session,
            'counts' => [
                'total' => $this->remotePosts->countForSession($sessionId),
                'selected_pending' => $this->remotePosts->countSelectedPending($sessionId),
                'imported' => $this->remotePosts->countImported($sessionId),
            ],
        ];
    }
}
