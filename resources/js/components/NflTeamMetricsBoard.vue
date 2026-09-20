<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { formatNumber } from '@/components/sport-team-metrics-helpers';
import type { Column } from '@/components/SportTeamMetrics.vue';

defineProps<{
    metrics: any[];
    columns: Column[];
    teamLink: (id: number) => string;
    sortLabel: string;
}>();

const groups = [
    {
        title: 'Offense & defense',
        labels: ['Off EPA', 'Def EPA', 'Net', 'YPG', 'YA/G', 'TO+/-'],
    },
    {
        title: 'Situational performance',
        labels: [
            'Home',
            'Away',
            'HFA',
            'L5',
            'L10',
            'Div',
            'NonDiv',
            '1H',
            '2H',
        ],
    },
    {
        title: 'Model & schedule context',
        labels: [
            'Predict',
            'Season SOS',
            'Future SOS',
            'Luck',
            'Cons',
            'Vs 1-5',
            'Vs 6-10',
            'Vs 11-16',
            'Vs 17-22',
            'Vs 23-32',
            'Form',
            'InjAdj',
            'Fatigue',
        ],
    },
];
const descriptions: Record<string, string> = {
    Predict: 'Heuristic rating',
    Net: 'Scoring margin / game',
    YPG: 'Yards / reported game',
    'YA/G': 'Yards allowed / reported game',
    'TO+/-': 'Turnover margin / game',
    Home: 'Home scoring margin',
    Away: 'Away scoring margin',
    HFA: 'Home minus away margin',
    L5: 'Last 5 scoring margin',
    L10: 'Last 10 scoring margin',
    Div: 'Division scoring margin',
    NonDiv: 'Non-division scoring margin',
    '1H': 'First-half margin',
    '2H': 'Second-half + OT margin',
    Cons: 'Consistency score',
    Luck: 'Win rate minus expected (pp)',
    'Season SOS': 'Played schedule (Elo)',
    'Future SOS': 'Remaining schedule (Elo)',
    Form: 'Recent weighted margin',
    InjAdj: 'Injury-adjusted Elo',
    Fatigue: 'Schedule fatigue estimate',
};
const sampleKeys: Record<string, string> = {
    YPG: 'yards_per_game',
    'YA/G': 'yards_allowed_per_game',
    'TO+/-': 'turnover_differential',
    Home: 'home',
    Away: 'away',
    L5: 'last_5',
    L10: 'last_10',
    'Off EPA': 'offensive_epa_plays',
    'Def EPA': 'defensive_epa_plays',
};
function sample(metric: any, label: string): string {
    const count = metric.sample_sizes?.[sampleKeys[label]];
    return count == null
        ? ''
        : `${count} ${label.includes('EPA') ? 'play' : 'game'}${count === 1 ? '' : 's'}`;
}
function updated(metric: any): string {
    const value = metric.updated_at ?? metric.calculation_date;
    if (!value) return 'Update time unknown';
    const date = new Date(value);
    return Number.isNaN(date.getTime())
        ? 'Update time unknown'
        : `Calculated ${date.toLocaleString('en-US', { timeZone: 'America/Chicago', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit', timeZoneName: 'short' })}`;
}
</script>

