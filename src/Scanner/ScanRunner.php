<?php

declare(strict_types=1);

namespace SimplePostImporter\Scanner;

use SimplePostImporter\Database\RemotePostsRepo;
use SimplePostImporter\Database\SessionsRepo;
use WP_Error;

final class ScanRunner
{
    public const PER_PAGE = 50;

    public function __construct(
        private SessionsRepo $sessions = new SessionsRepo(),
        private RemotePostsRepo $remotePosts = new RemotePostsRepo(),
        private RemoteClient $client = new RemoteClient()
    ) {
    }

    /**
     * Process one page of the remote /wp/v2/posts endpoint.
     *
     * @return array{status: string, scan_current_page: int, scan_total_pages: int, scan_total_posts: int, stored: int}|WP_Error
     */
    public function runChunk(int $sessionId, ?int $page = null): array|WP_Error
    {
        $session = $this->sessions->find($sessionId);
        if (!$session) {
            return new WP_Error('spi_not_found', __('Session not found.', 'simple-post-importer'));
        }

        if ($session['scan_status'] === 'complete') {
            return $this->buildStatus($session, 0);
        }

        $page = $page ?? ((int) $session['scan_current_page'] + 1);
        if ($page < 1) {
            $page = 1;
        }

        $restRoot = RemoteClient::buildRestRoot($session['source_url']);
        $url = add_query_arg(
            [
                'per_page' => self::PER_PAGE,
                'page' => $page,
                '_embed' => 'true',
                'orderby' => 'date',
                'order' => 'desc',
            ],
            $restRoot . '/wp/v2/posts'
        );

        $this->sessions->update($sessionId, ['scan_status' => 'running']);

        $result = $this->client->fetchJson($url);
        if (is_wp_error($result)) {
            $this->sessions->update($sessionId, [
                'scan_status' => 'failed',
                'scan_error' => $result->get_error_message(),
            ]);
            return $result;
        }

        $items = $result['data'];
        $this->enrichAuthors($items, $restRoot);

        $stored = 0;
        foreach ($items as $item) {
            if (!is_array($item) || !isset($item['id'])) {
                continue;
            }
            $this->remotePosts->upsert($sessionId, $this->normalizePost($item));
            $stored++;
        }

        $totalPages = $result['total_pages'] > 0 ? $result['total_pages'] : (int) $session['scan_total_pages'];
        if ($totalPages === 0 && count($items) > 0) {
            $totalPages = $page;
        }

        $totalPostsSoFar = (int) $session['scan_total_posts'] + $stored;
        $status = ($page >= $totalPages || count($items) === 0) ? 'complete' : 'running';

        $this->sessions->update($sessionId, [
            'scan_current_page' => $page,
            'scan_total_pages' => $totalPages,
            'scan_total_posts' => $totalPostsSoFar,
            'scan_status' => $status,
            'scan_error' => null,
        ]);

        $fresh = $this->sessions->find($sessionId);
        return $this->buildStatus($fresh ?? $session, $stored);
    }

    /**
     * For any post whose `_embedded.author[0]` is missing or is a REST error
     * (common when the remote site restricts public user exposure), try three
     * increasingly desperate strategies to recover a real username:
     *   1. batched `/wp/v2/users?include=…`
     *   2. per-id `/wp/v2/users/:id`
     *   3. `/?author=:id` author-archive redirect — reads the slug from the
     *      `Location: /author/{slug}/` header, then the display name from the
     *      archive page HTML
     * Whichever one wins is patched back into `_embedded.author[0]`.
     */
    private function enrichAuthors(array &$items, string $restRoot): void
    {
        $missing = $this->collectMissingAuthorIds($items);
        if ($missing === []) {
            return;
        }

        $resolved = $this->fetchUsersBatched($missing, $restRoot);

        $stillMissing = array_values(array_diff($missing, array_keys($resolved)));
        foreach ($stillMissing as $id) {
            $user = $this->fetchUserDirect($id, $restRoot);
            if ($user === null) {
                $user = $this->discoverUserViaAuthorArchive($id, $restRoot);
            }
            if ($user !== null) {
                $resolved[$id] = $user;
            }
        }

        if ($resolved === []) {
            return;
        }

        foreach ($items as &$item) {
            if (!is_array($item)) {
                continue;
            }
            $rawId = isset($item['author']) ? (int) $item['author'] : 0;
            if ($rawId > 0 && isset($resolved[$rawId])) {
                $item['_embedded']['author'][0] = $resolved[$rawId];
            }
        }
        unset($item);
    }

