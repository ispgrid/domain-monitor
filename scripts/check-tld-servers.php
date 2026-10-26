<?php
/**
 * Check database/tld-servers.json for errors.
 *
 * Usage: php scripts/check-tld-servers.php [path/to/file.json]
 * Exits with 1 when the file has errors, so it can run in CI.
 */

require __DIR__ . '/../app/Services/BundledTldServers.php';

use App\Services\BundledTldServers;

$file = $argv[1] ?? BundledTldServers::DEFAULT_FILE;
$list = new BundledTldServers($file);
$errors = $list->errors();

echo basename($file) . ': ' . count($list->all()) . " valid TLD entr" . (count($list->all()) === 1 ? 'y' : 'ies') . "\n";

if ($errors) {
    echo count($errors) . " error(s):\n";
    foreach ($errors as $error) {
        echo "  - {$error}\n";
    }
    exit(1);
}

echo "No errors\n";
exit(0);
