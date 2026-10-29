<?php

namespace App\Services\Registrars;

/**
 * A provider that hosts DNS zones. Read only.
 */
interface DnsProviderInterface
{
    /**
     * Zones the credentials can see
     *
     * @return array<int, array{id: string, name: string, status: string, name_servers: string[]}>
     */
    public function listZones(array $creds): array;

    /**
     * Records of one zone, grouped by type like DnsService results. Each record has host ('@' or a label),
     * value, ttl, priority (or null), proxied (bool), flattened (bool) and provider_id; SRV also has weight and port.
     */
    public function listRecords(array $creds, string $zoneId, string $zoneName): array;
}
