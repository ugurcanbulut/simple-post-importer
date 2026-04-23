<?php

declare(strict_types=1);

namespace SimplePostImporter\Scanner;

use WP_Error;

final class RemoteClient
{
    private const USER_AGENT = 'SimplePostImporter/0.1 (+https://wordpress.org)';
    private const TIMEOUT = 30;
    private const MAX_RETRIES = 3;

    /**
     * Fetches a URL and returns decoded JSON plus the WP_TotalPages header.
     *
     * @return array{data: array, total_pages: int}|WP_Error
     */
    public function fetchJson(string $url): array|WP_Error
    {
        $attempt = 0;
        $delay = 1;

        while (true) {
            $attempt++;
            $response = wp_remote_get($url, [
                'timeout' => self::TIMEOUT,
                'redirection' => 5,
                'user-agent' => self::USER_AGENT,
                'headers' => ['Accept' => 'application/json'],
            ]);

            if (is_wp_error($response)) {
                if ($attempt >= self::MAX_RETRIES) {
                    return $response;
                }
                sleep($delay);
                $delay *= 2;
                continue;
            }

            $status = (int) wp_remote_retrieve_response_code($response);

            if ($status === 429 || ($status >= 500 && $status < 600)) {
                if ($attempt >= self::MAX_RETRIES) {
                    return new WP_Error(
                        'spi_remote_error',
                        sprintf(__('Remote server returned %d after %d attempts.', 'simple-post-importer'), $status, $attempt)
                    );
                }
                sleep($delay);
                $delay *= 2;
                continue;
            }

            if ($status >= 400) {
                return new WP_Error(
                    'spi_remote_error',
                    sprintf(__('Remote server returned %d.', 'simple-post-importer'), $status)
                );
            }

            $body = wp_remote_retrieve_body($response);
            $decoded = json_decode($body, true);

            if (!is_array($decoded)) {
                return new WP_Error(
                    'spi_invalid_json',
                    __('Remote response was not valid JSON.', 'simple-post-importer')
                );
            }

            $totalPages = (int) wp_remote_retrieve_header($response, 'x-wp-totalpages');

            return [
                'data' => $decoded,
                'total_pages' => $totalPages,
            ];
        }
    }

    public static function buildRestRoot(string $baseUrl): string
    {
        $baseUrl = rtrim($baseUrl, '/');
        if (!str_contains($baseUrl, '/wp-json')) {
            $baseUrl .= '/wp-json';
        }
        return $baseUrl;
    }
}
