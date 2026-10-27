<?php

namespace App\Controllers;

use Core\Auth;
use Core\Controller;
use Core\Csrf;
use App\Models\AuditLog;
use App\Models\Passkey;
use App\Models\Setting;
use App\Models\User;
use App\Services\Logger;
use App\Services\PasskeyService;

class PasskeyController extends Controller
{
    private PasskeyService $passkeyService;
    private Passkey $passkeyModel;
    private User $userModel;
    private Logger $logger;

    public function __construct()
    {
        $this->passkeyService = new PasskeyService();
        $this->passkeyModel = new Passkey();
        $this->userModel = new User();
        $this->logger = new Logger('passkeys');
    }

    /**
     * Sign-in options (public)
     * POST /login/passkey/options
     */
    public function loginOptions()
    {
        $this->readJsonRequest();

        if (isset($_SESSION['user_id']) && empty($_SESSION['2fa_required'])) {
            $this->json(['redirect' => '/']);
        }

        $this->json(['options' => $this->passkeyService->getLoginOptions()]);
    }

    /**
     * Verify a passkey and sign the user in (public)
     * POST /login/passkey
     */
    public function login()
    {
        $input = $this->readJsonRequest();

        try {
            $user = $this->passkeyService->authenticate($this->credentialFrom($input));
        } catch (\RuntimeException $e) {
            $this->json(['error' => $e->getMessage()], 400);
        }

        // Same email verification rule as password sign-in
        $requireVerification = (new Setting())->getValue('require_email_verification');
        if ($requireVerification && !$user['email_verified'] && $user['role'] !== 'admin') {
            $_SESSION['error'] = 'Please verify your email address before logging in';
            $_SESSION['pending_verification_email'] = $user['email'];
            $this->json(['redirect' => '/verify-email']);
        }

        // A passkey with user verification is two factors, so no 2FA code is asked
        session_regenerate_id(true);

        unset($_SESSION['2fa_required'], $_SESSION['pending_remember']);
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['email'] = $user['email'];
        $_SESSION['role'] = $user['role'];

        unset($_SESSION['error'], $_SESSION['success'], $_SESSION['info']);

        if (!empty($input['remember'])) {
            (new AuthController())->createRememberTokenPublic((int)$user['id']);
        }

        $this->userModel->updateLastLogin($user['id']);

        $this->logger->info('Passkey sign-in successful', [
            'user_id' => $user['id'],
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        ]);

        try {
            $notificationService = new \App\Services\NotificationService();
            $notificationService->notifyNewLogin($user['id'], 'Passkey', $_SERVER['REMOTE_ADDR'] ?? 'unknown', $_SERVER['HTTP_USER_AGENT'] ?? null);
        } catch (\Exception $e) {
            // Don't block login if notification fails
        }
        AuditLog::record('auth.login', 'Signed in with a passkey');

        $_SESSION['success'] = 'Login successful! Welcome back, ' . htmlspecialchars($user['full_name']) . '.';
        $this->json(['redirect' => '/']);
    }

    /**
     * Options for adding a passkey to the signed-in account
     * POST /profile/passkeys/options
     */
    public function registerOptions()
    {
        $this->readJsonRequest();

        $user = $this->userModel->find(Auth::id());
        if (!$user) {
            $this->json(['error' => 'User not found'], 404);
        }

        $this->json(['options' => $this->passkeyService->getRegistrationOptions($user)]);
    }

    /**
     * Save a new passkey
     * POST /profile/passkeys
     */
    public function register()
    {
        $input = $this->readJsonRequest();

        $user = $this->userModel->find(Auth::id());
        if (!$user) {
            $this->json(['error' => 'User not found'], 404);
        }

        $name = is_string($input['name'] ?? null) ? $input['name'] : '';

        try {
            $passkey = $this->passkeyService->register($user, $this->credentialFrom($input), $name);
        } catch (\RuntimeException $e) {
            $this->json(['error' => $e->getMessage()], 400);
        }

        try {
            $notificationService = new \App\Services\NotificationService();
            $notificationService->notifyPasskeyAdded((int)$user['id'], $passkey['name']);
        } catch (\Exception $e) {
            // Non-critical
        }
        AuditLog::record('auth.passkey_added', 'Added passkey "' . $passkey['name'] . '"');

        $_SESSION['success'] = 'Passkey "' . $passkey['name'] . '" added. You can now sign in with it.';
        $this->json(['redirect' => '/profile#passkeys']);
    }

    /**
     * POST /profile/passkeys/{id}/rename
     */
    public function rename($params = [])
    {
        $this->verifyCsrf('/profile#passkeys');

        $id = (int)($params['id'] ?? 0);
        $name = $this->passkeyService->cleanName($_POST['name'] ?? '');

        if ($this->passkeyModel->rename($id, Auth::id(), $name)) {
            $_SESSION['success'] = 'Passkey renamed';
        } elseif (!$this->passkeyModel->findForUser($id, Auth::id())) {
            $_SESSION['error'] = 'Passkey not found';
        }

        $this->redirect('/profile#passkeys');
    }

    /**
     * POST /profile/passkeys/{id}/delete
     */
    public function delete($params = [])
    {
        $this->verifyCsrf('/profile#passkeys');

        $id = (int)($params['id'] ?? 0);
        $passkey = $this->passkeyModel->findForUser($id, Auth::id());

        if ($passkey && $this->passkeyModel->deleteForUser($id, Auth::id())) {
            $this->logger->info('Passkey removed', ['user_id' => Auth::id(), 'passkey_id' => $id]);
            AuditLog::record('auth.passkey_removed', 'Removed passkey "' . $passkey['name'] . '"');
            $_SESSION['success'] = 'Passkey "' . $passkey['name'] . '" removed. Also delete it from your device or password manager.';
        } else {
            $_SESSION['error'] = 'Passkey not found';
        }

        $this->redirect('/profile#passkeys');
    }

    /**
     * Check method, CSRF header and the admin switch, then return the JSON body
     */
    private function readJsonRequest(): array
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->json(['error' => 'Method not allowed'], 405);
        }

        if (!Csrf::validateToken($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
            $this->json(['error' => 'Security token validation failed. Please reload the page and try again.'], 403);
        }

        if (!$this->passkeyService->isEnabled()) {
            $this->json(['error' => 'Passkeys are disabled by the administrator.'], 403);
        }

        $input = json_decode(file_get_contents('php://input') ?: '', true);
        return is_array($input) ? $input : [];
    }

    /**
     * Keep only the string fields of the credential sent by the browser
     */
    private function credentialFrom(array $input): array
    {
        $credential = is_array($input['credential'] ?? null) ? $input['credential'] : [];
        return array_filter($credential, 'is_string');
    }
}
