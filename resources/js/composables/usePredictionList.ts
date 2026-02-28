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

    const fetchPage = async (page = 1): Promise<void> => {
        try {
            loading.value = true;
            error.value = null;
            const data = await fetcher(page);
            items.value = data.data;
            meta.value = data.meta;
        } catch (e) {
            error.value = e instanceof Error ? e.message : 'An error occurred';
        } finally {
            loading.value = false;
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
