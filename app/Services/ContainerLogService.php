<?php

namespace App\Services;

/**
 * Log files the Docker image writes into logs/ (the /data volume), shown in Logs → Container.
 * The app never reads `docker logs` itself: that would need the Docker socket, which gives
 * root on the host.
 */
class ContainerLogService
{
    public const FILES = [
        'startup' => [
            'file' => 'container-startup.log',
            'label' => 'Startup',
            'icon' => 'fa-power-off',
            'description' => 'What the container did when it started: settings written, folders prepared, scheduler started.',
        ],
        'scheduler' => [
            'file' => 'scheduler.log',
            'label' => 'Scheduler',
            'icon' => 'fa-clock',
            'description' => 'The check every 15 minutes that starts the domain, SSL and DNS checks when they are due.',
        ],
        'web-errors' => [
            'file' => 'container-apache-error.log',
            'label' => 'Web server errors',
            'icon' => 'fa-triangle-exclamation',
            'description' => 'Apache and PHP errors, including ones that happen before the app can log them itself.',
        ],
        'access' => [
            'file' => 'container-apache-access.log',
            'label' => 'Access log',
            'icon' => 'fa-globe',
            'description' => 'Every request with the visitor\'s IP address. Off unless ACCESS_LOG_FILE=true is set for the container.',
        ],
    ];

    // Files are cut back to the newest TRIM_TO bytes once they pass MAX_BYTES
    public const MAX_BYTES = 5242880;
    private const TRIM_TO = 4194304;

    private LogFileService $files;

    public function __construct(?LogFileService $files = null)
    {
        $this->files = $files ?? new LogFileService();
    }

    /**
     * Full path of a container log, or null when the file does not exist (yet)
     */
    public function path(string $key): ?string
    {
        if (!isset(self::FILES[$key])) {
            return null;
        }
        return $this->files->resolve(self::FILES[$key]['file']);
    }

    /**
     * Keep each file under MAX_BYTES. Apache opens its logs in append mode, so rewriting
     * a file in place is safe while it is still being written to.
     * Returns the names of the files that were trimmed.
     */
    public function trim(): array
    {
        $trimmed = [];
        foreach (self::FILES as $key => $info) {
            $path = $this->path($key);
            if ($path === null || filesize($path) <= self::MAX_BYTES) {
                continue;
            }

            $handle = @fopen($path, 'r+');
            if (!$handle) {
                continue;
            }
            if (flock($handle, LOCK_EX)) {
                $size = filesize($path);
                fseek($handle, $size - self::TRIM_TO);
                $tail = (string)fread($handle, self::TRIM_TO);
                // Start at a whole line
                $newline = strpos($tail, "\n");
                $tail = $newline === false ? $tail : substr($tail, $newline + 1);

                ftruncate($handle, 0);
                rewind($handle);
                fwrite($handle, '[' . date('Y-m-d H:i:s') . "] [INFO] Older lines removed to keep this file under " . (self::MAX_BYTES >> 20) . " MB\n" . $tail);
                fflush($handle);
                flock($handle, LOCK_UN);
                $trimmed[] = $info['file'];
            }
            fclose($handle);
        }
        return $trimmed;
    }
}
