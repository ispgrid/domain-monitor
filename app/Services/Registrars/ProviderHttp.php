<?php

namespace App\Services\Registrars;

/**
 * GET requests to provider APIs: JSON, timeouts, and waiting on 429 / Retry-After
 */
class ProviderHttp
{
    private const TIMEOUT = 20;
    private const MAX_RETRIES = 3;
    private const MAX_WAIT = 30;

    /**
     * @return array{status: int, body: array}
     */
    public function getJson(string $url, array $headers = []): array
    {
        for ($attempt = 1; ; $attempt++) {
            $responseHeaders = [];
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => self::TIMEOUT,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
                CURLOPT_HTTPHEADER => array_merge(['Accept: application/json'], $headers),
                CURLOPT_USERAGENT => 'DomainMonitor',
                CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$responseHeaders) {
                    $parts = explode(':', $line, 2);
                    if (count($parts) === 2) {
                        $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                    }
                    return strlen($line);
                },
            ]);
            $raw = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            unset($ch);

            if ($raw === false) {
                throw new ProviderException('Could not reach the provider: ' . $error);
            }

            if ($status === 429 && $attempt <= self::MAX_RETRIES) {
                $wait = (int)($responseHeaders['retry-after'] ?? 5);
                sleep(max(1, min(self::MAX_WAIT, $wait)));
                continue;
            }

            $body = json_decode($raw, true);
            if (!is_array($body)) {
                throw new ProviderException("The provider returned an unexpected answer (HTTP {$status})");
            }
            return ['status' => $status, 'body' => $body];
        }
    }
}
