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
            const currentYear = new Date().getFullYear();
            const preferredSeason = availableSeasons.value.includes(currentYear)
                ? currentYear
                : Math.max(...availableSeasons.value);

            selectedSeason.value = String(preferredSeason);
        }
    };

    return {
        availableSeasons,
        selectedSeason,
        fetchAvailableSeasons,
    };
}
