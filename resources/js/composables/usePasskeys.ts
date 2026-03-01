type PublicKeyCredentialDescriptorJson = {
    id: string;
    type: 'public-key';
    transports?: AuthenticatorTransport[];
};

type RegistrationOptionsResponse = {
    publicKey: {
        challenge: string;
        rp: {
            name: string;
            id: string;
        };
        user: {
            id: string;
            name: string;
            displayName: string;
        };
        pubKeyCredParams: Array<{
            type: 'public-key';
            alg: number;
        }>;
        timeout: number;
        attestation: AttestationConveyancePreference;
        authenticatorSelection: AuthenticatorSelectionCriteria;
        excludeCredentials: PublicKeyCredentialDescriptorJson[];
    };
};

type AuthenticationOptionsResponse = {
    publicKey: {
        challenge: string;
        rpId: string;
        timeout: number;
        userVerification: UserVerificationRequirement;
        allowCredentials: PublicKeyCredentialDescriptorJson[];
    };
};

export type PasskeySummary = {
    id: number;
    name: string | null;
    created_at: string;
    last_used_at: string | null;
};

type PasskeysResponse = {
    passkeys: PasskeySummary[];
};

const textEncoder = new TextEncoder();

function base64UrlEncode(buffer: ArrayBuffer): string {
    const bytes = new Uint8Array(buffer);
    let binary = '';

    for (const byte of bytes) {
        binary += String.fromCharCode(byte);
    }

    return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/g, '');
}

function base64UrlDecode(value: string): Uint8Array {
    const normalized = value.replace(/-/g, '+').replace(/_/g, '/');
    const padded = normalized + '='.repeat((4 - (normalized.length % 4)) % 4);
    const binary = atob(padded);
    const bytes = new Uint8Array(binary.length);

    for (let i = 0; i < binary.length; i += 1) {
        bytes[i] = binary.charCodeAt(i);
    }

    return bytes;
}

function csrfToken(): string {
    const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

    if (!token) {
        throw new Error('Missing CSRF token.');
    }

    return token;
}

function ensureWebAuthnSupport(): void {
    if (!window.isSecureContext || typeof window.PublicKeyCredential === 'undefined') {
        throw new Error('Passkeys are not available in this browser/context.');
    }
}

async function postJson<T>(url: string, payload: Record<string, unknown> = {}): Promise<T> {
    const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify(payload),
    });

    const json = (await response.json().catch(() => ({}))) as Record<string, unknown>;

    if (!response.ok) {
        if (response.status === 419) {
            throw new Error('Your session expired. Refresh the page and try passkey sign-in again.');
        }

        const message =
            (json.message as string | undefined)
            ?? (json.errors as Record<string, string[]> | undefined)?.credential?.[0]
            ?? 'Passkey request failed.';

        throw new Error(message);
    }

    return json as T;
}

async function deleteJson(url: string): Promise<void> {
    const response = await fetch(url, {
        method: 'DELETE',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
            'X-Requested-With': 'XMLHttpRequest',
        },
    });

    if (!response.ok) {
        throw new Error('Failed to delete passkey.');
    }
}

export async function listPasskeys(): Promise<PasskeySummary[]> {
    const response = await fetch('/passkeys', {
        method: 'GET',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
    });

    if (!response.ok) {
        throw new Error('Failed to load passkeys.');
    }

    const json = (await response.json()) as PasskeysResponse;

    return json.passkeys;
}

export async function deletePasskey(id: number): Promise<void> {
    await deleteJson(`/passkeys/${id}`);
}

