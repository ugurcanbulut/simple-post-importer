<?php

declare(strict_types=1);

namespace SimplePostImporter\Push;

use SimplePostImporter\Importer\ContentRewriter;
use SimplePostImporter\Importer\MediaImporter;
use SimplePostImporter\Importer\TermImporter;
use SimplePostImporter\Settings\Settings;
use WP_Error;

/**
 * Receiver-side: turns a push payload into a local WP post.
 * Idempotent on (source_site_url, source_post_id) via post meta.
 */
final class PostDeserializer
{
    public const META_SOURCE_URL = '_spi_push_source_url';
    public const META_SOURCE_ID = '_spi_push_source_id';

    public function __construct(
        private TermImporter $terms = new TermImporter(),
        private MediaImporter $media = new MediaImporter(),
        private ContentRewriter $rewriter = new ContentRewriter()
    ) {
    }

    /**
     * @return array{post_id: int, created: bool, attachments: int[]}|WP_Error
     */
    public function import(array $payload, string $sourceSiteUrl): array|WP_Error
    {
        MediaImporter::ensureIncludes();

        $sourcePostId = (int) ($payload['source_post_id'] ?? 0);
        if ($sourcePostId <= 0) {
            return new WP_Error('spi_missing_source_id', __('Payload missing source_post_id.', 'simple-post-importer'));
        }

        $existingId = $this->findExistingLocalPost($sourceSiteUrl, $sourcePostId);
        $sourceHost = (string) (wp_parse_url($sourceSiteUrl, PHP_URL_HOST) ?: '');

        $authorId = $this->resolveAuthor($payload['author'] ?? null);

        $categoryIds = $this->terms->resolve($payload['categories'] ?? [], 'category');
        $tagIds = $this->terms->resolve($payload['tags'] ?? [], 'post_tag');

        $attachments = [];
        $featuredId = null;
        $featuredUrl = $payload['featured_image_url'] ?? null;
        if ($featuredUrl && $this->isSameHost($featuredUrl, $sourceHost)) {
            $result = $this->media->sideload($featuredUrl);
            if (!is_wp_error($result)) {
                $featuredId = (int) $result;
                $attachments[] = $featuredId;
            }
        }

        $rewritten = $this->rewriter->rewrite(
            (string) ($payload['content'] ?? ''),
            $sourceHost
        );
        $attachments = array_merge($attachments, $rewritten['attachments']);

        $postData = [
            'post_title' => (string) ($payload['title'] ?? ''),
            'post_content' => $rewritten['content'],
            'post_excerpt' => (string) ($payload['excerpt'] ?? ''),
            'post_status' => $this->sanitizeStatus((string) ($payload['post_status'] ?? 'publish')),
            'post_type' => $this->sanitizePostType((string) ($payload['post_type'] ?? 'post')),
            'post_author' => $authorId,
            'post_date' => $this->sanitizeDate($payload['date_gmt'] ?? null),
            'post_date_gmt' => $this->sanitizeDate($payload['date_gmt'] ?? null),
            'post_name' => sanitize_title((string) ($payload['slug'] ?? '')),
        ];

        $created = false;
        if ($existingId) {
            $postData['ID'] = $existingId;
            $postId = wp_update_post(wp_slash($postData), true);
        } else {
            $postId = wp_insert_post(wp_slash($postData), true);
            $created = true;
        }

        if ($postId instanceof WP_Error) {
            foreach ($attachments as $aid) {
                wp_delete_attachment($aid, true);
            }
            return $postId;
        }

        update_post_meta((int) $postId, self::META_SOURCE_URL, $sourceSiteUrl);
        update_post_meta((int) $postId, self::META_SOURCE_ID, $sourcePostId);

        if ($featuredId) {
            set_post_thumbnail((int) $postId, $featuredId);
        }
        wp_set_object_terms((int) $postId, $categoryIds, 'category');
        wp_set_object_terms((int) $postId, $tagIds, 'post_tag');

        foreach ($attachments as $aid) {
            wp_update_post([
                'ID' => $aid,
                'post_parent' => (int) $postId,
            ]);
        }

        return [
            'post_id' => (int) $postId,
            'created' => $created,
            'attachments' => array_values(array_unique(array_map('intval', $attachments))),
        ];
    }

    private function findExistingLocalPost(string $sourceSiteUrl, int $sourcePostId): int
    {
        global $wpdb;
        $sql = "SELECT post_id FROM {$wpdb->postmeta} pm1
                INNER JOIN {$wpdb->postmeta} pm2 ON pm2.post_id = pm1.post_id
                WHERE pm1.meta_key = %s AND pm1.meta_value = %s
                  AND pm2.meta_key = %s AND pm2.meta_value = %s
                LIMIT 1";
        $id = $wpdb->get_var(
            $wpdb->prepare($sql, self::META_SOURCE_URL, $sourceSiteUrl, self::META_SOURCE_ID, (string) $sourcePostId)
        );
        return $id ? (int) $id : 0;
    }

    /**
     * Resolve the post author locally. Push payloads carry full author info
     * (login/email/display), so we can match existing WP users more reliably
     * than in the pull flow.
     */
    private function resolveAuthor(?array $author): int
    {
        if (!$author) {
            return Settings::getDefaultAuthorId();
        }

        $login = isset($author['login']) ? sanitize_user((string) $author['login'], true) : '';
        $email = isset($author['email']) ? sanitize_email((string) $author['email']) : '';

        if ($login !== '') {
            $user = get_user_by('login', $login);
            if ($user) {
                return (int) $user->ID;
            }
        }
        if ($email !== '') {
            $user = get_user_by('email', $email);
            if ($user) {
                return (int) $user->ID;
            }
        }

        if ($login === '' && $email === '') {
            return Settings::getDefaultAuthorId();
        }

        $newUser = wp_insert_user([
            'user_login' => $login !== '' ? $login : sanitize_user($email, true),
            'user_email' => $email !== '' ? $email : sprintf('%s@imported.invalid', $login),
            'user_pass' => wp_generate_password(32, true, true),
            'display_name' => (string) ($author['display_name'] ?? ($login ?: $email)),
            'first_name' => (string) ($author['first_name'] ?? ''),
            'last_name' => (string) ($author['last_name'] ?? ''),
            'description' => (string) ($author['description'] ?? ''),
            'user_url' => (string) ($author['url'] ?? ''),
            'role' => 'author',
        ]);

        if (is_wp_error($newUser)) {
            return Settings::getDefaultAuthorId();
        }
        return (int) $newUser;
    }

    private function isSameHost(string $url, string $host): bool
    {
        $h = wp_parse_url($url, PHP_URL_HOST);
        return $h && $host && strcasecmp($h, $host) === 0;
    }

    private function sanitizeStatus(string $s): string
    {
        return in_array($s, ['publish', 'draft', 'pending', 'private', 'future'], true) ? $s : 'publish';
    }

    private function sanitizePostType(string $t): string
    {
        if (post_type_exists($t)) {
            return $t;
        }
        return 'post';
    }

    private function sanitizeDate(?string $date): string
    {
        if (!$date) {
            return current_time('mysql');
        }
        $ts = strtotime($date);
        return $ts ? gmdate('Y-m-d H:i:s', $ts) : current_time('mysql');
    }
}
