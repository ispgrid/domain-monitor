#!/usr/bin/env php
<?php

/**
 * Scheduler
 *
 * Starts the domain, SSL and DNS checks when they are due, based on the intervals in
 * Settings. The Docker image runs it every 15 minutes, so no crontab is needed there.
 * Other installs can use it too, in place of the three separate crontab lines:
 *
 * Usage: php cron/scheduler.php [--verbose]   (--verbose also shows when each check is next due)
 * Crontab: 0,15,30,45 * * * * php /path/to/project/cron/scheduler.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use App\Helpers\DeploymentHelper;
use App\Models\Setting;
use App\Services\CommandRunner;
use App\Services\ContainerLogService;
use App\Services\CronMonitor;
use Core\Database;

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

// A job counts as due this many minutes early, so the 15-minute tick doesn't push it later every day
const SCHEDULER_EARLY_MINUTES = 10;

$logFile = __DIR__ . '/../logs/scheduler.log';
$verbose = in_array('--verbose', $_SERVER['argv'] ?? [], true);

function schedulerLog(string $message, string $level = 'INFO'): void
{
    global $logFile;
    $line = '[' . date('Y-m-d H:i:s') . "] [{$level}] {$message}\n";
    @file_put_contents($logFile, $line, FILE_APPEND);
    echo $line;
}

// One scheduler at a time
$lock = @fopen(__DIR__ . '/../cache/scheduler.lock', 'c');
if ($lock && !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);
}

$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

if (!is_file(__DIR__ . '/../.installed')) {
    // Nothing to check until the web installer has run
    exit(0);
}

try {
    new Database();
    $settingModel = new Setting();
    try {
        date_default_timezone_set($settingModel->getAppSettings()['app_timezone']);
    } catch (\Throwable $e) {
        date_default_timezone_set('UTC');
    }

    $started = [];
    foreach ((new CronMonitor())->getHealth() as $health) {
        // The same interval the Logs use to decide that a job is late
        $hours = $health['interval_hours'];

        // Already queued (Run now) or still running
        if (in_array($health['last_run']['effective_status'] ?? '', ['queued', 'running'], true)) {
            if ($verbose) {
                echo "{$health['label']}: running now
";
            }
            continue;
        }

        $last = $health['last_started_at'] ? strtotime($health['last_started_at']) : null;
        if ($last !== null && time() - $last < $hours * 3600 - SCHEDULER_EARLY_MINUTES * 60) {
            if ($verbose) {
                echo "{$health['label']}: next run after " . date('Y-m-d H:i', $last + $hours * 3600 - SCHEDULER_EARLY_MINUTES * 60) . " (every {$hours}h)\n";
            }
            continue;
        }

        $script = realpath(__DIR__ . '/' . $health['script']);
        if (CommandRunner::spawnPhp($script, [], 'Scheduler: ' . $health['label'], 'scheduler')) {
            $started[] = $health['label'];
            schedulerLog("Started {$health['label']} (every {$hours}h, last run " . ($health['last_started_at'] ?: 'never') . ')');
        } else {
            schedulerLog("Could not start {$health['label']}. See Logs → Commands.", 'ERROR');
        }
    }

    if (!$started) {
        // Quiet ticks are not logged, apart from about one line an hour to show the scheduler is alive
        if ((int)date('i') < 15 || $verbose) {
            schedulerLog('Nothing due');
        }
    }
} catch (\Throwable $e) {
    schedulerLog('Scheduler failed: ' . $e->getMessage(), 'ERROR');
    exit(1);
}

if (DeploymentHelper::isDocker()) {
    foreach ((new ContainerLogService())->trim() as $file) {
        schedulerLog("Trimmed {$file}");
    }
}
