<?php

declare(strict_types=1);

namespace SimplePostImporter\Importer;

use WP_Error;

final class PostImporter
{
    public function __construct(
        private UserImporter $users = new UserImporter(),
        private TermImporter $terms = new TermImporter(),
        private MediaImporter $media = new MediaImporter(),
        private ContentRewriter $rewriter = new ContentRewriter()
    ) {
    }

    /**
     * Import a single remote post row.
     *
     * @return array{post_id: int, attachments: int[], images_sideloaded: int}|WP_Error
     */
    public function import(array $remote, string $sourceHost): array|WP_Error
    {
        MediaImporter::ensureIncludes();

        $attachments = [];
        $sideloaded = 0;

        $authorId = $this->users->resolve($remote['author_data'] ?? null);

        $terms = $remote['terms_data'] ?? [];
        $categoryIds = $this->terms->resolve($terms['categories'] ?? [], 'category');
        $tagIds = $this->terms->resolve($terms['tags'] ?? [], 'post_tag');

        $featuredId = null;
        $featuredUrl = $remote['featured_image_url'] ?? null;
        if ($featuredUrl && $this->isSameHost($featuredUrl, $sourceHost)) {
            $result = $this->media->sideload($featuredUrl);
            if (!is_wp_error($result)) {
                $featuredId = (int) $result;
                $attachments[] = $featuredId;
                $sideloaded++;
            }
        }

        $rewritten = $this->rewriter->rewrite((string) ($remote['content'] ?? ''), $sourceHost);
        $attachments = array_merge($attachments, $rewritten['attachments']);
        $sideloaded += $rewritten['sideloaded'];

        $postData = [
            'post_title' => (string) ($remote['title'] ?? ''),
            'post_content' => $rewritten['content'],
            'post_excerpt' => (string) ($remote['excerpt'] ?? ''),
            'post_status' => $this->sanitizeStatus((string) ($remote['post_status'] ?? 'publish')),
            'post_author' => $authorId,
            'post_type' => 'post',
            'post_date' => $this->sanitizeDate($remote['published_date'] ?? null),
            'post_date_gmt' => $this->sanitizeDate($remote['published_date'] ?? null),
        ];

        $postId = wp_insert_post($postData, true);
        if ($postId instanceof WP_Error) {
            foreach ($attachments as $aid) {
                wp_delete_attachment($aid, true);
            }
            return $postId;
        }

        if ($featuredId) {
            set_post_thumbnail((int) $postId, $featuredId);
        }
        if ($categoryIds) {
            wp_set_object_terms((int) $postId, $categoryIds, 'category');
        }
        if ($tagIds) {
            wp_set_object_terms((int) $postId, $tagIds, 'post_tag');
        }

        update_post_meta((int) $postId, 'spi_source_remote_id', (int) ($remote['remote_id'] ?? 0));

        // Reparent sideloaded attachments to the post.
        foreach ($attachments as $aid) {
            wp_update_post([
                'ID' => $aid,
                'post_parent' => (int) $postId,
            ]);
        }

        return [
            'post_id' => (int) $postId,
            'attachments' => array_values(array_unique(array_map('intval', $attachments))),
            'images_sideloaded' => $sideloaded,
        ];
    }

    private function isSameHost(string $url, string $host): bool
    {
        $h = wp_parse_url($url, PHP_URL_HOST);
        return $h && strcasecmp($h, $host) === 0;
    }

    private function sanitizeStatus(string $status): string
    {
        $allowed = ['publish', 'draft', 'pending', 'private', 'future'];
        return in_array($status, $allowed, true) ? $status : 'publish';
    }

    private function sanitizeDate(?string $date): string
    {
        if (!$date) {
            return current_time('mysql');
        }
        $ts = strtotime($date);
        if (!$ts) {
            return current_time('mysql');
        }
        return gmdate('Y-m-d H:i:s', $ts);
    }
}
