<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Radio } from 'lucide-vue-next';
import { computed } from 'vue';
import type { DashboardPrediction, DashboardSport } from '@/types';

const props = defineProps<{
    sports: DashboardSport[];
}>();

type LiveTile = DashboardPrediction & {
    sportName: string;
};

const liveGames = computed<LiveTile[]>(() =>
    props.sports.flatMap((sport) =>
        sport.predictions
            .filter((prediction) => prediction.is_live || prediction.is_final)
            .map((prediction) => ({
                ...prediction,
                sportName: sport.name,
            })),
    ),
);

const hasLiveGames = computed(() =>
    liveGames.value.some((game) => game.is_live),
);

function statusLabel(game: LiveTile): string {
    if (game.is_live) {
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
</script>

<template>
    <section
        v-if="liveGames.length > 0"
        class="ui-surface overflow-hidden p-3 md:p-4"
        aria-label="Live games"
    >
        <div class="mb-3 flex items-center justify-between">
            <div class="flex items-center gap-2">
                <span
                    class="inline-flex items-center gap-1.5 rounded-full border border-red-300/60 bg-red-500/12 px-2.5 py-1 text-[11px] font-semibold tracking-wide text-red-700 uppercase dark:border-red-500/30 dark:bg-red-500/20 dark:text-red-300"
                >
                    <Radio class="size-3.5" />
                    Live
                </span>
                <p
                    class="text-sm font-semibold tracking-tight text-foreground/90"
                >
                    Game Center
                </p>
            </div>
            <p class="text-xs text-muted-foreground">
                {{ hasLiveGames ? 'Auto-updating every 30s' : 'Recent finals' }}
            </p>
        </div>

        <div class="flex snap-x snap-mandatory gap-2 overflow-x-auto pb-1">
            <Link
                v-for="game in liveGames"
                :key="`${game.sport}-${game.game_id}`"
                :href="`/${game.sport.toLowerCase()}/games/${game.game_id}`"
                class="ui-surface-subtle min-w-[250px] snap-start p-3 transition-all hover:-translate-y-0.5 hover:shadow-sm"
            >
                <div class="mb-2 flex items-center justify-between">
                    <span class="ui-chip text-foreground/80">{{
                        game.sportName
                    }}</span>
                    <span
                        class="text-xs font-semibold"
                        :class="
                            game.is_live
                                ? 'text-red-600 dark:text-red-400'
                                : 'text-muted-foreground'
                        "
                    >
                        {{ statusLabel(game) }}
                    </span>
                </div>

                <div class="space-y-1.5">
                    <div class="flex items-center justify-between text-sm">
                        <p class="font-medium text-foreground/85">
                            {{ game.away_team }}
                        </p>
                        <p class="text-base font-semibold">
                            {{ game.away_score ?? '-' }}
                        </p>
                    </div>
                    <div class="flex items-center justify-between text-sm">
                        <p class="font-medium text-foreground/85">
                            {{ game.home_team }}
                        </p>
                        <p class="text-base font-semibold">
                            {{ game.home_score ?? '-' }}
                        </p>
                    </div>
                </div>
            </Link>
        </div>
    </section>
</template>
