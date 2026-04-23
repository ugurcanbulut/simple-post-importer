<?php

declare(strict_types=1);

namespace SimplePostImporter\Importer;

use WP_Error;

final class MediaImporter
{
    private static bool $includesLoaded = false;

    /**
     * Download a remote image URL and sideload it into the media library.
     * Returns attachment ID or WP_Error.
     */
    public function sideload(string $url, int $postId = 0, ?string $title = null): int|WP_Error
    {
        self::ensureIncludes();

        $filename = $this->filenameFromUrl($url);
        $tmp = download_url($url, 30);
        if ($tmp instanceof WP_Error) {
            return $tmp;
        }

        $fileArray = [
            'name' => $filename,
            'tmp_name' => $tmp,
        ];

        $attachmentId = media_handle_sideload($fileArray, $postId, $title);

        if (file_exists($tmp)) {
            @unlink($tmp);
        }

        return $attachmentId;
    }

    private function filenameFromUrl(string $url): string
    {
        $path = wp_parse_url($url, PHP_URL_PATH) ?: '';
        $name = basename($path);
        if ($name === '' || !preg_match('/\.[a-zA-Z0-9]{2,5}$/', $name)) {
            $name = 'image-' . wp_generate_password(8, false) . '.jpg';
        }
        return sanitize_file_name($name);
    }

    public static function ensureIncludes(): void
    {
        if (self::$includesLoaded) {
            return;
        }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        self::$includesLoaded = true;
    }
}
