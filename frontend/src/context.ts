import { reactive } from 'vue';
import { ApiError, fetchJson } from '@/composables/useApiClient';
import type { AppPageProps } from '@/types';

type Context = Pick<AppPageProps, 'auth' | 'subscription' | 'name'>;
export const session = reactive<{ context: Context | null; loaded: boolean }>({
    context: null,
    loaded: false,
});
let pending: Promise<void> | null = null;
export async function loadSession(): Promise<void> {
    if (pending) return pending;
    pending = (async () => {
        try {
            const response = await fetchJson<{ data: Context }>(
                '/api/v2/auth/context',
            );
            if (!response?.data)
                throw new Error('Account information is unavailable.');
            session.context = response.data;
        } catch (error) {
            if (!(error instanceof ApiError) || error.status !== 401)
                throw error;
            session.context = null;
        }
        session.loaded = true;
    })();
    try {
        await pending;
    } finally {
        pending = null;
    }
}
