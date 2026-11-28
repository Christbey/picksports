import type { ComputedRef, Ref } from 'vue';
import { computed, onScopeDispose, getCurrentScope, ref } from 'vue';
import { qrCode, recoveryCodes, secretKey } from '@/routes/two-factor';

export type UseTwoFactorAuthReturn = {
    qrCodeSvg: Ref<string | null>;
    manualSetupKey: Ref<string | null>;
    recoveryCodesList: Ref<string[]>;
    errors: Ref<string[]>;
    hasSetupData: ComputedRef<boolean>;
    clearSetupData: () => void;
    clearErrors: () => void;
    clearTwoFactorAuthData: () => void;
    fetchQrCode: () => Promise<void>;
    fetchSetupKey: () => Promise<void>;
    fetchSetupData: () => Promise<void>;
    fetchRecoveryCodes: () => Promise<void>;
};

const fetchJson = async <T>(url: string, signal: AbortSignal): Promise<T> => {
    const response = await fetch(url, {
        headers: { Accept: 'application/json' },
        signal,
    });

    if (!response.ok) {
        throw new Error(`Failed to fetch: ${response.status}`);
    }

    return response.json();
};

const errors = ref<string[]>([]);
const manualSetupKey = ref<string | null>(null);
const qrCodeSvg = ref<string | null>(null);
const recoveryCodesList = ref<string[]>([]);

const hasSetupData = computed<boolean>(
    () => qrCodeSvg.value !== null && manualSetupKey.value !== null,
);

type SecretKind = 'qr' | 'key' | 'recovery';
const requests = new Map<SecretKind, AbortController>();

function cancelRequest(kind: SecretKind): void {
    requests.get(kind)?.abort();
    requests.delete(kind);
}

async function loadSecret<T>(
    kind: SecretKind,
    url: string,
    apply: (value: T) => void,
    fail: () => void,
): Promise<void> {
    cancelRequest(kind);
    const controller = new AbortController();
    requests.set(kind, controller);
    const current = () =>
        requests.get(kind) === controller && !controller.signal.aborted;
    try {
        const value = await fetchJson<T>(url, controller.signal);
        // Also fence delayed JSON parsing and transports that ignore cancellation.
        if (current()) apply(value);
    } catch {
        if (current()) fail();
    } finally {
        if (requests.get(kind) === controller) requests.delete(kind);
    }
}

export const useTwoFactorAuth = (): UseTwoFactorAuthReturn => {
    const clearErrors = (): void => {
        errors.value = [];
    };
    const clearSetupData = (): void => {
        cancelRequest('qr');
        cancelRequest('key');
        manualSetupKey.value = null;
        qrCodeSvg.value = null;
        clearErrors();
    };
    const clearTwoFactorAuthData = (): void => {
        clearSetupData();
        cancelRequest('recovery');
        recoveryCodesList.value = [];
    };
    // These refs are shared by the page, modal and recovery panel. Disposing a
    // consumer must revoke pending reads as well as erase already loaded secrets.
    if (getCurrentScope()) onScopeDispose(clearTwoFactorAuthData);

    const fetchQrCode = (): Promise<void> =>
        loadSecret<{ svg: string }>(
            'qr',
            qrCode.url(),
            (value) => {
                qrCodeSvg.value = value.svg;
            },
            () => {
                errors.value.push('Failed to fetch QR code');
                qrCodeSvg.value = null;
            },
        );
    const fetchSetupKey = (): Promise<void> =>
        loadSecret<{ secretKey: string }>(
            'key',
            secretKey.url(),
            (value) => {
                manualSetupKey.value = value.secretKey;
            },
            () => {
                errors.value.push('Failed to fetch a setup key');
                manualSetupKey.value = null;
            },
        );
    const fetchRecoveryCodes = (): Promise<void> => {
        clearErrors();
        return loadSecret<string[]>(
            'recovery',
            recoveryCodes.url(),
            (value) => {
                recoveryCodesList.value = value;
            },
            () => {
                errors.value.push('Failed to fetch recovery codes');
                recoveryCodesList.value = [];
            },
        );
    };
    const fetchSetupData = async (): Promise<void> => {
        clearErrors();
        await Promise.all([fetchQrCode(), fetchSetupKey()]);
    };
    return {
        qrCodeSvg,
        manualSetupKey,
        recoveryCodesList,
        errors,
        hasSetupData,
        clearSetupData,
        clearErrors,
        clearTwoFactorAuthData,
        fetchQrCode,
        fetchSetupKey,
        fetchSetupData,
        fetchRecoveryCodes,
    };
};