export async function registerPasskey(name: string | null = null): Promise<void> {
    ensureWebAuthnSupport();

    const optionsResponse = await postJson<RegistrationOptionsResponse>('/passkeys/registration/options');

    const publicKey: PublicKeyCredentialCreationOptions = {
        challenge: base64UrlDecode(optionsResponse.publicKey.challenge),
        rp: optionsResponse.publicKey.rp,
        user: {
            id: base64UrlDecode(optionsResponse.publicKey.user.id),
            name: optionsResponse.publicKey.user.name,
            displayName: optionsResponse.publicKey.user.displayName,
        },
        pubKeyCredParams: optionsResponse.publicKey.pubKeyCredParams,
        timeout: optionsResponse.publicKey.timeout,
        attestation: optionsResponse.publicKey.attestation,
        authenticatorSelection: optionsResponse.publicKey.authenticatorSelection,
        excludeCredentials: optionsResponse.publicKey.excludeCredentials.map((credential) => ({
            ...credential,
            id: base64UrlDecode(credential.id),
        })),
    };

    const credential = await navigator.credentials.create({ publicKey });

    if (!(credential instanceof PublicKeyCredential)) {
        throw new Error('Passkey registration was cancelled.');
    }

    const response = credential.response as AuthenticatorAttestationResponse & {
        getPublicKey?: () => ArrayBuffer | null;
        getPublicKeyAlgorithm?: () => number;
        getAuthenticatorData?: () => ArrayBuffer;
        getTransports?: () => AuthenticatorTransport[];
    };

    const publicKeyBuffer = response.getPublicKey?.() ?? null;
    const authenticatorData = response.getAuthenticatorData?.() ?? null;

    const payload = {
        name,
        credential_id: base64UrlEncode(credential.rawId),
        public_key: publicKeyBuffer ? base64UrlEncode(publicKeyBuffer) : undefined,
        attestation_object: base64UrlEncode(response.attestationObject),
        algorithm: publicKeyBuffer ? (response.getPublicKeyAlgorithm?.() ?? -7) : undefined,
        client_data_json: base64UrlEncode(response.clientDataJSON),
        authenticator_data: authenticatorData ? base64UrlEncode(authenticatorData) : undefined,
        transports: response.getTransports?.() ?? [],
    };

    await postJson('/passkeys/registration/verify', payload);
}

export async function signInWithPasskey(email?: string): Promise<void> {
    ensureWebAuthnSupport();

    const optionsResponse = await postJson<AuthenticationOptionsResponse>(
        '/passkeys/authentication/options',
        {
            email: email?.trim() ? email.trim() : undefined,
        },
    );

    const publicKey: PublicKeyCredentialRequestOptions = {
        challenge: base64UrlDecode(optionsResponse.publicKey.challenge),
        rpId: optionsResponse.publicKey.rpId,
        timeout: optionsResponse.publicKey.timeout,
        userVerification: optionsResponse.publicKey.userVerification,
        allowCredentials: optionsResponse.publicKey.allowCredentials.map((credential) => ({
            ...credential,
            id: base64UrlDecode(credential.id),
        })),
    };

    let credential: Credential | null;

    try {
        credential = await navigator.credentials.get({ publicKey });
    } catch (error) {
        if (error instanceof DOMException) {
            if (error.name === 'NotAllowedError') {
                throw new Error('Passkey sign-in was canceled or no matching passkey is available.');
            }

            if (error.name === 'InvalidStateError') {
                throw new Error('This device is not ready for passkey sign-in right now. Try again.');
            }

            if (error.name === 'SecurityError') {
                throw new Error('Passkey sign-in requires a secure domain (HTTPS) and matching app domain settings.');
            }
        }

        throw error;
    }

    if (!(credential instanceof PublicKeyCredential)) {
        throw new Error('No passkey was selected.');
    }

    const response = credential.response as AuthenticatorAssertionResponse;

    const verify = await postJson<{ redirect?: string }>('/passkeys/authentication/verify', {
        credential_id: base64UrlEncode(credential.rawId),
        client_data_json: base64UrlEncode(response.clientDataJSON),
        authenticator_data: base64UrlEncode(response.authenticatorData),
        signature: base64UrlEncode(response.signature),
    });

    window.location.assign(verify.redirect ?? '/dashboard');
}

export function passkeyNameOrFallback(name: string | null, createdAt: string): string {
    if (name && name.trim().length > 0) {
        return name;
    }

    return `Passkey ${new Date(createdAt).toLocaleDateString()}`;
}

export function toPasskeyLabelInput(value: string): string {
    return textEncoder.encode(value.trim()).length > 255 ? value.slice(0, 255) : value.trim();
}
