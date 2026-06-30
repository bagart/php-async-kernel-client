<?php

declare(strict_types=1);

/**
 * @param  array{url: string, name: string, parseResponse: callable}  $source
 */
return static function (array $source, Throwable $e, ?array $response): string {
    $tag = '['.trim($source['name']).']';
    $url = $source['url'];

    if ($response === null) {
        return str_pad($tag, 35)." NETWORK ERROR: {$e->getMessage()}\n"
            .str_pad('', 35)."   URL: {$url}";
    }

    $status = $response['status'] ?? '?';
    $body = trim((string) ($response['body'] ?? ''));
    if (strlen($body) > 200) {
        $body = substr($body, 0, 200).'...';
    }
    $body = trim(preg_replace('/\s+/', ' ', $body) ?? '');

    return str_pad($tag, 35)." PARSE ERROR: {$e->getMessage()}\n"
        .str_pad('', 35)."   HTTP {$status} | URL: {$url}\n"
        .str_pad('', 35)."   BODY: {$body}";
};
