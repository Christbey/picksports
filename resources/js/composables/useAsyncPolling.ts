import { onScopeDispose } from 'vue';

export function useAsyncPolling(
    task: () => Promise<void>,
    intervalMs: number,
    enabled: () => boolean = () => true,
) {
    let timer: ReturnType<typeof setTimeout> | undefined;
    let active = false;
    let disposed = false;
    let running = false;

    const schedule = () => {
        if (!active || disposed || running || timer !== undefined) return;
        timer = setTimeout(async () => {
            timer = undefined;
            if (!active || disposed) return;
            running = true;
            try {
                if (enabled()) await task();
            } finally {
                running = false;
                schedule();
            }
        }, intervalMs);
    };

    const start = () => {
        if (disposed) return;
        active = true;
        schedule();
    };
    const stop = () => {
        active = false;
        if (timer !== undefined) clearTimeout(timer);
        timer = undefined;
    };
    onScopeDispose(() => {
        disposed = true;
        stop();
    });
    return { start, stop };
}
