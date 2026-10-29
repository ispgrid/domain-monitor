<?php

namespace App\Controllers;

use Core\Controller;
use App\Models\ApiToken;
use App\Models\Domain;
use App\Models\Setting;
use App\Models\SslCertificate;
use App\Helpers\DomainHelper;
use App\Services\LoginThrottle;
use App\Services\Logger;

/**
 * Read-only JSON API under /api/v1, authenticated with a per-user token
 * (Profile → API Tokens). Runs without a session.
 *
 * Users see the same domains as in the app: everything in shared mode,
 * only their own in isolated mode.
 */
class ApiController extends Controller
{
    private const DEFAULT_PER_PAGE = 50;
    private const MAX_PER_PAGE = 100;
    private const SORTS = ['domain_name', 'expiration_date', 'status', 'registrar', 'created_at'];

    private Setting $settingModel;
    // User whose domains the token may see, or null for all (shared mode)
    private ?int $scopeUserId = null;

    public function __construct()
    {
        $this->settingModel = new Setting();
    }

    /**
     * GET /api/v1/stats: counts for dashboards
     */
    public function stats()
    {
        $this->run(function () {
            $stats = (new Domain())->getStatistics($this->scopeUserId);
            $ssl = (new SslCertificate())->getDashboardSummary(1, $this->scopeUserId);

            return ['data' => [
                'domains' => [
                    'total' => (int)$stats['total'],
                    'active' => (int)($stats['active'] ?? 0),
                    'expiring_soon' => (int)$stats['expiring_soon'],
                    'expired' => (int)($stats['expired'] ?? 0),
                    'redemption_period' => (int)($stats['redemption_period'] ?? 0),
                    'pending_delete' => (int)($stats['pending_delete'] ?? 0),
                    'available' => (int)($stats['available'] ?? 0),
                    'error' => (int)($stats['error'] ?? 0),
                    'inactive' => (int)$stats['inactive'],
                    'expiring_threshold_days' => (int)$stats['expiring_threshold'],
                ],
                'ssl' => [
                    'total' => $ssl['total'],
                    'expiring' => $ssl['expiring'],
                    'issues' => $ssl['issues'],
                ],
            ]];
        });
    }

    /**
     * GET /api/v1/domains: paginated list.
     * Filters: status, group (id), tag (name), search; sort, order, page, per_page
     */
    public function domains()
    {
        $this->run(function () {
            $filters = [
                'search' => trim((string)($_GET['search'] ?? '')),
                'status' => trim((string)($_GET['status'] ?? '')),
                'group' => (int)($_GET['group'] ?? 0) ?: '',
                'tag' => trim((string)($_GET['tag'] ?? '')),
            ];
            $sort = in_array($_GET['sort'] ?? '', self::SORTS, true) ? $_GET['sort'] : 'domain_name';
            $order = strtolower((string)($_GET['order'] ?? '')) === 'desc' ? 'desc' : 'asc';
            $page = max(1, (int)($_GET['page'] ?? 1));
            $perPage = (int)($_GET['per_page'] ?? self::DEFAULT_PER_PAGE);
            $perPage = min(self::MAX_PER_PAGE, max(1, $perPage));

            $threshold = $this->expiringThreshold();
            $result = (new Domain())->getFilteredPaginated($filters, $sort, $order, $page, $perPage, $threshold, $this->scopeUserId);

            $data = array_map(fn($domain) => $this->formatDomain($domain, $threshold), $result['domains']);

            return [
                'data' => array_values($data),
                'meta' => [
                    'page' => (int)$result['pagination']['current_page'],
                    'per_page' => (int)$result['pagination']['per_page'],
                    'total' => (int)$result['pagination']['total'],
                    'total_pages' => (int)$result['pagination']['total_pages'],
                ],
            ];
        });
    }

