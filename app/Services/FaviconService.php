<?php

namespace App\Services;

use App\Services\Channels\WebhookUrlGuard;

/**
 * Fetches and caches the icon of each monitored website for the domain list.
 *
 * Icons are read by the server, never by the visitor's browser, so the domain list
 * isn't sent to anyone. Each fetch goes through WebhookUrlGuard (no local or private
 * addresses, redirects followed one checked hop at a time). SVG is refused, since an
 * SVG opened on its own runs scripts on this site.
 *
 * Cache: cache/favicons/<sha1 of domain>.<ext>, or .none when the site has no icon.
 * The file time is when it was last tried.
 */
class FaviconService
{
    private const MAX_ICON_BYTES = 100 * 1024;
    private const MAX_PAGE_BYTES = 512 * 1024;
    private const MAX_REDIRECTS = 3;
    private const FOUND_TTL = 7 * 86400;
    private const MISSING_TTL = 86400;

    // Detected type => stored extension
    private const TYPES = [
        'image/png' => 'png',
        'image/x-icon' => 'ico',
        'image/vnd.microsoft.icon' => 'ico',
        'image/gif' => 'gif',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
    ];

    private const MIME = [
        'png' => 'image/png',
        'ico' => 'image/x-icon',
        'gif' => 'image/gif',
        'jpg' => 'image/jpeg',
        'webp' => 'image/webp',
    ];

    private string $dir;

    public function __construct()
    {
        $this->dir = dirname(__DIR__, 2) . '/cache/favicons';
    }

    /**
     * The cached icon: ['path', 'mime', 'version'], or null when there is none
     */
    public function cached(string $domain): ?array
    {
        $base = $this->basePath($domain);
        foreach (self::MIME as $ext => $mime) {
            $path = "$base.$ext";
            if (is_file($path)) {
                return ['path' => $path, 'mime' => $mime, 'version' => (string)filemtime($path)];
            }
        }
        return null;
    }

    /**
     * Whether the icon should be fetched again
     */
    public function isDue(string $domain): bool
    {
        $cached = $this->cached($domain);
        if ($cached) {
            return filemtime($cached['path']) < time() - self::FOUND_TTL;
        }
        $none = $this->basePath($domain) . '.none';
        return !is_file($none) || filemtime($none) < time() - self::MISSING_TTL;
    }

    /**
     * Fetch the site's icon and cache it. Returns true when an icon was found.
     */
    public function refresh(string $domain): bool
    {
        $domain = strtolower(trim($domain));
        if ($domain === '' || !is_dir($this->dir) && !@mkdir($this->dir, 0755, true)) {
            return false;
        }

        $icon = null;
        foreach (["https://$domain/", "http://$domain/"] as $home) {
            $page = $this->get($home, self::MAX_PAGE_BYTES);
            if ($page === null) {
                continue;
            }

            $candidates = $page['status'] < 400 ? $this->iconLinks($page['body'], $page['url']) : [];
            $candidates[] = $this->origin($page['url']) . '/favicon.ico';

            foreach (array_unique($candidates) as $url) {
                $icon = $this->download($url);
                if ($icon) {
                    break 2;
                }
            }
            // The site answered, so plain http won't have anything different
            break;
        }

        $this->store($domain, $icon);
        return $icon !== null;
    }

