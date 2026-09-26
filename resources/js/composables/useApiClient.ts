export class ApiError extends Error {
    readonly status: number;
    readonly data: unknown;
    readonly requestId: string | null;
    readonly code: string | null;
    readonly retryAfter: string | null;

    constructor(response: Response, data: unknown) {
        const payload = data as {
            message?: string;
            request_id?: string;
            error?: { message?: string; code?: string; request_id?: string };
        } | null;
        super(
            payload?.error?.message ??
                payload?.message ??
                `API request failed (${response.status})`,
        );
        this.name = 'ApiError';
        this.status = response.status;
        this.data = data;
        this.code = payload?.error?.code ?? null;
        this.requestId =
            response.headers.get('X-Request-ID') ??
            payload?.error?.request_id ??
            payload?.request_id ??
            null;
        this.retryAfter = response.headers.get('Retry-After');
    }
}

async function readResponse<T>(response: Response): Promise<T | null> {
    if (!response.ok) {
        let data: unknown = null;
        try {
            data = await response.json();
        } catch {
            // Proxies may return a non-JSON error; preserve its HTTP status.
        }
        throw new ApiError(response, data);
    }
    if (response.status === 204) return null;
    if (
        !(response.headers.get('content-type') ?? '').includes(
            'application/json',
        )
    )
        return null;
    return (await response.json()) as T;
}

function jsonHeaders(init: RequestInit): Headers {
    const headers = new Headers(init.headers);
    if (!headers.has('Accept')) headers.set('Accept', 'application/json');
    if (!headers.has('X-Requested-With'))
        headers.set('X-Requested-With', 'XMLHttpRequest');
    return headers;
}

export async function fetchJson<T>(
    url: string,
    init: RequestInit = {},
): Promise<T | null> {
    return readResponse<T>(
        await fetch(url, {
            credentials: 'same-origin',
            ...init,
            headers: jsonHeaders(init),
        }),
    );
}

function attachCsrfToken(headers: Headers): boolean {
    if (headers.has('X-CSRF-TOKEN') || headers.has('X-XSRF-TOKEN')) return true;
    if (typeof document === 'undefined') return false;
    const cookie = document.cookie
        .split(';')
        .map((part) => part.trim())
        .find((part) => part.startsWith('XSRF-TOKEN='));
    if (cookie) {
        headers.set(
            'X-XSRF-TOKEN',
            decodeURIComponent(cookie.substring('XSRF-TOKEN='.length)),
        );
        return true;
    }
    const token = document.querySelector<HTMLMetaElement>(
        'meta[name="csrf-token"]',
    )?.content;
    if (!token) return false;
    headers.set('X-CSRF-TOKEN', token);
    return true;
}

export type ApiMutationOptions = {
    init?: RequestInit;
    // Reuse this key when retrying the same logical operation across calls.
    idempotencyKey?: string;
};

export async function mutateJson<T>(
    url: string,
    method: 'POST' | 'PUT' | 'PATCH' | 'DELETE',
    payload?: Record<string, unknown>,
    options: ApiMutationOptions = {},
): Promise<T | null> {
    const init = options.init ?? {};
    const headers = jsonHeaders(init);
    headers.set('Content-Type', 'application/json');
    if (!headers.has('Idempotency-Key')) {
        headers.set(
            'Idempotency-Key',
            options.idempotencyKey ?? crypto.randomUUID(),
        );
    }
    if (
        !headers.has('Authorization') &&
        !attachCsrfToken(headers) &&
        typeof document !== 'undefined'
    ) {
        await fetchJson('/sanctum/csrf-cookie', { signal: init.signal });
        attachCsrfToken(headers);
    }
    const request: RequestInit = {
        credentials: 'same-origin',
        ...init,
        method,
        headers,
        body: payload === undefined ? undefined : JSON.stringify(payload),
    };
    let response: Response;
    try {
        response = await fetch(url, request);
    } catch (error) {
        // Retry an ambiguous network failure once with the same key and body.
        // Never retry a cancelled request or a completed HTTP error response.
        if (
            init.signal?.aborted ||
            (error instanceof Error && error.name === 'AbortError')
        )
            throw error;
        response = await fetch(url, request);
    }
    return readResponse<T>(response);
}