<template>
    <section aria-label="NFL team rankings" class="min-w-0 space-y-3">
        <p class="text-xs text-muted-foreground">
            Ordered by {{ sortLabel }}. EPA is expected points added per
            eligible play; lower defensive EPA is better. Early-season rankings
            use small samples.
        </p>
        <p
            v-if="!metrics.length"
            role="status"
            class="rounded-xl border p-6 text-sm text-muted-foreground"
        >
            No teams match this season, season type or search.
        </p>
        <article
            v-for="metric in metrics"
            :key="metric.id"
            class="min-w-0 rounded-xl border bg-card p-4"
        >
            <div
                class="grid grid-cols-2 items-start gap-3 sm:grid-cols-3 lg:grid-cols-[minmax(180px,2fr)_repeat(5,minmax(0,1fr))]"
            >
                <div class="col-span-2 min-w-0 sm:col-span-3 lg:col-span-1">
                    <Link
                        v-if="metric.team?.id"
                        :href="teamLink(metric.team.id)"
                        class="font-semibold hover:underline"
                        ><span class="mr-2 text-muted-foreground">{{
                            metric.display_rank == null
                                ? '—'
                                : `#${metric.display_rank}`
                        }}</span
                        >{{ metric.team.display_name }}</Link
                    >
                    <span v-else class="font-semibold">Unknown team</span>
                    <p class="mt-1 text-xs text-muted-foreground">
                        {{ updated(metric) }}
                    </p>
                </div>
                <div>
                    <p class="text-xs text-muted-foreground">W–L–T</p>
                    <p class="font-semibold tabular-nums">
                        {{ metric.record_label ?? '—' }}
                    </p>
                </div>
                <div>
                    <p class="text-xs text-muted-foreground">Games</p>
                    <p class="font-semibold tabular-nums">
                        {{ metric.games_played ?? '—' }}
                    </p>
                </div>
                <div>
                    <p class="text-xs text-muted-foreground">Net EPA / play</p>
                    <p class="font-semibold tabular-nums">
                        {{
                            metric.sample_sizes
                                ? formatNumber(metric.net_true_epa_per_play, 3)
                                : '—'
                        }}
                    </p>
                </div>
                <div>
                    <p class="text-xs text-muted-foreground">Points / game</p>
                    <p class="font-semibold tabular-nums">
                        {{
                            metric.sample_sizes
                                ? formatNumber(metric.points_per_game)
                                : '—'
                        }}
                    </p>
                </div>
                <div>
                    <p class="text-xs text-muted-foreground">Allowed / game</p>
                    <p class="font-semibold tabular-nums">
                        {{
                            metric.sample_sizes
                                ? formatNumber(metric.points_allowed_per_game)
                                : '—'
                        }}
                    </p>
                </div>
            </div>
            <p
                v-if="!metric.sample_sizes"
                class="mt-3 text-xs text-amber-700 dark:text-amber-400"
            >
                Recalculation required: this older row has no verified sample
                counts.
            </p>
            <template v-else>
                <p
                    v-if="metric.games_played < 5"
                    class="mt-3 text-xs text-amber-700 dark:text-amber-400"
                >
                    Small sample: {{ metric.games_played }} completed games.
                    Missing or insufficient metrics display —.
                </p>
                <details class="mt-3 border-t pt-3">
                    <summary class="cursor-pointer text-sm font-medium">
                        View offense, defense & situational details
                    </summary>
                    <p class="mt-3 text-xs text-muted-foreground">
                        Yardage averages use reported games only. Turnover
                        margin requires complete stats for both teams. Model
                        ratings are descriptive estimates, not win
                        probabilities. Opponent rank groups use current Elo
                        ranks.
                    </p>
                    <section
                        v-for="group in groups"
                        :key="group.title"
                        class="mt-4"
                    >
                        <h3 class="text-sm font-semibold">{{ group.title }}</h3>
                        <dl
                            class="mt-2 grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-4"
                        >
                            <div
                                v-for="col in columns.filter((column) =>
                                    group.labels.includes(column.label),
                                )"
                                :key="col.label"
                                class="min-w-0 rounded-lg bg-muted/30 p-3"
                            >
                                <dt class="text-xs text-muted-foreground">
                                    {{ descriptions[col.label] ?? col.label }}
                                </dt>
                                <dd
                                    class="mt-1 font-medium tabular-nums"
                                    :class="col.class?.(metric)"
                                >
                                    {{ col.value(metric) }}
                                </dd>
                                <dd
                                    v-if="sample(metric, col.label)"
                                    class="mt-1 text-xs text-muted-foreground"
                                >
                                    {{ sample(metric, col.label) }}
                                </dd>
                            </div>
                        </dl>
                    </section>
                </details>
            </template>
        </article>
    </section>
</template>