    /**
     * Icon URLs from <link rel="icon"> and friends, best first
     */
    private function iconLinks(string $html, string $pageUrl): array
    {
        if (!preg_match_all('/<link\b[^>]*>/i', $html, $tags)) {
            return [];
        }

        $found = [];
        foreach ($tags[0] as $tag) {
            $attrs = [];
            preg_match_all('/([a-z-]+)\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $tag, $m, PREG_SET_ORDER);
            foreach ($m as $a) {
                $attrs[strtolower($a[1])] = html_entity_decode($a[3] !== '' ? $a[3] : ($a[4] ?? '') . ($a[5] ?? ''));
            }

            $rel = ' ' . strtolower($attrs['rel'] ?? '') . ' ';
            $href = trim($attrs['href'] ?? '');
            if ($href === '' || str_starts_with($href, 'data:')) {
                continue;
            }
            if (!str_contains($rel, ' icon ') && !str_contains($rel, ' apple-touch-icon ')) {
                continue;
            }
            $type = strtolower($attrs['type'] ?? '');
            if ($type === 'image/svg+xml' || preg_match('/\.svgz?($|\?)/i', $href)) {
                continue;
            }

            // Prefer a small icon around 32px, then any icon, then the large touch icons
            $size = preg_match('/(\d+)x\d+/', $attrs['sizes'] ?? '', $s) ? (int)$s[1] : 0;
            $score = str_contains($rel, ' apple-touch-icon ') ? 3 : ($size === 0 || ($size >= 16 && $size <= 64) ? 1 : 2);
            $found[] = [$score, $this->absoluteUrl($href, $pageUrl)];
        }

        usort($found, fn($a, $b) => $a[0] <=> $b[0]);
        return array_values(array_filter(array_column($found, 1)));
    }

    /**
     * Download an icon: ['ext', 'body'], or null when it isn't a usable image
     */
    private function download(string $url): ?array
    {
        $response = $this->get($url, self::MAX_ICON_BYTES);
        if ($response === null || $response['status'] !== 200 || $response['too_big'] || $response['body'] === '') {
            return null;
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $ext = self::TYPES[$finfo->buffer($response['body'])] ?? null;
        return $ext ? ['ext' => $ext, 'body' => $response['body']] : null;
    }

    /**
     * GET with checked redirects and a size cap: ['status', 'url', 'body', 'too_big'], or null on failure
     */
    private function get(string $url, int $maxBytes): ?array
    {
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            try {
                // Throws for local or private addresses; pins the request to the checked IPs
                $guard = WebhookUrlGuard::requestOptions($url);
            } catch (\Throwable $e) {
                return null;
            }

            $body = '';
            $tooBig = false;
            $location = '';
            $ch = curl_init($url);
            curl_setopt_array($ch, ($guard['curl'] ?? []) + [
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_TIMEOUT => 8,
                CURLOPT_ENCODING => '',
                CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; DomainMonitor)',
                CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$location) {
                    if (stripos($line, 'location:') === 0) {
                        $location = trim(substr($line, 9));
                    }
                    return strlen($line);
                },
                CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$body, &$tooBig, $maxBytes) {
                    if (strlen($body) + strlen($chunk) > $maxBytes) {
                        $tooBig = true;
                        return 0; // stops the download
                    }
                    $body .= $chunk;
                    return strlen($chunk);
                },
            ]);
            $ok = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            unset($ch);

            if ($ok === false && !$tooBig) {
                return null;
            }

            if ($status >= 300 && $status < 400 && $location !== '') {
                $url = $this->absoluteUrl($location, $url);
                if ($url === null) {
                    return null;
                }
                continue;
            }

            return ['status' => $status, 'url' => $url, 'body' => $body, 'too_big' => $tooBig];
        }
        return null;
    }

    private function store(string $domain, ?array $icon): void
    {
        $base = $this->basePath($domain);
        foreach (array_merge(array_keys(self::MIME), ['none']) as $ext) {
            if ($icon && $ext === $icon['ext']) {
                continue;
            }
            @unlink("$base.$ext");
        }

        if ($icon) {
            $tmp = "$base.tmp";
            if (@file_put_contents($tmp, $icon['body']) !== false) {
                @rename($tmp, "$base.{$icon['ext']}");
            }
        } else {
            @touch("$base.none");
        }
    }

    private function basePath(string $domain): string
    {
        return $this->dir . '/' . sha1(strtolower(trim($domain)));
    }

    private function origin(string $url): string
    {
        $p = parse_url($url);
        return ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : '');
    }

    /**
     * Resolve a link against the page it is on. Only http(s) results are returned.
     */
    private function absoluteUrl(string $href, string $base): ?string
    {
        if (preg_match('#^https?://#i', $href)) {
            return $href;
        }
        $p = parse_url($base);
        if (empty($p['host'])) {
            return null;
        }
        if (str_starts_with($href, '//')) {
            return ($p['scheme'] ?? 'https') . ':' . $href;
        }
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $href)) {
            return null;
        }
        if (str_starts_with($href, '/')) {
            return $this->origin($base) . $href;
        }
        $dir = preg_replace('#/[^/]*$#', '/', $p['path'] ?? '/');
        return $this->origin($base) . $dir . $href;
    }
}
