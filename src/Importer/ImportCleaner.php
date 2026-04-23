<?php

declare(strict_types=1);

namespace SimplePostImporter\Importer;

use SimplePostImporter\Database\RemotePostsRepo;

/**
 * Per-session clear: wipes local posts + sideloaded attachments that were
 * imported by this session. Does NOT touch other sessions' imports.
 */
final class ImportCleaner
{
    public function __construct(
        private RemotePostsRepo $remotePosts = new RemotePostsRepo()
    ) {
    }

    /**
     * @return array{posts_deleted: int, attachments_deleted: int, skipped: int}
     */
    public function clearSession(int $sessionId): array
    {
        $rows = $this->remotePosts->allImportedForSession($sessionId);
        $postsDeleted = 0;
        $attsDeleted = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            $localPostId = (int) ($row['local_post_id'] ?? 0);
            if ($localPostId > 0) {
                if (wp_delete_post($localPostId, true)) {
                    $postsDeleted++;
                } else {
                    $skipped++;
                }
            }

            $attachments = is_array($row['local_attachment_ids'] ?? null) ? $row['local_attachment_ids'] : [];
            foreach ($attachments as $aid) {
                $aid = (int) $aid;
                if ($aid <= 0) {
                    continue;
                }
                if (wp_delete_attachment($aid, true)) {
                    $attsDeleted++;
                }
            }

            $this->remotePosts->resetImport((int) $row['id']);
        }

        return [
            'posts_deleted' => $postsDeleted,
            'attachments_deleted' => $attsDeleted,
            'skipped' => $skipped,
        ];
    }
}
