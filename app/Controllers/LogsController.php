<?php

namespace App\Controllers;

use Core\Auth;
use Core\Controller;
use App\Models\AuditLog;
use App\Models\CommandRun;
use App\Models\CronRun;
use App\Models\ErrorLog;
use App\Models\UpdateHistory;
use App\Services\CommandRunner;
use App\Services\CronMonitor;
use App\Services\LogFileService;
use App\Services\LogRetention;

/**
 * Logs area (admin only): overview, cron runs, updates, commands,
 * notifications sent, activity and log files. Errors stay at /errors.
 */
class LogsController extends Controller
{
    private const PER_PAGE_OPTIONS = [25, 50, 100];

    public function __construct()
    {
        Auth::requireAdmin();
    }

    public function index()
    {
        $health = (new CronMonitor())->getHealth();
        $db = \Core\Database::getConnection();
        $since = date('Y-m-d H:i:s', strtotime('-24 hours'));

        $errorStats = $this->safely(fn() => (new ErrorLog())->getAdminStats(), []);
        $failedNotifications = $this->safely(function () use ($db, $since) {
            $stmt = $db->prepare("SELECT COUNT(*) FROM notification_logs WHERE status = 'failed' AND sent_at >= ?");
            $stmt->execute([$since]);
            return (int)$stmt->fetchColumn();
        }, 0);

        $this->view('logs/index', [
            'activeTab' => 'overview',
            'health' => $health,
            'latestUpdate' => $this->safely(fn() => (new UpdateHistory())->getLatest(), null),
            'errorStats' => $errorStats,
            'failedNotifications' => $failedNotifications,
            'failedSignIns' => $this->safely(fn() => (new AuditLog())->countSince($since, 'auth.login_failed'), 0),
            'recentRuns' => $this->safely(fn() => (new CronRun())->getPaginated([], 8, 0), []),
            'recentActivity' => $this->safely(fn() => (new AuditLog())->getPaginated([], 8, 0), []),
            'retentionDays' => (new LogRetention())->getDays(),
            'tablesReady' => $this->tablesReady(),
        ]);
    }

    // ------------------------------------------------------------------
    // Cron runs
    // ------------------------------------------------------------------

    public function cron()
    {
        $filters = [
            'job' => $this->pick($_GET['job'] ?? '', array_keys(CronMonitor::JOBS)),
            'status' => $this->pick($_GET['status'] ?? '', ['running', 'success', 'partial', 'failed']),
            'trigger' => $this->pick($_GET['trigger'] ?? '', ['cron', 'manual']),
        ];
        $model = new CronRun();
        [$pagination, $limit, $offset] = $this->paginate($this->safely(fn() => $model->countFiltered($filters), 0));

        $this->view('logs/cron', [
            'activeTab' => 'cron',
            'runs' => $this->safely(fn() => $model->getPaginated($filters, $limit, $offset), []),
            'filters' => $filters,
            'pagination' => $pagination,
            'jobs' => CronMonitor::JOBS,
            'health' => (new CronMonitor())->getHealth(),
            'canRunNow' => CommandRunner::canRun(),
            'tablesReady' => $this->tablesReady(),
        ]);
    }

    public function cronShow($params = [])
    {
        $run = $this->safely(fn() => (new CronRun())->findWithUser((int)($params['id'] ?? 0)), null);
        if (!$run) {
            $_SESSION['error'] = 'Cron run not found';
            $this->redirect('/logs/cron');
        }

        $run['summary_data'] = json_decode($run['summary'] ?? '', true) ?: [];
        $this->view('logs/cron-show', [
            'activeTab' => 'cron',
            'run' => $run,
            'job' => CronMonitor::JOBS[$run['job']] ?? ['label' => $run['job'], 'log_file' => null],
        ]);
    }

    /**
     * JSON for the live view of a running job
     */
    public function cronStatus($params = [])
    {
        $run = $this->safely(fn() => (new CronRun())->findWithUser((int)($params['id'] ?? 0)), null);
        if (!$run) {
            $this->json(['error' => 'Not found'], 404);
        }

        $this->json([
            'status' => $run['effective_status'],
            'output' => $run['output'] ?? '',
            'duration_ms' => $run['duration_ms'],
            'items_checked' => (int)$run['items_checked'],
            'alerts_sent' => (int)$run['alerts_sent'],
            'errors' => (int)$run['errors'],
            'finished' => !in_array($run['effective_status'], ['queued', 'running'], true),
        ]);
    }

