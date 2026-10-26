/**
 * Passkey (WebAuthn) helpers for the login and profile pages.
 * The server sends and expects binary values as base64url strings.
 */
(function (window) {
    'use strict';

    function toBuffer(value) {
        var base64 = value.replace(/-/g, '+').replace(/_/g, '/');
        while (base64.length % 4) {
            base64 += '=';
        }
        var binary = atob(base64);
        var bytes = new Uint8Array(binary.length);
        for (var i = 0; i < binary.length; i++) {
            bytes[i] = binary.charCodeAt(i);
        }
        return bytes.buffer;
    }

    function toBase64Url(buffer) {
        if (!buffer) {
            return null;
        }
        var bytes = new Uint8Array(buffer);
        var binary = '';
        for (var i = 0; i < bytes.length; i++) {
            binary += String.fromCharCode(bytes[i]);
        }
        return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    }

    function csrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        if (meta) {
            return meta.getAttribute('content');
        }
        var input = document.querySelector('input[name="csrf_token"]');
        return input ? input.value : '';
    }

    async function post(url, body) {
        var response = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-Token': csrfToken()
            },
            body: JSON.stringify(body || {})
        });
        var data;
        try {
            data = await response.json();
        } catch (e) {
            throw new Error('Unexpected response from the server (HTTP ' + response.status + ').');
        }
        if (!response.ok || data.error) {
            throw new Error(data.error || ('Request failed (HTTP ' + response.status + ').'));
        }
        return data;
    }

    function errorMessage(error) {
        if (!error) {
            return 'Passkey request failed.';
        }
        if (error.name === 'NotAllowedError') {
            return 'The passkey request was cancelled or timed out.';
        }
        if (error.name === 'InvalidStateError') {
            return 'This device already has a passkey for your account.';
        }
        if (error.name === 'SecurityError') {
            return 'Passkeys need HTTPS (or localhost) and must be used on the address the app runs on.';
        }
        return error.message || String(error);
    }

    // Navigate, reloading when only the #hash would change (e.g. /profile#passkeys)
    function go(url) {
        var target = new URL(url, window.location.href);
        if (target.pathname === window.location.pathname && target.search === window.location.search) {
            window.location.hash = target.hash;
            window.location.reload();
        } else {
            window.location.href = target.href;
        }
    }

    function isSupported() {
        return !!(window.PublicKeyCredential && navigator.credentials && window.isSecureContext);
    }

    var conditionalAbort = null;

    /**
     * Sign in. With conditional = true the request waits quietly for the
     * user to pick a passkey from the username field's autofill list.
     */
    async function signIn(options) {
        options = options || {};
        if (conditionalAbort) {
            conditionalAbort.abort();
            conditionalAbort = null;
        }

        var data = await post('/login/passkey/options');
        if (data.redirect) {
            go(data.redirect);
            return;
        }

        var publicKey = data.options.publicKey;
        publicKey.challenge = toBuffer(publicKey.challenge);
        (publicKey.allowCredentials || []).forEach(function (cred) {
            cred.id = toBuffer(cred.id);
        });

        var request = { publicKey: publicKey };
        if (options.conditional) {
            conditionalAbort = new AbortController();
            request.mediation = 'conditional';
            request.signal = conditionalAbort.signal;
        }

        var credential;
        try {
            credential = await navigator.credentials.get(request);
        } catch (error) {
            // Background autofill that ends or isn't possible here is not an error the user caused
            error.silent = !!options.conditional;
            throw error;
        }
        conditionalAbort = null;

        var result = await post('/login/passkey', {
            remember: typeof options.remember === 'function' ? !!options.remember() : !!options.remember,
            credential: {
                id: credential.id,
                clientDataJSON: toBase64Url(credential.response.clientDataJSON),
                authenticatorData: toBase64Url(credential.response.authenticatorData),
                signature: toBase64Url(credential.response.signature),
                userHandle: toBase64Url(credential.response.userHandle)
            }
        });
        go(result.redirect || '/');
    }

    async function register(name) {
        var data = await post('/profile/passkeys/options');
        var publicKey = data.options.publicKey;
        publicKey.challenge = toBuffer(publicKey.challenge);
        publicKey.user.id = toBuffer(publicKey.user.id);
        (publicKey.excludeCredentials || []).forEach(function (cred) {
            cred.id = toBuffer(cred.id);
        });

        var credential = await navigator.credentials.create({ publicKey: publicKey });

        var result = await post('/profile/passkeys', {
            name: name || '',
            credential: {
                id: credential.id,
                clientDataJSON: toBase64Url(credential.response.clientDataJSON),
                attestationObject: toBase64Url(credential.response.attestationObject)
            }
        });
        go(result.redirect || '/profile#passkeys');
    }

    async function conditionalAvailable() {
        return isSupported()
            && typeof PublicKeyCredential.isConditionalMediationAvailable === 'function'
            && await PublicKeyCredential.isConditionalMediationAvailable();
    }

    window.Passkeys = {
        isSupported: isSupported,
        conditionalAvailable: conditionalAvailable,
        signIn: signIn,
        register: register,
        errorMessage: errorMessage
    };
})(window);
