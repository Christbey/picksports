import { ref } from 'vue';
import type { PaginationMeta } from '@/types';

export interface PagedResponse<T> {
    data: T[];
    meta: PaginationMeta | null;
}

export function usePredictionList<T>(
    fetcher: (page: number) => Promise<PagedResponse<T>>,
) {
    const items = ref<T[]>([]);
    const meta = ref<PaginationMeta | null>(null);
    const loading = ref(true);
    const error = ref<string | null>(null);
    let latestRequest = 0;

    const fetchPage = async (page = 1): Promise<void> => {
        const request = ++latestRequest;

        try {
            loading.value = true;
            error.value = null;
            const data = await fetcher(page);
            if (request !== latestRequest) return;

            items.value = data.data;
            meta.value = data.meta;
        } catch (e) {
            if (request !== latestRequest) return;
            error.value = e instanceof Error ? e.message : 'An error occurred';
        } finally {
            if (request === latestRequest) {
                loading.value = false;
            }
        }
    };

    return {
        items,
        meta,
        loading,
        error,
        fetchPage,
    };
}
