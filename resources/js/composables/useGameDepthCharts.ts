import { onMounted, ref } from 'vue';
import { useApiV2Client } from '@/composables/useApiV2Client';
import type { ApiV2SportSlug, GameDepthChartsData } from '@/types';

export function useGameDepthCharts(
    sport: ApiV2SportSlug,
    gameId: number,
    options: { autoLoad?: boolean } = {},
) {
    const depthCharts = ref<GameDepthChartsData | null>(null);
    const loading = ref(false);
    const error = ref<string | null>(null);
    const api = useApiV2Client();

    const load = async () => {
        try {
            loading.value = true;
            error.value = null;

            const payload = await api.games.depthCharts(sport, gameId);

            depthCharts.value = payload?.data ?? null;
        } catch (e) {
            error.value =
                e instanceof Error ? e.message : 'Unable to load depth charts';
        } finally {
            loading.value = false;
        }
    };

    if (options.autoLoad !== false) {
        onMounted(load);
    }

    return {
        depthCharts,
        depthChartsLoading: loading,
        depthChartsError: error,
        reloadDepthCharts: load,
    };
}
