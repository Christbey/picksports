type SignalCredentialParams = {
    credentialId: ArrayBuffer;
};

type SignalCurrentUserDetailsParams = {
    credentialId: ArrayBuffer;
    userId: ArrayBuffer;
    name: string;
    displayName: string;
};

type SignalAllAcceptedCredentialsParams = {
    userId: ArrayBuffer;
    allAcceptedCredentialIds: ArrayBuffer[];
};

type PublicKeyCredentialSignalApi = typeof PublicKeyCredential & {
    signalUnknownCredential?: (params: SignalCredentialParams) => Promise<void>;
    signalCurrentUserDetails?: (
        params: SignalCurrentUserDetailsParams,
    ) => Promise<void>;
    signalAllAcceptedCredentials?: (
        params: SignalAllAcceptedCredentialsParams,
    ) => Promise<void>;
};

function textToArrayBuffer(value: string): ArrayBuffer {
    return new TextEncoder().encode(value).buffer;
}

function supportsSignalApi(): boolean {
    if (typeof window === 'undefined' || !('PublicKeyCredential' in window)) {
        return false;
    }

    const api = PublicKeyCredential as PublicKeyCredentialSignalApi;

    return (
        typeof api.signalUnknownCredential === 'function' ||
        typeof api.signalCurrentUserDetails === 'function' ||
        typeof api.signalAllAcceptedCredentials === 'function'
    );
}

export async function signalCurrentUserDetails(
    userId: string,
    userName: string,
): Promise<void> {
    if (!supportsSignalApi()) {
        return;
    }

    const api = PublicKeyCredential as PublicKeyCredentialSignalApi;

    if (typeof api.signalCurrentUserDetails !== 'function') {
        return;
    }

    const pseudoCredentialId = textToArrayBuffer(`user:${userId}`);

    await api.signalCurrentUserDetails({
        credentialId: pseudoCredentialId,
        userId: textToArrayBuffer(userId),
        name: userName,
        displayName: userName,
    });
}
