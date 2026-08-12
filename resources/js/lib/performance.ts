import { pushAnalyticsEvent } from '@/lib/analytics';

type VitalState = {
    cls: number;
    inp: number | null;
    lcp: number | null;
    ttfb: number | null;
};

export function initializePerformanceMonitoring(): void {
    if (typeof window === 'undefined' || !('performance' in window)) {
        return;
    }

    const vitals: VitalState = {
        cls: 0,
        inp: null,
        lcp: null,
        ttfb: null,
    };
    let reported = false;

    const navigation = performance.getEntriesByType('navigation')[0] as
        | PerformanceNavigationTiming
        | undefined;
    if (navigation) {
        vitals.ttfb = Math.max(
            0,
            navigation.responseStart - navigation.requestStart,
        );
    }

    const observe = (
        type: string,
        callback: (entry: PerformanceEntry) => void,
    ): void => {
        try {
            const observer = new PerformanceObserver((list) => {
                list.getEntries().forEach(callback);
            });
            observer.observe({ type, buffered: true });
        } catch {
            // The browser does not support this performance entry type.
        }
    };

    observe('largest-contentful-paint', (entry) => {
        vitals.lcp = entry.startTime;
    });
    observe('layout-shift', (entry) => {
        const shift = entry as PerformanceEntry & {
            hadRecentInput?: boolean;
            value?: number;
        };
        if (!shift.hadRecentInput) {
            vitals.cls += shift.value ?? 0;
        }
    });
    observe('event', (entry) => {
        const event = entry as PerformanceEntry & { duration?: number };
        if (event.duration !== undefined) {
            vitals.inp = Math.max(vitals.inp ?? 0, event.duration);
        }
    });

    const report = (): void => {
        if (reported) return;
        reported = true;

        pushAnalyticsEvent('web_vitals', {
            page_path: window.location.pathname,
            ttfb_ms: vitals.ttfb === null ? null : Math.round(vitals.ttfb),
            lcp_ms: vitals.lcp === null ? null : Math.round(vitals.lcp),
            cls: Number(vitals.cls.toFixed(4)),
            inp_ms: vitals.inp === null ? null : Math.round(vitals.inp),
        });
    };

    window.addEventListener('load', () => window.setTimeout(report, 5_000), {
        once: true,
    });
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'hidden') report();
    });
}