    /**
     * Start a cron job in the background and open its live view
     */
    public function cronRun()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/logs/cron');
        }
        $this->verifyCsrf('/logs/cron');

        $job = $_POST['job'] ?? '';
        if (!isset(CronMonitor::JOBS[$job])) {
            $_SESSION['error'] = 'Unknown cron job';
            $this->redirect('/logs/cron');
        }
        if (!CommandRunner::canRun()) {
            $_SESSION['error'] = 'This server does not allow PHP to start commands (exec is disabled), so jobs cannot be started from here.';
            $this->redirect('/logs/cron');
        }

        try {
            $model = new CronRun();
            if ($model->hasActiveRun($job)) {
                $_SESSION['warning'] = CronMonitor::JOBS[$job]['label'] . ' is already running.';
                $this->redirect('/logs/cron');
            }

            $now = date('Y-m-d H:i:s');
            $runId = $model->create([
                'job' => $job,
                'status' => 'queued',
                'trigger_type' => 'manual',
                'triggered_by' => Auth::id(),
                'created_at' => $now,
            ]);
        } catch (\PDOException $e) {
            $_SESSION['error'] = 'Run the database update first (Settings → Updates).';
            $this->redirect('/logs/cron');
        }

        $script = realpath(__DIR__ . '/../../cron/' . CronMonitor::JOBS[$job]['script']);
        $started = CommandRunner::spawnPhp($script, ['--run-id=' . $runId], 'Run now: ' . CronMonitor::JOBS[$job]['label'], 'logs');

        AuditLog::record('logs.cron_run', 'Started ' . CronMonitor::JOBS[$job]['label'] . ' from Logs', [
            'entity_type' => 'cron_run',
            'entity_id' => $runId,
        ]);

        if (!$started) {
            (new CronRun())->update($runId, ['status' => 'failed', 'error_message' => 'Could not start the PHP process']);
            $_SESSION['error'] = 'Could not start the job. See Logs → Commands.';
        }

        $this->redirect('/logs/cron/' . $runId);
    }

    // ------------------------------------------------------------------
    // Updates
    // ------------------------------------------------------------------

    public function updates()
    {
        $filters = [
            'action' => $this->pick($_GET['action'] ?? '', ['update', 'rollback', 'migration']),
            'status' => $this->pick($_GET['status'] ?? '', ['running', 'success', 'failed']),
        ];
        $model = new UpdateHistory();
        [$pagination, $limit, $offset] = $this->paginate($this->safely(fn() => $model->countFiltered($filters), 0));

        $this->view('logs/updates', [
            'activeTab' => 'updates',
            'updates' => $this->safely(fn() => $model->getPaginated($filters, $limit, $offset), []),
            'filters' => $filters,
            'pagination' => $pagination,
            'tablesReady' => $this->tablesReady(),
        ]);
    }

    public function updateShow($params = [])
    {
        $update = $this->safely(fn() => (new UpdateHistory())->findWithUser((int)($params['id'] ?? 0)), null);
        if (!$update) {
            $_SESSION['error'] = 'Update not found';
            $this->redirect('/logs/updates');
        }

        $update['details_data'] = json_decode($update['details'] ?? '', true) ?: [];
        $to = $update['finished_at'] ?: date('Y-m-d H:i:s', strtotime($update['started_at']) + 3600);

        $this->view('logs/update-show', [
            'activeTab' => 'updates',
            'update' => $update,
            'commands' => $this->safely(fn() => (new CommandRun())->getBetween($update['started_at'], $to), []),
            'logLines' => $this->updateLogLines($update),
        ]);
    }

    // ------------------------------------------------------------------
    // Commands
    // ------------------------------------------------------------------

    public function commands()
    {
        $model = new CommandRun();
        $sources = $this->safely(fn() => $model->getSources(), []);
        $filters = [
            'source' => $this->pick($_GET['source'] ?? '', $sources),
            'result' => $this->pick($_GET['result'] ?? '', ['ok', 'failed']),
        ];
        [$pagination, $limit, $offset] = $this->paginate($this->safely(fn() => $model->countFiltered($filters), 0));

        $this->view('logs/commands', [
            'activeTab' => 'commands',
            'commands' => $this->safely(fn() => $model->getPaginated($filters, $limit, $offset), []),
            'sources' => $sources,
            'filters' => $filters,
            'pagination' => $pagination,
            'tablesReady' => $this->tablesReady(),
        ]);
    }

    public function commandShow($params = [])
    {
        $command = $this->safely(fn() => (new CommandRun())->findWithUser((int)($params['id'] ?? 0)), null);
        if (!$command) {
            $_SESSION['error'] = 'Command not found';
            $this->redirect('/logs/commands');
        }

        $this->view('logs/command-show', ['activeTab' => 'commands', 'command' => $command]);
    }

    // ------------------------------------------------------------------
    // Notifications sent
    // ------------------------------------------------------------------

    public function notifications()
    {
        $db = \Core\Database::getConnection();
        $channels = $this->safely(fn() => $db->query("SELECT DISTINCT channel_type FROM notification_logs ORDER BY channel_type")->fetchAll(\PDO::FETCH_COLUMN), []);
        $filters = [
            'status' => $this->pick($_GET['status'] ?? '', ['sent', 'failed']),
            'channel' => $this->pick($_GET['channel'] ?? '', $channels),
            'q' => $this->query('q'),
        ];

        $where = [];
        $params = [];
        if ($filters['status'] !== '') {
            $where[] = 'nl.status = ?';
            $params[] = $filters['status'];
        }
        if ($filters['channel'] !== '') {
            $where[] = 'nl.channel_type = ?';
            $params[] = $filters['channel'];
        }
        if ($filters['q'] !== '') {
            $where[] = '(d.domain_name LIKE ? OR nl.message LIKE ? OR nl.notification_type LIKE ?)';
            array_push($params, "%{$filters['q']}%", "%{$filters['q']}%", "%{$filters['q']}%");
        }
        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $total = $this->safely(function () use ($db, $whereSql, $params) {
            $stmt = $db->prepare("SELECT COUNT(*) FROM notification_logs nl LEFT JOIN domains d ON d.id = nl.domain_id $whereSql");
            $stmt->execute($params);
            return (int)$stmt->fetchColumn();
        }, 0);
        [$pagination, $limit, $offset] = $this->paginate($total);

        $logs = $this->safely(function () use ($db, $whereSql, $params, $limit, $offset) {
            $stmt = $db->prepare(
                "SELECT nl.*, d.domain_name FROM notification_logs nl
                 LEFT JOIN domains d ON d.id = nl.domain_id
                 $whereSql ORDER BY nl.id DESC LIMIT ? OFFSET ?"
            );
            $stmt->execute([...$params, $limit, $offset]);
            return $stmt->fetchAll();
        }, []);

        $this->view('logs/notifications', [
            'activeTab' => 'notifications',
            'logs' => $logs,
            'channels' => $channels,
            'filters' => $filters,
            'pagination' => $pagination,
        ]);
    }

    // ------------------------------------------------------------------
    // Activity
    // ------------------------------------------------------------------

    public function activity()
    {
        $model = new AuditLog();
        $usernames = $this->safely(fn() => $model->getUsernames(), []);
        $filters = [
            'category' => $this->pick($_GET['category'] ?? '', array_keys(AuditLog::CATEGORIES)),
            'user' => $this->pick($_GET['user'] ?? '', $usernames),
            'q' => $this->query('q'),
        ];
        [$pagination, $limit, $offset] = $this->paginate($this->safely(fn() => $model->countFiltered($filters), 0));

        $entries = $this->safely(fn() => $model->getPaginated($filters, $limit, $offset), []);
        foreach ($entries as &$entry) {
            $entry['changes_data'] = json_decode($entry['changes'] ?? '', true) ?: [];
        }
        unset($entry);

        $this->view('logs/activity', [
            'activeTab' => 'activity',
            'entries' => $entries,
            'categories' => AuditLog::CATEGORIES,
            'usernames' => $usernames,
            'filters' => $filters,
            'pagination' => $pagination,
            'tablesReady' => $this->tablesReady(),
        ]);
    }

    // ------------------------------------------------------------------
    // Log files
    // ------------------------------------------------------------------

    public function files()
    {
        $service = new LogFileService();
        $files = $service->listFiles();
        $channel = $this->pick($_GET['channel'] ?? '', $service->getChannels($files));
        if ($channel !== '') {
            $files = array_values(array_filter($files, fn($f) => $f['channel'] === $channel));
        }

        $this->view('logs/files', [
            'activeTab' => 'files',
            'files' => $files,
            'channels' => $service->getChannels($service->listFiles()),
            'filters' => ['channel' => $channel],
            'totalSize' => array_sum(array_column($files, 'size')),
            'retentionDays' => (new LogRetention())->getDays(),
        ]);
    }

    public function fileView()
    {
        $service = new LogFileService();
        $name = $this->query('file');
        $path = $service->resolve($name);
        if (!$path) {
            $_SESSION['error'] = 'Log file not found';
            $this->redirect('/logs/files');
        }

        $lines = (int)($_GET['lines'] ?? 500);
        $lines = in_array($lines, [100, 500, 2000, 5000], true) ? $lines : 500;
        $level = $this->pick(strtoupper($this->query('level')), ['INFO', 'WARNING', 'ERROR']);
        $search = $this->query('q');

        $this->view('logs/file-view', [
            'activeTab' => 'files',
            'file' => [
                'name' => basename($path),
                'size' => (int)filesize($path),
                'modified' => (int)filemtime($path),
            ],
            'content' => $service->read($path, $lines, $level, $search),
            'filters' => ['file' => basename($path), 'lines' => $lines, 'level' => $level, 'q' => $search],
        ]);
    }

    public function fileDownload()
    {
        $service = new LogFileService();
        $path = $service->resolve($this->query('file'));
        if (!$path) {
            $_SESSION['error'] = 'Log file not found';
            $this->redirect('/logs/files');
        }

        $service->download($path);
    }

    public function fileDelete()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/logs/files');
        }
        $this->verifyCsrf('/logs/files');

        $service = new LogFileService();
        $deleted = 0;
        foreach ((array)($_POST['files'] ?? []) as $name) {
            $path = $service->resolve((string)$name);
            if ($path && $service->delete($path)) {
                $deleted++;
            }
        }

        if ($deleted > 0) {
            AuditLog::record('logs.files_deleted', "Deleted {$deleted} log file(s)", [
                'changes' => ['files' => array_values(array_map('strval', (array)$_POST['files']))],
            ]);
        }

        $_SESSION['success'] = "Deleted {$deleted} log file(s)";
        $this->redirect('/logs/files');
    }

    /**
     * Run the retention cleanup now
     */
    public function cleanup()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/logs');
        }
        $this->verifyCsrf('/logs');

        $retention = new LogRetention();
        if ($retention->getDays() === 0) {
            $_SESSION['info'] = 'Logs are kept forever (Settings → Maintenance → Keep logs for). Nothing was deleted.';
            $this->redirect('/logs');
        }

        $deleted = $retention->cleanup();
        $total = array_sum($deleted);
        AuditLog::record('logs.cleanup', "Cleaned up logs older than {$retention->getDays()} days ({$total} item(s))", [
            'changes' => $deleted,
        ]);

        $_SESSION['success'] = "Deleted {$total} item(s) older than {$retention->getDays()} days.";
        $this->redirect('/logs');
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Lines of the updater log written during an update
     */
    private function updateLogLines(array $update): array
    {
        if (empty($update['log_file'])) {
            return [];
        }

        $service = new LogFileService();
        $path = $service->resolve($update['log_file']);
        if (!$path) {
            return [];
        }

        $from = $update['started_at'];
        $to = $update['finished_at'] ?: date('Y-m-d H:i:s', strtotime($from) + 3600);
        $lines = [];
        foreach ($service->read($path, 5000)['lines'] as $line) {
            if (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]/', $line['text'], $m) && $m[1] >= $from && $m[1] <= $to) {
                $lines[] = $line;
            }
        }
        return $lines;
    }

    private function paginate(int $total): array
    {
        $perPage = (int)($_GET['per_page'] ?? 25);
        $perPage = in_array($perPage, self::PER_PAGE_OPTIONS, true) ? $perPage : 25;
        $totalPages = max(1, (int)ceil($total / $perPage));
        $page = min(max(1, (int)($_GET['page'] ?? 1)), $totalPages);
        $offset = ($page - 1) * $perPage;

        return [[
            'current_page' => $page,
            'total_pages' => $totalPages,
            'per_page' => $perPage,
            'total' => $total,
            'showing_from' => $total > 0 ? $offset + 1 : 0,
            'showing_to' => min($offset + $perPage, $total),
        ], $perPage, $offset];
    }

    private function pick($value, array $allowed): string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : '';
    }

    private function query(string $key): string
    {
        return is_string($_GET[$key] ?? null) ? trim($_GET[$key]) : '';
    }

    /**
     * Run a query that fails when the 1.4.0 migration has not run yet
     */
    private function safely(callable $fn, $fallback)
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            return $fallback;
        }
    }

    private function tablesReady(): bool
    {
        return $this->safely(function () {
            \Core\Database::getConnection()->query("SELECT 1 FROM cron_runs LIMIT 1");
            return true;
        }, false);
    }
}
