<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Activity, Radio } from 'lucide-vue-next';
import { computed } from 'vue';
import type { DashboardPrediction, DashboardSport } from '@/types';

const props = defineProps<{
    sports: DashboardSport[];
}>();

type LiveTile = DashboardPrediction & {
    sportName: string;
};

function ordinal(value: number): string {
    const mod100 = value % 100;
    if (mod100 >= 11 && mod100 <= 13) return `${value}th`;

    const suffix =
        value % 10 === 1
            ? 'st'
            : value % 10 === 2
              ? 'nd'
              : value % 10 === 3
                ? 'rd'
                : 'th';

    return `${value}${suffix}`;
}

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
        if (game.inning_state && /\d/.test(game.inning_state)) {
            return game.inning_state;
        }
        if (game.inning) {
            const half = game.inning_state
                ? game.inning_state.charAt(0).toUpperCase() +
                  game.inning_state.slice(1)
                : 'Inning';

            return `${half} ${ordinal(game.inning)}`;
        }
        if (game.inning_state) return game.inning_state;
        return 'Live';
    }

    return 'Final';
}

function liveStateDetail(game: LiveTile): string | null {
    if (!game.is_live) return null;

    const parts = [];
    if (typeof game.outs === 'number') {
        parts.push(`${game.outs} ${game.outs === 1 ? 'out' : 'outs'}`);
    }
    if (typeof game.balls === 'number' && typeof game.strikes === 'number') {
        parts.push(`Count ${game.balls}-${game.strikes}`);
    }

    return parts.length ? parts.join(' · ') : null;
}

function liveTrendLabel(game: LiveTile): string | null {
    if (!game.is_live || typeof game.live_win_probability !== 'number') {
        return null;
    }

    const live =
        game.live_win_probability > 1
            ? game.live_win_probability / 100
            : game.live_win_probability;
    const pregame =
        game.win_probability > 1
            ? game.win_probability / 100
            : game.win_probability;
    const leader = live >= 0.5 ? game.home_team : game.away_team;
    const probability = Math.max(live, 1 - live) * 100;
    const movement = (live - pregame) * 100;
    const beneficiary = movement >= 0 ? game.home_team : game.away_team;
    const movementLabel =
        Math.abs(movement) < 1
            ? 'near pregame'
            : `${beneficiary} +${Math.abs(movement).toFixed(1)} pts`;

    return `${leader} ${probability.toFixed(1)}% · ${movementLabel}`;
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
                class="ui-surface-subtle min-w-[280px] snap-start p-3 transition-all hover:-translate-y-0.5 hover:shadow-sm"
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

                <p
                    v-if="liveStateDetail(game)"
                    class="mb-2 text-xs text-muted-foreground"
                >
                    {{ liveStateDetail(game) }}
                </p>

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

                <div
                    v-if="liveTrendLabel(game)"
                    class="mt-2 flex items-center gap-2 border-t border-border/60 pt-2 text-xs"
                >
                    <Activity
                        class="h-3.5 w-3.5 shrink-0 text-sky-600 dark:text-sky-400"
                    />
                    <span class="truncate font-medium text-foreground/80">
                        {{ liveTrendLabel(game) }}
                    </span>
                </div>
            </Link>
        </div>
    </section>
</template>
