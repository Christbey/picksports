import {
    getCurrentScope,
    onScopeDispose,
    readonly,
    ref,
    shallowRef,
    shallowReadonly,
} from 'vue';

type AsyncResourceOptions = {
    errorMessage?: string;
    clearOnError?: boolean;
};

export function useAsyncResource<T>(
    fetcher: (signal: AbortSignal) => Promise<T | null>,
    options: AsyncResourceOptions = {},
) {
    const data = shallowRef<T | null>(null);
    const error = ref<string | null>(null);
    const isLoading = ref(false);
    const loadedAt = ref<Date | null>(null);
    let controller: AbortController | null = null;
    let latestRequest = 0;
    let disposed = false;

    const execute = async (): Promise<T | null> => {
        if (disposed) return null;
        const request = ++latestRequest;
        controller?.abort();
        const current = new AbortController();
        controller = current;
        isLoading.value = true;
        error.value = null;

        try {
            const response = await fetcher(current.signal);
            if (request !== latestRequest) return null;
            data.value = response;
            loadedAt.value = new Date();
            return response;
        } catch (caught) {
            if (request !== latestRequest) return null;
            error.value =
                caught instanceof Error
                    ? caught.message
                    : (options.errorMessage ?? 'Unable to load resource.');
            if (options.clearOnError !== false) data.value = null;
            return null;
        } finally {
            if (request === latestRequest) {
                isLoading.value = false;
                controller = null;
            }
        }
    };

    if (getCurrentScope()) {
        onScopeDispose(() => {
            disposed = true;
            latestRequest++;
            controller?.abort();
            controller = null;
            isLoading.value = false;
        });
    }

    return {
        data: shallowReadonly(data),
        error: readonly(error),
        isLoading: readonly(isLoading),
        loadedAt: readonly(loadedAt),
        execute,
        reload: execute,
    };
}
