<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import {
    formatPropOdds,
    formatPropStat,
    formatPropTimestamp,
    formatProbabilityEdge,
    propFreshness,
    sideProbability,
} from '@/lib/playerPropPresentation';
import type { CoverRecord, Recommendation } from '@/lib/playerPropTypes';

const props = defineProps<{ rec: Recommendation; nowMs: number }>();
const history = computed(() => {
    const summary = props.rec.stats.season_summary;
    const records = props.rec.stats.cover_record;
    return [
        {
            label: 'This season',
            year: summary?.season,
            record: records?.season,
        },
        {
            label: 'Last season',
            year: summary?.season ? summary.season - 1 : null,
            record: records?.last_season,
        },
        { label: 'All available', record: records?.all_time },
        {
            label: `vs ${summary?.opponent ?? 'opponent'}`,
            record: records?.vs_opponent,
        },
        {
            label: `vs ${summary?.opponent_conference ?? 'conference'}`,
            record: records?.vs_conference,
        },
    ];
});
const extraHistory = computed(() => [
    ...history.value,
    ...[
        {
            label: 'Last 17',
            record: props.rec.stats.cover_record?.historical_last_17,
        },
        { label: 'Last 10', record: props.rec.stats.cover_record?.last_10 },
        { label: 'Last 5', record: props.rec.stats.cover_record?.last_5 },
        {
            label: 'Home / away',
            record: props.rec.stats.cover_record?.home_away,
        },
    ].filter((row) => row.record != null),
]);
const count = (record: CoverRecord | null | undefined) =>
    record && record.games > 0 ? `${record.wins}/${record.games}` : 'N/A';
const freshness = computed(() =>
    propFreshness(props.rec.fetched_at, props.rec.freshness_hours, props.nowMs),
);
const result = computed(() => {
    const { graded_at, actual_value, line, recommendation } = props.rec;
    if (!graded_at || actual_value == null || !Number.isFinite(actual_value))
        return null;
    if (Math.abs(actual_value - line) < 0.0001) return 'Push';
    return (
        recommendation === 'Over' ? actual_value > line : actual_value < line
    )
        ? 'Won'
        : 'Lost';
});
</script>

