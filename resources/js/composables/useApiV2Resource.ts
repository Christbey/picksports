import { computed, readonly, ref } from 'vue';
import type {
    ApiV2CollectionResponse,
    ApiV2ItemResponse,
    ApiV2Meta,
} from '@/types';

type ApiV2Response<T> =
    | ApiV2CollectionResponse<T>
    | ApiV2ItemResponse<T>
    | null;

type ApiV2ResourceFetcher<T> = () => Promise<ApiV2Response<T>>;

type ApiV2ResourceOptions = {
    immediate?: boolean;
};

const errorMessage = (error: unknown) =>
    error instanceof Error ? error.message : 'Unable to load API v2 resource.';

export function useApiV2Resource<T>(
    fetcher: ApiV2ResourceFetcher<T>,
    options: ApiV2ResourceOptions = {},
) {
    const data = ref<T | T[] | null>(null);
    const meta = ref<ApiV2Meta | null>(null);
    const error = ref<string | null>(null);
    const isLoading = ref(false);
    const loadedAt = ref<Date | null>(null);

    const freshness = computed(() => meta.value?.freshness ?? null);
    const warnings = computed(() => [
        ...(meta.value?.warnings ?? []),
        ...(meta.value?.freshness?.warnings ?? []),
    ]);

    const execute = async () => {
        isLoading.value = true;
        error.value = null;

        try {
            const response = await fetcher();

            data.value = response?.data ?? null;
            meta.value = response?.meta ?? null;
            loadedAt.value = new Date();

            return response;
        } catch (caught) {
            error.value = errorMessage(caught);
            data.value = null;
            meta.value = null;

            return null;
        } finally {
            isLoading.value = false;
        }
    };

    if (options.immediate) {
        void execute();
    }

    return {
        data: readonly(data),
        meta: readonly(meta),
        error: readonly(error),
        freshness,
        warnings,
        isLoading: readonly(isLoading),
        loadedAt: readonly(loadedAt),
        execute,
        reload: execute,
    };
}
