<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed, onMounted, ref, watch } from 'vue';
import PredictionsPageShell from '@/components/predictions/PredictionsPageShell.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { fetchJson } from '@/composables/useApiClient';

type ForecastTeam = {
    id: number;
    abbreviation: string;
    school?: string | null;
    mascot?: string | null;
    conference?: string | null;
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

type TournamentForecast = {
    id: number;
    team_id: number;
    season: number;
    projected_seed: number | null;
    auto_bid_probability: number;
    at_large_probability: number;
    tournament_make_probability: number;
    first_four_probability: number;
    first_four_auto_probability: number;
    first_four_at_large_probability: number;
    bid_thief_probability: number;
    champion_probability: number;
    final_four_probability: number;
    title_game_probability: number;
    team: ForecastTeam | null;
    market_odds?: MarketOdds | null;
    market_edge?: MarketEdge | null;
};

const forecasts = ref<TournamentForecast[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);
const selectedSeason = ref<number | null>(null);
const sortMode = ref<'model' | 'edge'>('model');
const onlyPlusEv = ref(false);
const availableSeasons = ref<number[]>([]);

const availableSeasonOptions = computed(() =>
    availableSeasons.value.length > 0 ? availableSeasons.value : [selectedSeason.value ?? new Date().getFullYear()],
);

const formatPct = (value: number, digits = 1) => `${(value * 100).toFixed(digits)}%`;
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
    const full = `${team.school ?? ''} ${team.mascot ?? ''}`.trim();
    return full || team.abbreviation || `Team ${fallback}`;
};

const visibleForecasts = computed(() =>
    onlyPlusEv.value
        ? forecasts.value.filter((row) => (row.market_edge?.edge_probability ?? 0) > 0)
        : forecasts.value
);

const edgeValue = (row: TournamentForecast) => row.market_edge?.edge_probability ?? Number.NEGATIVE_INFINITY;
const topByMake = computed(() =>
    [...visibleForecasts.value]
        .sort((a, b) => {
            if (sortMode.value === 'edge') {
                return edgeValue(b) - edgeValue(a);
            }

            return b.tournament_make_probability - a.tournament_make_probability;
        })
        .slice(0, 24)
);
const topByTitle = computed(() => [...visibleForecasts.value].sort((a, b) => b.champion_probability - a.champion_probability).slice(0, 24));
const bubbleRisk = computed(() =>
    [...visibleForecasts.value]
        .filter((f) => f.tournament_make_probability > 0.05 && f.tournament_make_probability < 0.95)
        .sort((a, b) => b.first_four_probability - a.first_four_probability)
        .slice(0, 20),
);

const conferenceBreakdown = computed(() => {
    const byConference = new Map<string, {
        conference: string;
        teamsTracked: number;
        projectedIn: number;
        expectedBids: number;
        avgMakeProbability: number;
        predictedWinner: string;
        predictedWinnerAutoBidProbability: number;
    }>();

    for (const row of visibleForecasts.value) {
        const conference = (row.team?.conference ?? 'Unknown').trim() || 'Unknown';
        const current = byConference.get(conference) ?? {
            conference,
            teamsTracked: 0,
            projectedIn: 0,
            expectedBids: 0,
            avgMakeProbability: 0,
            predictedWinner: formatTeam(row.team, row.team_id),
            predictedWinnerAutoBidProbability: row.auto_bid_probability,
        };

        current.teamsTracked += 1;
        current.projectedIn += row.tournament_make_probability >= 0.5 ? 1 : 0;
        current.expectedBids += row.tournament_make_probability;

        if (row.auto_bid_probability > current.predictedWinnerAutoBidProbability) {
            current.predictedWinner = formatTeam(row.team, row.team_id);
            current.predictedWinnerAutoBidProbability = row.auto_bid_probability;
        }

        byConference.set(conference, current);
    }

    return Array.from(byConference.values())
        .map((row) => ({
            ...row,
            expectedBids: Number(row.expectedBids.toFixed(2)),
            avgMakeProbability: row.teamsTracked > 0 ? row.expectedBids / row.teamsTracked : 0,
            predictedWinnerAutoBidProbability: Number(row.predictedWinnerAutoBidProbability.toFixed(4)),
        }))
        .sort((a, b) => {
            if (b.projectedIn !== a.projectedIn) return b.projectedIn - a.projectedIn;
            if (b.expectedBids !== a.expectedBids) return b.expectedBids - a.expectedBids;
            return a.conference.localeCompare(b.conference);
        });
});

