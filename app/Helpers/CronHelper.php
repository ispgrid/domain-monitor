<?php

namespace App\Helpers;

/**
 * Shared utilities for cron scripts (logging, formatting, DNS checks).
 *
 * Replaces the standalone functions that were duplicated across
 * check_dns.php, check_ssl.php, and check_domains.php.
 */
class CronHelper
{
    private const MAX_OUTPUT_BYTES = 524288;
    private const FLUSH_SECONDS = 5;

    private string $logFile;

    // Run tracking for Logs → Cron Runs (null when the cron_runs table does not exist yet)
    private ?int $runId = null;
    private string $job = '';
    private float $runStart = 0.0;
    private string $output = '';
    private float $lastFlush = 0.0;
    private bool $finished = false;

    public function __construct(string $logFile)
    {
        $this->logFile = $logFile;
        $this->rotateLogFile();
    }

    /**
     * Write a timestamped message to the log file and echo it.
     */
    public function log(string $message): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $line = "[{$timestamp}] {$message}\n";
        file_put_contents($this->logFile, $line, FILE_APPEND);
        echo $line;

        if ($this->runId !== null) {
            $this->output .= $line;
            if (strlen($this->output) > 2 * self::MAX_OUTPUT_BYTES) {
                $this->output = substr($this->output, -self::MAX_OUTPUT_BYTES);
            }
            if (microtime(true) - $this->lastFlush >= self::FLUSH_SECONDS) {
                $this->flush();
            }
        }
    }

    /**
     * Start recording this run in cron_runs. A run started with "Run now" passes --run-id
     * so the queued row created by the web request is picked up.
     */
    public function startRun(string $job): void
    {
        $this->job = $job;
        $this->runStart = microtime(true);
        $this->lastFlush = $this->runStart;
        $now = date('Y-m-d H:i:s');

        try {
            $model = new \App\Models\CronRun();
            $queuedId = 0;
            foreach ($_SERVER['argv'] ?? [] as $arg) {
                if (str_starts_with($arg, '--run-id=')) {
                    $queuedId = (int)substr($arg, 9);
                }
            }

            $queued = $queuedId > 0 ? $model->find($queuedId) : null;
            if ($queued && $queued['job'] === $job && $queued['status'] === 'queued') {
                $model->update($queuedId, ['status' => 'running', 'started_at' => $now, 'heartbeat_at' => $now]);
                $this->runId = $queuedId;
            } else {
                $this->runId = $model->create([
                    'job' => $job,
                    'status' => 'running',
                    'trigger_type' => 'cron',
                    'started_at' => $now,
                    'heartbeat_at' => $now,
                    'created_at' => $now,
                ]);
            }
        } catch (\Throwable $e) {
            $this->runId = null;
            return;
        }

        register_shutdown_function([$this, 'handleShutdown']);
    }

    /**
     * Mark the run finished. With errors the run is "partial" when some items were
     * still checked, otherwise "failed".
     */
    public function finishRun(int $itemsChecked, int $alertsSent, int $errors, array $summary = []): void
    {
        if ($this->runId === null || $this->finished) {
            return;
        }

        $status = 'success';
        if ($errors > 0) {
            $status = $itemsChecked > 0 ? 'partial' : 'failed';
        }

        $this->saveRun([
            'status' => $status,
            'items_checked' => $itemsChecked,
            'alerts_sent' => $alertsSent,
            'errors' => $errors,
            'summary' => json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
        $this->finished = true;

        try {
            (new \App\Services\CronMonitor())->checkAndAlert();
        } catch (\Throwable $e) {
            // Alerts are best effort
        }
    }

    /**
     * Shutdown handler: a run that never reached finishRun() crashed or called exit early
     */
    public function handleShutdown(): void
    {
        if ($this->runId === null || $this->finished) {
            return;
        }

        $error = error_get_last();
        $message = 'Stopped before finishing';
        if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
            $message = $error['message'] . ' in ' . basename($error['file']) . ':' . $error['line'];
            $this->output .= '[' . date('Y-m-d H:i:s') . "] Fatal error: {$message}\n";
        }

        $this->saveRun(['status' => 'failed', 'error_message' => $message]);
        $this->finished = true;
    }

    public function getRunId(): ?int
    {
        return $this->runId;
    }

    private function flush(): void
    {
        $this->lastFlush = microtime(true);
        try {
            (new \App\Models\CronRun())->update($this->runId, [
                'heartbeat_at' => date('Y-m-d H:i:s'),
                'output' => $this->trimmedOutput(),
            ]);
        } catch (\Throwable $e) {
            // Keep the cron job running even if the log row cannot be written
        }
    }

    private function saveRun(array $data): void
    {
        $now = date('Y-m-d H:i:s');
        try {
            (new \App\Models\CronRun())->update($this->runId, array_merge([
                'finished_at' => $now,
                'heartbeat_at' => $now,
                'duration_ms' => (int)round((microtime(true) - $this->runStart) * 1000),
                'output' => $this->trimmedOutput(),
            ], $data));
        } catch (\Throwable $e) {
            // Keep the cron job running even if the log row cannot be written
        }
    }

    private function trimmedOutput(): string
    {
        $output = LogMasker::mask($this->output);
        if (strlen($output) > self::MAX_OUTPUT_BYTES) {
            return "[... earlier output cut, see the log file ...]\n" . substr($output, -self::MAX_OUTPUT_BYTES);
        }
        return $output;
    }

    /**
     * Start a new file each day: yesterday's cron.log becomes cron.2026-01-31.log
     */
    private function rotateLogFile(): void
    {
        if (!is_file($this->logFile)) {
            return;
        }

        $mtime = @filemtime($this->logFile);
        if ($mtime === false || date('Y-m-d', $mtime) === date('Y-m-d')) {
            return;
        }

        $archive = preg_replace('/\.log$/', '', $this->logFile) . '.' . date('Y-m-d', $mtime) . '.log';
        if (!file_exists($archive)) {
            @rename($this->logFile, $archive);
        }
    }

    /**
     * Log elapsed time since a given microtime start.
     */
    public function logTimeSince(float $since, string $prefix = '  ⏱ '): void
    {
        $this->log($prefix . self::formatDuration(microtime(true) - $since));
    }

    /**
     * Short human-readable duration: "3.2s" or "2m 14.1s".
     */
    public static function formatDuration(float $seconds): string
    {
        if ($seconds < 60) {
            return sprintf('%.1fs', $seconds);
        }

        $minutes = (int) floor($seconds / 60);
        $remaining = $seconds - ($minutes * 60);
        return $minutes . 'm ' . sprintf('%.1fs', $remaining);
    }

    /**
     * Verbose elapsed time: "3.25 seconds", "2 minutes 14.25 seconds", etc.
     */
    public static function formatElapsedTime(float $seconds): string
    {
        if ($seconds < 60) {
            return sprintf('%.2f seconds', $seconds);
        }

        if ($seconds < 3600) {
            $minutes = (int) floor($seconds / 60);
            $remaining = $seconds - ($minutes * 60);
            return sprintf('%d minute%s %.2f seconds', $minutes, $minutes !== 1 ? 's' : '', $remaining);
        }

        $hours = (int) floor($seconds / 3600);
        $minutes = (int) floor(($seconds - ($hours * 3600)) / 60);
        $remaining = $seconds - ($hours * 3600) - ($minutes * 60);
        return sprintf(
            '%d hour%s %d minute%s %.2f seconds',
            $hours,
            $hours !== 1 ? 's' : '',
            $minutes,
            $minutes !== 1 ? 's' : '',
            $remaining
        );
    }

    /**
     * Check whether a hostname resolves at all (SOA, A, or AAAA).
     */
    public static function hostnameResolves(string $hostname): bool
    {
        // Trailing dot so the resolver's search suffixes are never tried
        $name = rtrim($hostname, '.') . '.';
        return @checkdnsrr($name, 'SOA')
            || @checkdnsrr($name, 'A')
            || @checkdnsrr($name, 'AAAA');
    }
}
