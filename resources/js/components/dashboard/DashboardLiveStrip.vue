<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Activity, Radio } from 'lucide-vue-next';
import { computed } from 'vue';
import { livePeriodLabel } from '@/lib/liveGamePresentation';
import type { DashboardPrediction, DashboardSport } from '@/types';

const props = defineProps<{
    sports: DashboardSport[];
}>();

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

const liveGames = computed(() =>
    props.sports.flatMap((sport) =>
        sport.predictions
            .filter((prediction) => prediction.is_live && !prediction.is_final)
            .map((prediction) => ({
                ...prediction,
                sportName: sport.name,
                trend: liveTrend(prediction),
            })),
    ),
);

function statusLabel(game: DashboardPrediction): string {
    if (
        ['nfl', 'cfb'].includes(game.sport.toLowerCase()) ||
        (game.game_clock && game.period)
    ) {
        return livePeriodLabel(
            game.sport,
            game.period ?? null,
            game.game_clock ?? null,
            game.status ?? null,
        );
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

function liveStateDetail(game: DashboardPrediction): string | null {
    const parts = [];
    if (typeof game.outs === 'number') {
        parts.push(`${game.outs} ${game.outs === 1 ? 'out' : 'outs'}`);
    }
    if (typeof game.balls === 'number' && typeof game.strikes === 'number') {
        parts.push(`Count ${game.balls}-${game.strikes}`);
    }

    return parts.length ? parts.join(' · ') : null;
}

function liveTrend(game: DashboardPrediction) {
    if (typeof game.live_win_probability !== 'number') {
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

    return {
        probability: `${leader} ${probability.toFixed(1)}%`,
        movement: movementLabel,
    };
}
</script>

<template>
    <section
        v-if="liveGames.length > 0"
        class="ui-surface overflow-hidden p-4 shadow-none sm:p-5 lg:p-0"
        aria-label="Live games"
    >
        <div
            class="mb-4 flex flex-wrap items-center justify-between gap-3 lg:mb-0 lg:px-4 lg:py-3"
        >
            <div class="flex items-center gap-2">
                <span
                    class="inline-flex items-center gap-1.5 rounded-full border border-sky-300/60 bg-sky-500/12 px-2.5 py-1 text-[11px] font-semibold tracking-wide text-sky-700 uppercase dark:border-sky-500/30 dark:bg-sky-500/20 dark:text-sky-300"
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
            <p class="text-xs text-muted-foreground">Auto-updating every 30s</p>
        </div>

        <div class="hidden overflow-x-auto border-t border-border lg:block">
            <table class="w-full text-left text-sm" aria-label="Game Center">
                <thead class="bg-muted/60 text-xs text-muted-foreground">
                    <tr>
                        <th
                            v-for="(column, index) in [
                                'League',
                                'Matchup',
                                'Score (away–home)',
                                'Status',
                                'Live win probability',
                            ]"
                            :key="column"
                            scope="col"
                            class="px-4 py-2.5 font-medium whitespace-nowrap"
                            :class="{ 'text-center': index === 2 }"
                        >
                            {{ column }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="game in liveGames"
                        :key="`${game.sport}-${game.game_id}`"
                        class="border-t border-border transition-colors focus-within:bg-muted/35 hover:bg-muted/35"
                    >
                        <td class="w-px px-4 py-3">
                            <span
                                class="rounded-md bg-muted px-2 py-1 text-xs font-semibold text-muted-foreground"
                            >
                                {{ game.sportName }}
                            </span>
                        </td>
                        <th
                            scope="row"
                            class="px-4 py-3 text-left font-semibold"
                        >
                            <Link
                                :href="`/${game.sport.toLowerCase()}/games/${game.game_id}`"
                                class="rounded-sm underline-offset-4 hover:underline focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-ring"
                            >
                                {{ game.away_team }} @ {{ game.home_team }}
                            </Link>
                        </th>
                        <td
                            class="px-4 py-3 text-center text-base font-semibold whitespace-nowrap tabular-nums"
                        >
                            {{ game.away_score ?? '—' }} –
                            {{ game.home_score ?? '—' }}
                        </td>
                        <td class="px-4 py-3">
                            <p
                                class="inline-flex rounded-md bg-sky-500/10 px-2 py-1 text-xs font-semibold whitespace-nowrap text-sky-700 tabular-nums dark:text-sky-300"
                            >
                                {{ statusLabel(game) }}
                            </p>
                            <p
                                v-if="liveStateDetail(game)"
                                class="mt-1 text-xs text-muted-foreground"
                            >
                                {{ liveStateDetail(game) }}
                            </p>
                        </td>
                        <td class="px-4 py-3 tabular-nums">
                            <template v-if="game.trend">
                                <p class="font-semibold">
                                    {{ game.trend.probability }}
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    {{ game.trend.movement }}
                                </p>
                            </template>
                            <span v-else class="text-muted-foreground">—</span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div
            class="flex snap-x snap-mandatory gap-2 overflow-x-auto pb-1 lg:hidden"
        >
            <Link
                v-for="game in liveGames"
                :key="`${game.sport}-${game.game_id}`"
                :href="`/${game.sport.toLowerCase()}/games/${game.game_id}`"
                class="min-w-[260px] snap-start rounded-lg border border-border bg-muted/40 p-4 transition-colors hover:bg-muted sm:min-w-[280px]"
            >
                <div class="mb-2 flex items-center justify-between">
                    <span class="ui-chip text-foreground/80">{{
                        game.sportName
                    }}</span>
                    <span
                        class="text-xs font-semibold text-sky-600 dark:text-sky-400"
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
                    v-if="game.trend"
                    class="mt-2 flex items-center gap-2 border-t border-border/60 pt-2 text-xs"
                >
                    <Activity
                        class="h-3.5 w-3.5 shrink-0 text-sky-600 dark:text-sky-400"
                    />
                    <span class="truncate font-medium text-foreground/80">
                        {{ game.trend.probability }} · {{ game.trend.movement }}
                    </span>
                </div>
            </Link>
        </div>
    </section>
</template>
