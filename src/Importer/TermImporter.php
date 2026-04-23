<?php

declare(strict_types=1);

namespace SimplePostImporter\Importer;

final class TermImporter
{
    /**
     * Resolve remote term records into local term IDs, creating missing terms.
     *
     * @param array<int, array{slug?: string, name?: string}> $remoteTerms
     * @return int[]
     */
    public function resolve(array $remoteTerms, string $taxonomy): array
    {
        $ids = [];
        foreach ($remoteTerms as $term) {
            $slug = isset($term['slug']) ? sanitize_title((string) $term['slug']) : '';
            $name = isset($term['name']) ? (string) $term['name'] : '';
            if ($slug === '' && $name === '') {
                continue;
            }
            if ($slug === '') {
                $slug = sanitize_title($name);
            }

            $existing = get_term_by('slug', $slug, $taxonomy);
            if ($existing) {
                $ids[] = (int) $existing->term_id;
                continue;
            }

            $created = wp_insert_term($name !== '' ? $name : $slug, $taxonomy, ['slug' => $slug]);
            if (is_array($created) && isset($created['term_id'])) {
                $ids[] = (int) $created['term_id'];
            }
        }
        return array_values(array_unique($ids));
    }
}
