<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue';
import NflMatchupSignalEvidence from '@/components/game-page/NflMatchupSignalEvidence.vue';
import type { NflMatchupSignalData } from '@/lib/nflMatchupSignals';

const props = defineProps<{ gameId: number }>();
const data = ref<NflMatchupSignalData | null>(null);
const loading = ref(false);
const error = ref('');
const expanded = ref(false);
const window = ref('season_to_date');
let request: AbortController | null = null;
async function load() {
    if (data.value || loading.value) return;
    const controller = new AbortController();
    request = controller;
    loading.value = true;
    error.value = '';
    try {
        const response = await fetch(
            `/api/v2/sports/nfl/games/${props.gameId}/matchup-signals?window=${window.value}`,
            {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
                signal: controller.signal,
            },
        );
        if (!response.ok) throw new Error('Unavailable');
        const payload = await response.json();
        if (request === controller) data.value = payload.data;
    } catch {
        if (!controller.signal.aborted)
            error.value =
                'Matchup evidence is unavailable right now. No result is inferred.';
    } finally {
        if (request === controller) loading.value = false;
    }
}
function toggle(event: Event) {
    expanded.value = (event.target as HTMLDetailsElement).open;
    if (expanded.value) void load();
}
watch([() => props.gameId, window], () => {
    request?.abort();
    request = null;
    data.value = null;
    loading.value = false;
    error.value = '';
    if (expanded.value) void load();
});
onBeforeUnmount(() => request?.abort());
</script>

<template>
    <details
        class="min-w-0 rounded-xl border bg-card p-4 sm:p-5"
        @toggle="toggle"
    >
        <summary class="min-h-11 cursor-pointer content-center font-semibold">
            Independent matchup analysis · full checklist
        </summary>
        <p class="mt-2 text-xs text-muted-foreground">
            Analysis only. Does not change your model winner, spread, total,
            confidence, or wager approval.
        </p>
        <label v-if="expanded" class="mt-3 block text-sm">
            Efficiency comparison window
            <select
                v-model="window"
                class="mt-1 min-h-11 w-full rounded-md border bg-background px-3"
            >
                <option value="season_to_date">Current season</option>
                <option value="previous_season">
                    Previous season — historical context
                </option>
            </select>
        </label>
        <p
            v-if="!data && !loading && !error"
            class="text-sm text-muted-foreground"
        >
            Open to compare supported efficiency matchups and historical W–L–T
            records.
        </p>
        <p
            v-if="loading"
            role="status"
            class="mt-3 text-sm text-muted-foreground"
        >
            Loading recorded evidence…
        </p>
        <div v-else-if="error" class="mt-3 space-y-2">
            <p role="status" class="text-sm text-muted-foreground">
                {{ error }}
            </p>
            <button
                class="min-h-11 rounded-md border px-3 text-sm"
                type="button"
                @click="load"
            >
                Retry evidence
            </button>
        </div>
        <NflMatchupSignalEvidence v-else-if="data" :data="data" />
    </details>
</template>
