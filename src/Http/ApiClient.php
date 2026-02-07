<?php

declare(strict_types=1);

namespace Adion\NexusCli\Http;

class ApiClient
{
    private string $baseUrl;
    private array $headers;

    public function __construct(string $remote, ?string $ak = null, ?string $ck = null, ?string $sk = null)
    {
        $remote = rtrim($remote, '/');
        $this->baseUrl = $remote . '/api';

        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
        ];

        if ($ak) {
            $headers[] = 'X-Api-Ak: ' . $ak;
        }
        if ($ck) {
            $headers[] = 'X-Api-Ck: ' . $ck;
        }
        if ($sk) {
            $headers[] = 'X-Api-Sk: ' . $sk;
        }

        $this->headers = $headers;
    }

    public function request(string $method, string $path, $body = null): array
    {
        $url = $this->baseUrl . $path;
        $method = strtoupper($method);

        $content = null;
        if ($body !== null) {
            $content = is_array($body) ? json_encode($body, JSON_UNESCAPED_SLASHES) : (string) $body;
        }

        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $this->headers),
                'content' => $content,
                'ignore_errors' => true,
            ],
        ]);

        $rawBody = @file_get_contents($url, false, $context);
        $error = null;
        if ($rawBody === false) {
            $error = error_get_last()['message'] ?? 'HTTP request failed';
            $rawBody = '';
        }

        $rawHeaders = $http_response_header ?? [];
        $status = $this->extractStatus($rawHeaders);

        $data = json_decode($rawBody, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $data = null;
        }

        return [
            'status' => $status,
            'headers' => $this->parseHeaders($rawHeaders),
            'raw' => $rawBody,
            'data' => $data,
            'error' => $error,
        ];
    }

    private function extractStatus(array $rawHeaders): int
    {
        if (empty($rawHeaders)) {
            return 0;
        }
        $first = $rawHeaders[0];
        if (preg_match('#HTTP/\d+\.\d+\s+(\d+)#', $first, $matches)) {
            return (int) $matches[1];
        }
        return 0;
    }

    private function parseHeaders(array $rawHeaders): array
    {
        $headers = [];
        foreach ($rawHeaders as $line) {
            if (strpos($line, ':') === false) {
                continue;
            }
            [$key, $value] = explode(':', $line, 2);
            $headers[trim($key)] = trim($value);
        }
        return $headers;
    }
}
