import { onScopeDispose, ref } from 'vue';

export interface AdminUserSuggestion {
    id: number;
    name: string;
    email: string;
}

type SearchKey = string | number;
type PendingSearch = {
    timer: ReturnType<typeof setTimeout> | null;
    controller: AbortController | null;
};

export function useAdminUserSearch(
    resolveUrl: (key: SearchKey, query: string) => string,
) {
    const suggestions = ref<Record<string, AdminUserSuggestion[]>>({});
    const searching = ref<Record<string, boolean>>({});
    const pending = new Map<SearchKey, PendingSearch>();
    let disposed = false;

    function cancel(key: SearchKey): void {
        const current = pending.get(key);
        pending.delete(key);
        if (current?.timer) clearTimeout(current.timer);
        current?.controller?.abort();
        searching.value[key] = false;
    }

    function clear(key: SearchKey): void {
        cancel(key);
        suggestions.value[key] = [];
    }

    function search(key: SearchKey, value: string): void {
        cancel(key);
        if (disposed) return;
        const query = value.trim();
        if (query.length < 2) {
            suggestions.value[key] = [];
            return;
        }

        const current: PendingSearch = { timer: null, controller: null };
        pending.set(key, current);
        const isCurrent = () => !disposed && pending.get(key) === current;
        current.timer = setTimeout(async () => {
            current.timer = null;
            current.controller = new AbortController();
            searching.value[key] = true;
            try {
                const response = await fetch(resolveUrl(key, query), {
                    method: 'GET',
                    headers: { Accept: 'application/json' },
                    signal: current.controller.signal,
                });
                if (!response.ok) throw new Error('Failed user lookup.');
                const payload = (await response.json()) as {
                    users?: AdminUserSuggestion[];
                };
                if (isCurrent()) {
                    suggestions.value[key] = Array.isArray(payload.users)
                        ? payload.users
                        : [];
                }
            } catch {
                if (isCurrent()) suggestions.value[key] = [];
            } finally {
                if (isCurrent()) {
                    searching.value[key] = false;
                    pending.delete(key);
                }
            }
        }, 250);
    }

    onScopeDispose(() => {
        disposed = true;
        for (const key of pending.keys()) cancel(key);
    });

    return { suggestions, searching, search, clear };
}