<template>
    <article
        class="min-w-0 rounded-xl border bg-card text-card-foreground"
        :aria-labelledby="`prop-${rec.id}`"
    >
        <div class="space-y-4 p-4">
            <header>
                <div class="flex items-baseline justify-between gap-3">
                    <h2
                        :id="`prop-${rec.id}`"
                        class="min-w-0 text-base leading-snug font-semibold break-words"
                    >
                        <Link
                            v-if="rec.player.url"
                            :href="rec.player.url"
                            class="hover:underline"
                            >{{ rec.player.name }}</Link
                        >
                        <template v-else>{{ rec.player.name }}</template>
                    </h2>
                    <span class="shrink-0 text-xs text-muted-foreground">{{
                        [rec.player.team, rec.player.position]
                            .filter(Boolean)
                            .join(' · ')
                    }}</span>
                </div>
                <p class="mt-1 text-xs text-muted-foreground">
                    {{ rec.game.away_team }} @ {{ rec.game.home_team }}
                </p>
                <time
                    v-if="rec.game.kickoff_label"
                    :datetime="rec.game.starts_at ?? undefined"
                    class="text-xs text-muted-foreground"
                    >{{ rec.game.kickoff_label }}</time
                >
            </header>

            <div
                class="flex items-start justify-between gap-3 rounded-lg bg-muted/50 p-3"
            >
                <div class="min-w-0">
                    <p class="text-xl font-semibold tracking-tight">
                        {{ rec.recommendation }} {{ rec.line }}
                    </p>
                    <p class="text-sm break-words">{{ rec.market }}</p>
                </div>
                <div class="max-w-[45%] text-right">
                    <p class="text-lg font-semibold tabular-nums">
                        {{ formatPropOdds(rec.odds) }}
                    </p>
                    <p class="text-xs break-words text-muted-foreground">
                        {{ rec.bookmaker || 'Unknown sportsbook' }}
                    </p>
                </div>
            </div>

            <p
                v-if="freshness !== 'fresh'"
                role="status"
                class="rounded-md border border-amber-500/40 px-3 py-2 text-xs text-amber-800 dark:text-amber-300"
            >
                {{
                    freshness === 'stale'
                        ? 'Quote expired — check the current line and price.'
                        : 'Quote age unavailable — verify the line and price.'
                }}
            </p>
            <p
                v-if="result"
                class="flex justify-between gap-3 text-sm"
                role="status"
            >
                <strong>{{ result }}</strong
                ><span>Actual: {{ formatPropStat(rec.actual_value) }}</span>
            </p>

            <table class="w-full text-sm">
                <caption
                    class="pb-2 text-left text-xs font-medium text-muted-foreground"
                >
                    Covered
                    {{
                        rec.recommendation.toLowerCase()
                    }}
                    {{
                        rec.line
                    }}
                    · wins / games
                </caption>
                <thead class="sr-only">
                    <tr>
                        <th scope="col">History</th>
                        <th scope="col">Times covered</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-for="row in history" :key="row.label">
                        <th scope="row" class="py-2 text-left font-normal">
                            {{ row.label }}
                            <span
                                v-if="row.year"
                                class="text-xs text-muted-foreground"
                                >({{ row.year }})</span
                            >
                        </th>
                        <td class="py-2 text-right font-semibold tabular-nums">
                            {{ count(row.record) }}
                            <span
                                v-if="row.record?.pushes"
                                class="block text-[11px] font-normal text-muted-foreground"
                                >{{ row.record.pushes }} push{{
                                    row.record.pushes === 1 ? '' : 'es'
                                }}</span
                            >
                        </td>
                    </tr>
                </tbody>
            </table>
            <p class="text-[11px] text-muted-foreground">
                Regular-season history at this line, not past bets. N/A = no
                sample.
            </p>
        </div>

        <details class="border-t">
            <summary
                class="cursor-pointer px-4 py-3 text-sm font-medium focus-visible:rounded-b-xl focus-visible:outline-2 focus-visible:outline-ring"
            >
                More details
            </summary>
            <div class="space-y-4 px-4 pb-4 text-xs">
                <section class="space-y-2">
                    <h3 class="font-semibold">Averages</h3>
                    <dl class="space-y-2">
                        <div class="flex justify-between gap-3">
                            <dt>This season</dt>
                            <dd class="tabular-nums">
                                {{ formatPropStat(rec.stats.season_avg) }}
                                <span class="text-muted-foreground"
                                    >({{
                                        rec.stats.season_summary?.games ?? 0
                                    }}
                                    games)</span
                                >
                            </dd>
                        </div>
                        <div class="flex justify-between gap-3">
                            <dt>Historical average</dt>
                            <dd>
                                {{ formatPropStat(rec.stats.historical_avg) }}
                            </dd>
                        </div>
                        <div class="flex justify-between gap-3">
                            <dt>Last 10 (weighted)</dt>
                            <dd>{{ formatPropStat(rec.stats.recent_avg) }}</dd>
                        </div>
                        <div class="flex justify-between gap-3">
                            <dt>Last 5 (weighted)</dt>
                            <dd>{{ formatPropStat(rec.stats.last5_avg) }}</dd>
                        </div>
                        <div class="flex justify-between gap-3">
                            <dt>vs opponent (weighted)</dt>
                            <dd>
                                {{ formatPropStat(rec.stats.vs_opponent_avg) }}
                            </dd>
                        </div>
                    </dl>
                    <p
                        v-if="(rec.stats.season_summary?.games ?? 0) < 3"
                        class="text-muted-foreground"
                    >
                        Small season sample; history is not a guarantee.
                    </p>
                    <p
                        v-if="rec.stats.season_summary?.missing_stat_games"
                        class="text-muted-foreground"
                    >
                        {{ rec.stats.season_summary.missing_stat_games }} season
                        games excluded for missing stats.
                    </p>
                </section>
                <table class="w-full text-left tabular-nums">
                    <caption class="pb-2 text-left font-semibold">
                        {{
                            rec.recommendation
                        }}
                        records at
                        {{
                            rec.line
                        }}
                    </caption>
                    <thead>
                        <tr class="text-muted-foreground">
                            <th scope="col" class="pb-2 font-normal">
                                History
                            </th>
                            <th scope="col" class="pb-2 text-right font-normal">
                                W–L–P
                            </th>
                            <th scope="col" class="pb-2 text-right font-normal">
                                Win %
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in extraHistory" :key="row.label">
                            <th scope="row" class="py-1 font-normal">
                                {{ row.label }}
                            </th>
                            <td class="text-right">
                                {{
                                    row.record
                                        ? `${row.record.wins}–${row.record.losses}–${row.record.pushes}`
                                        : 'N/A'
                                }}
                            </td>
                            <td class="text-right">
                                {{
                                    row.record?.win_rate == null
                                        ? 'N/A'
                                        : `${row.record.win_rate.toFixed(1)}%`
                                }}
                            </td>
                        </tr>
                    </tbody>
                </table>
                <p class="text-muted-foreground">
                    Games include pushes; win % excludes them. Only available
                    stats before this matchup are counted. All available may not
                    cover a full career. Conference history uses current team
                    membership.
                </p>
                <section class="space-y-2 border-t pt-3">
                    <h3 class="font-semibold">Model details</h3>
                    <p
                        v-if="rec.confidence_decomposition?.probability_method"
                        class="text-muted-foreground"
                    >
                        Model estimate, not calibrated. Probabilities exclude
                        pushes.
                        <template v-if="rec.confidence_decomposition.history">
                            Uses
                            {{
                                rec.confidence_decomposition.history
                                    .model_season
                            }}
                            current-team stats;
                            {{
                                rec.confidence_decomposition.history
                                    .current_season_games
                            }}
                            current-season games.
                        </template>
                    </p>
                    <dl class="space-y-2">
                        <div class="flex justify-between gap-3">
                            <dt>Signal score (not win %)</dt>
                            <dd>{{ rec.confidence }}/100</dd>
                        </div>
                        <div class="flex justify-between gap-3">
                            <dt>{{ rec.recommendation }} probability</dt>
                            <dd>
                                {{
                                    sideProbability(
                                        rec.model_over_probability,
                                        rec.recommendation,
                                    )
                                }}
                            </dd>
                        </div>
                        <div class="flex justify-between gap-3">
                            <dt>Fair market probability</dt>
                            <dd>
                                {{
                                    sideProbability(
                                        rec.market_over_probability,
                                        rec.recommendation,
                                    )
                                }}
                            </dd>
                        </div>
                        <div class="flex justify-between gap-3">
                            <dt>Probability edge</dt>
                            <dd>
                                {{
                                    formatProbabilityEdge(rec.edge_probability)
                                }}
                            </dd>
                        </div>
                        <div
                            v-if="rec.stats.consistency"
                            class="flex justify-between gap-3"
                        >
                            <dt>Stat variability</dt>
                            <dd>
                                ±{{ rec.stats.consistency.std_dev.toFixed(1) }}
                            </dd>
                        </div>
                    </dl>
                </section>
                <p class="border-t pt-3 text-muted-foreground">
                    Quote fetched:
                    {{
                        formatPropTimestamp(rec.fetched_at, rec.game.timezone)
                    }}. Quote window: {{ rec.freshness_hours ?? 24 }} hours.
                </p>
            </div>
        </details>
    </article>
</template>
