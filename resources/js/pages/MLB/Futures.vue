<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed, onMounted, ref, watch } from 'vue';
import PredictionsPageShell from '@/components/predictions/PredictionsPageShell.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';

type ForecastTeam = {
    id: number;
    abbreviation: string;
    location?: string | null;
    name?: string | null;
    league?: string | null;
};

type PlayoffForecast = {
    id: number;
    team_id: number;
    season: number;
    league: string | null;
    league_rank: number | null;
    projected_seed: number | null;
    playoff_make_probability: number;
    league_championship_probability: number;
    world_series_probability: number;
    champion_probability: number;
    selection_score: number;
    team: ForecastTeam | null;
};

const forecasts = ref<PlayoffForecast[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);
const selectedSeason = ref<number | null>(null);
const availableSeasons = ref<number[]>([]);

const availableSeasonOptions = computed(() =>
    availableSeasons.value.length > 0 ? availableSeasons.value : [selectedSeason.value ?? new Date().getFullYear()],
);

const formatPct = (value: number, digits = 1) => `${(value * 100).toFixed(digits)}%`;
const toPct = (value: number) => Math.max(0, Math.min(100, value * 100));
const formatTeam = (team: ForecastTeam | null, fallback: number) => {
    if (!team) return `Team ${fallback}`;
    const full = `${team.location ?? ''} ${team.name ?? ''}`.trim();
    return full || team.abbreviation || `Team ${fallback}`;
};

const topByMake = computed(() => [...forecasts.value].sort((a, b) => b.playoff_make_probability - a.playoff_make_probability).slice(0, 20));
const topByTitle = computed(() => [...forecasts.value].sort((a, b) => b.champion_probability - a.champion_probability).slice(0, 20));
const bubbleWatch = computed(() =>
    [...forecasts.value]
        .filter((row) => row.playoff_make_probability >= 0.25 && row.playoff_make_probability < 0.75)
        .sort((a, b) => b.playoff_make_probability - a.playoff_make_probability)
        .slice(0, 12),
);

const leagueBreakdown = computed(() => {
    const byLeague = new Map<string, {
        league: string;
        teamsTracked: number;
        projectedIn: number;
        expectedBids: number;
        predictedWinner: string;
        winnerLcsProbability: number;
    }>();

    for (const row of forecasts.value) {
        const league = (row.league ?? row.team?.league ?? 'Unknown').trim() || 'Unknown';
        const current = byLeague.get(league) ?? {
            league,
            teamsTracked: 0,
            projectedIn: 0,
            expectedBids: 0,
            predictedWinner: formatTeam(row.team, row.team_id),
            winnerLcsProbability: row.league_championship_probability,
        };

        current.teamsTracked += 1;
        current.projectedIn += row.playoff_make_probability >= 0.5 ? 1 : 0;
        current.expectedBids += row.playoff_make_probability;

        if (row.league_championship_probability > current.winnerLcsProbability) {
            current.predictedWinner = formatTeam(row.team, row.team_id);
            current.winnerLcsProbability = row.league_championship_probability;
        }

        byLeague.set(league, current);
    }

    return Array.from(byLeague.values())
        .map((row) => ({
            ...row,
            expectedBids: Number(row.expectedBids.toFixed(2)),
        }))
        .sort((a, b) => b.projectedIn - a.projectedIn);
});

const projectedInCount = computed(() => forecasts.value.filter((f) => f.playoff_make_probability >= 0.5).length);
const avgTitleOdds = computed(() => {
    if (forecasts.value.length === 0) return 0;
    return forecasts.value.reduce((sum, row) => sum + row.champion_probability, 0) / forecasts.value.length;
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
    if (make >= 0.85) return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-200';
    if (make >= 0.55) return 'bg-sky-100 text-sky-800 dark:bg-sky-900/30 dark:text-sky-200';
    if (make >= 0.35) return 'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-200';
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
        const response = await fetch(`/api/v1/mlb/playoff-forecasts?season=${selectedSeason.value}`);
        if (!response.ok) {
            throw new Error('Failed to load MLB futures data');
        }
        const payload = await response.json();
        forecasts.value = payload?.data ?? [];
        availableSeasons.value = payload?.meta?.available_seasons ?? [];
    } catch (e) {
        error.value = e instanceof Error ? e.message : 'An error occurred while loading MLB futures.';
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
    if (availableSeasons.value.length > 0 && selectedSeason.value !== null && !availableSeasons.value.includes(selectedSeason.value)) {
        selectedSeason.value = availableSeasons.value[0];
    }
});
</script>

