<?php

declare(strict_types=1);

namespace SimplePostImporter\CLI;

use SimplePostImporter\Database\RemotePostsRepo;
use SimplePostImporter\Database\SessionsRepo;
use SimplePostImporter\Importer\ImportCleaner;
use SimplePostImporter\Importer\ImportRunner;
use SimplePostImporter\Scanner\ScanRunner;
use WP_CLI;
use WP_CLI\Formatter;

/**
 * Simple Post Importer CLI commands.
 */
final class Commands
{
    /**
     * Scan a remote WordPress site.
     *
     * ## OPTIONS
     *
     * <url>
     * : URL of the remote site (e.g. https://example.com).
     *
     * ## EXAMPLES
     *
     *     wp spi scan https://wordpress.org/news
     */
    public function scan($args, $assoc_args): void
    {
        [$url] = $args;

        $parts = wp_parse_url($url);
        if (!$parts || empty($parts['host'])) {
            WP_CLI::error('Invalid URL.');
        }
        $scheme = $parts['scheme'] ?? 'https';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $base = $scheme . '://' . $parts['host'] . $port;

        $sessions = new SessionsRepo();
        $runner = new ScanRunner();

        $sessionId = $sessions->create($base, $parts['host']);
        WP_CLI::log("Created session #{$sessionId} for {$base}");

        $progress = null;
        while (true) {
            $result = $runner->runChunk($sessionId);
            if (is_wp_error($result)) {
                WP_CLI::error($result->get_error_message());
            }
            if (!$progress && $result['scan_total_pages'] > 0) {
                $progress = WP_CLI\Utils\make_progress_bar(
                    'Scanning pages',
                    $result['scan_total_pages']
                );
                $progress->tick($result['scan_current_page']);
            } elseif ($progress) {
                $progress->tick();
            }
            if ($result['status'] === 'complete' || $result['status'] === 'failed') {
                break;
            }
        }

        if ($progress) {
            $progress->finish();
        }

        $final = $sessions->find($sessionId);
        WP_CLI::success(sprintf(
            'Scan %s — %d posts found (session #%d).',
            $final['scan_status'],
            (int) $final['scan_total_posts'],
            $sessionId
        ));
    }

    /**
     * List all scan sessions.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Render output in a particular format.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     *   - csv
     *   - yaml
     * ---
     */
    public function sessions($args, $assoc_args): void
    {
        $repo = new SessionsRepo();
        $rows = $repo->paginated(1, 200);
        $formatter = new Formatter(
            $assoc_args,
            ['id', 'source_url', 'scan_status', 'scan_total_posts', 'import_status', 'import_done', 'created_at']
        );
        $formatter->display_items($rows);
    }

    /**
     * List remote posts in a session.
     *
     * ## OPTIONS
     *
     * <session_id>
     * : Session ID.
     *
     * [--format=<format>]
     * : Render output in a particular format.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     *   - csv
     *   - yaml
     * ---
     */
    public function posts($args, $assoc_args): void
    {
        [$sessionId] = $args;
        $sessionId = (int) $sessionId;
        $repo = new RemotePostsRepo();
        $rows = [];
        $page = 1;
        while (true) {
            $batch = $repo->listForSession($sessionId, $page, 200);
            if (!$batch) {
                break;
            }
            foreach ($batch as $r) {
                $rows[] = [
                    'id' => $r['id'],
                    'remote_id' => $r['remote_id'],
                    'title' => $r['title'],
                    'status' => $r['post_status'],
                    'selected' => $r['selected'] ? 'yes' : 'no',
                    'imported' => $r['imported'] ? 'yes' : 'no',
                    'local_post_id' => $r['local_post_id'] ?? '',
                    'published_date' => $r['published_date'] ?? '',
                ];
            }
            $page++;
        }
        $formatter = new Formatter(
            $assoc_args,
            ['id', 'remote_id', 'title', 'status', 'selected', 'imported', 'local_post_id', 'published_date']
        );
        $formatter->display_items($rows);
    }

