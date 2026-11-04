import { computed } from 'vue';
import { useAsyncResource } from '@/composables/useAsyncResource';
import type { ApiV2CollectionResponse, ApiV2ItemResponse } from '@/types';

type ApiV2Response<T> =
    | ApiV2CollectionResponse<T>
    | ApiV2ItemResponse<T>
    | null;

type ApiV2ResourceFetcher<T> = (
    signal: AbortSignal,
) => Promise<ApiV2Response<T>>;

type ApiV2ResourceOptions = {
    immediate?: boolean;
};

export function useApiV2Resource<T>(
    fetcher: ApiV2ResourceFetcher<T>,
    options: ApiV2ResourceOptions = {},
) {
    const resource = useAsyncResource(fetcher, {
        errorMessage: 'Unable to load API v2 resource.',
    });
    const data = computed(() => resource.data.value?.data ?? null);
    const meta = computed(() => resource.data.value?.meta ?? null);
    const freshness = computed(() => meta.value?.freshness ?? null);
    const warnings = computed(() => [
        ...(meta.value?.warnings ?? []),
        ...(meta.value?.freshness?.warnings ?? []),
    ]);

    if (options.immediate) {
        void resource.execute();
    }

    return {
        ...resource,
        data,
        meta,
        freshness,
        warnings,
    };
}
