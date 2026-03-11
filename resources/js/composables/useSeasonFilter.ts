import { ref } from 'vue';
import { fetchJson } from '@/composables/useApiClient';

interface SeasonPayload {
    data?: Array<number | string>;
}

export function useSeasonFilter(getEndpoint: () => string) {
    const availableSeasons = ref<number[]>([]);
    const selectedSeason = ref('');

    const fetchAvailableSeasons = async () => {
        const payload = await fetchJson<SeasonPayload>(getEndpoint());
        if (!payload) {
            throw new Error('Failed to fetch available seasons');
        }
        availableSeasons.value = Array.isArray(payload.data)
            ? payload.data
                .map((season) => Number(season))
                .filter((season) => Number.isFinite(season))
            : [];

        if (!selectedSeason.value && availableSeasons.value.length > 0) {
            selectedSeason.value = String(availableSeasons.value[0]);
        }
    };

    return {
        availableSeasons,
        selectedSeason,
        fetchAvailableSeasons,
    };
}
