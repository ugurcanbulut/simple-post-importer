<?php

declare(strict_types=1);

namespace SimplePostImporter\Push;

use WP_Error;

/**
 * HTTP client that talks to a target site's /push/* endpoints using the
 * plaintext token supplied by the user. Kept deliberately small — each
 * method returns decoded body or WP_Error.
 */
final class TargetClient
{
    private const USER_AGENT = 'SimplePostImporter-Push/0.1';

    public function __construct(
        private string $targetUrl,
        private string $token
    ) {
    }

    public function handshake(): array|WP_Error
    {
        return $this->post('/push/handshake', []);
    }

    /**
     * @param array<int, array> $posts Serialised post payloads
     */
    public function pushBatch(string $sourceSiteUrl, array $posts): array|WP_Error
    {
        return $this->post('/push/batch', [
            'site_url' => $sourceSiteUrl,
            'posts' => $posts,
        ]);
    }

    private function post(string $path, array $body): array|WP_Error
    {
        $url = $this->buildUrl($path);

        $response = wp_remote_post($url, [
            'timeout' => 60,
            'redirection' => 3,
            'user-agent' => self::USER_AGENT,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->token,
                'X-SPI-Token' => $this->token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            'body' => wp_json_encode($body),
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $raw = wp_remote_retrieve_body($response);
        $decoded = json_decode($raw, true);

        if ($status >= 400) {
            $msg = is_array($decoded) && !empty($decoded['message'])
                ? $decoded['message']
                : sprintf(__('Target responded with %d.', 'simple-post-importer'), $status);
            return new WP_Error('spi_target_error', $msg, ['status' => $status, 'body' => $raw]);
        }
        if (!is_array($decoded)) {
            return new WP_Error('spi_bad_response', __('Target returned non-JSON response.', 'simple-post-importer'));
        }

        return $decoded;
    }

    private function buildUrl(string $path): string
    {
        $base = rtrim($this->targetUrl, '/');
        if (!str_contains($base, '/wp-json')) {
            $base .= '/wp-json';
        }
        return $base . '/simple-post-importer/v1' . $path;
    }
}
