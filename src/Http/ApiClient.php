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
        $ch = curl_init($url);

        $method = strtoupper($method);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $this->headers,
            CURLOPT_HEADER => true,
        ];

        if ($body !== null) {
            if (is_array($body)) {
                $body = json_encode($body, JSON_UNESCAPED_SLASHES);
            }
            $options[CURLOPT_POSTFIELDS] = $body;
        }

        curl_setopt_array($ch, $options);
        $response = curl_exec($ch);

        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            return [
                'status' => 0,
                'headers' => [],
                'raw' => '',
                'data' => null,
                'error' => $error,
            ];
        }

        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $rawHeaders = substr($response, 0, $headerSize);
        $rawBody = substr($response, $headerSize);
        curl_close($ch);

        $data = json_decode($rawBody, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $data = null;
        }

        return [
            'status' => $status,
            'headers' => $this->parseHeaders($rawHeaders),
            'raw' => $rawBody,
            'data' => $data,
            'error' => null,
        ];
    }

    private function parseHeaders(string $rawHeaders): array
    {
        $headers = [];
        $lines = preg_split('/\r\n|\n|\r/', trim($rawHeaders));
        foreach ($lines as $line) {
            if (strpos($line, ':') === false) {
                continue;
            }
            [$key, $value] = explode(':', $line, 2);
            $headers[trim($key)] = trim($value);
        }
        return $headers;
    }
}
