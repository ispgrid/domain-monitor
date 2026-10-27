<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\CommandRun;
use App\Models\CronRun;
use App\Models\Setting;

/**
 * Deletes logs older than the "Keep logs for" setting (log files, cron runs, commands,
 * activity, sent notifications and resolved errors). Update history is kept.
 */
class LogRetention
{
    public const DEFAULT_DAYS = 30;

    private Setting $settingModel;

    public function __construct()
    {
        $this->settingModel = new Setting();
    }

    public function getDays(): int
    {
        return max(0, (int)$this->settingModel->getValue('log_retention_days', self::DEFAULT_DAYS));
    }

    /**
     * Run the cleanup once per day (called from the domain check cron)
     */
    public function cleanupIfDue(): ?array
    {
        $today = date('Y-m-d');
        if ($this->settingModel->getValue('log_cleanup_last_run') === $today) {
            return null;
        }
        $this->settingModel->setValue('log_cleanup_last_run', $today);
        return $this->cleanup();
    }

    /**
     * Returns the number of items deleted per kind; empty when retention is off (0 days)
     */
    public function cleanup(): array
    {
        $days = $this->getDays();
        if ($days === 0) {
            return [];
        }

        $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        $db = \Core\Database::getConnection();
        $deleted = [];

        $deleted['files'] = (new LogFileService())->deleteOlderThan(date('Y-m-d', strtotime("-{$days} days")));

        $tables = [
            'cron_runs' => fn() => (new CronRun())->deleteOlderThan($cutoff),
            'commands' => fn() => (new CommandRun())->deleteOlderThan($cutoff),
            'activity' => fn() => (new AuditLog())->deleteOlderThan($cutoff),
            'notifications' => function () use ($db, $cutoff) {
                $stmt = $db->prepare("DELETE FROM notification_logs WHERE sent_at < ?");
                $stmt->execute([$cutoff]);
                return $stmt->rowCount();
            },
            'resolved_errors' => function () use ($db, $cutoff) {
                $stmt = $db->prepare("DELETE FROM error_logs WHERE is_resolved = 1 AND last_occurred_at < ?");
                $stmt->execute([$cutoff]);
                return $stmt->rowCount();
            },
        ];

        foreach ($tables as $kind => $delete) {
            try {
                $deleted[$kind] = $delete();
            } catch (\Throwable $e) {
                $deleted[$kind] = 0;
            }
        }

        return $deleted;
    }
}
