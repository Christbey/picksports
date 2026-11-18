import type { ApiV2CollectionResponse, ApiV2Prediction } from '@/types';

export async function fetchPredictionSlate(
    fetchPage: (
        page: number,
    ) => Promise<ApiV2CollectionResponse<ApiV2Prediction> | null>,
    active: () => boolean,
): Promise<ApiV2Prediction[] | null> {
    const rows: ApiV2Prediction[] = [];
    let lastPage = 1;
    for (let page = 1; page <= lastPage; page++) {
        const payload = await fetchPage(page);
        if (!active() || !payload) return null;
        rows.push(...payload.data);
        lastPage = payload.meta?.pagination?.last_page ?? 1;
    }
    return rows;
}