    /**
     * Import selected posts for a session.
     *
     * ## OPTIONS
     *
     * <session_id>
     * : Session ID.
     *
     * [--all]
     * : Mark all not-yet-imported posts in the session as selected, then import.
     *
     * [--ids=<ids>]
     * : Comma-separated list of row IDs (from `wp spi posts`) to import.
     *
     * ## EXAMPLES
     *
     *     wp spi import 12 --all
     *     wp spi import 12 --ids=34,35,36
     */
    public function import($args, $assoc_args): void
    {
        [$sessionId] = $args;
        $sessionId = (int) $sessionId;

        $sessions = new SessionsRepo();
        $posts = new RemotePostsRepo();

        $session = $sessions->find($sessionId);
        if (!$session) {
            WP_CLI::error('Session not found.');
        }

        if (!empty($assoc_args['all'])) {
            $posts->setAllSelected($sessionId, true);
        } elseif (!empty($assoc_args['ids'])) {
            $ids = array_filter(array_map('intval', explode(',', (string) $assoc_args['ids'])));
            if ($ids) {
                $posts->setSelectedBulk($sessionId, $ids, true);
            }
        }

        $pending = $posts->countSelectedPending($sessionId);
        if ($pending === 0) {
            WP_CLI::warning('No posts selected for import.');
            return;
        }

        $sessions->update($sessionId, [
            'import_status' => 'running',
            'import_total' => $pending,
            'import_done' => 0,
            'import_error' => null,
        ]);

        $runner = new ImportRunner();
        $progress = WP_CLI\Utils\make_progress_bar("Importing {$pending} posts", $pending);

        $lastDone = 0;
        while (true) {
            // Use a generous per-chunk budget; CLI isn't time-constrained the way REST is.
            $res = $runner->runChunk($sessionId, 60, 50);
            if (is_wp_error($res)) {
                WP_CLI::error($res->get_error_message());
            }
            $delta = max(0, $res['done'] - $lastDone);
            for ($i = 0; $i < $delta; $i++) {
                $progress->tick();
            }
            $lastDone = $res['done'];
            if ($res['status'] !== 'running') {
                break;
            }
        }
        $progress->finish();

        $final = $sessions->find($sessionId);
        WP_CLI::success(sprintf(
            'Import %s — %d of %d posts.',
            $final['import_status'],
            (int) $final['import_done'],
            (int) $final['import_total']
        ));
    }

    /**
     * Clear all imported posts and their sideloaded attachments for a session.
     *
     * ## OPTIONS
     *
     * <session_id>
     * : Session ID.
     *
     * [--yes]
     * : Skip confirmation.
     */
    public function clear($args, $assoc_args): void
    {
        [$sessionId] = $args;
        $sessionId = (int) $sessionId;

        $sessions = new SessionsRepo();
        $posts = new RemotePostsRepo();

        if (!$sessions->find($sessionId)) {
            WP_CLI::error('Session not found.');
        }

        $count = $posts->countImported($sessionId);
        if ($count === 0) {
            WP_CLI::log('No imported posts to clear.');
            return;
        }

        if (empty($assoc_args['yes'])) {
            WP_CLI::confirm("This will delete {$count} local posts and their sideloaded attachments. Continue?");
        }

        $cleaner = new ImportCleaner();
        $result = $cleaner->clearSession($sessionId);

        $sessions->update($sessionId, [
            'import_status' => 'idle',
            'import_total' => 0,
            'import_done' => 0,
            'import_error' => null,
        ]);

        WP_CLI::success(sprintf(
            'Cleared %d posts and %d attachments (%d skipped).',
            $result['posts_deleted'],
            $result['attachments_deleted'],
            $result['skipped']
        ));
    }

    /**
     * Delete a scan session and all its scanned metadata.
     *
     * ## OPTIONS
     *
     * <session_id>
     * : Session ID.
     *
     * [--with-imports]
     * : Also delete any locally imported posts from this session.
     *
     * [--yes]
     * : Skip confirmation.
     */
    public function delete($args, $assoc_args): void
    {
        [$sessionId] = $args;
        $sessionId = (int) $sessionId;

        $sessions = new SessionsRepo();
        $posts = new RemotePostsRepo();

        if (!$sessions->find($sessionId)) {
            WP_CLI::error('Session not found.');
        }

        if (empty($assoc_args['yes'])) {
            WP_CLI::confirm("Delete session #{$sessionId}?");
        }

        if (!empty($assoc_args['with-imports'])) {
            (new ImportCleaner())->clearSession($sessionId);
        }

        $posts->deleteForSession($sessionId);
        $sessions->delete($sessionId);

        WP_CLI::success("Deleted session #{$sessionId}.");
    }
}
