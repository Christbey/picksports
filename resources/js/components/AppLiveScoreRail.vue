<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Radio } from 'lucide-vue-next';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { useApiV2Client } from '@/composables/useApiV2Client';
import type { DashboardPrediction } from '@/types';

const games = ref<DashboardPrediction[]>([]);
const loading = ref(false);
const api = useApiV2Client();
let timer: ReturnType<typeof setTimeout> | null = null;

const hasLiveGames = computed(() => games.value.some((game) => game.is_live));

function ordinal(value: number): string {
    const mod100 = value % 100;
    const suffix =
        mod100 >= 11 && mod100 <= 13
            ? 'th'
            : value % 10 === 1
              ? 'st'
              : value % 10 === 2
                ? 'nd'
                : value % 10 === 3
                  ? 'rd'
                  : 'th';

    return `${value}${suffix}`;
}

function inningStateLabel(value: string): string {
    const normalized = value.toLowerCase();
    if (normalized.startsWith('top')) return 'Top';
    if (normalized.startsWith('bot')) return 'Bottom';
    if (normalized.startsWith('mid')) return 'Mid';
    if (normalized.startsWith('end')) return 'End';

    return value;
}

function statusLabel(game: DashboardPrediction): string {
    if (game.is_live) {
        if (
            game.sport.toLowerCase() === 'mlb' &&
            game.inning &&
            game.inning_state
        ) {
            return `${inningStateLabel(game.inning_state)} ${ordinal(game.inning)}`;
        }
        if (game.game_clock && game.period) {
            return `P${game.period} ${game.game_clock}`;
        }
        if (game.inning && game.inning_state) {
            return `${game.inning_state} ${game.inning}`;
        }
        return 'Live';
    }

    return 'Final';
}

function pollIntervalMs(): number {
    return hasLiveGames.value ? 30_000 : 120_000;
}

async function fetchGames(): Promise<void> {
    if (loading.value) return;

    loading.value = true;
    try {
        const response = await api.liveScoreboard.show();
        games.value = Array.isArray(response?.data?.games)
            ? response.data.games
            : [];
    } finally {
        loading.value = false;
    }
}

function stopPolling(): void {
    if (!timer) return;
    clearTimeout(timer);
    timer = null;
}

function scheduleNextPoll(): void {
    stopPolling();
    timer = setTimeout(async () => {
        if (document.hidden) {
            scheduleNextPoll();
            return;
        }

        await fetchGames();
        scheduleNextPoll();
    }, pollIntervalMs());
}

onMounted(async () => {
    await fetchGames();
    scheduleNextPoll();
});

onUnmounted(() => {
    stopPolling();
});
</script>

<template>
    <div
        v-if="games.length > 0"
        class="border-b border-sidebar-border/70 bg-background/55 px-2 py-2 backdrop-blur-lg md:px-4"
    >
        <div class="flex items-center gap-2 overflow-x-auto">
            <span
                class="sticky left-0 z-10 inline-flex shrink-0 items-center gap-1 rounded-full border border-red-300/50 bg-red-500/10 px-2 py-0.5 text-[10px] font-semibold tracking-wide text-red-700 uppercase backdrop-blur dark:border-red-500/30 dark:bg-red-500/20 dark:text-red-300"
            >
                <Radio class="size-3 animate-pulse" />
                Live
            </span>

            <Link
                v-for="game in games"
                :key="`${game.sport}-${game.game_id}`"
                :href="`/${game.sport.toLowerCase()}/games/${game.game_id}`"
                class="ui-surface-subtle flex min-w-[184px] shrink-0 items-center justify-between gap-3 px-2.5 py-1.5 text-xs transition-all hover:-translate-y-0.5 hover:shadow-sm"
            >
                <div class="min-w-0">
                    <p class="truncate font-semibold text-foreground/90">
                        {{ game.away_team }} {{ game.away_score ?? '-' }} -
                        {{ game.home_score ?? '-' }} {{ game.home_team }}
                    </p>
                    <p
                        class="truncate text-[10px] tracking-wide text-muted-foreground uppercase"
                    >
                        {{ game.sport }} · {{ statusLabel(game) }}
                    </p>
                </div>
            </Link>
        </div>
    </div>
</template>
