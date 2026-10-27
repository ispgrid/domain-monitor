<?php

namespace App\Services\Channels;

/**
 * Keeps webhook-style channels (webhook, Discord, Slack, Mattermost) from reaching the
 * server itself or the local network: loopback, private, link-local (cloud metadata)
 * and other non-public addresses are refused unless the admin allows them under
 * Settings → Monitoring.
 *
 * The request is pinned to the addresses that were checked, so a DNS answer that
 * changes between the check and the request can't swap in an internal address,
 * and redirects are not followed.
 */
class WebhookUrlGuard
{
    private static ?bool $allowPrivate = null;

    /**
     * An error message when the URL can't be used, null when it can
     */
    public static function check(string $url): ?string
    {
        return self::inspect($url)['error'];
    }

    /**
     * Guzzle options for a request to the URL. Throws when the URL can't be used.
     */
    public static function requestOptions(string $url): array
    {
        $target = self::inspect($url);
        if ($target['error'] !== null) {
            throw new \InvalidArgumentException($target['error']);
        }

        $options = ['allow_redirects' => false];

        if (!empty($target['ips']) && defined('CURLOPT_RESOLVE')) {
            $addresses = array_map(fn($ip) => str_contains($ip, ':') ? "[$ip]" : $ip, $target['ips']);
            $options['curl'] = [
                CURLOPT_RESOLVE => [$target['host'] . ':' . $target['port'] . ':' . implode(',', $addresses)],
            ];
        }

        return $options;
    }

    /**
     * Whether an address is on the public internet
     */
    public static function isPublicIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) === false) {
            return false;
        }

        $packed = inet_pton($ip);
        if ($packed === false) {
            return false;
        }

        if (strlen($packed) === 4) {
            // Multicast 224.0.0.0/4
            return (ord($packed[0]) & 0xF0) !== 0xE0;
        }

        // IPv6 multicast ff00::/8
        if (ord($packed[0]) === 0xFF) {
            return false;
        }
        // NAT64 64:ff9b::/96 carries an IPv4 address: judge that one
        if (substr($packed, 0, 12) === "\x00\x64\xff\x9b" . str_repeat("\x00", 8)) {
            return self::isPublicIp(inet_ntop(substr($packed, 12)));
        }

        return true;
    }

    /**
     * @return array{error: ?string, host: string, port: int, ips: string[]}
     */
    private static function inspect(string $url): array
    {
        $result = ['error' => null, 'host' => '', 'port' => 0, 'ips' => []];

        $parts = parse_url(trim($url));
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host'] ?? '');

        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            $result['error'] = 'The URL must start with http:// or https://';
            return $result;
        }

        $result['host'] = $host;
        $result['port'] = (int)($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

        if (self::allowPrivate()) {
            return $result;
        }

        $literal = trim($host, '[]');
        if (filter_var($literal, FILTER_VALIDATE_IP) !== false) {
            $ips = [$literal];
        } else {
            $ips = self::resolve($host);
            if (empty($ips)) {
                $result['error'] = "Could not resolve {$host}";
                return $result;
            }
        }

        foreach ($ips as $ip) {
            if (!self::isPublicIp($ip)) {
                $result['error'] = "{$host} points to a local or private network address ({$ip}). "
                    . 'An admin can allow this under Settings → Monitoring.';
                return $result;
            }
        }

        $result['ips'] = $ips;
        return $result;
    }

    /**
     * Every IPv4 and IPv6 address the host resolves to
     */
    private static function resolve(string $host): array
    {
        $ips = gethostbynamel($host) ?: [];

        $records = @dns_get_record($host, DNS_AAAA);
        foreach ($records ?: [] as $record) {
            if (!empty($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($ips));
    }

    private static function allowPrivate(): bool
    {
        if (self::$allowPrivate === null) {
            try {
                self::$allowPrivate = (new \App\Models\Setting())->getValue('webhook_allow_private', '0') === '1';
            } catch (\Throwable $e) {
                self::$allowPrivate = false;
            }
        }
        return self::$allowPrivate;
    }
}
