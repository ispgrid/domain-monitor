<?php

namespace App\Services;

use App\Models\CronRun;
use App\Models\Setting;

/**
 * Health of the cron jobs: when each last ran, whether it is late or keeps failing,
 * and alerts for admins when that happens.
 */
class CronMonitor
{
    public const JOBS = [
        'domains' => [
            'label' => 'Domain check',
            'script' => 'check_domains.php',
            'interval_setting' => 'check_interval_hours',
            'default_hours' => 24,
            'last_run_setting' => 'last_check_run',
            'log_file' => 'cron.log',
            'icon' => 'globe',
        ],
        'ssl' => [
            'label' => 'SSL check',
            'script' => 'check_ssl.php',
            'interval_setting' => 'ssl_check_interval_hours',
            'default_hours' => 12,
            'last_run_setting' => 'last_ssl_check_run',
            'log_file' => 'ssl_cron.log',
            'icon' => 'lock',
        ],
        'dns' => [
            'label' => 'DNS check',
            'script' => 'check_dns.php',
            'interval_setting' => 'dns_check_interval_hours',
            'default_hours' => 24,
            'last_run_setting' => 'last_dns_check_run',
            'log_file' => 'dns_cron.log',
            'icon' => 'network-wired',
        ],
    ];

    // A job is late when it has not started for twice its interval plus this grace
    private const GRACE_HOURS = 1;
    private const CHECK_EVERY_MINUTES = 15;

    private Setting $settingModel;

    public function __construct()
    {
        $this->settingModel = new Setting();
    }

    /**
     * One entry per job: last run, state (ok, running, late, failing, never) and a message
     */
    public function getHealth(): array
    {
        $runModel = null;
        try {
            $runModel = new CronRun();
            $runModel->countFiltered([]);
        } catch (\Throwable $e) {
            $runModel = null;
        }

        $health = [];
        foreach (self::JOBS as $job => $info) {
            $interval = max(1, (int)$this->settingModel->getValue($info['interval_setting'], $info['default_hours']));
            $runs = $runModel ? $runModel->getLatestForJob($job, 5) : [];
            $lastRun = $runs[0] ?? null;

            // Before the first tracked run, fall back to the timestamp the cron script saves
            $lastStarted = $lastRun['started_at'] ?? $lastRun['created_at'] ?? null;
            $settingLastRun = $this->settingModel->getValue($info['last_run_setting']);
            if (!$lastStarted || ($settingLastRun && strtotime($settingLastRun) > strtotime($lastStarted))) {
                $lastStarted = $settingLastRun ?: $lastStarted;
            }

            $lastSuccess = null;
            foreach ($runs as $run) {
                if (in_array($run['effective_status'], ['success', 'partial'], true)) {
                    $lastSuccess = $run;
                    break;
                }
            }

            $state = 'ok';
            $message = '';
            $finished = array_values(array_filter($runs, fn($r) => !in_array($r['effective_status'], ['queued', 'running'], true)));

            if (!$lastStarted) {
                $state = 'never';
                $message = 'Has not run yet. Add it to the server\'s crontab.';
            } elseif ($lastRun && $lastRun['effective_status'] === 'running') {
                $state = 'running';
                $message = 'Running now';
            } elseif (count($finished) >= 2 && $this->isFailure($finished[0]) && $this->isFailure($finished[1])) {
                $state = 'failing';
                $message = 'The last two runs failed';
            } elseif (strtotime($lastStarted) < time() - (2 * $interval + self::GRACE_HOURS) * 3600) {
                $state = 'late';
                $message = 'Has not run since ' . $lastStarted . ' (expected every ' . $interval . 'h)';
            }

            $health[$job] = array_merge($info, [
                'job' => $job,
                'interval_hours' => $interval,
                'last_run' => $lastRun,
                'last_started_at' => $lastStarted,
                'last_success' => $lastSuccess,
                'state' => $state,
                'message' => $message,
            ]);
        }

        return $health;
    }

    /**
     * Jobs that are late or failing
     */
    public function getProblems(): array
    {
        return array_filter($this->getHealth(), fn($h) => in_array($h['state'], ['late', 'failing'], true));
    }

    /**
     * Notify admins once per problem; a job that recovers clears its alert
     */
    public function checkAndAlert(): void
    {
        $this->settingModel->setValue('cron_health_checked_at', date('Y-m-d H:i:s'));

        if ($this->settingModel->getValue('cron_alerts_enabled', '1') !== '1') {
            return;
        }

        foreach ($this->getHealth() as $job => $health) {
            $settingKey = 'cron_alert_' . $job;
            $sentKey = (string)$this->settingModel->getValue($settingKey, '');

            if (!in_array($health['state'], ['late', 'failing'], true)) {
                if ($sentKey !== '' && $health['state'] !== 'running') {
                    $this->settingModel->setValue($settingKey, '');
                }
                continue;
            }

            $key = $health['state'] . ':' . ($health['state'] === 'late' ? $health['last_started_at'] : ($health['last_run']['id'] ?? ''));
            if ($key === $sentKey) {
                continue;
            }

            $this->settingModel->setValue($settingKey, $key);
            (new NotificationService())->notifyAdminsCronProblem($health['label'], $health['state'], $health['message']);
        }
    }

    /**
     * Called on admin page loads: run checkAndAlert() at most every few minutes,
     * so a cron that stopped completely is still noticed
     */
    public function checkIfDue(): void
    {
        $last = $this->settingModel->getValue('cron_health_checked_at');
        if ($last && strtotime($last) > time() - self::CHECK_EVERY_MINUTES * 60) {
            return;
        }
        $this->checkAndAlert();
    }

    private function isFailure(array $run): bool
    {
        return in_array($run['effective_status'], ['failed', 'interrupted', 'not_started'], true);
    }
}
