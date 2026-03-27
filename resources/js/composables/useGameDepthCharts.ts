import { onMounted, ref } from 'vue';
import { fetchJson } from '@/composables/useApiClient';
import type { ApiEnvelope, GameDepthChartsData } from '@/types';

export function useGameDepthCharts(sport: 'nfl' | 'nba' | 'mlb', gameId: number) {
    const depthCharts = ref<GameDepthChartsData | null>(null);
    const loading = ref(false);
    const error = ref<string | null>(null);

    const load = async () => {
        try {
            loading.value = true;
            error.value = null;

            const payload = await fetchJson<ApiEnvelope<GameDepthChartsData>>(
                `/api/v1/${sport}/games/${gameId}/depth-charts`,
            );

            depthCharts.value = payload?.data ?? null;
        } catch (e) {
            error.value = e instanceof Error ? e.message : 'Unable to load depth charts';
        } finally {
            loading.value = false;
        }
    };

    onMounted(load);

    return {
        depthCharts,
        depthChartsLoading: loading,
        depthChartsError: error,
        reloadDepthCharts: load,
    };
}
