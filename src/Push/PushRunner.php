<?php

declare(strict_types=1);

namespace SimplePostImporter\Push;

use WP_Error;

/**
 * Pushes the next chunk of a push session to the target.
 * Budgeted by time (~20s) and batch count (~5 posts/batch).
 */
final class PushRunner
{
    public const DEFAULT_TIME_BUDGET = 20;
    public const BATCH_SIZE = 5;

    public function __construct(
        private PushRepo $repo = new PushRepo(),
        private PostSerializer $serializer = new PostSerializer()
    ) {
    }

    /**
     * @return array{processed: int, done: int, total: int, status: string}|WP_Error
     */
    public function runChunk(int $sessionId, int $timeBudget = self::DEFAULT_TIME_BUDGET): array|WP_Error
    {
        $session = $this->repo->findSession($sessionId);
        if (!$session) {
            return new WP_Error('spi_not_found', __('Push session not found.', 'simple-post-importer'));
        }

        $token = $this->repo->getToken($sessionId);
        if ($token === null) {
            return new WP_Error('spi_missing_token', __('Token for push session is missing. Re-create the session.', 'simple-post-importer'));
        }

        $client = new TargetClient((string) $session['target_url'], $token);
        $sourceUrl = home_url();

        $start = microtime(true);
        $processed = 0;

        while ((microtime(true) - $start) < $timeBudget) {
            $items = $this->repo->nextPendingItems($sessionId, self::BATCH_SIZE);
            if ($items === []) {
                break;
            }

            $payloads = [];
            $itemById = [];
            foreach ($items as $item) {
                $post = get_post((int) $item['local_post_id']);
                if (!$post) {
                    $this->repo->markItemError((int) $item['id'], __('Local post no longer exists.', 'simple-post-importer'));
                    // Mark as pushed with 0 target to not block progress.
                    $this->repo->markItemPushed((int) $item['id'], 0, null);
                    $processed++;
                    continue;
                }
                $payloads[] = $this->serializer->serialize($post);
                $itemById[(int) $item['local_post_id']] = (int) $item['id'];
            }

            if ($payloads === []) {
                continue;
            }

            $response = $client->pushBatch($sourceUrl, $payloads);
            if (is_wp_error($response)) {
                $this->repo->updateSession($sessionId, [
                    'status' => 'failed',
                    'error' => $response->get_error_message(),
                ]);
                return $response;
            }

            foreach ($response['results'] ?? [] as $result) {
                $sourceId = (int) ($result['source_post_id'] ?? 0);
                $itemId = $itemById[$sourceId] ?? null;
                if ($itemId === null) {
                    continue;
                }
                if (($result['status'] ?? '') === 'ok') {
                    $this->repo->markItemPushed(
                        $itemId,
                        (int) ($result['local_post_id'] ?? 0),
                        isset($result['local_post_url']) ? (string) $result['local_post_url'] : null
                    );
                    $processed++;
                } else {
                    $this->repo->markItemError($itemId, (string) ($result['error'] ?? 'unknown error'));
                }
            }

            $this->repo->updateSession($sessionId, [
                'done' => $this->repo->countPushed($sessionId),
            ]);
        }

        $pending = $this->repo->countPending($sessionId);
        $status = $pending === 0 ? 'complete' : 'running';
        $this->repo->updateSession($sessionId, [
            'status' => $status,
            'done' => $this->repo->countPushed($sessionId),
        ]);

        $fresh = $this->repo->findSession($sessionId) ?? $session;
        return [
            'processed' => $processed,
            'done' => (int) $fresh['done'],
            'total' => (int) $fresh['total'],
            'status' => $status,
        ];
    }
}
