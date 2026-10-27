<?php

namespace App\Services;

use App\Helpers\LogMasker;

/**
 * Read-only access to the files in logs/ for Logs → Files.
 * Only plain file names inside logs/ are accepted.
 */
class LogFileService
{
    private const NAME_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_.-]*\.log$/';
    private const DATE_PATTERN = '/[._](\d{4}-\d{2}-\d{2})\.log$/';
    private const LEVELS = ['DEBUG' => 0, 'INFO' => 1, 'WARNING' => 2, 'ERROR' => 3, 'CRITICAL' => 4];

    private string $logDir;

    public function __construct()
    {
        $this->logDir = realpath(__DIR__ . '/../../logs') ?: (__DIR__ . '/../../logs');
    }

    public function getLogDir(): string
    {
        return $this->logDir;
    }

    /**
     * All log files, newest first: name, channel, date, size, modified
     */
    public function listFiles(): array
    {
        $files = [];
        foreach (glob($this->logDir . DIRECTORY_SEPARATOR . '*.log') ?: [] as $path) {
            $name = basename($path);
            if (!preg_match(self::NAME_PATTERN, $name) || !is_file($path)) {
                continue;
            }

            $date = preg_match(self::DATE_PATTERN, $name, $m) ? $m[1] : null;
            $files[] = [
                'name' => $name,
                'channel' => self::channelOf($name),
                'date' => $date,
                'size' => (int)filesize($path),
                'modified' => (int)filemtime($path),
            ];
        }

        usort($files, fn($a, $b) => $b['modified'] <=> $a['modified'] ?: strcmp($a['name'], $b['name']));
        return $files;
    }

    public function getChannels(array $files): array
    {
        $channels = array_values(array_unique(array_column($files, 'channel')));
        sort($channels);
        return $channels;
    }

    /**
     * Full path of a log file, or null when the name is not a log file in logs/
     */
    public function resolve(string $name): ?string
    {
        if (!preg_match(self::NAME_PATTERN, $name)) {
            return null;
        }

        $path = realpath($this->logDir . DIRECTORY_SEPARATOR . $name);
        if ($path === false || !is_file($path) || dirname($path) !== $this->logDir) {
            return null;
        }

        return $path;
    }

    /**
     * The last lines of a file (masked), filtered by minimum level and search text.
     * Returns ['lines' => [...], 'truncated' => bool]
     */
    public function read(string $path, int $maxLines = 500, string $minLevel = '', string $search = ''): array
    {
        $minRank = self::LEVELS[strtoupper($minLevel)] ?? null;
        $filtering = $minRank !== null || $search !== '';

        // When filtering, scan more of the file so enough matching lines are found
        $raw = $this->tail($path, $filtering ? 20000 : $maxLines, $readAll);

        $lines = [];
        foreach ($raw as $line) {
            if ($minRank !== null) {
                $level = self::levelOf($line);
                if ($level === null || self::LEVELS[$level] < $minRank) {
                    continue;
                }
            }
            if ($search !== '' && stripos($line, $search) === false) {
                continue;
            }
            $lines[] = [
                'text' => LogMasker::mask($line),
                'level' => self::levelOf($line),
            ];
        }

        $truncated = !$readAll || count($lines) > $maxLines;
        return ['lines' => array_slice($lines, -$maxLines), 'truncated' => $truncated];
    }

    /**
     * Send the masked file as a download
     */
    public function download(string $path): void
    {
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . basename($path) . '"');
        header('X-Content-Type-Options: nosniff');

        $handle = fopen($path, 'r');
        if ($handle) {
            while (($line = fgets($handle)) !== false) {
                echo LogMasker::mask($line);
            }
            fclose($handle);
        }
        exit;
    }

    public function delete(string $path): bool
    {
        return @unlink($path);
    }

    /**
     * Delete dated log files older than the cutoff date (Y-m-d). Returns the number deleted.
     */
    public function deleteOlderThan(string $cutoffDate): int
    {
        $deleted = 0;
        foreach ($this->listFiles() as $file) {
            if ($file['date'] !== null && $file['date'] < $cutoffDate) {
                $path = $this->resolve($file['name']);
                if ($path && @unlink($path)) {
                    $deleted++;
                }
            }
        }
        return $deleted;
    }

    public static function channelOf(string $name): string
    {
        return preg_replace(['/[._]\d{4}-\d{2}-\d{2}\.log$/', '/\.log$/'], '', $name);
    }

    public static function levelOf(string $line): ?string
    {
        if (preg_match('/^\[[^\]]+\]\s+\[(DEBUG|INFO|WARNING|ERROR|CRITICAL)\]/', $line, $m)) {
            return $m[1];
        }
        return null;
    }

    /**
     * Last $count lines of a file without loading all of it. $readAll is set when the whole file was read.
     */
    private function tail(string $path, int $count, ?bool &$readAll = null): array
    {
        $readAll = true;
        $handle = @fopen($path, 'r');
        if (!$handle) {
            return [];
        }

        $size = (int)filesize($path);
        $chunk = 65536;
        $pos = $size;
        $data = '';

        while ($pos > 0 && substr_count($data, "\n") <= $count) {
            $read = min($chunk, $pos);
            $pos -= $read;
            fseek($handle, $pos);
            $data = fread($handle, $read) . $data;
        }
        fclose($handle);

        $lines = preg_split('/\r?\n/', rtrim($data, "\r\n"));
        if ($pos > 0) {
            array_shift($lines); // first line is probably cut
        }
        if (count($lines) > $count) {
            $lines = array_slice($lines, -$count);
            $readAll = false;
        } elseif ($pos > 0) {
            $readAll = false;
        }

        return $lines === [''] ? [] : $lines;
    }
}
