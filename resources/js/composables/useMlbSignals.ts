import { onMounted, onUnmounted, ref, watch } from 'vue';
import { useApiV2Client } from '@/composables/useApiV2Client';
import type { SignalsPayload } from '@/types/mlb-signals';

export function useMlbSignals(props: { season?: string | number | null }) {
    const loading = ref(true);
    const error = ref<string | null>(null);
    const payload = ref<SignalsPayload | null>(null);
    const api = useApiV2Client();
    let refreshTimer: number | undefined;
    let controller: AbortController | undefined;
    let serial = 0;
    let disposed = false;

    async function loadSignals(silent = false): Promise<void> {
        if (disposed) return;
        controller?.abort();
        const request = ++serial;
        controller = new AbortController();
        const signal = controller.signal;
        const current = () =>
            !disposed && request === serial && !signal.aborted;
        if (!silent) loading.value = true;
        error.value = null;

        try {
            const response = await api.signals.index<SignalsPayload>('mlb', {
                query: props.season ? { season: props.season } : undefined,
                init: { signal },
            });
            if (!current()) return;
            if (!response?.data) {
                throw new Error('Failed to load MLB signals');
            }

            payload.value = response.data;
        } catch (e) {
            if (!current()) return;
            error.value =
                e instanceof Error ? e.message : 'Unable to load MLB signals';
        } finally {
            if (current()) loading.value = false;
        }
    }

    function startPolling(): void {
        refreshTimer = window.setInterval(() => {
            void loadSignals(true);
        }, 30000);
    }

    onMounted(() => {
        void loadSignals();
        startPolling();
    });

    watch(
        () => props.season,
        () => {
            payload.value = null;
            void loadSignals();
        },
    );

    onUnmounted(() => {
        disposed = true;
        ++serial;
        controller?.abort();
        if (refreshTimer !== undefined) {
            window.clearInterval(refreshTimer);
        }
    });

    return { loading, error, payload };
}
