<?php

declare(strict_types=1);

namespace SimplePostImporter\Push;

use WP_Post;
use WP_User;

/**
 * Serialises a local post into the payload shape `/push/batch` expects.
 * Keeps the wire format stable — receiver (PostDeserializer) reads these
 * exact keys.
 */
final class PostSerializer
{
    public function serialize(WP_Post $post): array
    {
        $featuredId = (int) get_post_thumbnail_id($post->ID);
        $featuredUrl = $featuredId ? wp_get_attachment_url($featuredId) : null;

        $author = get_userdata((int) $post->post_author);

        return [
            'source_post_id' => (int) $post->ID,
            'slug' => (string) $post->post_name,
            'title' => (string) $post->post_title,
            'content' => (string) $post->post_content,
            'excerpt' => (string) $post->post_excerpt,
            'post_status' => (string) $post->post_status,
            'post_type' => (string) $post->post_type,
            'date_gmt' => (string) $post->post_date_gmt,
            'modified_gmt' => (string) $post->post_modified_gmt,
            'author' => $this->serializeAuthor($author instanceof WP_User ? $author : null),
            'featured_image_url' => $featuredUrl ?: null,
            'categories' => $this->serializeTerms($post->ID, 'category'),
            'tags' => $this->serializeTerms($post->ID, 'post_tag'),
        ];
    }

    private function serializeAuthor(?WP_User $user): ?array
    {
        if (!$user) {
            return null;
        }
        return [
            'login' => (string) $user->user_login,
            'email' => (string) $user->user_email,
            'display_name' => (string) $user->display_name,
            'first_name' => (string) ($user->first_name ?? ''),
            'last_name' => (string) ($user->last_name ?? ''),
            'description' => (string) ($user->description ?? ''),
            'url' => (string) $user->user_url,
        ];
    }

    /**
     * @return array<int, array{slug: string, name: string}>
     */
    private function serializeTerms(int $postId, string $taxonomy): array
    {
        $terms = get_the_terms($postId, $taxonomy);
        if (!is_array($terms)) {
            return [];
        }
        $out = [];
        foreach ($terms as $term) {
            $out[] = [
                'slug' => (string) $term->slug,
                'name' => (string) $term->name,
            ];
        }
        return $out;
    }
}
