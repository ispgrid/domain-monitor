<?php

namespace App\Services;

use App\Helpers\LogMasker;
use App\Models\CommandRun;

/**
 * Runs shell commands and records each run (command, exit code, duration, output)
 * for Logs → Commands. Secrets are masked before anything is stored.
 */
class CommandRunner
{
    private const MAX_OUTPUT_BYTES = 65536;

    /**
     * exec() that records the run. $output and $exitCode are filled like exec() does.
     */
    public static function exec(string $command, ?array &$output, ?int &$exitCode, string $label, string $source = 'app', array $secrets = []): void
    {
        $output = [];
        $exitCode = 0;
        $startedAt = date('Y-m-d H:i:s');
        $start = microtime(true);

        exec($command, $output, $exitCode);

        self::record($source, $label, $command, $exitCode, implode("\n", $output), $start, $startedAt, $secrets);
    }

    /**
     * Start a PHP script in the background. Returns true when the launcher reported success.
     */
    public static function spawnPhp(string $script, array $args, string $label, string $source = 'app'): bool
    {
        $php = self::phpBinary();
        $parts = array_map('escapeshellarg', array_merge([$script], $args));
        $startedAt = date('Y-m-d H:i:s');
        $start = microtime(true);

        if (PHP_OS_FAMILY === 'Windows') {
            // popen + start /B returns at once without waiting for the child's output
            $command = 'start "" /B ' . escapeshellarg($php) . ' ' . implode(' ', $parts) . ' > NUL 2>&1';
            $handle = @popen($command, 'r');
            $exitCode = $handle === false ? 1 : (pclose($handle) === -1 ? 1 : 0);
        } else {
            $command = 'nohup ' . escapeshellarg($php) . ' ' . implode(' ', $parts) . ' > /dev/null 2>&1 &';
            $output = [];
            $exitCode = 0;
            exec($command, $output, $exitCode);
        }

        self::record($source, $label, $command, $exitCode, '', $start, $startedAt);
        return $exitCode === 0;
    }

    /**
     * The PHP CLI binary. Under PHP-FPM or Apache, PHP_BINARY is not the CLI, so fall back to "php" on PATH.
     */
    public static function phpBinary(): string
    {
        if (PHP_BINARY !== '' && preg_match('/^php(\d+(\.\d+)*)?(\.exe)?$/i', basename(PHP_BINARY))) {
            return PHP_BINARY;
        }

        $candidate = PHP_BINDIR . DIRECTORY_SEPARATOR . (PHP_OS_FAMILY === 'Windows' ? 'php.exe' : 'php');
        if (@is_file($candidate)) {
            return $candidate;
        }

        return 'php';
    }

    public static function canRun(): bool
    {
        if (!function_exists('exec')) {
            return false;
        }
        $disabled = array_map('trim', explode(',', strtolower((string)ini_get('disable_functions'))));
        return !in_array('exec', $disabled, true);
    }

    private static function record(string $source, string $label, string $command, int $exitCode, string $output, float $start, string $startedAt, array $secrets = []): void
    {
        try {
            $output = LogMasker::mask($output, $secrets);
            if (strlen($output) > self::MAX_OUTPUT_BYTES) {
                $output = "[... earlier output cut ...]\n" . substr($output, -self::MAX_OUTPUT_BYTES);
            }

            (new CommandRun())->create([
                'source' => $source,
                'label' => mb_substr($label, 0, 100),
                'command' => LogMasker::mask($command, $secrets),
                'exit_code' => $exitCode,
                'duration_ms' => (int)round((microtime(true) - $start) * 1000),
                'output' => $output,
                'user_id' => session_status() === PHP_SESSION_ACTIVE ? \Core\Auth::id() : null,
                'started_at' => $startedAt,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // Table may not exist yet (code updated before the migration ran)
        }
    }
}
