type AnalyticsValue = string | number | boolean | null;
type AnalyticsParams = Record<string, AnalyticsValue | undefined>;

type PendingEvent = {
    event: 'login_complete' | 'sign_up_complete' | 'purchase';
    params?: AnalyticsParams;
    createdAt: number;
};

type InertiaPageLike = {
    component?: string;
    props?: Record<string, unknown>;
    url?: string;
};

const PENDING_EVENT_KEY = 'ps_pending_analytics_event';
const PENDING_EVENT_TTL_MS = 1000 * 60 * 30;

let lastTrackedUrl: string | null = null;
const trackedViewItemKeys = new Set<string>();

function normalizeParams(params?: AnalyticsParams): Record<string, AnalyticsValue> {
    if (!params) {
        return {};
    }

    return Object.entries(params).reduce<Record<string, AnalyticsValue>>((acc, [key, value]) => {
        if (value !== undefined) {
            acc[key] = value;
        }

        return acc;
    }, {});
}

export function pushAnalyticsEvent(event: string, params?: AnalyticsParams): void {
    if (typeof window === 'undefined') {
        return;
    }

    window.dataLayer = window.dataLayer || [];
    window.dataLayer.push({
        event,
        ...normalizeParams(params),
    });
}

export function createAnalyticsEventId(): string {
    if (typeof window !== 'undefined' && typeof window.crypto?.randomUUID === 'function') {
        return window.crypto.randomUUID();
    }

    return `evt_${Date.now()}_${Math.random().toString(36).slice(2, 10)}`;
}

export function setPendingAnalyticsEvent(
    event: PendingEvent['event'],
    params?: PendingEvent['params'],
): void {
    if (typeof window === 'undefined') {
        return;
    }

    const payload: PendingEvent = {
        event,
        params,
        createdAt: Date.now(),
    };

    window.sessionStorage.setItem(PENDING_EVENT_KEY, JSON.stringify(payload));
}

export function clearPendingAnalyticsEvent(): void {
    if (typeof window === 'undefined') {
        return;
    }

    window.sessionStorage.removeItem(PENDING_EVENT_KEY);
}

function readPendingAnalyticsEvent(): PendingEvent | null {
    if (typeof window === 'undefined') {
        return null;
    }

    const raw = window.sessionStorage.getItem(PENDING_EVENT_KEY);

    if (!raw) {
        return null;
    }

    try {
        const parsed = JSON.parse(raw) as PendingEvent;

        if (
            !parsed
            || typeof parsed !== 'object'
            || typeof parsed.event !== 'string'
            || typeof parsed.createdAt !== 'number'
        ) {
            clearPendingAnalyticsEvent();

            return null;
        }

        return parsed;
    } catch {
        clearPendingAnalyticsEvent();

        return null;
    }
}

function isAuthenticated(page: InertiaPageLike): boolean {
    const auth = page.props?.auth as { user?: { id?: number | string } } | undefined;

    return Boolean(auth?.user?.id);
}

function isAuthRoute(path: string): boolean {
    return path.startsWith('/login')
        || path.startsWith('/register')
        || path.startsWith('/forgot-password')
        || path.startsWith('/reset-password')
        || path.startsWith('/two-factor-challenge')
        || path.startsWith('/verify-email');
}

function getPathname(page: InertiaPageLike): string {
    if (typeof window === 'undefined') {
        return page.url?.split('?')[0] ?? '/';
    }

    return window.location.pathname || page.url?.split('?')[0] || '/';
}

function getPageType(page: InertiaPageLike): string {
    const component = page.component ?? '';
    const path = getPathname(page);

    if (component === 'Welcome' || path === '/') return 'landing';
    if (component.startsWith('auth/')) return 'auth';
    if (component.startsWith('Subscription/') || path.startsWith('/subscription')) return 'subscription';
    if (component.endsWith('/Game')) return 'game';
    if (component.includes('/Predictions') || path.includes('/predictions')) return 'predictions';
    if (component.includes('/Player') || path.includes('/players')) return 'player';
    if (component.includes('/Team') || path.includes('/teams')) return 'team';
    if (component.startsWith('settings/')) return 'settings';

    return 'other';
}

function getUserTier(page: InertiaPageLike): string {
    const subscription = page.props?.subscription as { tier?: string } | undefined;

    return subscription?.tier ?? 'free';
}

export function trackPageView(page: InertiaPageLike): void {
    if (typeof window === 'undefined') {
        return;
    }

    const url = window.location.href;

    if (url === lastTrackedUrl) {
        return;
    }

    lastTrackedUrl = url;

    pushAnalyticsEvent('page_view', {
        page_path: getPathname(page),
        page_title: document.title || null,
        page_location: url,
        page_type: getPageType(page),
        is_logged_in: isAuthenticated(page),
        user_tier: getUserTier(page),
    });
}

export function flushPendingAnalyticsEvent(page: InertiaPageLike): void {
    const pending = readPendingAnalyticsEvent();

    if (!pending) {
        return;
    }

    if (Date.now() - pending.createdAt > PENDING_EVENT_TTL_MS) {
        clearPendingAnalyticsEvent();

        return;
    }

    const path = getPathname(page);
    const authenticated = isAuthenticated(page);

    const shouldFlush = (() => {
        if (pending.event === 'login_complete') {
            return authenticated && !isAuthRoute(path);
        }

        if (pending.event === 'sign_up_complete') {
            return authenticated && !isAuthRoute(path);
        }

        if (pending.event === 'purchase') {
            return authenticated && (path.startsWith('/dashboard') || path.startsWith('/subscription'));
        }

        return false;
    })();

    if (!shouldFlush) {
        return;
    }

    pushAnalyticsEvent(pending.event, {
        ...pending.params,
        page_path: path,
    });
    clearPendingAnalyticsEvent();
}

interface ViewItemOptions {
    itemId: string | number;
    itemName: string;
    sport: string;
    league?: string;
    homeTeam?: string | null;
    awayTeam?: string | null;
}

export function trackViewItem(options: ViewItemOptions): void {
    if (typeof window === 'undefined') {
        return;
    }

    const key = `${window.location.pathname}:${options.sport}:${options.itemId}`;
    if (trackedViewItemKeys.has(key)) {
        return;
    }

    trackedViewItemKeys.add(key);
    pushAnalyticsEvent('view_item', {
        item_id: String(options.itemId),
        item_name: options.itemName,
        content_type: 'game',
        sport: options.sport,
        league: options.league ?? options.sport.toUpperCase(),
        home_team: options.homeTeam ?? null,
        away_team: options.awayTeam ?? null,
    });
}
