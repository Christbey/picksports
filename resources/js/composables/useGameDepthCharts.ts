import { computed, onMounted } from 'vue';
import { useAsyncResource } from '@/composables/useAsyncResource';
import { useApiV2Client } from '@/composables/useApiV2Client';
import type { ApiV2SportSlug, GameDepthChartsData } from '@/types';

export function useGameDepthCharts(
    sport: ApiV2SportSlug,
    gameId: number,
    options: { autoLoad?: boolean } = {},
) {
    const api = useApiV2Client();
    const resource = useAsyncResource(
        async (signal): Promise<GameDepthChartsData | null> => {
            const payload = await api.games.depthCharts(sport, gameId, {
                init: { signal },
            });
            return payload?.data ?? null;
        },
        { errorMessage: 'Unable to load depth charts', clearOnError: false },
    );
    const depthCharts = computed(() => resource.data.value);
    const load = async (): Promise<void> => {
        await resource.execute();
    };

    if (options.autoLoad !== false) {
        onMounted(load);
    }

    return {
        depthCharts,
        depthChartsLoading: resource.isLoading,
        depthChartsError: resource.error,
        reloadDepthCharts: load,
    };
}
