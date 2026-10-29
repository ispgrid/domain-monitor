#!/usr/bin/env php
<?php

/**
 * Sync one DNS provider account (Cloudflare) in the background
 *
 * Started by Sync now on the Integrations page, so a big account doesn't hold the browser request.
 * Every account is also synced by check_dns.php, so this is not a cron job.
 *
 * Usage:
 *   php cron/sync_dns_provider.php --account=12
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use App\Models\RegistrarAccount;
use App\Models\Setting;
use App\Services\Logger;
use App\Services\ProviderDnsSync;
use Core\Database;

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
new Database();

try {
    $appSettings = (new Setting())->getAppSettings();
    date_default_timezone_set($appSettings['app_timezone']);
} catch (\Exception $e) {
    date_default_timezone_set('UTC');
}

$accountId = 0;
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--account=(\d+)$/', $arg, $m)) {
        $accountId = (int)$m[1];
    }
}
if ($accountId <= 0) {
    fwrite(STDERR, "Usage: php cron/sync_dns_provider.php --account=ID\n");
    exit(1);
}

set_time_limit(0);

$accountModel = new RegistrarAccount();
$account = $accountModel->find($accountId);
if (!$account) {
    fwrite(STDERR, "Account $accountId not found\n");
    exit(1);
}

$logger = new Logger('provider-dns');
$started = microtime(true);

// syncAccount stores ok or error on the account, which ends the "syncing" state
$result = (new ProviderDnsSync())->syncAccount($account);

$logger->info('Background provider DNS sync finished', [
    'account_id' => $accountId,
    'zones' => $result['zones'],
    'linked' => $result['linked'],
    'mismatched' => $result['mismatched'],
    'alerts' => $result['alerts'],
    'error' => $result['error'],
    'seconds' => round(microtime(true) - $started, 1),
]);

echo "{$account['label']}: {$result['zones']} zone(s), {$result['linked']} matched, {$result['mismatched']} differ from public DNS"
    . ($result['error'] ? " - {$result['error']}" : '') . "\n";
exit($result['error'] ? 1 : 0);
