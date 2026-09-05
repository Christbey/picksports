<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import PredictionsPageShell from '@/components/predictions/PredictionsPageShell.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { useApiV2Client } from '@/composables/useApiV2Client';

type ForecastTeam = {
    id: number;
    abbreviation: string;
    location?: string | null;
    name?: string | null;
    conference?: string | null;
    division?: string | null;
};

type MarketOdds = {
    bookmaker: string;
    market_key: string;
    price: number | null;
    implied_probability: number | null;
    fetched_at: string | null;
    odds_api_sport_key: string;
};

type MarketEdge = {
    model_probability: number | null;
    market_probability: number | null;
    edge_probability: number | null;
    edge_percent_points: number | null;
    fair_price: number | null;
    market_price: number | null;
    has_edge: boolean;
};

type PlayoffForecast = {
    id: number;
    team_id: number;
    season: number;
    conference: string | null;
    conference_rank: number | null;
    projected_seed: number | null;
    playoff_make_probability: number;
    direct_playoff_probability: number;
    play_in_tournament_probability: number;
    division_win_probability: number;
    conference_finals_probability: number;
    nba_finals_probability: number;
    champion_probability: number;
    selection_score: number;
    team: ForecastTeam | null;
    market_odds?: MarketOdds | null;
    market_edge?: MarketEdge | null;
};

type ForecastMeta = {
    available_seasons?: number[];
    playoff_teams_per_conference?: number;
    play_in_teams_per_conference?: number;
    season?: number;
    requested_season?: number;
    fallback_applied?: boolean;
};

const forecasts = ref<PlayoffForecast[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);
const selectedSeason = ref<number>(new Date().getFullYear());
const sortMode = ref<'model' | 'edge'>('model');
const onlyPlusEv = ref(false);
const availableSeasons = ref<number[]>([]);
const playoffTeamsPerConference = ref(8);
const playInTeamsPerConference = ref(10);
const activeRequestId = ref(0);
let activeAbortController: AbortController | null = null;
const api = useApiV2Client();

const availableSeasonOptions = computed(() =>
    availableSeasons.value.length > 0
        ? availableSeasons.value
        : [selectedSeason.value],
);

const formatPct = (value: number, digits = 1) =>
    `${(value * 100).toFixed(digits)}%`;
const formatEdgePoints = (value: number | null | undefined) => {
    if (value === null || value === undefined) return '-';
    const rounded = value.toFixed(1);
    return value >= 0 ? `+${rounded}pp` : `${rounded}pp`;
};
const formatAmericanOdds = (value: number | null | undefined) => {
    if (value === null || value === undefined) return '-';
    return value > 0 ? `+${value}` : `${value}`;
};
const toPct = (value: number) => Math.max(0, Math.min(100, value * 100));
const formatTeam = (team: ForecastTeam | null, fallback: number) => {
    if (!team) return `Team ${fallback}`;
    const full = `${team.location ?? ''} ${team.name ?? ''}`.trim();
    return full || team.abbreviation || `Team ${fallback}`;
};

const visibleForecasts = computed(() =>
    onlyPlusEv.value
        ? forecasts.value.filter(
              (row) => (row.market_edge?.edge_probability ?? 0) > 0,
          )
        : forecasts.value,
);

const edgeValue = (row: PlayoffForecast) =>
    row.market_edge?.edge_probability ?? Number.NEGATIVE_INFINITY;
const topByMake = computed(() =>
    [...visibleForecasts.value]
        .sort((a, b) => {
            if (sortMode.value === 'edge') {
                return edgeValue(b) - edgeValue(a);
            }

            return b.playoff_make_probability - a.playoff_make_probability;
        })
        .slice(0, 20),
);
const topByTitle = computed(() =>
    [...visibleForecasts.value]
        .sort((a, b) => b.champion_probability - a.champion_probability)
        .slice(0, 20),
);
const bubbleWatch = computed(() =>
    [...visibleForecasts.value]
        .filter(
            (row) =>
                row.play_in_tournament_probability >= 0.15 &&
                row.play_in_tournament_probability <= 0.8 &&
                row.playoff_make_probability <= 0.9,
        )
        .sort(
            (a, b) =>
                b.play_in_tournament_probability -
                a.play_in_tournament_probability,
        )
        .slice(0, 12),
);

