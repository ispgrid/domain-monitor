<?php

namespace App\Controllers;

use App\Models\AuditLog;
use App\Models\RegistrarAccount;
use App\Services\CommandRunner;
use App\Services\ProviderDnsSync;
use App\Services\Registrars\ProviderRegistry;
use Core\Auth;
use Core\Controller;

/**
 * Integrations: each user's registrar and DNS provider accounts (Cloudflare for now). Read only at the provider.
 */
class IntegrationController extends Controller
{
    private RegistrarAccount $accountModel;

    public function __construct()
    {
        $this->accountModel = new RegistrarAccount();
    }

    public function index()
    {
        try {
            $accounts = $this->accountModel->getByUser(Auth::id());
        } catch (\PDOException $e) {
            // Tables missing until the update's migration has run
            $accounts = null;
        }

        $providers = [];
        foreach (ProviderRegistry::all() as $key => $class) {
            $providers[$key] = ['label' => $class::label(), 'fields' => $class::credentialFields()];
        }

        $this->view('integrations/index', [
            'accounts' => $accounts,
            'providers' => $providers,
            'graceMinutes' => ProviderDnsSync::DRIFT_GRACE_MINUTES,
            'title' => 'Integrations',
        ]);
    }

    public function add()
    {
        $this->verifyCsrf('/integrations');

        $provider = (string)($_POST['provider'] ?? '');
        $label = trim((string)($_POST['label'] ?? ''));
        if (!ProviderRegistry::has($provider)) {
            $this->fail('Choose a provider');
        }
        if ($label === '' || mb_strlen($label) > 100) {
            $this->fail('Give the account a name of up to 100 characters');
        }

        $creds = $this->credentialsFromPost($provider, []);
        if (is_string($creds)) {
            $this->fail($creds);
        }

        $test = ProviderRegistry::make($provider)->testConnection($creds);
        if ($test !== true) {
            $this->fail('Could not connect: ' . $test);
        }

        try {
            $id = $this->accountModel->createAccount(Auth::id(), $provider, $label, $creds);
        } catch (\PDOException $e) {
            $this->fail('Integrations are not available yet. An admin needs to finish the update under Settings → Updates.');
        }

        AuditLog::record('integration.added', 'Connected ' . ProviderRegistry::label($provider) . ' account "' . $label . '"', [
            'entity_type' => 'integration',
            'entity_id' => $id,
        ]);

        // Reading every zone can take a minute on big accounts, so that waits for Sync now or the next DNS check
        $_SESSION['success'] = 'Account connected. Click Sync now to read its zones, or they are read with the next DNS check.';
        $this->redirect('/integrations');
    }

    public function update($params = [])
    {
        $this->verifyCsrf('/integrations');
        $account = $this->ownAccount($params);

        $label = trim((string)($_POST['label'] ?? ''));
        if ($label === '' || mb_strlen($label) > 100) {
            $this->fail('Give the account a name of up to 100 characters');
        }

        // Blank credential fields keep the saved ones
        $saved = $this->accountModel->credentials($account);
        $creds = $this->credentialsFromPost($account['provider'], $saved);
        if (is_string($creds)) {
            $this->fail($creds);
        }
        if ($creds !== $saved) {
            $test = ProviderRegistry::make($account['provider'])->testConnection($creds);
            if ($test !== true) {
                $this->fail('Could not connect with the new credentials: ' . $test);
            }
            $this->accountModel->updateCredentials((int)$account['id'], $creds);
        }

        $this->accountModel->update((int)$account['id'], [
            'label' => $label,
            'is_active' => !empty($_POST['is_active']) ? 1 : 0,
        ]);

        AuditLog::record('integration.updated', 'Updated integration "' . $label . '"' . ($creds !== $saved ? ' (new credentials)' : ''), [
            'entity_type' => 'integration',
            'entity_id' => (int)$account['id'],
        ]);
        $_SESSION['success'] = 'Integration saved';
        $this->redirect('/integrations');
    }

    public function sync($params = [])
    {
        $this->verifyCsrf('/integrations');
        $account = $this->ownAccount($params);

        if (in_array((int)$account['id'], $this->accountModel->syncingIds(Auth::id()), true)) {
            $_SESSION['info'] = 'This account is already syncing.';
            $this->redirect('/integrations');
        }

        // Big accounts take a minute or more, so the sync runs in its own process when the server allows it
        if (CommandRunner::canRun()) {
            $this->accountModel->markSyncing((int)$account['id']);
            $started = CommandRunner::spawnPhp(
                realpath(__DIR__ . '/../../cron/sync_dns_provider.php'),
                ['--account=' . (int)$account['id']],
                'Sync DNS provider: ' . $account['label'],
                'integrations'
            );
            if ($started) {
                $_SESSION['info'] = 'Sync started in the background. This page updates when it finishes.';
                $this->redirect('/integrations');
            }
            $this->accountModel->update((int)$account['id'], ['last_status' => $account['last_status']]);
        }

        $summary = $this->runSync($account);
        $_SESSION[$summary['error'] ? 'error' : 'success'] = $this->describe($summary);
        $this->redirect('/integrations');
    }

    /**
     * Accounts still syncing in the background, polled by the Integrations page
     */
    public function status()
    {
        try {
            $this->json(['syncing' => $this->accountModel->syncingIds(Auth::id())]);
        } catch (\PDOException $e) {
            $this->json(['syncing' => []]);
        }
    }

    public function delete($params = [])
    {
        $this->verifyCsrf('/integrations');
        $account = $this->ownAccount($params);

        $this->accountModel->delete((int)$account['id']);
        AuditLog::record('integration.deleted', 'Removed integration "' . $account['label'] . '"', [
            'entity_type' => 'integration',
            'entity_id' => (int)$account['id'],
        ]);
        $_SESSION['success'] = 'Integration removed. Its saved zones were deleted too.';
        $this->redirect('/integrations');
    }

    private function runSync(array $account): array
    {
        @set_time_limit(300);
        return (new ProviderDnsSync())->syncAccount($account);
    }

    private function describe(array $summary): string
    {
        if ($summary['error']) {
            return 'Sync problem: ' . $summary['error'];
        }
        $text = "Found {$summary['zones']} zone(s), {$summary['linked']} matched to your domains.";
        if ($summary['mismatched'] > 0) {
            $text .= " {$summary['mismatched']} differ from public DNS (see each domain's DNS tab).";
        }
        if ($summary['unused'] > 0) {
            $text .= " {$summary['unused']} not in use (the domain's nameservers are elsewhere), so not compared.";
        }
        return $text;
    }

    /**
     * @return array|string credentials, or the error to show
     */
    private function credentialsFromPost(string $provider, array $saved): array|string
    {
        $class = ProviderRegistry::all()[$provider];
        $creds = [];
        foreach ($class::credentialFields() as $name => $field) {
            $value = trim((string)($_POST['credentials'][$name] ?? ''));
            if ($value === '') {
                $value = (string)($saved[$name] ?? '');
            }
            if ($value === '' && !empty($field['required'])) {
                return "Enter the {$field['label']}";
            }
            if (strlen($value) > 500) {
                return "The {$field['label']} is too long";
            }
            $creds[$name] = $value;
        }
        return $creds;
    }

    private function ownAccount(array $params): array
    {
        $account = $this->accountModel->findForUser((int)($params['id'] ?? 0), Auth::id());
        if (!$account) {
            $this->fail('Integration not found');
        }
        return $account;
    }

    private function fail(string $message): never
    {
        $_SESSION['error'] = $message;
        $this->redirect('/integrations');
    }
}
