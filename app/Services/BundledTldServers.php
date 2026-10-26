<?php

namespace App\Services;

/**
 * RDAP/WHOIS servers shipped in database/tld-servers.json for TLDs that IANA does not cover.
 * Only fills gaps: a server from the TLD registry (admin-set or IANA) always wins.
 *
 * Kept free of other classes so scripts/check-tld-servers.php can run it without Composer.
 */
class BundledTldServers
{
    public const DEFAULT_FILE = __DIR__ . '/../../database/tld-servers.json';

    private const ENTRY_FIELDS = ['rdap', 'whois', 'source'];

    /** @var array<string, array{rdap: string[], whois: ?string, source: string}> */
    private array $servers = [];

    /** @var string[] */
    private array $errors = [];

    public function __construct(?string $file = null)
    {
        $file = $file ?? self::DEFAULT_FILE;

        if (!is_file($file)) {
            $this->errors[] = 'File not found: ' . basename($file);
            return;
        }

        $result = self::parse((string)file_get_contents($file));
        $this->servers = $result['servers'];
        $this->errors = $result['errors'];
    }

    /**
     * Servers for a TLD (no leading dot), or null when the file has no valid entry for it
     *
     * @return array{rdap: string[], whois: ?string, source: string}|null
     */
    public function get(string $tld): ?array
    {
        return $this->servers[strtolower(ltrim($tld, '.'))] ?? null;
    }

    /** @return array<string, array{rdap: string[], whois: ?string, source: string}> */
    public function all(): array
    {
        return $this->servers;
    }

    /** @return string[] */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * Validate the file contents. Entries with errors are left out of 'servers'.
     *
     * @return array{servers: array<string, array{rdap: string[], whois: ?string, source: string}>, errors: string[]}
     */
    public static function parse(string $json): array
    {
        $servers = [];
        $errors = [];

        $data = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['servers' => [], 'errors' => ['Invalid JSON: ' . json_last_error_msg() . ' (look for a missing or extra comma, quote or bracket)']];
        }
        if (!is_array($data) || (array_is_list($data) && $data !== [])) {
            return ['servers' => [], 'errors' => ['The file must be a JSON object keyed by TLD']];
        }

        // json_decode keeps only the last of two equal keys, so find duplicates in the raw text
        foreach (array_count_values(self::topLevelKeys($json)) as $key => $count) {
            if ($count > 1) {
                $errors[] = "\"{$key}\": listed {$count} times";
            }
        }

        foreach ($data as $tld => $entry) {
            $tld = (string)$tld;

            // Keys starting with "_" hold notes for contributors
            if (str_starts_with($tld, '_')) {
                continue;
            }

            $entryErrors = self::validateEntry($tld, $entry);
            if ($entryErrors) {
                foreach ($entryErrors as $error) {
                    $errors[] = "\"{$tld}\": {$error}";
                }
                continue;
            }

            $servers[$tld] = [
                'rdap' => array_map(fn($url) => rtrim($url, '/') . '/', $entry['rdap'] ?? []),
                'whois' => isset($entry['whois']) ? strtolower($entry['whois']) : null,
                'source' => $entry['source'],
            ];
        }

        return ['servers' => $servers, 'errors' => $errors];
    }

    /** @return string[] */
    private static function validateEntry(string $tld, mixed $entry): array
    {
        $errors = [];

        if (!preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)*$/', $tld)) {
            $errors[] = 'TLD must be lowercase letters, digits and hyphens, without the leading dot (e.g. "iq" or "com.iq")';
        }

        if (!is_array($entry) || array_is_list($entry)) {
            $errors[] = 'entry must be an object with "rdap" and/or "whois" and a "source"';
            return $errors;
        }

        foreach (array_diff(array_keys($entry), self::ENTRY_FIELDS) as $unknown) {
            $errors[] = "unknown field \"{$unknown}\" (allowed: " . implode(', ', self::ENTRY_FIELDS) . ')';
        }

        if (!isset($entry['rdap']) && !isset($entry['whois'])) {
            $errors[] = 'needs "rdap", "whois" or both';
        }

        if (isset($entry['rdap'])) {
            if (!is_array($entry['rdap']) || !array_is_list($entry['rdap']) || $entry['rdap'] === []) {
                $errors[] = '"rdap" must be a non-empty list of URLs';
            } else {
                foreach ($entry['rdap'] as $url) {
                    if (!is_string($url) || !self::isRdapUrl($url)) {
                        $errors[] = '"rdap" entry ' . json_encode($url, JSON_UNESCAPED_SLASHES) . ' is not an https:// URL without query, fragment or login';
                    }
                }
            }
        }

        if (isset($entry['whois']) && (!is_string($entry['whois']) || !self::isHostname($entry['whois']))) {
            $errors[] = '"whois" must be a hostname such as whois.nic.example (no scheme or port)';
        }

        if (!isset($entry['source'])) {
            $errors[] = '"source" is required: a link where the server can be checked';
        } elseif (!is_string($entry['source']) || !self::isWebUrl($entry['source'])) {
            $errors[] = '"source" must be an http(s) URL';
        }

        return $errors;
    }

    private static function isRdapUrl(string $url): bool
    {
        $parts = parse_url($url);
        return $parts !== false
            && ($parts['scheme'] ?? '') === 'https'
            && self::isHostname($parts['host'] ?? '')
            && !isset($parts['query']) && !isset($parts['fragment']) && !isset($parts['user']);
    }

    private static function isWebUrl(string $url): bool
    {
        $parts = parse_url($url);
        return $parts !== false
            && in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            && self::isHostname($parts['host'] ?? '');
    }

    private static function isHostname(string $host): bool
    {
        return strlen($host) <= 253
            && (bool)preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/i', $host);
    }

    /**
     * Keys of the top-level JSON object, in order, including repeated ones
     *
     * @return string[]
     */
    private static function topLevelKeys(string $json): array
    {
        $keys = [];
        $depth = 0;
        $length = strlen($json);

        for ($i = 0; $i < $length; $i++) {
            $char = $json[$i];

            if ($char === '"') {
                $start = $i;
                for ($i++; $i < $length && $json[$i] !== '"'; $i++) {
                    if ($json[$i] === '\\') {
                        $i++;
                    }
                }
                // A string at depth 1 followed by ":" is a top-level key
                if ($depth === 1 && preg_match('/\G\s*:/', $json, $m, 0, $i + 1)) {
                    $keys[] = (string)json_decode(substr($json, $start, $i - $start + 1));
                }
            } elseif ($char === '{' || $char === '[') {
                $depth++;
            } elseif ($char === '}' || $char === ']') {
                $depth--;
            }
        }

        return $keys;
    }
}
