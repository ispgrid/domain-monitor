<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\UpdateHistory;
use GuzzleHttp\Client;

class UpdateService
{
    private const GITHUB_REPO = 'ispgrid/domain-monitor';
    private const GITHUB_API_BASE = 'https://api.github.com';
    private const CACHE_TTL_HOURS = 1;

    private const PROTECTED_PATHS = [
        '.env',
        '.installed',
        'vendor',
        'logs',
        'storage',
        'domain-monitor-docker',
        '.git',
        '.gitignore',
        'node_modules',
    ];

    private Setting $settingModel;
    private Logger $logger;
    private Client $httpClient;
    private string $rootPath;

    // Full commit SHA GitHub stores as the zipball's archive comment (null if missing)
    private ?string $archiveCommitSha = null;

    public function __construct()
    {
        $this->settingModel = new Setting();
        $this->logger = new Logger('updater');
        $this->rootPath = realpath(__DIR__ . '/../../');
        $this->httpClient = new Client([
            'timeout' => 30,
            'headers' => [
                'Accept' => 'application/vnd.github.v3+json',
                'User-Agent' => 'DomainMonitor/' . $this->settingModel->getAppVersion(),
            ],
        ]);
    }

    /**
     * Check for available updates based on the user's chosen update channel
     * Returns structured info about what's available
     */
    public function checkForUpdate(bool $forceCheck = false): array
    {
        // Docker images are only built for releases, so hotfix commits are never offered there
        $channel = \App\Helpers\DeploymentHelper::isDocker() ? 'stable' : $this->settingModel->getValue('update_channel', 'stable');
        $currentVersion = $this->settingModel->getAppVersion();
        $localSha = $this->settingModel->getValue('installed_commit_sha', null);

        // Check cache (unless forced)
        if (!$forceCheck) {
            $lastCheck = $this->settingModel->getValue('last_update_check', null);
            if ($lastCheck && $this->isCacheValid($lastCheck)) {
                return $this->getCachedResult($currentVersion, $channel, $localSha);
            }
        }

        $this->logger->info('Checking for updates', [
            'channel' => $channel,
            'current_version' => $currentVersion,
            'local_sha' => $localSha ? substr($localSha, 0, 7) : 'unknown',
        ]);

        $result = [
            'available' => false,
            'type' => null,
            'current_version' => $currentVersion,
            'channel' => $channel,
            'error' => null,
        ];

        try {
            // Always check for tagged releases
            $release = $this->fetchLatestRelease();

            if ($release) {
                $latestVersion = ltrim($release['tag_name'], 'v');

                $result['latest_version'] = $latestVersion;
                $result['release_notes'] = $release['body'] ?? '';
                $result['release_url'] = $release['html_url'] ?? '';
                $result['published_at'] = $release['published_at'] ?? null;
                $result['download_url'] = $release['zipball_url'] ?? null;

                if (version_compare($latestVersion, $currentVersion, '>')) {
                    $result['available'] = true;
                    $result['type'] = 'release';
                }

                // Cache release info
                $this->settingModel->setValue('latest_available_version', $latestVersion);
                $this->settingModel->setValue('latest_release_notes', $release['body'] ?? '');
                $this->settingModel->setValue('latest_release_url', $release['html_url'] ?? '');
                $this->settingModel->setValue('latest_release_published_at', $release['published_at'] ?? '');
            }

            // If on "latest" channel, also check for untagged commits
            if ($channel === 'latest' && $localSha) {
                $commits = $this->fetchCommitsSince($localSha);

                if ($commits !== null && !empty($commits)) {
                    // If there's no new version release but there ARE new commits, it's a hotfix
                    if (!$result['available']) {
                        $result['available'] = true;
                        $result['type'] = 'hotfix';
                    }

                    $result['commits_behind'] = count($commits);
                    $result['commit_messages'] = array_map(function ($c) {
                        return [
                            'sha' => substr($c['sha'], 0, 7),
                            'message' => $c['commit']['message'] ?? '',
                            'author' => $c['commit']['author']['name'] ?? 'Unknown',
                            'date' => $c['commit']['author']['date'] ?? null,
                        ];
                    }, array_slice($commits, 0, 20)); // Limit to 20 most recent

                    $result['remote_sha'] = $commits[0]['sha'] ?? null;

                    // Cache commit info
                    $this->settingModel->setValue('latest_remote_sha', $result['remote_sha'] ?? '');
                    $this->settingModel->setValue('commits_behind_count', count($commits));
                } else {
                    // No new commits — explicitly clear stale cache so the UI
                    // doesn't keep showing "update available" after a hotfix was applied.
                    $this->settingModel->setValue('commits_behind_count', '0');
                    $this->settingModel->setValue('latest_remote_sha', '');
                }
            } elseif ($channel === 'latest' && !$localSha) {
                $result['commit_tracking_unavailable'] = true;
            }

            // Update last check timestamp
            $this->settingModel->setValue('last_update_check', date('Y-m-d H:i:s'));

            $this->logger->info('Update check completed', [
                'available' => $result['available'],
                'type' => $result['type'],
                'latest_version' => $result['latest_version'] ?? 'N/A',
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Update check failed', [
                'error' => $e->getMessage(),
            ]);
            $result['error'] = 'Failed to check for updates: ' . $e->getMessage();
        }

        return $result;
    }

    /**
     * Download and apply an update (release or hotfix), recorded in Logs → Updates
     */
    public function performUpdate(string $type = 'release'): array
    {
        $start = microtime(true);
        $historyId = UpdateHistory::begin('update', [
            'update_type' => $type,
            'from_version' => $this->settingModel->getAppVersion(),
            'log_file' => basename($this->logger->getLogFile()),
        ]);

        $result = $this->applyUpdate($type);

        UpdateHistory::finish($historyId, $result['success'] ? 'success' : 'failed', [
            'to_version' => $result['to_version'] ?? null,
            'files_updated' => $result['files_updated'],
            'composer_status' => $result['composer_status'] ?? null,
            'db_backup_path' => $result['db_backup_path'] ?? null,
            'file_backup_path' => $result['backup_path'],
            'error_message' => $result['errors'] ? implode('; ', $result['errors']) : null,
            'details' => array_filter([
                'db_backup_warning' => $result['db_backup_warning'] ?? null,
                'composer_manual_required' => !empty($result['composer_manual_required']),
            ]),
        ], $start);

        return $result;
    }

