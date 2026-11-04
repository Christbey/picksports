import { onMounted, onUnmounted, ref } from 'vue';
import { useApiV2Client } from '@/composables/useApiV2Client';
import type { SignalsPayload } from '@/types/mlb-signals';

export function useMlbSignals(props: { season?: string | number | null }) {
    const loading = ref(true);
    const error = ref<string | null>(null);
    const payload = ref<SignalsPayload | null>(null);
    const api = useApiV2Client();
    let refreshTimer: number | undefined;

    async function loadSignals(silent = false): Promise<void> {
        if (!silent) {
            loading.value = true;
        }
        error.value = null;

        try {
            const response = await api.signals.index<SignalsPayload>('mlb', {
                query: props.season ? { season: props.season } : undefined,
            });
            if (!response?.data) {
                throw new Error('Failed to load MLB signals');
            }

            payload.value = response.data;
        } catch (e) {
            error.value =
                e instanceof Error ? e.message : 'Unable to load MLB signals';
        } finally {
            if (!silent) {
                loading.value = false;
            }
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

    onUnmounted(() => {
        if (refreshTimer !== undefined) {
            window.clearInterval(refreshTimer);
        }
    });

    return { loading, error, payload };
}
