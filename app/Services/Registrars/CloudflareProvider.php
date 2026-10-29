<?php

namespace App\Services\Registrars;

/**
 * Cloudflare: reads zones and DNS records with an API token (Zone Read + DNS Read). Only GET requests.
 * https://developers.cloudflare.com/api/
 */
class CloudflareProvider implements ProviderInterface, DnsProviderInterface
{
    public const API_BASE = 'https://api.cloudflare.com/client/v4';

    private string $baseUrl;
    private ProviderHttp $http;

    public function __construct(?string $baseUrl = null, ?ProviderHttp $http = null)
    {
        $this->baseUrl = rtrim($baseUrl ?? self::API_BASE, '/');
        $this->http = $http ?? new ProviderHttp();
    }

    public static function key(): string
    {
        return 'cloudflare';
    }

    public static function label(): string
    {
        return 'Cloudflare';
    }

    public static function credentialFields(): array
    {
        return [
            'api_token' => [
                'label' => 'API token',
                'type' => 'password',
                'help' => 'Create a Custom Token in Cloudflare (My Profile → API Tokens) with Zone → Zone → Read and Zone → DNS → Read. Read permissions are enough.',
                'required' => true,
            ],
        ];
    }

    public function testConnection(array $creds): true|string
    {
        try {
            $this->get($creds, '/zones', ['per_page' => 5]);
            return true;
        } catch (ProviderException $e) {
            return $e->getMessage();
        }
    }

    public function listZones(array $creds): array
    {
        $zones = [];
        foreach ($this->getAllPages($creds, '/zones', 50) as $zone) {
            $zones[] = [
                'id' => (string)($zone['id'] ?? ''),
                'name' => strtolower((string)($zone['name'] ?? '')),
                'status' => (string)($zone['status'] ?? ''),
                'name_servers' => array_values(array_map('strtolower', (array)($zone['name_servers'] ?? []))),
            ];
        }
        return array_values(array_filter($zones, fn($z) => $z['id'] !== '' && $z['name'] !== ''));
    }

    public function listRecords(array $creds, string $zoneId, string $zoneName): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/i', $zoneId)) {
            throw new ProviderException('Invalid Cloudflare zone id');
        }

        $grouped = [];
        foreach ($this->getAllPages($creds, "/zones/{$zoneId}/dns_records", 100) as $record) {
            $type = strtoupper((string)($record['type'] ?? ''));
            $name = strtolower(rtrim((string)($record['name'] ?? ''), '.'));
            if ($type === '' || $name === '') {
                continue;
            }
            $host = $name === $zoneName ? '@' : (str_ends_with($name, '.' . $zoneName) ? substr($name, 0, -strlen($zoneName) - 1) : $name);
            $data = is_array($record['data'] ?? null) ? $record['data'] : [];
            $content = (string)($record['content'] ?? '');

            $entry = [
                'host' => $host,
                'value' => $content,
                'ttl' => isset($record['ttl']) ? (int)$record['ttl'] : null,
                'priority' => isset($record['priority']) ? (int)$record['priority'] : null,
                'proxied' => !empty($record['proxied']),
                'flattened' => !empty($record['settings']['flatten_cname']),
                'provider_id' => (string)($record['id'] ?? ''),
            ];

            switch ($type) {
                case 'TXT':
                    $entry['value'] = self::unquoteTxt($content);
                    break;
                case 'SRV':
                    $entry['value'] = rtrim((string)($data['target'] ?? (preg_split('/\s+/', $content)[2] ?? '')), '.');
                    $entry['priority'] = isset($data['priority']) ? (int)$data['priority'] : $entry['priority'];
                    $entry['weight'] = (int)($data['weight'] ?? (preg_split('/\s+/', $content)[0] ?? 0));
                    $entry['port'] = (int)($data['port'] ?? (preg_split('/\s+/', $content)[1] ?? 0));
                    break;
                case 'CAA':
                    if ($data) {
                        $entry['value'] = ($data['flags'] ?? 0) . ' ' . ($data['tag'] ?? '') . ' "' . ($data['value'] ?? '') . '"';
                    }
                    break;
            }

            $grouped[$type][] = $entry;
        }

        ksort($grouped);
        return $grouped;
    }

    /**
     * TXT content may come quoted, in one or more "..." strings
     */
    public static function unquoteTxt(string $content): string
    {
        $content = trim($content);
        if ($content === '' || $content[0] !== '"') {
            return $content;
        }
        if (!preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"/', $content, $m)) {
            return $content;
        }
        return stripcslashes(implode('', $m[1]));
    }

    private function getAllPages(array $creds, string $path, int $perPage): array
    {
        $items = [];
        for ($page = 1; $page <= 200; $page++) {
            $body = $this->get($creds, $path, ['page' => $page, 'per_page' => $perPage]);
            $items = array_merge($items, (array)($body['result'] ?? []));
            $totalPages = (int)($body['result_info']['total_pages'] ?? 1);
            if ($page >= $totalPages) {
                break;
            }
        }
        return $items;
    }

    private function get(array $creds, string $path, array $query = []): array
    {
        $token = trim((string)($creds['api_token'] ?? ''));
        if ($token === '') {
            throw new ProviderException('No Cloudflare API token saved');
        }

        $url = $this->baseUrl . $path . ($query ? '?' . http_build_query($query) : '');
        $response = $this->http->getJson($url, ['Authorization: Bearer ' . $token]);
        $body = $response['body'];

        if ($response['status'] >= 400 || empty($body['success'])) {
            $error = $body['errors'][0] ?? [];
            $message = trim((string)($error['message'] ?? ''));
            $code = isset($error['code']) ? " (code {$error['code']})" : '';
            throw new ProviderException('Cloudflare: ' . ($message !== '' ? $message : "HTTP {$response['status']}") . $code);
        }
        return $body;
    }
}