    private function applyUpdate(string $type): array
    {
        $this->logger->startOperation('Application Update');

        $result = [
            'success' => false,
            'from_version' => $this->settingModel->getAppVersion(),
            'files_updated' => 0,
            'backup_path' => null,
            'errors' => [],
        ];

        try {
            // Step 1: Pre-flight checks
            $this->logger->info('Running pre-flight checks');
            $preflight = $this->preflightChecks();
            if (!$preflight['pass']) {
                $result['errors'] = $preflight['errors'];
                return $result;
            }

            // Step 2: Determine download URL
            $downloadUrl = $this->getDownloadUrl($type);
            if (!$downloadUrl) {
                $result['errors'][] = 'Could not determine download URL for update';
                return $result;
            }

            // Step 3a: Create database backup
            $this->logger->info('Creating database backup');
            $dbBackupResult = $this->createDatabaseBackup();
            if ($dbBackupResult['success']) {
                $result['db_backup_path'] = $dbBackupResult['path'];
                $this->settingModel->setValue('update_db_backup_path', $dbBackupResult['path']);
                $this->logger->info('Database backup created', ['path' => $dbBackupResult['path'], 'method' => $dbBackupResult['method']]);
            } else {
                $this->logger->warning('Database backup skipped: ' . $dbBackupResult['reason']);
                $result['db_backup_warning'] = $dbBackupResult['reason'];
            }

            // Step 3b: Create file backup
            $this->logger->info('Creating file backup');
            $backupPath = $this->createBackup();
            $result['backup_path'] = $backupPath;
            $this->settingModel->setValue('update_backup_path', $backupPath);

            // Step 4: Download the archive
            $this->logger->info('Downloading update', ['url' => $downloadUrl]);
            $archivePath = $this->downloadArchive($downloadUrl);

            // Step 5: Extract to staging directory
            $this->logger->info('Extracting archive');
            $stagingDir = $this->extractArchive($archivePath);

            // Step 5b: Verify extracted archive matches expected commit (integrity check)
            $this->verifyExtractedCommitSha($stagingDir, $type);

            // Step 5c: Stop before touching any files if this server's PHP is too old for the new version
            $phpError = $this->checkPhpRequirement($stagingDir);
            if ($phpError !== null) {
                $this->logger->warning('Update blocked: ' . $phpError);
                $this->cleanup($archivePath, $stagingDir);
                $result['errors'][] = $phpError;
                return $result;
            }

            // Step 5d: Check if composer dependencies changed (before we overwrite root)
            $composerChanged = $this->checkComposerChanged($stagingDir);

            // Step 6: Copy files (respecting protected paths)
            $this->logger->info('Applying update files');
            $filesUpdated = $this->applyFiles($stagingDir);
            $result['files_updated'] = $filesUpdated;
            $this->clearTemplateCache();

            // Step 7: Update commit SHA tracking. Done before composer install: once vendor is
            // replaced, classes loaded earlier in this request no longer match the files on disk
            $this->updateCommitSha($type);

            // Step 8: Run composer install if dependencies changed (skip if exec disabled, e.g. cPanel)
            $result['composer_manual_required'] = false;
            $result['composer_status'] = 'not_needed';
            if ($composerChanged) {
                if (!$this->canRunShellCommands()) {
                    $this->logger->warning('Composer dependencies changed but shell commands are disabled (e.g. exec in disable_functions). Run composer install manually.');
                    $result['composer_manual_required'] = true;
                    $result['composer_status'] = 'manual';
                } elseif (!$this->runComposerInstall()) {
                    $result['composer_manual_required'] = true;
                    $result['composer_status'] = 'failed';
                } else {
                    $result['composer_status'] = 'ran';
                }
                if ($result['composer_manual_required']) {
                    $this->logger->info('Composer manual action required. If dependencies changed, run: composer install --no-dev (e.g. via SSH or cPanel Terminal).');
                }
            }

            // Step 9: Clean up
            $this->cleanup($archivePath, $stagingDir);

            $result['success'] = true;
            // Report the version we actually applied (DB app_version only changes after migrations)
            $result['to_version'] = $type === 'release'
                ? ($this->settingModel->getValue('latest_available_version') ?: $this->settingModel->getAppVersion())
                : ($this->settingModel->getValue('latest_remote_sha') ? substr($this->settingModel->getValue('latest_remote_sha'), 0, 7) : 'latest');

            // Clear cached update state so the UI no longer shows a stale "update available" card
            $this->clearUpdateCache();

            $this->logger->endOperation('Application Update', [
                'success' => true,
                'files_updated' => $filesUpdated,
                'composer_updated' => $composerChanged,
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Update failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $result['errors'][] = $e->getMessage();

            // Attempt rollback
            if (!empty($result['backup_path'])) {
                $this->logger->info('Attempting rollback');
                try {
                    $this->restoreBackup($result['backup_path']);
                    $result['errors'][] = 'Update failed but rollback was successful';
                } catch (\Exception $rollbackError) {
                    $result['errors'][] = 'Rollback also failed: ' . $rollbackError->getMessage();
                }
            }
        }

        return $result;
    }

    /**
     * Rollback to last backup
     */
    public function rollback(): array
    {
        $backupPath = $this->settingModel->getValue('update_backup_path', null);

        if (!$backupPath || !file_exists($backupPath)) {
            return [
                'success' => false,
                'error' => 'No backup available for rollback',
            ];
        }

        $start = microtime(true);
        $dbBackupPath = $this->settingModel->getValue('update_db_backup_path', null);
        $historyId = UpdateHistory::begin('rollback', [
            'from_version' => $this->settingModel->getAppVersion(),
            'to_version' => $dbBackupPath && preg_match('/db_backup_v([^_]+)_/', basename($dbBackupPath), $m) ? $m[1] : null,
            'db_backup_path' => $dbBackupPath ?: null,
            'file_backup_path' => $backupPath,
            'log_file' => basename($this->logger->getLogFile()),
        ]);

        try {
            $this->logger->startOperation('Rollback');

            // Restore database first (if backup exists)
            if ($dbBackupPath && file_exists($dbBackupPath)) {
                $this->logger->info('Restoring database from backup', ['path' => $dbBackupPath]);
                // The backup predates this rollback, so keep the log rows written since then
                $logRows = $this->snapshotLogRows((int)filemtime($dbBackupPath));
                $dbRestored = $this->restoreDatabaseBackup($dbBackupPath);
                if (!$dbRestored) {
                    $this->logger->warning('Database restore failed or skipped. SQL file is still available for manual import.', ['path' => $dbBackupPath]);
                } else {
                    $this->restoreLogRows($logRows);
                }
            } else {
                $this->logger->info('No database backup found, restoring files only');
            }

            // Restore files
            $this->restoreBackup($backupPath);
            $this->clearTemplateCache();
            $this->logger->endOperation('Rollback', ['success' => true]);

            UpdateHistory::finish($historyId, 'success', [
                'details' => ['db_restored' => $dbRestored ?? null],
            ], $start);

            return ['success' => true, 'db_restored' => isset($dbRestored) ? $dbRestored : null];
        } catch (\Exception $e) {
            $this->logger->error('Rollback failed', ['error' => $e->getMessage()]);
            UpdateHistory::finish($historyId, 'failed', ['error_message' => $e->getMessage()], $start);
            return [
                'success' => false,
                'error' => 'Rollback failed: ' . $e->getMessage(),
            ];
        }
    }

    // ========================================================================
    // Private: GitHub API methods
    // ========================================================================

    /**
     * Fetch the latest release from GitHub
     */
    private function fetchLatestRelease(): ?array
    {
        try {
            $url = self::GITHUB_API_BASE . '/repos/' . self::GITHUB_REPO . '/releases/latest';
            $response = $this->httpClient->get($url);
            return json_decode($response->getBody()->getContents(), true);
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            if ($e->getResponse()->getStatusCode() === 404) {
                // No releases yet
                return null;
            }
            throw $e;
        }
    }

    /**
     * Fetch commits on main since a given SHA
     * Uses the compare API: /repos/{owner}/{repo}/compare/{base}...{head}
     */
    private function fetchCommitsSince(string $sinceCommitSha): ?array
    {
        try {
            $url = self::GITHUB_API_BASE . '/repos/' . self::GITHUB_REPO
                . '/compare/' . $sinceCommitSha . '...main';
            $response = $this->httpClient->get($url);
            $data = json_decode($response->getBody()->getContents(), true);

            if (isset($data['status']) && $data['status'] === 'identical') {
                return [];
            }

            return $data['commits'] ?? [];
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            if ($e->getResponse()->getStatusCode() === 404) {
                $this->logger->warning('Commit comparison failed - SHA may not exist on remote', [
                    'sha' => substr($sinceCommitSha, 0, 7),
                ]);
                return null;
            }
            throw $e;
        }
    }

    /**
     * Get the latest commit SHA from the main branch
     */
    private function fetchLatestCommitSha(): ?string
    {
        try {
            $url = self::GITHUB_API_BASE . '/repos/' . self::GITHUB_REPO . '/commits/main';
            $response = $this->httpClient->get($url);
            $data = json_decode($response->getBody()->getContents(), true);
            return $data['sha'] ?? null;
        } catch (\Exception $e) {
            $this->logger->warning('Failed to fetch latest commit SHA', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    // ========================================================================
    // Private: Cache methods
    // ========================================================================

    /**
     * Clear all cached update-check state so the UI no longer shows stale "update available" info.
     * Called after a successful update is applied.
     */
    private function clearUpdateCache(): void
    {
        $this->settingModel->setValue('last_update_check', null);
        $this->settingModel->setValue('commits_behind_count', '0');
        $this->settingModel->setValue('latest_remote_sha', '');
        $this->settingModel->setValue('latest_release_notes', '');
        $this->settingModel->setValue('latest_release_url', '');
        $this->settingModel->setValue('latest_release_published_at', '');
        // Note: latest_available_version is kept — it's used by the view to compare
        // against current_version. After migrations bump app_version, the comparison
        // will no longer show an update. Clearing it could cause issues if migrations
        // haven't run yet and the user reloads the page.
    }

    private function isCacheValid(string $lastCheckTimestamp): bool
    {
        $lastCheck = strtotime($lastCheckTimestamp);
        $ttlSeconds = self::CACHE_TTL_HOURS * 3600;
        return (time() - $lastCheck) < $ttlSeconds;
    }

    private function getCachedResult(string $currentVersion, string $channel, ?string $localSha): array
    {
        $latestVersion = $this->settingModel->getValue('latest_available_version', null);

        $result = [
            'available' => false,
            'type' => null,
            'current_version' => $currentVersion,
            'channel' => $channel,
            'cached' => true,
            'last_check' => $this->settingModel->getValue('last_update_check'),
            'error' => null,
        ];

        if ($latestVersion) {
            $result['latest_version'] = $latestVersion;
            $result['release_notes'] = $this->settingModel->getValue('latest_release_notes', '');
            $result['release_url'] = $this->settingModel->getValue('latest_release_url', '');
            $result['published_at'] = $this->settingModel->getValue('latest_release_published_at', '');

            if (version_compare($latestVersion, $currentVersion, '>')) {
                $result['available'] = true;
                $result['type'] = 'release';
            }
        }

        // Check cached commit info for "latest" channel
        if ($channel === 'latest' && $localSha) {
            $commitsBehind = (int) $this->settingModel->getValue('commits_behind_count', 0);
            if ($commitsBehind > 0 && !$result['available']) {
                $result['available'] = true;
                $result['type'] = 'hotfix';
                $result['commits_behind'] = $commitsBehind;
                $result['remote_sha'] = $this->settingModel->getValue('latest_remote_sha', '');
            }
        }

        return $result;
    }

    // ========================================================================
    // Private: Update process methods
    // ========================================================================

    /**
     * Run pre-flight checks before updating
     */
    private function preflightChecks(): array
    {
        $errors = [];

        // Check PHP extensions
        if (!extension_loaded('zip')) {
            $errors[] = 'PHP zip extension is required for updates';
        }

        // Check write permissions on key directories
        $dirsToCheck = [
            $this->rootPath . '/app',
            $this->rootPath . '/core',
            $this->rootPath . '/public',
            $this->rootPath . '/database',
            $this->rootPath . '/routes',
        ];

        foreach ($dirsToCheck as $dir) {
            if (is_dir($dir) && !is_writable($dir)) {
                $errors[] = "Directory not writable: $dir";
            }
        }

        // Check temp directory is writable
        $tempDir = sys_get_temp_dir();
        if (!is_writable($tempDir)) {
            $errors[] = "System temp directory not writable: $tempDir";
        }

        // Check available disk space (require at least 50MB free)
        $freeSpace = disk_free_space($this->rootPath);
        if ($freeSpace !== false && $freeSpace < 50 * 1024 * 1024) {
            $errors[] = 'Insufficient disk space. At least 50MB required';
        }

        return [
            'pass' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Determine the download URL based on update type
     */
    private function getDownloadUrl(string $type): ?string
    {
        if ($type === 'release') {
            // Use the cached release download URL or fetch fresh
            $release = $this->fetchLatestRelease();
            return $release['zipball_url'] ?? null;
        }

        // For hotfix updates, download the latest main branch as zip
        return self::GITHUB_API_BASE . '/repos/' . self::GITHUB_REPO . '/zipball/main';
    }

    /**
     * Download archive from URL to temp file
     */
    private function downloadArchive(string $url): string
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'dm_update_') . '.zip';

        $response = $this->httpClient->get($url, [
            'sink' => $tempFile,
            'timeout' => 120,
        ]);

        $fileSize = filesize($tempFile);
        $sha256 = hash_file('sha256', $tempFile);
        $this->logger->info('Archive downloaded', [
            'size_bytes' => $fileSize,
            'sha256' => $sha256,
            'path' => $tempFile,
        ]);

        if ($fileSize < 1000) {
            unlink($tempFile);
            throw new \RuntimeException('Downloaded file is too small - likely an error response');
        }

        return $tempFile;
    }

    /**
     * Extract zip archive to a staging directory
     */
    private function extractArchive(string $archivePath): string
    {
        $stagingDir = sys_get_temp_dir() . '/dm_staging_' . uniqid();
        mkdir($stagingDir, 0755, true);

        $zip = new \ZipArchive();
        $openResult = $zip->open($archivePath);

        if ($openResult !== true) {
            throw new \RuntimeException("Failed to open zip archive (error code: $openResult)");
        }

        $comment = trim((string)$zip->getArchiveComment());
        $this->archiveCommitSha = preg_match('/^[a-f0-9]{40}$/i', $comment) ? $comment : null;

        $zip->extractTo($stagingDir);
        $zip->close();

        // GitHub zipballs have a top-level directory like "Owner-Repo-SHA/"
        // Find it and return the actual content directory
        $entries = scandir($stagingDir);
        $topDir = null;
        foreach ($entries as $entry) {
            if ($entry !== '.' && $entry !== '..' && is_dir($stagingDir . '/' . $entry)) {
                $topDir = $stagingDir . '/' . $entry;
                break;
            }
        }

        if (!$topDir) {
            throw new \RuntimeException('Unexpected archive structure - no top-level directory found');
        }

        $this->logger->info('Archive extracted', ['staging_dir' => $topDir]);
        return $topDir;
    }

    /**
     * Get the expected short commit SHA (7 chars) for the update we are applying.
     * Used to verify the downloaded zipball matches the expected ref.
     */
    private function getExpectedShortSha(string $type): ?string
    {
        if ($type === 'hotfix') {
            $fullSha = $this->fetchLatestCommitSha();
            return $fullSha ? substr($fullSha, 0, 7) : null;
        }

        if ($type === 'release') {
            $fullSha = $this->fetchReleaseCommitSha();
            return $fullSha ? substr($fullSha, 0, 7) : null;
        }

        return null;
    }

    /**
     * Full commit SHA of the latest release tag
     */
    private function fetchReleaseCommitSha(): ?string
    {
        $release = $this->fetchLatestRelease();
        if (empty($release['tag_name'])) {
            return null;
        }
        $tag = $release['tag_name']; // e.g. v1.1.3
        try {
            $url = self::GITHUB_API_BASE . '/repos/' . self::GITHUB_REPO . '/commits/' . $tag;
            $response = $this->httpClient->get($url);
            $data = json_decode($response->getBody()->getContents(), true);
            return $data['sha'] ?? null;
        } catch (\Exception $e) {
            $this->logger->warning('Could not fetch commit SHA for release tag', [
                'tag' => $tag,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Extract the short commit SHA from GitHub zipball top-level folder name.
     * Format is "Owner-Repo-<short_sha>" (e.g. Hosteroid-domain-monitor-abc1234).
     */
    private function getShortShaFromFolderName(string $stagingDirPath): ?string
    {
        $folderName = basename($stagingDirPath);
        $parts = explode('-', $folderName);
        $last = end($parts);
        // GitHub uses 7-char short SHA; could also be 40-char full SHA in some cases
        if (preg_match('/^[a-f0-9]{7,40}$/i', $last)) {
            return strlen($last) >= 7 ? substr($last, 0, 7) : $last;
        }
        return null;
    }

    /**
     * Verify that the extracted archive's folder name matches the expected commit SHA.
     * This ensures we applied the correct ref (tag or main) and detects corrupted/wrong downloads.
     */
    private function verifyExtractedCommitSha(string $stagingDir, string $type): void
    {
        $expectedShort = $this->getExpectedShortSha($type);
        if ($expectedShort === null) {
            $this->logger->warning('Skipping commit SHA verification (could not get expected SHA)');
            return;
        }

        // Prefer the commit SHA from the archive comment: for annotated tags the folder name
        // carries the tag object SHA instead of the commit SHA
        $actualShort = $this->archiveCommitSha !== null
            ? substr($this->archiveCommitSha, 0, 7)
            : $this->getShortShaFromFolderName($stagingDir);
        if ($actualShort === null) {
            throw new \RuntimeException(
                'Integrity check failed: could not read commit SHA from archive folder name. ' .
                'The download may be corrupted or from an unexpected source.'
            );
        }

        if (strcasecmp($actualShort, $expectedShort) !== 0) {
            throw new \RuntimeException(
                "Integrity check failed: archive commit SHA does not match. " .
                "Expected: {$expectedShort}, got: {$actualShort}. " .
                "The download may be corrupted or from a different ref."
            );
        }

        $this->logger->info('Commit SHA verified', [
            'expected' => $expectedShort,
            'actual' => $actualShort,
            'type' => $type,
        ]);
    }

    /**
     * Check the running PHP version against the "php" requirement in the new composer.json.
     * Returns an error message if PHP is too old, null if it's fine or the requirement can't be read.
     */
    private function checkPhpRequirement(string $stagingDir): ?string
    {
        $composerJson = $stagingDir . '/composer.json';
        if (!file_exists($composerJson)) {
            return null;
        }

        $composer = json_decode(file_get_contents($composerJson), true);
        $constraint = $composer['require']['php'] ?? null;
        if (!is_string($constraint)) {
            return null;
        }

        $minimum = self::minimumPhpVersion($constraint);
        if ($minimum === null || version_compare(PHP_VERSION, $minimum, '>=')) {
            return null;
        }

        return "This update requires PHP {$minimum} or newer, but this server runs PHP " . PHP_VERSION . '. ' .
            'Upgrade PHP first, then run the update again. No files were changed.';
    }

    /**
     * Lowest PHP version allowed by a composer constraint (e.g. ">=8.2", "^8.2", "^8.2 || ^9.0")
     */
    private static function minimumPhpVersion(string $constraint): ?string
    {
        $minimum = null;
        foreach (preg_split('/\s*\|\|?\s*/', trim($constraint)) as $alternative) {
            // Only a leading lower bound counts ("<9.0" or "!=8.3.0" on its own sets no minimum)
            if (!preg_match('/^(?:>=?|\^|~|==?)?\s*v?(\d+(?:\.\d+){0,2})/', $alternative, $match)) {
                continue;
            }
            if ($minimum === null || version_compare($match[1], $minimum, '<')) {
                $minimum = $match[1];
            }
        }
        return $minimum;
    }

    /**
     * Copy files from staging to the application root, respecting protected paths
     */
    private function applyFiles(string $stagingDir): int
    {
        $count = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($stagingDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $relativePath = str_replace($stagingDir . DIRECTORY_SEPARATOR, '', $item->getPathname());
            $relativePath = str_replace('\\', '/', $relativePath); // Normalize for Windows

            // Skip protected paths
            if ($this->isProtected($relativePath)) {
                continue;
            }

            $targetPath = $this->rootPath . '/' . $relativePath;

            if ($item->isDir()) {
                if (!is_dir($targetPath)) {
                    mkdir($targetPath, 0755, true);
                }
            } else {
                // Ensure parent directory exists
                $parentDir = dirname($targetPath);
                if (!is_dir($parentDir)) {
                    mkdir($parentDir, 0755, true);
                }

                copy($item->getPathname(), $targetPath);
                $count++;
            }
        }

        $this->logger->info('Files applied', ['count' => $count]);
        return $count;
    }

    /**
     * Check if a relative path is protected from overwriting
     */
    private function isProtected(string $relativePath): bool
    {
        foreach (self::PROTECTED_PATHS as $protected) {
            if ($relativePath === $protected || strpos($relativePath, $protected . '/') === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if composer.json or composer.lock changed
     */
    private function checkComposerChanged(string $stagingDir): bool
    {
        $currentLock = $this->rootPath . '/composer.lock';
        $newLock = $stagingDir . '/composer.lock';

        if (!file_exists($currentLock) || !file_exists($newLock)) {
            // If lock file doesn't exist in either place, check composer.json
            $currentJson = $this->rootPath . '/composer.json';
            $newJson = $stagingDir . '/composer.json';

            if (file_exists($currentJson) && file_exists($newJson)) {
                return md5_file($currentJson) !== md5_file($newJson);
            }
            return false;
        }

        return md5_file($currentLock) !== md5_file($newLock);
    }

    /**
     * Check if PHP is allowed to run shell commands (exec, etc.).
     * On cPanel / shared hosting, disable_functions often includes exec.
     */
    private function canRunShellCommands(): bool
    {
        $disabled = ini_get('disable_functions');
        if ($disabled === false || $disabled === '') {
            return true;
        }
        $list = array_map('trim', explode(',', strtolower($disabled)));
        return !in_array('exec', $list, true);
    }

    /**
     * Run composer install --no-dev.
     * Returns true on success, false if skipped or failed (caller may set composer_manual_required).
     */
    private function runComposerInstall(): bool
    {
        $composerPath = $this->findComposer();
        $command = "$composerPath install --no-dev --optimize-autoloader --no-interaction 2>&1";

        $this->logger->info('Running composer install', ['command' => $command, 'cwd' => $this->rootPath]);

        // Web server users (e.g. www-data under PHP-FPM) often have no HOME, and Composer
        // refuses to start without a home directory, so give it one inside the app
        $composerHome = null;
        $hasHome = PHP_OS_FAMILY === 'Windows' ? getenv('APPDATA') !== false : getenv('HOME') !== false;
        if (getenv('COMPOSER_HOME') === false && !$hasHome) {
            $composerHome = $this->rootPath . '/cache/composer';
            if (!is_dir($composerHome)) {
                @mkdir($composerHome, 0755, true);
            }
            putenv('COMPOSER_HOME=' . $composerHome);
            $this->logger->info('No home directory for composer, using COMPOSER_HOME', ['path' => $composerHome]);
        }

        $output = [];
        $returnCode = 0;
        $oldCwd = getcwd();
        try {
            if (!@chdir($this->rootPath)) {
                $this->logger->warning('Could not change to project directory for composer', [
                    'path' => $this->rootPath,
                ]);
                return false;
            }
            CommandRunner::exec($command, $output, $returnCode, 'composer install', 'updater');
        } finally {
            @chdir($oldCwd);
            if ($composerHome !== null) {
                putenv('COMPOSER_HOME');
            }
        }

        $outputStr = implode("\n", $output);

        if ($returnCode !== 0) {
            $this->logger->error('Composer install failed (run manually if needed)', [
                'return_code' => $returnCode,
                'output' => $outputStr,
            ]);
            return false;
        }

        $this->logger->info('Composer install completed', ['output' => $outputStr]);
        return true;
    }

    /**
     * Find the composer executable
     */
    private function findComposer(): string
    {
        // Check for local composer.phar first
        if (file_exists($this->rootPath . '/composer.phar')) {
            return 'php ' . $this->rootPath . '/composer.phar';
        }

        // Check if composer is in PATH
        $command = PHP_OS_FAMILY === 'Windows' ? 'where composer 2>NUL' : 'which composer 2>/dev/null';
        $output = [];
        exec($command, $output);

        if (!empty($output[0])) {
            return trim($output[0]);
        }

        // Fallback
        return 'composer';
    }

    /**
     * Find a system binary (e.g. mysqldump, mysql) in common paths
     */
    private function findBinary(string $name): ?string
    {
        // Check PATH first
        $command = PHP_OS_FAMILY === 'Windows' ? "where {$name} 2>NUL" : "which {$name} 2>/dev/null";
        $output = [];
        @exec($command, $output);
        if (!empty($output[0]) && $this->isExecutableFile(trim($output[0]))) {
            return trim($output[0]);
        }

        // Common locations on Linux/cPanel hosts
        $commonPaths = [
            "/usr/bin/{$name}",
            "/usr/local/bin/{$name}",
            "/usr/local/mysql/bin/{$name}",
            "/opt/cpanel/composer/bin/{$name}",
            "/usr/sbin/{$name}",
        ];
        foreach ($commonPaths as $path) {
            if ($this->isExecutableFile($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Check a binary path without tripping open_basedir (shared hosting), where checking
     * a path outside the allowed directories raises a warning that stops the update
     */
    private function isExecutableFile(string $path): bool
    {
        $openBasedir = (string)ini_get('open_basedir');
        if ($openBasedir !== '') {
            $allowed = false;
            foreach (explode(PATH_SEPARATOR, $openBasedir) as $dir) {
                if ($dir !== '' && str_starts_with($path, $dir)) {
                    $allowed = true;
                    break;
                }
            }
            if (!$allowed) {
                return false;
            }
        }

        return file_exists($path) && is_executable($path);
    }

    // ========================================================================
    // Private: Backup and rollback methods
    // ========================================================================

    /**
     * Create a full database backup (.sql file) before updating.
     * Tries mysqldump first; falls back to a pure-PDO dump of all tables.
     * Returns ['success' => bool, 'path' => string|null, 'method' => string, 'reason' => string]
     */
    private function createDatabaseBackup(): array
    {
        $backupDir = $this->rootPath . '/backups';
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0775, true);
        }

        $host = $_ENV['DB_HOST'] ?? 'localhost';
        $port = $_ENV['DB_PORT'] ?? '3306';
        $database = $_ENV['DB_DATABASE'] ?? '';
        $username = $_ENV['DB_USERNAME'] ?? '';
        $password = $_ENV['DB_PASSWORD'] ?? '';

        if (empty($database) || empty($username)) {
            return ['success' => false, 'path' => null, 'method' => 'none', 'reason' => 'Database credentials not available'];
        }

        $version = $this->settingModel->getAppVersion();
        $timestamp = date('Y-m-d_His');
        $sqlFile = $backupDir . "/db_backup_v{$version}_{$timestamp}.sql";

        // Try mysqldump first (fastest, most reliable)
        if ($this->canRunShellCommands()) {
            $mysqldumpPath = $this->findBinary('mysqldump');
            $optionsFile = $mysqldumpPath ? $this->writeMysqlOptionsFile($backupDir) : null;
            if ($optionsFile) {
                // --result-file keeps mysqldump's messages (e.g. warnings) out of the dump
                $cmd = sprintf(
                    '%s --defaults-extra-file=%s --single-transaction --routines --triggers --add-drop-table --result-file=%s %s 2>&1',
                    escapeshellarg($mysqldumpPath),
                    escapeshellarg($optionsFile),
                    escapeshellarg($sqlFile),
                    escapeshellarg($database)
                );
                $output = [];
                CommandRunner::exec($cmd, $output, $exitCode, 'Database backup (mysqldump)', 'updater', [$password]);
                @unlink($optionsFile);
                if ($exitCode === 0 && file_exists($sqlFile) && filesize($sqlFile) > 0) {
                    return ['success' => true, 'path' => $sqlFile, 'method' => 'mysqldump', 'reason' => ''];
                }
                $this->logger->warning('mysqldump failed, using PDO dump', ['output' => implode("\n", $output)]);
                // mysqldump failed, clean up and fall through to PDO
                if (file_exists($sqlFile)) {
                    @unlink($sqlFile);
                }
            }
        }

        // Fallback: pure PDO dump (works on cPanel/shared hosts without exec)
        try {
            $pdo = \Core\Database::getConnection();
            $handle = fopen($sqlFile, 'w');
            if (!$handle) {
                return ['success' => false, 'path' => null, 'method' => 'pdo', 'reason' => 'Could not create SQL file'];
            }

            fwrite($handle, "-- Domain Monitor Database Backup\n");
            fwrite($handle, "-- Date: " . date('Y-m-d H:i:s') . "\n");
            fwrite($handle, "-- Database: {$database}\n");
            fwrite($handle, "-- Method: PDO dump (fallback)\n\n");
            fwrite($handle, "SET FOREIGN_KEY_CHECKS=0;\n\n");

            // Get all tables
            $tables = $pdo->query("SHOW TABLES")->fetchAll(\PDO::FETCH_COLUMN);

            foreach ($tables as $table) {
                // DROP + CREATE
                fwrite($handle, "DROP TABLE IF EXISTS `{$table}`;\n");
                $createStmt = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(\PDO::FETCH_ASSOC);
                fwrite($handle, $createStmt['Create Table'] . ";\n\n");

                // Dump rows in batches
                $count = (int)$pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
                $batchSize = 500;
                for ($offset = 0; $offset < $count; $offset += $batchSize) {
                    $rows = $pdo->query("SELECT * FROM `{$table}` LIMIT {$batchSize} OFFSET {$offset}")->fetchAll(\PDO::FETCH_ASSOC);
                    foreach ($rows as $row) {
                        $values = array_map(function ($val) use ($pdo) {
                            if ($val === null) return 'NULL';
                            return $pdo->quote($val);
                        }, $row);
                        $cols = '`' . implode('`, `', array_keys($row)) . '`';
                        fwrite($handle, "INSERT INTO `{$table}` ({$cols}) VALUES (" . implode(', ', $values) . ");\n");
                    }
                }
                fwrite($handle, "\n");
            }

            fwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
            fclose($handle);

            if (filesize($sqlFile) > 0) {
                return ['success' => true, 'path' => $sqlFile, 'method' => 'pdo', 'reason' => ''];
            }
            @unlink($sqlFile);
            return ['success' => false, 'path' => null, 'method' => 'pdo', 'reason' => 'PDO dump produced empty file'];
        } catch (\Exception $e) {
            if (isset($handle) && is_resource($handle)) {
                fclose($handle);
            }
            if (file_exists($sqlFile)) {
                @unlink($sqlFile);
            }
            return ['success' => false, 'path' => null, 'method' => 'pdo', 'reason' => 'PDO dump failed: ' . $e->getMessage()];
        }
    }

    /**
     * Log rows written since $since (a timestamp), so restoring an older database backup
     * does not erase them. Update history is kept whole.
     */
    private function snapshotLogRows(int $since): array
    {
        $tables = ['update_history' => null, 'cron_runs' => 'created_at', 'command_runs' => 'created_at', 'audit_log' => 'created_at'];
        $pdo = \Core\Database::getConnection();
        $sinceStr = date('Y-m-d H:i:s', $since - 60);
        $rows = [];

        foreach ($tables as $table => $column) {
            try {
                $stmt = $pdo->prepare($column ? "SELECT * FROM `{$table}` WHERE `{$column}` >= ?" : "SELECT * FROM `{$table}`");
                $stmt->execute($column ? [$sinceStr] : []);
                $rows[$table] = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            } catch (\Throwable $e) {
                // Table does not exist on this version
            }
        }

        return $rows;
    }

    /**
     * Put back rows saved by snapshotLogRows() after a database restore.
     * A row that is in the backup too (same id and created_at) is updated to its newer state;
     * a row whose id is now taken by another row (written since the restore, e.g. the restore
     * command itself) is added with a new id.
     */
    private function restoreLogRows(array $rows): void
    {
        $pdo = \Core\Database::getConnection();

        foreach ($rows as $table => $tableRows) {
            try {
                $find = $pdo->prepare("SELECT created_at FROM `{$table}` WHERE id = ?");
                foreach ($tableRows as $row) {
                    $find->execute([$row['id']]);
                    $existing = $find->fetchColumn();

                    if ($existing === $row['created_at']) {
                        $id = $row['id'];
                        unset($row['id']);
                        $set = implode(', ', array_map(fn($c) => "`{$c}` = ?", array_keys($row)));
                        $pdo->prepare("UPDATE `{$table}` SET {$set} WHERE id = ?")->execute([...array_values($row), $id]);
                        continue;
                    }

                    if ($existing !== false) {
                        unset($row['id']);
                    }
                    $cols = array_keys($row);
                    $pdo->prepare(
                        "INSERT INTO `{$table}` (`" . implode('`, `', $cols) . "`) VALUES ("
                        . implode(', ', array_fill(0, count($cols), '?')) . ")"
                    )->execute(array_values($row));
                }
            } catch (\Throwable $e) {
                // The restored (older) schema has no such table or columns
                $this->logger->info('Could not keep log rows after restore', ['table' => $table, 'error' => $e->getMessage()]);
            }
        }
    }

    /**
     * Restore a database backup from a .sql file (used during rollback)
     */
    private function restoreDatabaseBackup(string $sqlFile): bool
    {
        if (!file_exists($sqlFile)) {
            $this->logger->warning('Database backup file not found for restore', ['path' => $sqlFile]);
            return false;
        }

        // Backups made before 1.2.6 start with mysqldump's password warning, which is not SQL
        $cleanFile = $this->withoutDumpWarnings($sqlFile);

        try {
            // Try mysql CLI first
            if ($this->canRunShellCommands()) {
                $mysqlPath = $this->findBinary('mysql');
                $optionsFile = $mysqlPath ? $this->writeMysqlOptionsFile(dirname($sqlFile)) : null;
                if ($optionsFile) {
                    $cmd = sprintf(
                        '%s --defaults-extra-file=%s %s < %s 2>&1',
                        escapeshellarg($mysqlPath),
                        escapeshellarg($optionsFile),
                        escapeshellarg($_ENV['DB_DATABASE'] ?? ''),
                        escapeshellarg($cleanFile)
                    );
                    $output = [];
                    CommandRunner::exec($cmd, $output, $exitCode, 'Database restore (mysql)', 'updater', [$_ENV['DB_PASSWORD'] ?? '']);
                    @unlink($optionsFile);
                    if ($exitCode === 0) {
                        $this->logger->info('Database restored via mysql CLI', ['path' => $sqlFile]);
                        return true;
                    }
                    $this->logger->warning('Database restore via mysql CLI failed, trying PDO', ['output' => implode("\n", $output)]);
                }
            }

            return $this->restoreDatabaseWithPdo($cleanFile, $sqlFile);
        } finally {
            if ($cleanFile !== $sqlFile) {
                @unlink($cleanFile);
            }
        }
    }

    /**
     * Execute a .sql backup statement by statement via PDO (fallback when the mysql CLI is unavailable)
     */
    private function restoreDatabaseWithPdo(string $file, string $originalPath): bool
    {
        try {
            $pdo = \Core\Database::getConnection();
            $sql = file_get_contents($file);
            if (empty($sql)) {
                return false;
            }

            $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
            // Split on semicolons followed by newline (to avoid breaking on values containing semicolons)
            $statements = preg_split('/;\s*\n/', $sql);
            foreach ($statements as $stmt) {
                // Drop comment lines only: in mysqldump output they come right before the DROP TABLE
                $stmt = trim(preg_replace('/^--(\s.*)?$/m', '', $stmt));
                if ($stmt === '') continue;
                $pdo->exec($stmt);
            }
            $pdo->exec("SET FOREIGN_KEY_CHECKS=1");

            $this->logger->info('Database restored via PDO', ['path' => $originalPath]);
            return true;
        } catch (\Exception $e) {
            $this->logger->error('Database restore via PDO failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Write DB credentials to a temporary MySQL option file (readable by owner only) for
     * mysqldump/mysql, so the password is not on the command line. Caller deletes it.
     */
    private function writeMysqlOptionsFile(string $dir): ?string
    {
        $quote = static fn(string $v): string => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $v) . '"';
        $content = "[client]\n"
            . 'host=' . $quote($_ENV['DB_HOST'] ?? 'localhost') . "\n"
            . 'port=' . (int)($_ENV['DB_PORT'] ?? 3306) . "\n"
            . 'user=' . $quote($_ENV['DB_USERNAME'] ?? '') . "\n"
            . 'password=' . $quote($_ENV['DB_PASSWORD'] ?? '') . "\n";

        $file = rtrim($dir, '/\\') . '/.mysql-' . bin2hex(random_bytes(8)) . '.cnf';
        if (@file_put_contents($file, $content, LOCK_EX) === false) {
            $this->logger->warning('Could not write MySQL option file', ['dir' => $dir]);
            return null;
        }
        @chmod($file, 0600);

        return $file;
    }

    /**
     * Return a copy of the dump without leading "mysqldump: ..." lines, or the file itself if it has none.
     * Backups made before 1.2.6 captured mysqldump's warnings in the file.
     */
    private function withoutDumpWarnings(string $sqlFile): string
    {
        $in = @fopen($sqlFile, 'rb');
        if (!$in) {
            return $sqlFile;
        }

        $skipped = 0;
        while (($line = fgets($in)) !== false && strpos($line, 'mysqldump: ') === 0) {
            $skipped++;
        }
        if ($skipped === 0) {
            fclose($in);
            return $sqlFile;
        }

        $cleanFile = $sqlFile . '.restore';
        $out = @fopen($cleanFile, 'wb');
        if (!$out) {
            fclose($in);
            return $sqlFile;
        }
        if ($line !== false) {
            fwrite($out, $line);
        }
        stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);
        $this->logger->info('Skipped mysqldump messages at the start of the backup', ['lines' => $skipped]);

        return $cleanFile;
    }

    /**
     * Create a zip backup of current application files
     */
    private function createBackup(): string
    {
        $backupDir = $this->rootPath . '/backups';
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0775, true);
        }
        // Ensure the directory is writable
        if (!is_writable($backupDir)) {
            @chmod($backupDir, 0775);
        }

        // ZipArchive::close() writes a temp file; point TMPDIR to a writable location
        // so it works on hosts where the system /tmp is not writable by the web user
        $originalTmpDir = getenv('TMPDIR') ?: null;
        putenv('TMPDIR=' . $backupDir);

        $version = $this->settingModel->getAppVersion();
        $timestamp = date('Y-m-d_His');
        $backupFile = $backupDir . "/backup_v{$version}_{$timestamp}.zip";

        $zip = new \ZipArchive();
        if ($zip->open($backupFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            // Restore TMPDIR before throwing
            if ($originalTmpDir !== null) {
                putenv('TMPDIR=' . $originalTmpDir);
            } else {
                putenv('TMPDIR');
            }
            throw new \RuntimeException("Failed to create backup archive: $backupFile");
        }

        // Back up key application directories
        $dirsToBackup = ['app', 'core', 'public', 'database', 'routes', 'cron'];
        $filesToBackup = ['composer.json', 'composer.lock'];

        foreach ($dirsToBackup as $dir) {
            $fullDir = $this->rootPath . '/' . $dir;
            if (is_dir($fullDir)) {
                $this->addDirectoryToZip($zip, $fullDir, $dir);
            }
        }

        foreach ($filesToBackup as $file) {
            $fullFile = $this->rootPath . '/' . $file;
            if (file_exists($fullFile)) {
                $zip->addFile($fullFile, $file);
            }
        }

        $zip->close();

        // Restore original TMPDIR
        if ($originalTmpDir !== null) {
            putenv('TMPDIR=' . $originalTmpDir);
        } else {
            putenv('TMPDIR');
        }

        $this->logger->info('Backup created', [
            'path' => $backupFile,
            'size_bytes' => filesize($backupFile),
        ]);

        return $backupFile;
    }

    /**
     * Recursively add a directory to a zip archive
     */
    private function addDirectoryToZip(\ZipArchive $zip, string $dir, string $zipPath): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $relativePath = $zipPath . '/' . str_replace(
                [$dir . DIRECTORY_SEPARATOR, $dir . '/'],
                '',
                $item->getPathname()
            );
            $relativePath = str_replace('\\', '/', $relativePath); // Zip entries use forward slashes

            if ($item->isDir()) {
                $zip->addEmptyDir($relativePath);
            } else {
                $zip->addFile($item->getPathname(), $relativePath);
            }
        }
    }

    /**
     * Restore from a backup zip archive
     */
    private function restoreBackup(string $backupPath): void
    {
        if (!file_exists($backupPath)) {
            throw new \RuntimeException("Backup file not found: $backupPath");
        }

        $zip = new \ZipArchive();
        if ($zip->open($backupPath) !== true) {
            throw new \RuntimeException("Failed to open backup archive: $backupPath");
        }

        $zip->extractTo($this->rootPath);
        $zip->close();

        $this->logger->info('Backup restored', ['path' => $backupPath]);
    }

    // ========================================================================
    // Private: Utility methods
    // ========================================================================

    /**
     * Store the commit SHA of the code that was just installed
     */
    private function updateCommitSha(string $type): void
    {
        // The commit actually installed, not main's head: after a release, commits already
        // pushed to main must still show as a hotfix on the Latest channel
        $installedSha = $this->archiveCommitSha
            ?? ($type === 'release' ? $this->fetchReleaseCommitSha() : $this->fetchLatestCommitSha());
        if ($installedSha) {
            $this->settingModel->setValue('installed_commit_sha', $installedSha);
            $this->logger->info('Updated installed commit SHA', [
                'sha' => substr($installedSha, 0, 7),
            ]);
        }
    }

    /**
     * Clean up temporary files
     */
    private function cleanup(string $archivePath, string $stagingDir): void
    {
        // Remove archive
        if (file_exists($archivePath)) {
            unlink($archivePath);
        }

        // Remove staging directory
        if (is_dir($stagingDir)) {
            $this->removeDirectory($stagingDir);
        }

        // Also clean parent staging dir if it exists
        $parentStaging = dirname($stagingDir);
        if (strpos(basename($parentStaging), 'dm_staging_') === 0 && is_dir($parentStaging)) {
            $this->removeDirectory($parentStaging);
        }
    }

    /**
     * Recursively remove a directory
     */
    /**
     * Delete compiled Twig templates so pages use the template files just installed or restored
     */
    private function clearTemplateCache(): void
    {
        try {
            $this->removeDirectory($this->rootPath . '/cache/twig');
            $this->logger->info('Cleared template cache');
        } catch (\Throwable $e) {
            $this->logger->warning('Could not clear template cache', ['error' => $e->getMessage()]);
        }
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($dir);
    }

    /**
     * Get update channel label for display
     */
    public static function getChannelLabel(string $channel): string
    {
        return match ($channel) {
            'stable' => 'Stable (Releases only)',
            'latest' => 'Latest (Releases + hotfixes)',
            default => ucfirst($channel),
        };
    }

    /**
     * Check if there are pending database migrations
     */
    public function hasPendingMigrations(): bool
    {
        try {
            $pdo = \Core\Database::getConnection();

            // Check if migrations table exists
            try {
                $stmt = $pdo->query("SELECT COUNT(*) FROM migrations");
            } catch (\Exception $e) {
                return false;
            }

            $executed = [];
            $stmt = $pdo->query("SELECT migration FROM migrations");
            $executed = $stmt->fetchAll(\PDO::FETCH_COLUMN);

            // Scan migrations directory
            $migrationsDir = $this->rootPath . '/database/migrations';
            $files = glob($migrationsDir . '/*.sql');
            $allMigrations = array_map('basename', $files);

            // Filter out the consolidated schema
            $allMigrations = array_filter($allMigrations, function ($m) {
                return strpos($m, '000_') !== 0;
            });

            $pending = array_diff($allMigrations, $executed);
            return !empty($pending);
        } catch (\Exception $e) {
            return false;
        }
    }
}