    /**
     * GET /api/v1/domains/{id}: one domain by id or by name, with nameservers and SSL certificates
     */
    public function domain($params = [])
    {
        $this->run(function () use ($params) {
            $key = strtolower(trim(urldecode((string)($params['id'] ?? ''))));
            $domainModel = new Domain();

            $domain = ctype_digit($key)
                ? $domainModel->findWithIsolation((int)$key, $this->scopeUserId)
                : $domainModel->findByNameWithIsolation($key, $this->scopeUserId);

            if (!$domain) {
                $this->error(404, 'Domain not found');
            }

            $tags = $domainModel->getTagsForDomains([(int)$domain['id']], $this->scopeUserId);
            $domain['tags'] = implode(',', array_column($tags, 'name'));
            $domain['group_name'] = null;
            if (!empty($domain['notification_group_id'])) {
                $group = (new \App\Models\NotificationGroup())->find((int)$domain['notification_group_id']);
                $domain['group_name'] = $group['name'] ?? null;
            }

            $whois = json_decode($domain['whois_data'] ?? '', true) ?: [];
            $data = $this->formatDomain($domain, $this->expiringThreshold());
            $data['nameservers'] = array_values(array_filter((array)($whois['nameservers'] ?? []), 'is_string'));
            $data['registry_statuses'] = array_values(array_filter((array)($whois['status'] ?? []), 'is_string'));
            $data['creation_date'] = $whois['creation_date'] ?? null;
            $data['updated_date'] = $domain['updated_date'] ?? null;
            $data['notes'] = $domain['notes'] ?? null;
            $data['ssl_certificates'] = array_map(
                [$this, 'formatCertificate'],
                (new SslCertificate())->getByDomain((int)$domain['id'])
            );

            return ['data' => $data];
        });
    }

    /**
     * GET /api/v1/ssl: certificates of active domains with SSL monitoring on.
     * Filters: status (valid, expiring, expired, invalid), domain (id)
     */
    public function ssl()
    {
        $this->run(function () {
            $status = trim((string)($_GET['status'] ?? ''));
            $domainId = (int)($_GET['domain'] ?? 0) ?: null;
            $certificates = (new SslCertificate())->getForApi($this->scopeUserId, $status ?: null, $domainId);

            return ['data' => array_map(function ($cert) {
                return ['domain_id' => (int)$cert['domain_id'], 'domain' => $cert['domain_name']] + $this->formatCertificate($cert);
            }, $certificates)];
        });
    }

    /**
     * Unknown paths under /api/v1
     */
    public static function notFound(): void
    {
        self::send(404, ['error' => ['code' => 404, 'message' => 'Unknown API endpoint']]);
    }

