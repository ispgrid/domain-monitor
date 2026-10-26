<?php

namespace App\Services;

use App\Models\Passkey;
use App\Models\Setting;
use App\Models\User;
use lbuchs\WebAuthn\WebAuthn;
use lbuchs\WebAuthn\WebAuthnException;

/**
 * Passkey (WebAuthn) registration and sign-in.
 *
 * Passkeys are discoverable credentials with user verification required,
 * so a passkey sign-in counts as two factors and skips the 2FA code.
 * Binary values travel to and from the browser as base64url strings.
 */
class PasskeyService
{
    private const CHALLENGE_TTL = 300;
    private const TIMEOUT_SECONDS = 120;
    private const MAX_CREDENTIAL_ID_LENGTH = 512;
    private const MAX_NAME_LENGTH = 100;

    private Passkey $passkeyModel;
    private Setting $settingModel;
    private Logger $logger;

    public function __construct()
    {
        $this->passkeyModel = new Passkey();
        $this->settingModel = new Setting();
        $this->logger = new Logger('passkeys');
    }

    public function isEnabled(): bool
    {
        return $this->settingModel->getValue('passkeys_enabled', '1') === '1';
    }

    /**
     * Relying party ID: the host the app is served on, without port.
     * Passkeys only work on the domain they were created on.
     */
    public function getRpId(): string
    {
        $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
        $host = strtolower(trim(preg_replace('/:\d+$/', '', $host), '[]'));
        return $host !== '' ? $host : 'localhost';
    }

    /**
     * Options for navigator.credentials.create()
     */
    public function getRegistrationOptions(array $user): \stdClass
    {
        $handle = $this->passkeyModel->getUserHandle((int)$user['id']) ?? bin2hex(random_bytes(16));

        $exclude = [];
        foreach ($this->passkeyModel->getByUserId((int)$user['id']) as $passkey) {
            $exclude[] = $this->decode($passkey['credential_id']);
        }

        $webAuthn = $this->webAuthn();
        $args = $webAuthn->getCreateArgs(
            $handle,
            $user['username'],
            $user['full_name'] ?: $user['username'],
            self::TIMEOUT_SECONDS,
            'required',
            'required',
            null,
            $exclude
        );
        // No attestation needed: we only keep the public key, and it avoids extra browser prompts
        $args->publicKey->attestation = 'none';
        unset($args->publicKey->extensions);

        $_SESSION['passkey_registration'] = [
            'challenge' => bin2hex($webAuthn->getChallenge()->getBinaryString()),
            'handle' => $handle,
            'user_id' => (int)$user['id'],
            'expires' => time() + self::CHALLENGE_TTL,
        ];

        return $args;
    }