<template>
    <Head title="MLB Futures" />

    <PredictionsPageShell
        title="MLB Futures"
        breadcrumb-title="MLB Futures"
        breadcrumb-href="/mlb-futures"
        banner-storage-key="mlb-futures-banner-dismissed"
        seo-description="MLB postseason forecast with playoff odds, LCS probability, World Series probability, and championship futures."
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
                                <option v-for="season in availableSeasonOptions" :key="season" :value="season">
                                    {{ season }}
                                </option>
                            </select>
                        </div>
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
                            <p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Projected Playoff Teams</p>
                            <p class="mt-2 text-3xl font-bold">{{ projectedInCount }}</p>
                            <p class="mt-1 text-xs text-muted-foreground">Teams with playoff make probability 50%+</p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent class="pt-5">
                            <p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Bubble Watch</p>
                            <p class="mt-2 text-3xl font-bold">{{ bubbleWatch.length }}</p>
                            <p class="mt-1 text-xs text-muted-foreground">Teams in 25-75% range</p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent class="pt-5">
                            <p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Top Champion</p>
                            <p class="mt-2 text-lg font-bold">{{ topChampion ? formatTeam(topChampion.team, topChampion.team_id) : '-' }}</p>
                            <p class="mt-1 text-sm text-muted-foreground">{{ topChampion ? formatPct(topChampion.champion_probability, 2) : '-' }}</p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent class="pt-5">
                            <p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Avg Title Odds</p>
                            <p class="mt-2 text-3xl font-bold">{{ formatPct(avgTitleOdds, 2) }}</p>
                            <p class="mt-1 text-xs text-muted-foreground">Across all tracked teams</p>
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader>
                        <div class="ui-kicker">League View</div>
                        <CardTitle class="tracking-tight">League Playoff Forecast</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div class="ui-table-wrap">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="border-b bg-muted/30 text-left">
                                        <th class="p-2 font-medium">League</th>
                                        <th class="p-2 font-medium">Predicted Winner</th>
                                        <th class="p-2 text-right font-medium">Teams Tracked</th>
                                        <th class="p-2 text-right font-medium">Projected In</th>
                                        <th class="p-2 text-right font-medium">Expected Bids</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="row in leagueBreakdown" :key="`lg-${row.league}`" class="border-b odd:bg-muted/15">
                                        <td class="p-2 font-medium">{{ row.league }}</td>
                                        <td class="p-2">
                                            <div class="flex flex-col">
                                                <span class="font-medium">{{ row.predictedWinner }}</span>
                                                <span class="text-xs text-muted-foreground">LCS {{ formatPct(row.winnerLcsProbability, 1) }}</span>
                                            </div>
                                        </td>
                                        <td class="p-2 text-right">{{ row.teamsTracked }}</td>
                                        <td class="p-2 text-right font-semibold">{{ row.projectedIn }}</td>
                                        <td class="p-2 text-right">{{ row.expectedBids.toFixed(2) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <div class="ui-kicker">Team Odds</div>
                        <CardTitle class="tracking-tight">Top Playoff Probabilities</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div class="ui-table-wrap">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="border-b bg-muted/30 text-left">
                                        <th class="p-2 font-medium">Team</th>
                                        <th class="p-2 font-medium">Status</th>
                                        <th class="p-2 text-right font-medium">League Rank</th>
                                        <th class="p-2 text-right font-medium">Seed</th>
                                        <th class="p-2 font-medium">Playoff Make</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="row in topByMake" :key="row.id" class="border-b odd:bg-muted/15">
                                        <td class="p-2 font-medium">{{ formatTeam(row.team, row.team_id) }}</td>
                                        <td class="p-2">
                                            <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-semibold" :class="statusTagClass(row)">{{ statusTag(row) }}</span>
                                        </td>
                                        <td class="p-2 text-right">{{ row.league_rank ?? '-' }}</td>
                                        <td class="p-2 text-right">{{ row.projected_seed ?? '-' }}</td>
                                        <td class="p-2">
                                            <div class="flex items-center gap-2">
                                                <div class="h-2.5 w-28 overflow-hidden rounded-full bg-muted">
                                                    <div class="h-full rounded-full transition-all" :class="meterClass(row.playoff_make_probability)" :style="{ width: `${toPct(row.playoff_make_probability)}%` }" />
                                                </div>
                                                <span class="text-xs font-semibold">{{ formatPct(row.playoff_make_probability, 1) }}</span>
                                            </div>
                                        </td>
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
                                    <p class="text-sm font-semibold tracking-tight">{{ formatTeam(row.team, row.team_id) }}</p>
                                    <p class="text-xs text-muted-foreground">{{ row.league ?? '-' }} · Seed {{ row.projected_seed ?? '-' }}</p>
                                </div>
                                <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-semibold" :class="statusTagClass(row)">{{ statusTag(row) }}</span>
                            </div>
                            <div class="mt-4 space-y-2">
                                <div>
                                    <div class="mb-1 flex items-center justify-between text-xs">
                                        <span class="text-muted-foreground">League Championship</span>
                                        <span class="font-medium">{{ formatPct(row.league_championship_probability, 2) }}</span>
                                    </div>
                                    <div class="h-2 overflow-hidden rounded-full bg-muted">
                                        <div class="h-full rounded-full bg-sky-500" :style="{ width: `${toPct(row.league_championship_probability)}%` }" />
                                    </div>
                                </div>
                                <div>
                                    <div class="mb-1 flex items-center justify-between text-xs">
                                        <span class="text-muted-foreground">World Series</span>
                                        <span class="font-medium">{{ formatPct(row.world_series_probability, 2) }}</span>
                                    </div>
                                    <div class="h-2 overflow-hidden rounded-full bg-violet-500/20">
                                        <div class="h-full rounded-full bg-violet-500" :style="{ width: `${toPct(row.world_series_probability)}%` }" />
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
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                </div>
            </template>
        </div>
    </PredictionsPageShell>
</template>