const conferenceBreakdown = computed(() => {
    const byConference = new Map<
        string,
        {
            conference: string;
            teamsTracked: number;
            projectedIn: number;
            expectedBids: number;
            projectedPlayIn: number;
            divisionLeadersProjectedIn: number;
            predictedWinner: string;
            winnerFinalsProbability: number;
        }
    >();

    const divisionLeaders = new Set<string>();
    const byConferenceDivision = new Map<string, PlayoffForecast[]>();
    for (const row of visibleForecasts.value) {
        const conference =
            (row.conference ?? row.team?.conference ?? 'Unknown').trim() ||
            'Unknown';
        const divisionName = row.team?.division ?? 'Unknown';
        const key = `${conference}::${divisionName}`;
        const current = byConferenceDivision.get(key) ?? [];
        current.push(row);
        byConferenceDivision.set(key, current);
    }

    for (const [key, rows] of byConferenceDivision.entries()) {
        const leader = [...rows].sort((a, b) => {
            if (b.playoff_make_probability !== a.playoff_make_probability) {
                return b.playoff_make_probability - a.playoff_make_probability;
            }
            const aRank = a.conference_rank ?? 99;
            const bRank = b.conference_rank ?? 99;
            return aRank - bRank;
        })[0];
        if (leader) {
            divisionLeaders.add(`${key}::${leader.team_id}`);
        }
    }

    for (const row of visibleForecasts.value) {
        const conference =
            (row.conference ?? row.team?.conference ?? 'Unknown').trim() ||
            'Unknown';
        const divisionName = row.team?.division ?? 'Unknown';
        const current = byConference.get(conference) ?? {
            conference,
            teamsTracked: 0,
            projectedIn: 0,
            expectedBids: 0,
            projectedPlayIn: 0,
            divisionLeadersProjectedIn: 0,
            predictedWinner: formatTeam(row.team, row.team_id),
            winnerFinalsProbability: row.nba_finals_probability,
        };

        current.teamsTracked += 1;
        current.projectedIn += row.playoff_make_probability >= 0.5 ? 1 : 0;
        current.projectedPlayIn +=
            row.play_in_tournament_probability >= 0.5 ? 1 : 0;
        current.expectedBids += row.playoff_make_probability;
        if (
            row.playoff_make_probability >= 0.5 &&
            divisionLeaders.has(
                `${conference}::${divisionName}::${row.team_id}`,
            )
        ) {
            current.divisionLeadersProjectedIn += 1;
        }

        if (row.nba_finals_probability > current.winnerFinalsProbability) {
            current.predictedWinner = formatTeam(row.team, row.team_id);
            current.winnerFinalsProbability = row.nba_finals_probability;
        }

        byConference.set(conference, current);
    }

    return Array.from(byConference.values())
        .map((row) => ({
            ...row,
            expectedBids: Number(row.expectedBids.toFixed(2)),
        }))
        .sort((a, b) => b.projectedIn - a.projectedIn);
});

const projectedInCount = computed(
    () =>
        visibleForecasts.value.filter((f) => f.playoff_make_probability >= 0.5)
            .length,
);
const playInFieldSize = computed(() =>
    Math.max(
        0,
        playInTeamsPerConference.value -
            Math.max(1, playoffTeamsPerConference.value - 2),
    ),
);
const avgTitleOdds = computed(() => {
    if (visibleForecasts.value.length === 0) return 0;
    return (
        visibleForecasts.value.reduce(
            (sum, row) => sum + row.champion_probability,
            0,
        ) / visibleForecasts.value.length
    );
});
const topChampion = computed(() => topByTitle.value[0] ?? null);

const statusTag = (row: PlayoffForecast) => {
    const make = row.playoff_make_probability;
    if (make >= 0.85) return 'Playoff Lock';
    if (make >= 0.55) return 'In Picture';
    if (make >= 0.35) return 'Bubble';
    return 'Longshot';
};

const statusTagClass = (row: PlayoffForecast) => {
    const make = row.playoff_make_probability;
    if (make >= 0.85)
        return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-200';
    if (make >= 0.55)
        return 'bg-sky-100 text-sky-800 dark:bg-sky-900/30 dark:text-sky-200';
    if (make >= 0.35)
        return 'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-200';
    return 'bg-zinc-200 text-zinc-800 dark:bg-zinc-800 dark:text-zinc-200';
};

const meterClass = (value: number) => {
    const pct = toPct(value);
    if (pct >= 70) return 'bg-emerald-500';
    if (pct >= 45) return 'bg-amber-500';
    return 'bg-rose-500';
};

const fetchForecasts = async () => {
    const requestId = activeRequestId.value + 1;
    activeRequestId.value = requestId;

    if (activeAbortController) {
        activeAbortController.abort();
    }
    activeAbortController = new AbortController();

    loading.value = true;
    error.value = null;

    try {
        const payload = await api.forecasts.index<PlayoffForecast>('nba', {
            query: { season: selectedSeason.value },
            init: {
                signal: activeAbortController.signal,
            },
        });
        if (!payload) {
            throw new Error(
                'Failed to load NBA futures data. Please refresh and sign in again if your session expired.',
            );
        }
        if (requestId !== activeRequestId.value) {
            return;
        }

        const meta = (payload.meta ?? {}) as ForecastMeta;

        forecasts.value = payload.data ?? [];
        availableSeasons.value = meta.available_seasons ?? [];
        playoffTeamsPerConference.value =
            meta.playoff_teams_per_conference ?? 8;
        playInTeamsPerConference.value =
            meta.play_in_teams_per_conference ?? 10;
        const resolvedSeason = Number(meta.season ?? selectedSeason.value);
        const requestedSeason = Number(
            meta.requested_season ?? selectedSeason.value,
        );
        const fallbackApplied = Boolean(meta.fallback_applied ?? false);

        if (
            availableSeasons.value.length > 0 &&
            !availableSeasons.value.includes(selectedSeason.value)
        ) {
            selectedSeason.value = availableSeasons.value[0];
        }

        if (forecasts.value.length === 0) {
            error.value = `No NBA futures rows found for season ${requestedSeason}.`;
        } else if (fallbackApplied && resolvedSeason !== requestedSeason) {
            error.value = `No rows for ${requestedSeason}; showing latest available season ${resolvedSeason}.`;
        }
    } catch (e) {
        if (e instanceof DOMException && e.name === 'AbortError') {
            return;
        }
        if (requestId !== activeRequestId.value) {
            return;
        }

        error.value =
            e instanceof Error
                ? e.message
                : 'An error occurred while loading NBA futures.';
        forecasts.value = [];
    } finally {
        if (requestId === activeRequestId.value) {
            loading.value = false;
        }
    }
};

watch(
    selectedSeason,
    () => {
        void fetchForecasts();
    },
    { immediate: true },
);
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