const projectedInCount = computed(() => visibleForecasts.value.filter((f) => f.tournament_make_probability >= 0.5).length);
const bubbleCount = computed(() => visibleForecasts.value.filter((f) => f.tournament_make_probability >= 0.35 && f.tournament_make_probability < 0.75).length);
const avgBidThiefRisk = computed(() => {
    if (visibleForecasts.value.length === 0) return 0;
    return visibleForecasts.value.reduce((sum, row) => sum + row.bid_thief_probability, 0) / visibleForecasts.value.length;
});
const topChampion = computed(() => topByTitle.value[0] ?? null);

const statusTag = (row: TournamentForecast) => {
    const make = row.tournament_make_probability;
    if (make >= 0.85) return 'Lock';
    if (make >= 0.55) return 'Likely In';
    if (make >= 0.35) return 'Bubble';
    if (make >= 0.12) return 'Outside';
    return 'Longshot';
};

const statusTagClass = (row: TournamentForecast) => {
    const make = row.tournament_make_probability;
    if (make >= 0.85) return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-200';
    if (make >= 0.55) return 'bg-teal-100 text-teal-800 dark:bg-teal-900/30 dark:text-teal-200';
    if (make >= 0.35) return 'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-200';
    if (make >= 0.12) return 'bg-orange-100 text-orange-800 dark:bg-orange-900/30 dark:text-orange-200';
    return 'bg-zinc-200 text-zinc-800 dark:bg-zinc-800 dark:text-zinc-200';
};

const meterClass = (value: number) => {
    const pct = toPct(value);
    if (pct >= 70) return 'bg-emerald-500';
    if (pct >= 45) return 'bg-amber-500';
    return 'bg-rose-500';
};

const fetchForecasts = async () => {
    if (!selectedSeason.value) return;

    loading.value = true;
    error.value = null;

    try {
        const payload = await fetchJson<{ data?: TournamentForecast[]; meta?: { available_seasons?: number[] } }>(
            `/api/v1/cbb/tournament-forecasts?season=${selectedSeason.value}`,
        );
        if (!payload) {
            throw new Error('Failed to load tournament forecast data');
        }
        forecasts.value = payload?.data ?? [];
        availableSeasons.value = payload?.meta?.available_seasons ?? [];
    } catch (e) {
        error.value = e instanceof Error ? e.message : 'An error occurred while loading forecast data.';
        forecasts.value = [];
    } finally {
        loading.value = false;
    }
};

watch(selectedSeason, () => {
    fetchForecasts();
});

onMounted(async () => {
    selectedSeason.value = new Date().getFullYear();
    await fetchForecasts();
    if (availableSeasons.value.length > 0 && !availableSeasons.value.includes(selectedSeason.value)) {
        selectedSeason.value = availableSeasons.value[0];
    }
});
</script>