    /**
     * Authenticate, then send the handler's result as JSON. Unexpected errors give a 500
     * without details; the details go to the log.
     */
    private function run(callable $handler): void
    {
        try {
            $this->authenticate();
            $result = $handler();
        } catch (\PDOException $e) {
            // After a git pull the tokens table only exists once the update's migration has run
            if (($e->errorInfo[1] ?? null) === 1146) {
                $this->error(503, 'The API is not ready yet. An admin needs to finish the update under Settings → Updates.');
            }
            (new Logger('api'))->error('API request failed', [
                'path' => parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH),
                'error' => $e->getMessage(),
            ]);
            $this->error(500, 'Server error');
        } catch (\Throwable $e) {
            (new Logger('api'))->error('API request failed', [
                'path' => parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH),
                'error' => $e->getMessage(),
            ]);
            $this->error(500, 'Server error');
        }

        self::send(200, $result);
    }

    private function authenticate(): void
    {
        if ($this->settingModel->getValue('api_enabled', '1') !== '1') {
            $this->error(403, 'The API is turned off. An admin can turn it on under Settings → Security.');
        }

        $token = $this->tokenFromRequest();
        if ($token === null) {
            $this->error(401, 'Send an API token in the Authorization header: Bearer <token>');
        }

        $ip = LoginThrottle::clientIp();
        $throttle = new LoginThrottle();
        $minutes = $throttle->apiBlockedFor($ip);
        if ($minutes > 0) {
            header('Retry-After: ' . ($minutes * 60));
            $this->error(429, "Too many requests with a wrong token. Try again in {$minutes} minute(s).");
        }

        $tokenModel = new ApiToken();
        $client = $tokenModel->findValid($token);
        if (!$client) {
            $throttle->recordApiFailure($ip);
            $this->error(401, 'Invalid or expired API token');
        }

        if (!$tokenModel->hit((int)$client['id'], $ip)) {
            header('Retry-After: 60');
            $this->error(429, 'Rate limit reached: ' . ApiToken::REQUESTS_PER_MINUTE . ' requests per minute per token');
        }

        $isolated = $this->settingModel->getValue('user_isolation_mode', 'shared') === 'isolated';
        $this->scopeUserId = $isolated ? (int)$client['user_id'] : null;
    }

    /**
     * The token from "Authorization: Bearer <token>" or "X-API-Key: <token>"
     */
    private function tokenFromRequest(): ?string
    {
        // Some Apache setups only pass the Authorization header as REDIRECT_HTTP_AUTHORIZATION
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/^Bearer\s+(\S+)$/i', trim($header), $matches)) {
            return $matches[1];
        }

        $key = trim($_SERVER['HTTP_X_API_KEY'] ?? '');
        return $key !== '' ? $key : null;
    }

    private function expiringThreshold(): int
    {
        $days = $this->settingModel->getNotificationDays();
        return !empty($days) ? max($days) : 30;
    }

    private function formatDomain(array $domain, int $threshold): array
    {
        $daysLeft = DomainHelper::daysUntil($domain['expiration_date'] ?? null);
        $isActive = !empty($domain['is_active']);
        $tags = trim((string)($domain['tags'] ?? ''));

        return [
            'id' => (int)$domain['id'],
            'domain' => $domain['domain_name'],
            'status' => $domain['status'],
            'expiration_date' => $domain['expiration_date'] ?: null,
            'days_left' => $daysLeft,
            'expiring_soon' => $isActive && $daysLeft !== null && $daysLeft > 0 && $daysLeft <= $threshold,
            'expiration_source' => empty($domain['expiration_date']) ? null : (DomainHelper::isManualExpiration($domain) ? 'manual' : 'whois'),
            'registrar' => $domain['registrar'] ?: null,
            'purpose' => $domain['purpose'] ?? 'own',
            'monitoring' => [
                'enabled' => $isActive,
                'dns' => !empty($domain['dns_monitoring_enabled']),
                'ssl' => !empty($domain['ssl_monitoring_enabled']),
            ],
            'group' => !empty($domain['notification_group_id'])
                ? ['id' => (int)$domain['notification_group_id'], 'name' => $domain['group_name'] ?? null]
                : null,
            'tags' => $tags === '' ? [] : explode(',', $tags),
            'last_checked' => $domain['last_checked'] ?: null,
            'created_at' => $domain['created_at'] ?? null,
        ];
    }

    private function formatCertificate(array $cert): array
    {
        return [
            'id' => (int)$cert['id'],
            'hostname' => $cert['hostname'],
            'port' => (int)$cert['port'],
            'status' => $cert['status'],
            'valid_from' => $cert['valid_from'] ?? null,
            'valid_to' => $cert['valid_to'] ?? null,
            'days_left' => $cert['valid_to'] ? DomainHelper::daysUntil($cert['valid_to']) : null,
            'issuer' => $cert['issuer_name'] ?? null,
            'trusted' => !empty($cert['is_trusted']),
            'self_signed' => !empty($cert['is_self_signed']),
            'last_checked' => $cert['last_checked'] ?? null,
            'last_error' => $cert['last_error'] ?? null,
        ];
    }

    private function error(int $status, string $message): void
    {
        self::send($status, ['error' => ['code' => $status, 'message' => $message]]);
    }

    private static function send(int $status, array $body): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }
}
