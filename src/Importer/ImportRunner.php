<?php

declare(strict_types=1);

namespace SimplePostImporter\Importer;

use SimplePostImporter\Database\RemotePostsRepo;
use SimplePostImporter\Database\SessionsRepo;
use WP_Error;

/**
 * Processes selected posts in one chunk, budgeted by time and image count,
 * and reports progress back via the sessions table.
 */
final class ImportRunner
{
    public const DEFAULT_TIME_BUDGET_SECONDS = 20;
    public const DEFAULT_IMAGE_BUDGET = 10;
    public const BATCH_FETCH_SIZE = 5;

    public function __construct(
        private SessionsRepo $sessions = new SessionsRepo(),
        private RemotePostsRepo $remotePosts = new RemotePostsRepo(),
        private PostImporter $postImporter = new PostImporter()
    ) {
    }

    /**
     * Process the next chunk for this session.
     *
     * @return array{processed: int, done: int, total: int, status: string}|WP_Error
     */
    public function runChunk(
        int $sessionId,
        int $timeBudget = self::DEFAULT_TIME_BUDGET_SECONDS,
        int $imageBudget = self::DEFAULT_IMAGE_BUDGET
    ): array|WP_Error {
        $session = $this->sessions->find($sessionId);
        if (!$session) {
            return new WP_Error('spi_not_found', __('Session not found.', 'simple-post-importer'));
        }

        $sourceHost = (string) ($session['source_host'] ?? '');
        $start = microtime(true);
        $imagesThisChunk = 0;
        $processed = 0;

        while (true) {
            $elapsed = microtime(true) - $start;
            if ($elapsed >= $timeBudget || $imagesThisChunk >= $imageBudget) {
                break;
            }

            $batch = $this->remotePosts->nextPendingImportBatch($sessionId, self::BATCH_FETCH_SIZE);
            if ($batch === []) {
                break;
            }

            foreach ($batch as $row) {
                $result = $this->postImporter->import($row, $sourceHost);
                if (is_wp_error($result)) {
                    $this->sessions->update($sessionId, [
                        'import_status' => 'failed',
                        'import_error' => sprintf(
                            /* translators: 1: remote post id 2: error message */
                            __('Failed on remote post #%1$d: %2$s', 'simple-post-importer'),
                            (int) $row['remote_id'],
                            $result->get_error_message()
                        ),
                    ]);
                    return $result;
                }

                $this->remotePosts->markImported(
                    (int) $row['id'],
                    (int) $result['post_id'],
                    $result['attachments']
                );

                $imagesThisChunk += (int) $result['images_sideloaded'];
                $processed++;

                $this->sessions->update($sessionId, [
                    'import_done' => $this->remotePosts->countImported($sessionId),
                ]);

                $elapsed = microtime(true) - $start;
                if ($elapsed >= $timeBudget || $imagesThisChunk >= $imageBudget) {
                    break 2;
                }
            }
        }

        $pending = $this->remotePosts->countSelectedPending($sessionId);
        $status = $pending === 0 ? 'complete' : 'running';
        $this->sessions->update($sessionId, [
            'import_status' => $status,
            'import_done' => $this->remotePosts->countImported($sessionId),
        ]);

        $fresh = $this->sessions->find($sessionId) ?? $session;

        return [
            'processed' => $processed,
            'done' => (int) $fresh['import_done'],
            'total' => (int) $fresh['import_total'],
            'status' => $status,
        ];
    }
}