<template>
    <Head title="CBB Tournament Forecast" />

    <PredictionsPageShell
        title="CBB Tournament Forecast"
        breadcrumb-title="CBB Tournament Forecast"
        breadcrumb-href="/cbb-tournament-forecast"
        banner-storage-key="cbb-tournament-forecast-banner-dismissed"
        seo-description="March Madness forecast with automatic bid probability, at-large probability, First Four risk, and championship odds."
    >
        <div class="space-y-4">
            <Card>
                <CardContent class="pt-6">
                    <div class="flex flex-wrap items-end gap-4">
                        <div class="min-w-[220px]">
                            <label for="season" class="text-sm font-medium">Season</label>
                            <select
                                id="season"
                                v-model.number="selectedSeason"
                                class="mt-1 flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-base ring-offset-background focus:ring-2 focus:ring-ring focus:ring-offset-2 focus:outline-none"
                            >
                                <option v-for="season in availableSeasonOptions" :key="season" :value="season">
                                    {{ season }}
                                </option>
                            </select>
                        </div>
                        <div class="min-w-[220px]">
                            <label for="sort-mode" class="text-sm font-medium">Sort</label>
                            <select
                                id="sort-mode"
                                v-model="sortMode"
                                class="mt-1 flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-base ring-offset-background focus:ring-2 focus:ring-ring focus:ring-offset-2 focus:outline-none"
                            >
                                <option value="model">Model Probability</option>
                                <option value="edge">Best Edge</option>
                            </select>
                        </div>
                        <label class="mt-6 inline-flex items-center gap-2 text-sm font-medium text-muted-foreground">
                            <input
                                v-model="onlyPlusEv"
                                type="checkbox"
                                class="h-4 w-4 rounded border-input"
                            >
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
                            <p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Projected In</p>
                            <p class="mt-2 text-3xl font-bold">{{ projectedInCount }}</p>
                            <p class="mt-1 text-xs text-muted-foreground">Teams with make probability 50%+</p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent class="pt-5">
                            <p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Bubble Watch</p>
                            <p class="mt-2 text-3xl font-bold">{{ bubbleCount }}</p>
                            <p class="mt-1 text-xs text-muted-foreground">Teams in 35-75% make range</p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent class="pt-5">
                            <p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Top Title Odds</p>
                            <p class="mt-2 text-lg font-bold">{{ topChampion ? formatTeam(topChampion.team, topChampion.team_id) : '-' }}</p>
                            <p class="mt-1 text-sm text-muted-foreground">{{ topChampion ? formatPct(topChampion.champion_probability, 2) : '-' }}</p>
                            <p class="mt-1 text-xs text-muted-foreground">Market {{ topChampion ? formatAmericanOdds(topChampion.market_odds?.price) : '-' }}</p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent class="pt-5">
                            <p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Avg Bid-Thief Risk</p>
                            <p class="mt-2 text-3xl font-bold">{{ formatPct(avgBidThiefRisk, 2) }}</p>
                            <p class="mt-1 text-xs text-muted-foreground">Across all forecasted teams</p>
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Top Tournament Make Probabilities</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div class="overflow-x-auto rounded-md border">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="border-b bg-muted/30 text-left">
                                        <th class="p-2 font-medium">Team</th>
                                        <th class="p-2 font-medium">Status</th>
                                        <th class="p-2 font-medium">Conf</th>
                                        <th class="p-2 text-right font-medium">Seed</th>
                                        <th class="p-2 font-medium">AQ / AL / First 4</th>
                                        <th class="p-2 font-medium">Make Probability</th>
                                        <th class="p-2 text-right font-medium">Edge</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="row in topByMake" :key="row.id" class="border-b">
                                        <td class="p-2 font-medium">{{ formatTeam(row.team, row.team_id) }}</td>
                                        <td class="p-2">
                                            <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-semibold" :class="statusTagClass(row)">
                                                {{ statusTag(row) }}
                                            </span>
                                        </td>
                                        <td class="p-2 text-muted-foreground">{{ row.team?.conference ?? '-' }}</td>
                                        <td class="p-2 text-right">{{ row.projected_seed ?? '-' }}</td>
                                        <td class="p-2">
                                            <div class="flex flex-wrap gap-1.5">
                                                <span class="rounded bg-blue-100 px-1.5 py-0.5 text-xs font-medium text-blue-800 dark:bg-blue-900/30 dark:text-blue-200">AQ {{ formatPct(row.auto_bid_probability) }}</span>
                                                <span class="rounded bg-indigo-100 px-1.5 py-0.5 text-xs font-medium text-indigo-800 dark:bg-indigo-900/30 dark:text-indigo-200">AL {{ formatPct(row.at_large_probability) }}</span>
                                                <span class="rounded bg-amber-100 px-1.5 py-0.5 text-xs font-medium text-amber-800 dark:bg-amber-900/30 dark:text-amber-200">F4 {{ formatPct(row.first_four_probability) }}</span>
                                            </div>
                                        </td>
                                        <td class="p-2">
                                            <div class="flex items-center gap-2">
                                                <div class="h-2.5 w-28 overflow-hidden rounded-full bg-muted">
                                                    <div class="h-full rounded-full transition-all" :class="meterClass(row.tournament_make_probability)" :style="{ width: `${toPct(row.tournament_make_probability)}%` }" />
                                                </div>
                                                <span class="text-xs font-semibold">{{ formatPct(row.tournament_make_probability) }}</span>
                                            </div>
                                        </td>
                                        <td class="p-2 text-right text-xs font-semibold" :class="(row.market_edge?.edge_probability ?? 0) >= 0 ? 'text-emerald-600' : 'text-rose-600'">
                                            <div class="flex items-center justify-end">
                                                <span
                                                    class="inline-flex rounded-full px-1.5 py-0.5 text-[10px] font-semibold"
                                                    :class="(row.market_edge?.has_edge ?? false)
                                                        ? ((row.market_edge?.edge_probability ?? 0) > 0
                                                            ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-200'
                                                            : 'bg-rose-100 text-rose-700 dark:bg-rose-900/30 dark:text-rose-200')
                                                        : 'bg-zinc-200 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300'"
                                                >
                                                    {{ !(row.market_edge?.has_edge ?? false)
                                                        ? 'No Edge'
                                                        : `${(row.market_edge?.edge_probability ?? 0) > 0 ? '+EV' : '-EV'} ${formatEdgePoints(row.market_edge?.edge_percent_points)}` }}
                                                </span>
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Conference Bid Forecast</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div class="overflow-x-auto rounded-md border">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="border-b bg-muted/30 text-left">
                                        <th class="p-2 font-medium">Conference</th>
                                        <th class="p-2 font-medium">Predicted Winner</th>
                                        <th class="p-2 text-right font-medium">Teams Tracked</th>
                                        <th class="p-2 text-right font-medium">Projected In</th>
                                        <th class="p-2 text-right font-medium">Expected Bids</th>
                                        <th class="p-2 text-right font-medium">Avg Make%</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="row in conferenceBreakdown" :key="`conf-${row.conference}`" class="border-b">
                                        <td class="p-2 font-medium">{{ row.conference }}</td>
                                        <td class="p-2">
                                            <div class="flex flex-col">
                                                <span class="font-medium">{{ row.predictedWinner }}</span>
                                                <span class="text-xs text-muted-foreground">AQ {{ formatPct(row.predictedWinnerAutoBidProbability, 1) }}</span>
                                            </div>
                                        </td>
                                        <td class="p-2 text-right">{{ row.teamsTracked }}</td>
                                        <td class="p-2 text-right font-semibold">{{ row.projectedIn }}</td>
                                        <td class="p-2 text-right">{{ row.expectedBids.toFixed(2) }}</td>
                                        <td class="p-2 text-right">{{ formatPct(row.avgMakeProbability) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </CardContent>
                </Card>

                <div class="grid gap-4 lg:grid-cols-3">
                    <Card v-for="row in topByTitle.slice(0, 12)" :key="`title-${row.id}`">
                        <CardContent class="pt-4">
                            <div class="flex items-start justify-between gap-2">
                                <div>
                                    <p class="text-sm font-semibold">{{ formatTeam(row.team, row.team_id) }}</p>
                                    <p class="text-xs text-muted-foreground">{{ row.team?.conference ?? 'Independent' }} · Seed {{ row.projected_seed ?? '-' }}</p>
                                </div>
                                <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-semibold" :class="statusTagClass(row)">
                                    {{ statusTag(row) }}
                                </span>
                            </div>

                            <div class="mt-4 space-y-2">
                                <div>
                                    <div class="mb-1 flex items-center justify-between text-xs">
                                        <span class="text-muted-foreground">Final Four</span>
                                        <span class="font-medium">{{ formatPct(row.final_four_probability, 2) }}</span>
                                    </div>
                                    <div class="h-2 overflow-hidden rounded-full bg-muted">
                                        <div class="h-full rounded-full bg-sky-500" :style="{ width: `${toPct(row.final_four_probability)}%` }" />
                                    </div>
                                </div>
                                <div>
                                    <div class="mb-1 flex items-center justify-between text-xs">
                                        <span class="text-muted-foreground">Title Game</span>
                                        <span class="font-medium">{{ formatPct(row.title_game_probability, 2) }}</span>
                                    </div>
                                    <div class="h-2 overflow-hidden rounded-full bg-muted">
                                        <div class="h-full rounded-full bg-violet-500" :style="{ width: `${toPct(row.title_game_probability)}%` }" />
                                    </div>
                                </div>
                                <div>
                                    <div class="mb-1 flex items-center justify-between text-xs">
                                        <span class="text-muted-foreground">Champion</span>
                                        <span class="font-semibold">{{ formatPct(row.champion_probability, 2) }}</span>
                                    </div>
                                    <div class="h-2.5 overflow-hidden rounded-full bg-muted">
                                        <div class="h-full rounded-full bg-emerald-500" :style="{ width: `${toPct(row.champion_probability)}%` }" />
                                    </div>
                                    <p class="mt-1 text-[11px] text-muted-foreground">Market {{ formatAmericanOdds(row.market_odds?.price) }}</p>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                <Card v-if="bubbleRisk.length > 0" class="border-amber-200/60 dark:border-amber-900/40">
                    <CardHeader>
                        <CardTitle>Bubble Watch / Bid-Thief Risk</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div class="overflow-x-auto rounded-md border border-amber-200/50 dark:border-amber-900/40">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="border-b bg-amber-50/60 text-left dark:bg-amber-950/20">
                                        <th class="p-2 font-medium">Team</th>
                                        <th class="p-2 font-medium">Status</th>
                                        <th class="p-2 text-right font-medium">Bid Thief%</th>
                                        <th class="p-2 text-right font-medium">First 4 (AQ)%</th>
                                        <th class="p-2 text-right font-medium">First 4 (AL)%</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="row in bubbleRisk" :key="row.id" class="border-b">
                                        <td class="p-2 font-medium">{{ formatTeam(row.team, row.team_id) }}</td>
                                        <td class="p-2">
                                            <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-semibold" :class="statusTagClass(row)">
                                                {{ statusTag(row) }}
                                            </span>
                                        </td>
                                        <td class="p-2 text-right">{{ formatPct(row.bid_thief_probability, 2) }}</td>
                                        <td class="p-2 text-right">{{ formatPct(row.first_four_auto_probability, 2) }}</td>
                                        <td class="p-2 text-right">{{ formatPct(row.first_four_at_large_probability, 2) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </CardContent>
                </Card>
            </template>
        </div>
    </PredictionsPageShell>
</template>
