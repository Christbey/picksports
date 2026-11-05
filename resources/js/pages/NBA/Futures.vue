<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import PredictionsPageShell from '@/components/predictions/PredictionsPageShell.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { provide } from 'vue';
import { useNbaFutures, nbaFuturesKey } from '@/composables/useNbaFutures';

const state = useNbaFutures();
provide(nbaFuturesKey, state);
const {
    loading,
    error,
    selectedSeason,
    sortMode,
    onlyPlusEv,
    playoffTeamsPerConference,
    availableSeasonOptions,
    formatPct,
    formatEdgePoints,
    formatAmericanOdds,
    toPct,
    formatTeam,
    topByMake,
    topByTitle,
    bubbleWatch,
    conferenceBreakdown,
    projectedInCount,
    playInFieldSize,
    avgTitleOdds,
    topChampion,
    statusTag,
    statusTagClass,
    meterClass,
} = state;
</script>
<template>
    <Head title="NBA Futures" />

    <PredictionsPageShell
        title="NBA Futures"
        sport-title="NBA"
        sport-href="/nba/predictions"
        page-title="Futures"
        banner-storage-key="nba-futures-banner-dismissed"
        seo-description="NBA postseason forecast with playoff odds, conference finals odds, Finals probability, and championship futures."
    >
        <div class="space-y-5">
            <Card>
                <CardContent class="pt-6">
                    <div class="flex flex-wrap items-end gap-4">
                        <div class="min-w-[220px]">
                            <p class="ui-kicker">Season</p>
                            <select
                                id="season"
                                v-model.number="selectedSeason"
                                class="ui-select mt-1"
                            >
                                <option
                                    v-for="season in availableSeasonOptions"
                                    :key="season"
                                    :value="season"
                                >
                                    {{ season }}
                                </option>
                            </select>
                        </div>
                        <div class="min-w-[220px]">
                            <p class="ui-kicker">Sort</p>
                            <select
                                id="sort-mode"
                                v-model="sortMode"
                                class="ui-select mt-1"
                            >
                                <option value="model">Model Probability</option>
                                <option value="edge">Best Edge</option>
                            </select>
                        </div>
                        <label
                            class="mt-5 inline-flex items-center gap-2 text-sm font-medium text-muted-foreground"
                        >
                            <input
                                v-model="onlyPlusEv"
                                type="checkbox"
                                class="h-4 w-4 rounded border-input"
                            />
                            Only +EV
                        </label>
                    </div>
                </CardContent>
            </Card>

            <Alert v-if="error" variant="destructive">
                <AlertDescription>{{ error }}</AlertDescription>
            </Alert>

            <div v-if="loading" class="space-y-3">
                <Skeleton v-for="i in 8" :key="i" class="h-12 w-full" />
            </div>

            <template v-else>
                <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                    <Card>
                        <CardContent class="pt-5">
                            <p
                                class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
                            >
                                Projected Playoff Teams
                            </p>
                            <p class="mt-2 text-3xl font-bold">
                                {{ projectedInCount }}
                            </p>
                            <p class="mt-1 text-xs text-muted-foreground">
                                Threshold: 50%+ ({{ playoffTeamsPerConference }}
                                spots per conference)
                            </p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent class="pt-5">
                            <p
                                class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
                            >
                                Bubble Watch
                            </p>
                            <p class="mt-2 text-3xl font-bold">
                                {{ bubbleWatch.length }}
                            </p>
                            <p class="mt-1 text-xs text-muted-foreground">
                                Teams fighting for play-in spots (7-10)
                            </p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent class="pt-5">
                            <p
                                class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
                            >
                                Top Champion
                            </p>
                            <p class="mt-2 text-lg font-bold">
                                {{
                                    topChampion
                                        ? formatTeam(
                                              topChampion.team,
                                              topChampion.team_id,
                                          )
                                        : '-'
                                }}
                            </p>
                            <p class="mt-1 text-sm text-muted-foreground">
                                {{
                                    topChampion
                                        ? formatPct(
                                              topChampion.champion_probability,
                                              2,
                                          )
                                        : '-'
                                }}
                            </p>
                            <p class="mt-1 text-xs text-muted-foreground">
                                Market
                                {{
                                    topChampion
                                        ? formatAmericanOdds(
                                              topChampion.market_odds?.price,
                                          )
                                        : '-'
                                }}
                            </p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent class="pt-5">
                            <p
                                class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
                            >
                                Avg Title Odds
                            </p>
                            <p class="mt-2 text-3xl font-bold">
                                {{ formatPct(avgTitleOdds, 2) }}
                            </p>
                            <p class="mt-1 text-xs text-muted-foreground">
                                Across all tracked teams
                            </p>
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader>
                        <div class="ui-kicker">Conference View</div>
                        <CardTitle class="tracking-tight"
                            >Conference Playoff Forecast</CardTitle
                        >
                    </CardHeader>
                    <CardContent>
                        <div class="ui-table-wrap">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="border-b bg-muted/30 text-left">
                                        <th class="p-2 font-medium">
                                            Conference
                                        </th>
                                        <th class="p-2 font-medium">
                                            Predicted Winner
                                        </th>
                                        <th class="p-2 text-right font-medium">
                                            Teams Tracked
                                        </th>
                                        <th class="p-2 text-right font-medium">
                                            Projected In
                                        </th>
                                        <th class="p-2 text-right font-medium">
                                            Projected Play-In
                                        </th>
                                        <th class="p-2 text-right font-medium">
                                            Division Leaders In
                                        </th>
                                        <th class="p-2 text-right font-medium">
                                            Expected Bids
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="row in conferenceBreakdown"
                                        :key="`conf-${row.conference}`"
                                        class="border-b odd:bg-muted/15"
                                    >
                                        <td class="p-2 font-medium">
                                            {{ row.conference }}
                                        </td>
                                        <td class="p-2">
                                            <div class="flex flex-col">
                                                <span class="font-medium">{{
                                                    row.predictedWinner
                                                }}</span>
                                                <span
                                                    class="text-xs text-muted-foreground"
                                                    >Finals
                                                    {{
                                                        formatPct(
                                                            row.winnerFinalsProbability,
                                                            1,
                                                        )
                                                    }}</span
                                                >
                                            </div>
                                        </td>
                                        <td class="p-2 text-right">
                                            {{ row.teamsTracked }}
                                        </td>
                                        <td
                                            class="p-2 text-right font-semibold"
                                        >
                                            {{ row.projectedIn }} /
                                            {{ playoffTeamsPerConference }}
                                        </td>
                                        <td class="p-2 text-right">
                                            {{ row.projectedPlayIn }} /
                                            {{ playInFieldSize }}
                                        </td>
                                        <td class="p-2 text-right">
                                            {{ row.divisionLeadersProjectedIn }}
                                            / 3
                                        </td>
                                        <td class="p-2 text-right">
                                            {{ row.expectedBids.toFixed(2) }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <div class="ui-kicker">Team Odds</div>
                        <CardTitle class="tracking-tight"
                            >Top Playoff Probabilities</CardTitle
                        >
                    </CardHeader>
                    <CardContent>
                        <div class="ui-table-wrap">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="border-b bg-muted/30 text-left">
                                        <th class="p-2 font-medium">Team</th>
                                        <th class="p-2 font-medium">Status</th>
                                        <th class="p-2 text-right font-medium">
                                            Conf Rank
                                        </th>
                                        <th class="p-2 text-right font-medium">
                                            Seed
                                        </th>
                                        <th class="p-2 font-medium">
                                            Playoff Make
                                        </th>
                                        <th class="p-2 font-medium">
                                            Play-In Spot
                                        </th>
                                        <th class="p-2 text-right font-medium">
                                            Edge
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="row in topByMake"
                                        :key="row.id"
                                        class="border-b odd:bg-muted/15"
                                    >
                                        <td class="p-2 font-medium">
                                            {{
                                                formatTeam(
                                                    row.team,
                                                    row.team_id,
                                                )
                                            }}
                                        </td>
                                        <td class="p-2">
                                            <span
                                                class="inline-flex rounded-full px-2 py-0.5 text-xs font-semibold"
                                                :class="statusTagClass(row)"
                                                >{{ statusTag(row) }}</span
                                            >
                                        </td>
                                        <td class="p-2 text-right">
                                            {{ row.conference_rank ?? '-' }}
                                        </td>
                                        <td class="p-2 text-right">
                                            {{ row.projected_seed ?? '-' }}
                                        </td>
                                        <td class="p-2">
                                            <div
                                                class="flex items-center gap-2"
                                            >
                                                <div
                                                    class="h-2.5 w-28 overflow-hidden rounded-full bg-muted"
                                                >
                                                    <div
                                                        class="h-full rounded-full transition-all"
                                                        :class="
                                                            meterClass(
                                                                row.playoff_make_probability,
                                                            )
                                                        "
                                                        :style="{
                                                            width: `${toPct(row.playoff_make_probability)}%`,
                                                        }"
                                                    />
                                                </div>
                                                <span
                                                    class="text-xs font-semibold"
                                                    >{{
                                                        formatPct(
                                                            row.playoff_make_probability,
                                                            1,
                                                        )
                                                    }}</span
                                                >
                                            </div>
                                        </td>
                                        <td class="p-2">
                                            <div
                                                class="flex items-center gap-2"
                                            >
                                                <div
                                                    class="h-2.5 w-28 overflow-hidden rounded-full bg-muted"
                                                >
                                                    <div
                                                        class="h-full rounded-full bg-amber-500 transition-all"
                                                        :style="{
                                                            width: `${toPct(row.play_in_tournament_probability)}%`,
                                                        }"
                                                    />
                                                </div>
                                                <span
                                                    class="text-xs font-semibold"
                                                    >{{
                                                        formatPct(
                                                            row.play_in_tournament_probability,
                                                            1,
                                                        )
                                                    }}</span
                                                >
                                            </div>
                                        </td>
                                        <td
                                            class="p-2 text-right text-xs font-semibold"
                                            :class="
                                                (row.market_edge
                                                    ?.edge_probability ?? 0) >=
                                                0
                                                    ? 'text-emerald-600'
                                                    : 'text-rose-600'
                                            "
                                        >
                                            <div
                                                class="flex items-center justify-end"
                                            >
                                                <span
                                                    class="inline-flex rounded-full px-1.5 py-0.5 text-[10px] font-semibold"
                                                    :class="
                                                        (row.market_edge
                                                            ?.has_edge ?? false)
                                                            ? (row.market_edge
                                                                  ?.edge_probability ??
                                                                  0) > 0
                                                                ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-200'
                                                                : 'bg-rose-100 text-rose-700 dark:bg-rose-900/30 dark:text-rose-200'
                                                            : 'bg-zinc-200 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300'
                                                    "
                                                >
                                                    {{
                                                        !(
                                                            row.market_edge
                                                                ?.has_edge ??
                                                            false
                                                        )
                                                            ? 'No Edge'
                                                            : `${(row.market_edge?.edge_probability ?? 0) > 0 ? '+EV' : '-EV'} ${formatEdgePoints(row.market_edge?.edge_percent_points)}`
                                                    }}
                                                </span>
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </CardContent>
                </Card>

                <div class="grid gap-4 lg:grid-cols-3">
                    <Card
                        v-for="row in topByTitle.slice(0, 12)"
                        :key="`title-${row.id}`"
                    >
                        <CardContent class="pt-4">
                            <div class="flex items-start justify-between gap-2">
                                <div>
                                    <p
                                        class="text-sm font-semibold tracking-tight"
                                    >
                                        {{ formatTeam(row.team, row.team_id) }}
                                    </p>
                                    <p class="text-xs text-muted-foreground">
                                        {{ row.conference ?? '-' }} · Seed
                                        {{ row.projected_seed ?? '-' }}
                                    </p>
                                </div>
                                <span
                                    class="inline-flex rounded-full px-2 py-0.5 text-xs font-semibold"
                                    :class="statusTagClass(row)"
                                    >{{ statusTag(row) }}</span
                                >
                            </div>
                            <div class="mt-4 space-y-2">
                                <div>
                                    <div
                                        class="mb-1 flex items-center justify-between text-xs"
                                    >
                                        <span class="text-muted-foreground"
                                            >Win Division</span
                                        >
                                        <span class="font-medium">{{
                                            formatPct(
                                                row.division_win_probability,
                                                2,
                                            )
                                        }}</span>
                                    </div>
                                    <div
                                        class="h-2 overflow-hidden rounded-full bg-amber-500/20"
                                    >
                                        <div
                                            class="h-full rounded-full bg-amber-500"
                                            :style="{
                                                width: `${toPct(row.division_win_probability)}%`,
                                            }"
                                        />
                                    </div>
                                </div>
                                <div>
                                    <div
                                        class="mb-1 flex items-center justify-between text-xs"
                                    >
                                        <span class="text-muted-foreground"
                                            >Conference Finals</span
                                        >
                                        <span class="font-medium">{{
                                            formatPct(
                                                row.conference_finals_probability,
                                                2,
                                            )
                                        }}</span>
                                    </div>
                                    <div
                                        class="h-2 overflow-hidden rounded-full bg-muted"
                                    >
                                        <div
                                            class="h-full rounded-full bg-sky-500"
                                            :style="{
                                                width: `${toPct(row.conference_finals_probability)}%`,
                                            }"
                                        />
                                    </div>
                                </div>
                                <div>
                                    <div
                                        class="mb-1 flex items-center justify-between text-xs"
                                    >
                                        <span class="text-muted-foreground"
                                            >NBA Finals</span
                                        >
                                        <span class="font-medium">{{
                                            formatPct(
                                                row.nba_finals_probability,
                                                2,
                                            )
                                        }}</span>
                                    </div>
                                    <div
                                        class="h-2 overflow-hidden rounded-full bg-violet-500/20"
                                    >
                                        <div
                                            class="h-full rounded-full bg-violet-500"
                                            :style="{
                                                width: `${toPct(row.nba_finals_probability)}%`,
                                            }"
                                        />
                                    </div>
                                </div>
                                <div>
                                    <div
                                        class="mb-1 flex items-center justify-between text-xs"
                                    >
                                        <span class="text-muted-foreground"
                                            >Champion</span
                                        >
                                        <span class="font-semibold">{{
                                            formatPct(
                                                row.champion_probability,
                                                2,
                                            )
                                        }}</span>
                                    </div>
                                    <div
                                        class="h-2.5 overflow-hidden rounded-full bg-muted"
                                    >
                                        <div
                                            class="h-full rounded-full bg-emerald-500"
                                            :style="{
                                                width: `${toPct(row.champion_probability)}%`,
                                            }"
                                        />
                                    </div>
                                    <p
                                        class="mt-1 text-[11px] text-muted-foreground"
                                    >
                                        Market
                                        {{
                                            formatAmericanOdds(
                                                row.market_odds?.price,
                                            )
                                        }}
                                    </p>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                </div>
            </template>
        </div>
    </PredictionsPageShell>
</template>
