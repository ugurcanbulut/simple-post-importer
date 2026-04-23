<?php

declare(strict_types=1);

namespace SimplePostImporter\Importer;

use DOMDocument;
use DOMElement;

/**
 * Rewrites <img> tags in imported post content: sideloads same-origin images
 * and updates src attributes. Skips data: URLs and external CDN images.
 */
final class ContentRewriter
{
    /** @var array<string, int> remote_url → local_attachment_id */
    private array $cache = [];

    /** @var int[] */
    private array $attachmentsThisRun = [];

    private int $sideloadedCount = 0;

    public function __construct(
        private MediaImporter $media = new MediaImporter()
    ) {
    }

    /**
     * @return array{content: string, attachments: int[], sideloaded: int}
     */
    public function rewrite(string $html, string $sourceHost, int $postId = 0): array
    {
        $this->attachmentsThisRun = [];
        $this->sideloadedCount = 0;

        if (trim($html) === '' || !str_contains($html, '<img')) {
            return [
                'content' => $html,
                'attachments' => [],
                'sideloaded' => 0,
            ];
        }

        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $flags = LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD;
        $loaded = $doc->loadHTML('<?xml encoding="UTF-8">' . $html, $flags);
        libxml_clear_errors();

        if (!$loaded) {
            return [
                'content' => $html,
                'attachments' => [],
                'sideloaded' => 0,
            ];
        }

        $imgs = $doc->getElementsByTagName('img');
        // Cache references first (live nodelist).
        $nodes = [];
        foreach ($imgs as $img) {
            $nodes[] = $img;
        }

        foreach ($nodes as $img) {
            /** @var DOMElement $img */
            $this->handleImg($img, $sourceHost, $postId);
        }

        $out = '';
        foreach ($doc->childNodes as $child) {
            if ($child->nodeName === 'xml') {
                continue;
            }
            $out .= $doc->saveHTML($child);
        }
        // Strip the XML processing instruction if it leaked through.
        $out = preg_replace('/<\?xml[^>]*\?>/', '', $out) ?? $out;

        return [
            'content' => trim($out),
            'attachments' => array_values(array_unique($this->attachmentsThisRun)),
            'sideloaded' => $this->sideloadedCount,
        ];
    }

    private function handleImg(DOMElement $img, string $sourceHost, int $postId): void
    {
        $src = trim((string) $img->getAttribute('src'));
        if ($src === '') {
            return;
        }
        if (str_starts_with($src, 'data:')) {
            return;
        }

        $host = wp_parse_url($src, PHP_URL_HOST);
        if (!$host) {
            return;
        }
        if (strcasecmp($host, $sourceHost) !== 0) {
            return;
        }

        $attachmentId = $this->cache[$src] ?? null;
        if ($attachmentId === null) {
            $result = $this->media->sideload($src, $postId);
            if (is_wp_error($result)) {
                return;
            }
            $attachmentId = (int) $result;
            $this->cache[$src] = $attachmentId;
            $this->sideloadedCount++;
        }

        $this->attachmentsThisRun[] = $attachmentId;
        $localUrl = wp_get_attachment_url($attachmentId);
        if ($localUrl) {
            $img->setAttribute('src', $localUrl);
        }
        $img->removeAttribute('srcset');
        $img->removeAttribute('sizes');

        $classes = $img->getAttribute('class');
        $classes = preg_replace('/\bwp-image-\d+\b/', '', $classes) ?? '';
        $classes = trim($classes . ' wp-image-' . $attachmentId);
        $img->setAttribute('class', $classes);
    }
}
