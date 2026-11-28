/** Catalog comes from named server routes; never store the matched credential URL. */
function templates(): string[] {
    if (typeof document === 'undefined') return [];
    try {
        const value: unknown = JSON.parse(
            document.querySelector<HTMLMetaElement>(
                'meta[name="ps-sensitive-paths"]',
            )?.content ?? '[]',
        );
        return Array.isArray(value)
            ? value.filter((path): path is string => typeof path === 'string')
            : [];
    } catch {
        return [];
    }
}
export function isSensitiveLocation(value: string): boolean {
    try {
        const url = new URL(value, window.location.origin);
        if (
            [...url.searchParams.keys()].some((key) =>
                /token|code|email|signature|invite|join|secret/i.test(key),
            )
        )
            return true;
        // Fallback also protects telemetry before the route catalog is available.
        if (
            /^\/(?:reset-password|forgot-password|login|register|two-factor-challenge|verify-email|confirm-password|passkeys|oauth|settings|subscription|stripe\/payment|admin\/(?:users|subscriptions)|group-invitations|groups\/join)(?:\/|$)/i.test(
                url.pathname,
            )
        )
            return true;
        return templates().some((template) => {
            const pattern = template
                .split('/')
                .map((part) =>
                    /^\{.*\}$/.test(part)
                        ? '[^/]+'
                        : part.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'),
                )
                .join('/');
            return new RegExp(`^${pattern}/?$`).test(url.pathname);
        });
    } catch {
        return true;
    }
}
export function isSensitiveDocument(): boolean {
    return (
        typeof document !== 'undefined' &&
        document.querySelector<HTMLMetaElement>(
            'meta[name="ps-sensitive-document"]',
        )?.content === 'true'
    );
}
export function requiresDocumentNavigation(target: string): boolean {
    try {
        const url = new URL(target, window.location.origin);
        return (
            url.origin === window.location.origin &&
            (isSensitiveDocument() || isSensitiveLocation(url.href))
        );
    } catch {
        return false;
    }
}
export function telemetryLocation(value: string): string | null {
    try {
        if (isSensitiveLocation(value)) return null;
        const url = new URL(value, window.location.origin);
        if (url.origin !== window.location.origin) return null;
        return value.startsWith('/')
            ? url.pathname
            : `${url.origin}${url.pathname}`;
    } catch {
        return null;
    }
}
