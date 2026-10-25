<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useApiV2Client } from '@/composables/useApiV2Client';
import {
    formatPropOdds,
    formatPropTimestamp,
} from '@/lib/playerPropPresentation';
import type { ApiV2PlayerProp } from '@/types/api-v2';

const props = defineProps<{ date: string; game: string; market: string }>();
const api = useApiV2Client();
const rows = ref<ApiV2PlayerProp[]>([]);
const search = ref('');
const page = ref(1);
const lastPage = ref(1);
const total = ref(0);
const loading = ref(false);
const error = ref<string | null>(null);
let request: AbortController | null = null;
let timer: ReturnType<typeof setTimeout> | undefined;
const marketName = (market: string) =>
    market.replace(/^player_/, '').replaceAll('_', ' ');
const freshness = (date?: string | null) => {
    if (!date || Number.isNaN(Date.parse(date)))
        return 'Quote time unavailable';
    return Date.now() - Date.parse(date) > 36 * 3600_000
        ? 'Stale stored quote'
        : 'Stored quote';
};
async function load() {
    request?.abort();
    const controller = new AbortController();
    request = controller;
    loading.value = true;
    error.value = null;
    rows.value = [];
    try {
        const result = await api.playerProps.index('nfl', {
            query: {
                per_page: 50,
                page: page.value,
                ...(props.date ? { date: props.date } : {}),
                ...(props.game ? { game_id: props.game } : {}),
                ...(props.market ? { market: props.market } : {}),
                ...(search.value.trim() ? { search: search.value.trim() } : {}),
            },
            init: { signal: controller.signal },
        });
        if (controller.signal.aborted) return;
        if (!result) throw new Error('Empty response');
        rows.value = result.data;
        total.value = result.meta.pagination?.total ?? rows.value.length;
        lastPage.value = result.meta.pagination?.last_page ?? 1;
    } catch {
        if (!controller.signal.aborted)
            error.value = 'Unable to load all props. Try again.';
    } finally {
        if (!controller.signal.aborted) loading.value = false;
    }
}
function movePage(next: number) {
    page.value = next;
    void load();
}
watch(
    () => [props.date, props.game, props.market],
    () => {
        clearTimeout(timer);
        page.value = 1;
        void load();
    },
);
function scheduleSearch() {
    request?.abort();
    rows.value = [];
    loading.value = true;
    clearTimeout(timer);
    page.value = 1;
    error.value = null;
    timer = setTimeout(() => void load(), 300);
}
onMounted(() => {
    void load();
});
onBeforeUnmount(() => {
    request?.abort();
    clearTimeout(timer);
});
</script>

<template>
    <section
        class="space-y-4"
        aria-label="All NFL player props"
        :aria-busy="loading"
    >
        <div class="space-y-2">
            <Label for="all-props-search">Find a player</Label>
            <Input
                id="all-props-search"
                v-model="search"
                type="search"
                maxlength="100"
                placeholder="Search every stored player quote"
                @update:model-value="scheduleSearch"
            />
            <p class="text-sm text-muted-foreground">
                All quoted lines and bookmakers are included. A quote is not a
                recommended bet. Missing prices remain unavailable.
            </p>
        </div>
        <p role="status" class="text-sm text-muted-foreground">
            {{
                loading
                    ? 'Loading all props…'
                    : `${total} stored quotes · page ${page} of ${lastPage}`
            }}
        </p>
        <div v-if="error" role="alert">
            <p>{{ error }}</p>
            <Button variant="outline" @click="load">Retry</Button>
        </div>
        <p
            v-else-if="!loading && !rows.length"
            class="text-sm text-muted-foreground"
        >
            No stored quotes match these filters.
        </p>
        <div
            v-if="rows.length"
            class="grid gap-3 md:grid-cols-2 xl:grid-cols-3"
        >
            <article
                v-for="quote in rows"
                :key="quote.id"
                class="min-w-0 space-y-2 rounded-lg border p-4"
            >
                <h2 class="font-semibold">
                    {{ quote.player_name ?? 'Unlinked player' }}
                </h2>
                <p class="text-sm text-muted-foreground">
                    {{ quote.game?.away_team?.abbreviation ?? 'Away' }} at
                    {{ quote.game?.home_team?.abbreviation ?? 'Home' }} ·
                    {{
                        quote.game?.status
                            ?.replace('STATUS_', '')
                            .replaceAll('_', ' ')
                            .toLowerCase()
                    }}
                </p>
                <p class="capitalize">
                    {{ marketName(quote.market ?? '') }} ·
                    {{ quote.line ?? 'Line unavailable' }}
                </p>
                <p>
                    Over
                    {{
                        quote.over_price == null
                            ? 'unavailable'
                            : formatPropOdds(quote.over_price)
                    }}
                    · Under
                    {{
                        quote.under_price == null
                            ? 'unavailable'
                            : formatPropOdds(quote.under_price)
                    }}
                </p>
                <p class="text-sm">
                    {{
                        quote.recommendation?.side
                            ? `Model lean: ${quote.recommendation.side} · not betting approval`
                            : 'No recommendation'
                    }}
                </p>
                <p class="text-xs text-muted-foreground">
                    {{ quote.bookmaker }} · {{ freshness(quote.fetched_at) }} ·
                    {{
                        quote.fetched_at
                            ? formatPropTimestamp(quote.fetched_at)
                            : 'Unknown observation time'
                    }}
                </p>
            </article>
        </div>
        <nav class="flex gap-2" aria-label="All props pages">
            <Button
                variant="outline"
                :disabled="loading || page <= 1"
                @click="movePage(page - 1)"
                >Previous</Button
            >
            <Button
                variant="outline"
                :disabled="loading || page >= lastPage"
                @click="movePage(page + 1)"
                >Next</Button
            >
        </nav>
    </section>
</template>