    /**
     * Verify the browser's create() response and save the passkey
     *
     * @throws \RuntimeException with a message safe to show the user
     */
    public function register(array $user, array $credential, string $name): array
    {
        $pending = $_SESSION['passkey_registration'] ?? null;
        unset($_SESSION['passkey_registration']);

        if (!$pending || $pending['user_id'] !== (int)$user['id'] || $pending['expires'] < time()) {
            throw new \RuntimeException('The passkey request expired. Please try again.');
        }

        $name = $this->cleanName($name);

        try {
            $data = $this->webAuthn()->processCreate(
                $this->decode($credential['clientDataJSON'] ?? ''),
                $this->decode($credential['attestationObject'] ?? ''),
                hex2bin($pending['challenge']),
                true,
                true,
                false
            );
        } catch (WebAuthnException $e) {
            $this->logger->warning('Passkey registration failed', [
                'user_id' => $user['id'],
                'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException('The passkey could not be verified. Please try again.');
        }

        $credentialId = $this->encode($data->credentialId);
        if (strlen($credentialId) > self::MAX_CREDENTIAL_ID_LENGTH) {
            throw new \RuntimeException('This passkey is not supported (credential ID too long).');
        }
        if ($this->passkeyModel->findByCredentialId($credentialId)) {
            throw new \RuntimeException('This passkey is already registered.');
        }

        $id = $this->passkeyModel->create([
            'user_id' => (int)$user['id'],
            'credential_id' => $credentialId,
            'user_handle' => $pending['handle'],
            'public_key' => $data->credentialPublicKey,
            'sign_count' => (int)($data->signatureCounter ?? 0),
            'name' => $name,
        ]);

        $this->logger->info('Passkey added', ['user_id' => $user['id'], 'passkey_id' => $id]);

        return $this->passkeyModel->find($id);
    }

    /**
     * Options for navigator.credentials.get(). No user is known yet:
     * the browser offers the passkeys it has for this site.
     */
    public function getLoginOptions(): \stdClass
    {
        $webAuthn = $this->webAuthn();
        $args = $webAuthn->getGetArgs([], self::TIMEOUT_SECONDS, true, true, true, true, true, 'required');

        $_SESSION['passkey_login'] = [
            'challenge' => bin2hex($webAuthn->getChallenge()->getBinaryString()),
            'expires' => time() + self::CHALLENGE_TTL,
        ];

        return $args;
    }

    /**
     * Verify the browser's get() response
     *
     * @return array The user the passkey belongs to
     * @throws \RuntimeException with a message safe to show the user
     */
    public function authenticate(array $credential): array
    {
        $pending = $_SESSION['passkey_login'] ?? null;
        unset($_SESSION['passkey_login']);

        if (!$pending || $pending['expires'] < time()) {
            throw new \RuntimeException('The passkey request expired. Please try again.');
        }

        $passkey = $this->passkeyModel->findByCredentialId((string)($credential['id'] ?? ''));
        if (!$passkey) {
            $this->logger->warning('Passkey sign-in with unknown credential', [
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            ]);
            throw new \RuntimeException('This passkey is not registered here. Sign in with your password, then add it in your profile.');
        }

        // If the authenticator sent a user handle it has to match the stored one
        $userHandle = $credential['userHandle'] ?? null;
        if (is_string($userHandle) && $userHandle !== '' && !hash_equals($passkey['user_handle'], $this->decode($userHandle))) {
            $this->logger->warning('Passkey sign-in with mismatched user handle', ['passkey_id' => $passkey['id']]);
            throw new \RuntimeException('The passkey could not be verified. Please try again.');
        }

        $webAuthn = $this->webAuthn();
        try {
            $webAuthn->processGet(
                $this->decode($credential['clientDataJSON'] ?? ''),
                $this->decode($credential['authenticatorData'] ?? ''),
                $this->decode($credential['signature'] ?? ''),
                $passkey['public_key'],
                hex2bin($pending['challenge']),
                (int)$passkey['sign_count'],
                true
            );
        } catch (WebAuthnException $e) {
            $this->logger->warning('Passkey sign-in failed', [
                'user_id' => $passkey['user_id'],
                'passkey_id' => $passkey['id'],
                'error' => $e->getMessage(),
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            ]);
            throw new \RuntimeException('The passkey could not be verified. Please try again.');
        }

        $this->passkeyModel->markUsed((int)$passkey['id'], (int)($webAuthn->getSignatureCounter() ?? 0));

        $user = (new User())->find((int)$passkey['user_id']);
        if (!$user || !$user['is_active']) {
            throw new \RuntimeException('This account is disabled.');
        }

        return $user;
    }

    public function cleanName(string $name): string
    {
        $name = trim(preg_replace('/\s+/', ' ', $name));
        if ($name === '') {
            $name = 'Passkey';
        }
        return mb_substr($name, 0, self::MAX_NAME_LENGTH);
    }

    private function webAuthn(): WebAuthn
    {
        $appName = $this->settingModel->getValue('app_name', 'Domain Monitor') ?: 'Domain Monitor';
        return new WebAuthn($appName, $this->getRpId(), null, true);
    }

    private function encode(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }

    private function decode(mixed $base64url): string
    {
        $base64url = is_string($base64url) ? $base64url : '';
        $binary = base64_decode(strtr($base64url, '-_', '+/'), true);
        if ($binary === false || $base64url === '') {
            throw new \RuntimeException('The passkey response was incomplete. Please try again.');
        }
        return $binary;
    }
}