    /**
     * @return int[]
     */
    private function collectMissingAuthorIds(array $items): array
    {
        $missing = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $rawId = isset($item['author']) ? (int) $item['author'] : 0;
            if ($rawId <= 0) {
                continue;
            }
            if ($this->isValidEmbeddedUser($item['_embedded']['author'][0] ?? null)) {
                continue;
            }
            $missing[$rawId] = true;
        }
        return array_keys($missing);
    }

    /**
     * @param int[] $ids
     * @return array<int, array> User payloads indexed by id.
     */
    private function fetchUsersBatched(array $ids, string $restRoot): array
    {
        if ($ids === []) {
            return [];
        }
        $url = add_query_arg(
            [
                'include' => implode(',', $ids),
                'per_page' => count($ids),
                'context' => 'view',
            ],
            $restRoot . '/wp/v2/users'
        );
        $result = $this->client->fetchJson($url);
        if (is_wp_error($result)) {
            return [];
        }
        $out = [];
        foreach ($result['data'] as $user) {
            if ($this->isValidEmbeddedUser($user)) {
                $out[(int) $user['id']] = $user;
            }
        }
        return $out;
    }

    private function fetchUserDirect(int $id, string $restRoot): ?array
    {
        $url = add_query_arg(['context' => 'view'], $restRoot . '/wp/v2/users/' . $id);
        $result = $this->client->fetchJson($url);
        if (is_wp_error($result)) {
            return null;
        }
        $user = $result['data'] ?? null;
        return $this->isValidEmbeddedUser($user) ? $user : null;
    }

    /**
     * Discover author slug (= user_nicename, usually equal to user_login) by
     * following the `/?author=N` redirect. WordPress's default rewrite maps
     * `?author=N` → 302 Location `/author/{slug}/` — this still works on many
     * sites even when `/wp/v2/users` is explicitly blocked.
     */
    private function discoverUserViaAuthorArchive(int $id, string $restRoot): ?array
    {
        $siteRoot = preg_replace('#/wp-json/?$#', '', $restRoot);
        $probe = $siteRoot . '/?author=' . $id;

        $response = wp_remote_get($probe, [
            'timeout' => 15,
            'redirection' => 0,
            'user-agent' => 'SimplePostImporter/0.1',
        ]);
        if (is_wp_error($response)) {
            return null;
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $archiveUrl = null;

        if ($status >= 300 && $status < 400) {
            $location = wp_remote_retrieve_header($response, 'location');
            if (is_string($location) && $location !== '') {
                $archiveUrl = $location;
            }
        } elseif ($status === 200) {
            // Some sites don't redirect; the /?author=N page IS the archive.
            $archiveUrl = $probe;
        }

        if ($archiveUrl === null) {
            return null;
        }

        $slug = $this->extractSlugFromUrl($archiveUrl);
        if ($slug === null || $slug === '') {
            return null;
        }

        $displayName = null;
        if (is_string($archiveUrl) && $archiveUrl !== $probe) {
            $displayName = $this->fetchDisplayNameFromArchive($archiveUrl);
        } elseif ($status === 200) {
            $html = wp_remote_retrieve_body($response);
            $displayName = is_string($html) ? $this->parseDisplayNameFromHtml($html) : null;
        }

        return [
            'id' => $id,
            'name' => $displayName !== null && $displayName !== '' ? $displayName : $slug,
            'slug' => $slug,
            'description' => '',
            'url' => '',
            'avatar_urls' => [],
        ];
    }

    private function extractSlugFromUrl(string $url): ?string
    {
        if (preg_match('#/author/([^/?#]+)#', $url, $m)) {
            return urldecode($m[1]);
        }
        $query = wp_parse_url($url, PHP_URL_QUERY);
        if (is_string($query) && $query !== '') {
            parse_str($query, $parts);
            if (!empty($parts['author_name'])) {
                return (string) $parts['author_name'];
            }
        }
        return null;
    }

    private function fetchDisplayNameFromArchive(string $url): ?string
    {
        $response = wp_remote_get($url, [
            'timeout' => 15,
            'user-agent' => 'SimplePostImporter/0.1',
        ]);
        if (is_wp_error($response)) {
            return null;
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status < 200 || $status >= 300) {
            return null;
        }
        $html = wp_remote_retrieve_body($response);
        return is_string($html) ? $this->parseDisplayNameFromHtml($html) : null;
    }

    private function parseDisplayNameFromHtml(string $html): ?string
    {
        // Schema.org Person microdata
        if (preg_match('#itemprop=["\']name["\'][^>]*>\s*([^<]+)<#i', $html, $m)) {
            $name = trim($m[1]);
            if ($name !== '') {
                return html_entity_decode($name, ENT_QUOTES | ENT_HTML5);
            }
        }
        // og:title, strip site suffix after last dash/pipe
        if (preg_match('#<meta[^>]+property=["\']og:title["\'][^>]+content=["\']([^"\']+)["\']#i', $html, $m)) {
            $title = html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5);
            $title = preg_replace('/\s*[-\x{2013}\x{2014}|]\s*[^-\x{2013}\x{2014}|]+$/u', '', $title);
            if (is_string($title) && trim($title) !== '') {
                return trim($title);
            }
        }
        // First <h1>
        if (preg_match('#<h1[^>]*>\s*(.+?)\s*</h1>#is', $html, $m)) {
            $text = html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5);
            $text = preg_replace('/^(?:Posts by|Author|By)\s*:?\s*/i', '', trim($text)) ?? '';
            if ($text !== '') {
                return $text;
            }
        }
        return null;
    }

    private function isValidEmbeddedUser(mixed $embed): bool
    {
        return is_array($embed)
            && !isset($embed['code'])
            && !empty($embed['name'])
            && !empty($embed['slug']);
    }

    private function buildStatus(array $session, int $stored): array
    {
        return [
            'status' => $session['scan_status'],
            'scan_current_page' => (int) $session['scan_current_page'],
            'scan_total_pages' => (int) $session['scan_total_pages'],
            'scan_total_posts' => (int) $session['scan_total_posts'],
            'stored' => $stored,
        ];
    }

    /**
     * Transform a WP REST post object into the shape we store.
     */
    public function normalizePost(array $post): array
    {
        $embedded = $post['_embedded'] ?? [];
        $featuredUrl = null;
        if (!empty($embedded['wp:featuredmedia'][0]['source_url'])) {
            $featuredUrl = $embedded['wp:featuredmedia'][0]['source_url'];
        }

        $authorData = null;
        $rawAuthorId = isset($post['author']) ? (int) $post['author'] : null;
        if ($this->isValidEmbeddedUser($embedded['author'][0] ?? null)) {
            $a = $embedded['author'][0];
            $authorData = [
                'id' => $a['id'] ?? $rawAuthorId,
                'name' => (string) $a['name'],
                'slug' => (string) ($a['slug'] ?? ''),
                'description' => (string) ($a['description'] ?? ''),
                'url' => (string) ($a['url'] ?? ''),
                'avatar' => $a['avatar_urls']['96'] ?? ($a['avatar_urls']['48'] ?? null),
                'synthetic' => false,
            ];
        } elseif ($rawAuthorId) {
            $authorData = [
                'id' => $rawAuthorId,
                'name' => sprintf(
                    /* translators: %d: remote author ID */
                    __('Unknown Author (#%d)', 'simple-post-importer'),
                    $rawAuthorId
                ),
                'slug' => '',
                'description' => '',
                'url' => '',
                'avatar' => null,
                'synthetic' => true,
            ];
        }

        $categories = [];
        $tags = [];
        if (!empty($embedded['wp:term']) && is_array($embedded['wp:term'])) {
            foreach ($embedded['wp:term'] as $termGroup) {
                if (!is_array($termGroup)) {
                    continue;
                }
                foreach ($termGroup as $term) {
                    if (!isset($term['taxonomy'])) {
                        continue;
                    }
                    $record = [
                        'id' => $term['id'] ?? null,
                        'slug' => $term['slug'] ?? '',
                        'name' => $term['name'] ?? '',
                    ];
                    if ($term['taxonomy'] === 'category') {
                        $categories[] = $record;
                    } elseif ($term['taxonomy'] === 'post_tag') {
                        $tags[] = $record;
                    }
                }
            }
        }

        $title = $post['title']['rendered'] ?? ($post['title'] ?? '');
        $excerpt = $post['excerpt']['rendered'] ?? '';
        $content = $post['content']['rendered'] ?? '';
        $date = $post['date_gmt'] ?? ($post['date'] ?? null);
        if ($date && !str_contains($date, ' ')) {
            $date = str_replace('T', ' ', $date);
        }

        return [
            'remote_id' => (int) $post['id'],
            'title' => wp_strip_all_tags((string) $title, true),
            'excerpt' => wp_strip_all_tags((string) $excerpt, true),
            'content' => (string) $content,
            'post_status' => $post['status'] ?? 'publish',
            'published_date' => $date,
            'featured_image_url' => $featuredUrl,
            'author_data' => $authorData,
            'terms_data' => [
                'categories' => $categories,
                'tags' => $tags,
            ],
        ];
    }
}
