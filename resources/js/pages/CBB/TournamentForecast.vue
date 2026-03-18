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
    logo?: string | null;
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
    tournament_make_probability: number;
    champion_probability: number;
    final_four_probability: number;
    title_game_probability: number;
    team: ForecastTeam | null;
    market_odds?: MarketOdds | null;
    market_edge?: MarketEdge | null;
    is_actual_field: boolean;
    is_first_four: boolean;
    actual_round: string | null;
    actual_region: string | null;
    actual_seed: number | null;
    is_eliminated?: boolean;
};

type ForecastPayload = {
    data?: TournamentForecast[];
    meta?: {
        available_seasons?: number[];
        actual_field_size?: number;
    };
};

const forecasts = ref<TournamentForecast[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);
const selectedSeason = ref<number | null>(null);
const availableSeasons = ref<number[]>([]);
const actualFieldSize = ref(0);

const regionOrder = ['East', 'West', 'South', 'Midwest'];

const availableSeasonOptions = computed(() =>
    availableSeasons.value.length > 0 ? availableSeasons.value : [selectedSeason.value ?? new Date().getFullYear()],
);

const formatPct = (value: number, digits = 1) => `${(value * 100).toFixed(digits)}%`;
const formatAmericanOdds = (value: number | null | undefined) => {
    if (value === null || value === undefined) return '-';
    return value > 0 ? `+${value}` : `${value}`;
};

const formatTeam = (team: ForecastTeam | null, fallback: number) => {
    if (!team) return `Team ${fallback}`;
    const full = `${team.school ?? ''} ${team.mascot ?? ''}`.trim();
    return full || team.abbreviation || `Team ${fallback}`;
};

const actualFieldForecasts = computed(() =>
    forecasts.value
        .filter((row) => row.is_actual_field && !row.is_eliminated)
        .sort((a, b) => {
            const regionA = regionOrder.indexOf(a.actual_region ?? '');
            const regionB = regionOrder.indexOf(b.actual_region ?? '');
            if (regionA !== regionB) return (regionA === -1 ? 999 : regionA) - (regionB === -1 ? 999 : regionB);
            if ((a.actual_seed ?? 99) !== (b.actual_seed ?? 99)) return (a.actual_seed ?? 99) - (b.actual_seed ?? 99);
            return formatTeam(a.team, a.team_id).localeCompare(formatTeam(b.team, b.team_id));
        }),
);

const actualFieldKnown = computed(() => actualFieldForecasts.value.length > 0 || actualFieldSize.value > 0);
const regionalField = computed(() =>
    regionOrder.map((region) => ({
        region,
        teams: actualFieldForecasts.value.filter((row) => row.actual_region === region && !row.is_first_four),
    })),
);

const firstFourTeams = computed(() =>
    actualFieldForecasts.value
        .filter((row) => row.is_first_four)
        .sort((a, b) => {
            if ((a.actual_seed ?? 99) !== (b.actual_seed ?? 99)) return (a.actual_seed ?? 99) - (b.actual_seed ?? 99);
            return formatTeam(a.team, a.team_id).localeCompare(formatTeam(b.team, b.team_id));
        }),
);

const titleContenders = computed(() =>
    [...actualFieldForecasts.value]
        .sort((a, b) => b.champion_probability - a.champion_probability)
        .slice(0, 24),
);

const deepRunContenders = computed(() =>
    [...actualFieldForecasts.value]
        .sort((a, b) => {
            if (b.final_four_probability !== a.final_four_probability) return b.final_four_probability - a.final_four_probability;
            return b.title_game_probability - a.title_game_probability;
        })
        .slice(0, 16),
);

const favorite = computed(() => titleContenders.value[0] ?? null);
const plusEvTitleEdges = computed(() =>
    titleContenders.value.filter((row) => (row.market_edge?.edge_probability ?? 0) > 0).slice(0, 8),
);

const fetchForecasts = async () => {
    if (!selectedSeason.value) return;

    loading.value = true;
    error.value = null;

    try {
        const payload = await fetchJson<ForecastPayload>(`/api/v1/cbb/tournament-forecasts?season=${selectedSeason.value}`);
        if (!payload) {
            throw new Error('Failed to load tournament forecast data');
        }

        forecasts.value = payload.data ?? [];
        availableSeasons.value = payload.meta?.available_seasons ?? [];
        actualFieldSize.value = payload.meta?.actual_field_size ?? 0;
    } catch (e) {
        error.value = e instanceof Error ? e.message : 'An error occurred while loading forecast data.';
        forecasts.value = [];
        actualFieldSize.value = 0;
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
        breadcrumb-href="/cbb/tournament-forecast"
        banner-storage-key="cbb-tournament-forecast-banner-dismissed"
        seo-description="March Madness field outlook with actual teams, regional placement, and championship probabilities."
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
                        <div class="min-w-[260px] text-sm text-muted-foreground">
                            <p class="font-medium text-foreground">Actual tournament field</p>
                            <p v-if="actualFieldKnown" class="mt-1">This page now centers on the confirmed field and uses the forecast model for title and deep-run outlook.</p>
                            <p v-else class="mt-1">Official field data is not available yet for this season.</p>
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

            <template v-else-if="actualFieldKnown">
                <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                    <Card>
                        <CardContent class="pt-5">
                            <p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Tournament Teams</p>
                            <p class="mt-2 text-3xl font-bold">{{ actualFieldForecasts.length }}</p>
                            <p class="mt-1 text-xs text-muted-foreground">Forecast rows matched to the actual field</p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent class="pt-5">
                            <p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Regions</p>
                            <p class="mt-2 text-3xl font-bold">{{ regionalField.filter((group) => group.teams.length > 0).length }}</p>
                            <p class="mt-1 text-xs text-muted-foreground">East, West, South, Midwest</p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent class="pt-5">
                            <p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">First Four Teams</p>
                            <p class="mt-2 text-3xl font-bold">{{ firstFourTeams.length }}</p>
                            <p class="mt-1 text-xs text-muted-foreground">Play-in teams currently in the field</p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent class="pt-5">
                            <p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Title Favorite</p>
                            <p class="mt-2 text-lg font-bold">{{ favorite ? formatTeam(favorite.team, favorite.team_id) : '-' }}</p>
                            <p class="mt-1 text-sm text-muted-foreground">{{ favorite ? formatPct(favorite.champion_probability, 2) : '-' }}</p>
                            <p class="mt-1 text-xs text-muted-foreground">Market {{ favorite ? formatAmericanOdds(favorite.market_odds?.price) : '-' }}</p>
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Confirmed Tournament Field</CardTitle>
                    </CardHeader>
                    <CardContent class="space-y-4">
                        <div class="grid gap-4 lg:grid-cols-2">
                            <Card v-for="group in regionalField" :key="group.region" class="border-border/70">
                                <CardHeader class="pb-3">
                                    <CardTitle class="text-base">{{ group.region }}</CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <div v-if="group.teams.length > 0" class="space-y-2">
                                        <div
                                            v-for="row in group.teams"
                                            :key="`field-${group.region}-${row.team_id}`"
                                            class="flex items-center justify-between gap-3 rounded-lg border border-border/60 bg-muted/20 px-3 py-2"
                                        >
                                            <div class="flex min-w-0 items-center gap-3">
                                                <img
                                                    v-if="row.team?.logo"
                                                    :src="row.team.logo"
                                                    :alt="formatTeam(row.team, row.team_id)"
                                                    class="h-8 w-8 rounded object-contain"
                                                >
                                                <div class="min-w-0">
                                                    <p class="truncate text-sm font-semibold">{{ formatTeam(row.team, row.team_id) }}</p>
                                                    <p class="text-xs text-muted-foreground">{{ row.team?.conference ?? 'Independent' }}</p>
                                                </div>
                                            </div>
                                            <div class="text-right">
                                                <p class="text-sm font-semibold">No. {{ row.actual_seed ?? '-' }}</p>
                                                <p class="text-xs text-muted-foreground">{{ formatPct(row.champion_probability, 2) }} title</p>
                                            </div>
                                        </div>
                                    </div>
                                    <p v-else class="text-sm text-muted-foreground">No confirmed teams loaded for this region yet.</p>
                                </CardContent>
                            </Card>
                        </div>

                        <Card v-if="firstFourTeams.length > 0" class="border-border/70">
                            <CardHeader class="pb-3">
                                <CardTitle class="text-base">First Four</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div class="grid gap-2 md:grid-cols-2">
                                    <div
                                        v-for="row in firstFourTeams"
                                        :key="`first-four-${row.team_id}`"
                                        class="flex items-center justify-between gap-3 rounded-lg border border-border/60 bg-muted/20 px-3 py-2"
                                    >
                                        <div class="flex min-w-0 items-center gap-3">
                                            <img
                                                v-if="row.team?.logo"
                                                :src="row.team.logo"
                                                :alt="formatTeam(row.team, row.team_id)"
                                                class="h-8 w-8 rounded object-contain"
                                            >
                                            <div class="min-w-0">
                                                <p class="truncate text-sm font-semibold">{{ formatTeam(row.team, row.team_id) }}</p>
                                                <p class="text-xs text-muted-foreground">{{ row.actual_region ?? 'Region TBD' }} · No. {{ row.actual_seed ?? '-' }}</p>
                                            </div>
                                        </div>
                                        <p class="text-xs font-medium text-muted-foreground">{{ formatPct(row.tournament_make_probability, 1) }} in</p>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Championship Outlook</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div class="overflow-x-auto rounded-md border">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="border-b bg-muted/30 text-left">
                                        <th class="p-2 font-medium">Team</th>
                                        <th class="p-2 font-medium">Region / Seed</th>
                                        <th class="p-2 text-right font-medium">Final Four</th>
                                        <th class="p-2 text-right font-medium">Title Game</th>
                                        <th class="p-2 text-right font-medium">Champion</th>
                                        <th class="p-2 text-right font-medium">Market</th>
                                        <th class="p-2 text-right font-medium">Edge</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="row in titleContenders" :key="`title-${row.id}`" class="border-b">
                                        <td class="p-2 font-medium">{{ formatTeam(row.team, row.team_id) }}</td>
                                        <td class="p-2 text-muted-foreground">{{ row.actual_region ?? 'TBD' }} · No. {{ row.actual_seed ?? '-' }}</td>
                                        <td class="p-2 text-right">{{ formatPct(row.final_four_probability, 2) }}</td>
                                        <td class="p-2 text-right">{{ formatPct(row.title_game_probability, 2) }}</td>
                                        <td class="p-2 text-right font-semibold">{{ formatPct(row.champion_probability, 2) }}</td>
                                        <td class="p-2 text-right">{{ formatAmericanOdds(row.market_odds?.price) }}</td>
                                        <td class="p-2 text-right" :class="(row.market_edge?.edge_probability ?? 0) > 0 ? 'text-emerald-600' : 'text-muted-foreground'">
                                            {{ (row.market_edge?.edge_probability ?? 0) > 0 ? `+${((row.market_edge?.edge_percent_points ?? 0)).toFixed(1)}pp` : '-' }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </CardContent>
                </Card>

                <div class="grid gap-4 xl:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle>Best Deep-Run Profiles</CardTitle>
                        </CardHeader>
                        <CardContent class="space-y-3">
                            <div
                                v-for="row in deepRunContenders"
                                :key="`deep-${row.id}`"
                                class="flex items-center justify-between gap-3 rounded-lg border border-border/60 bg-muted/20 px-3 py-2"
                            >
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-semibold">{{ formatTeam(row.team, row.team_id) }}</p>
                                    <p class="text-xs text-muted-foreground">{{ row.actual_region ?? 'TBD' }} · No. {{ row.actual_seed ?? '-' }}</p>
                                </div>
                                <div class="text-right text-sm">
                                    <p>FF {{ formatPct(row.final_four_probability, 2) }}</p>
                                    <p class="text-muted-foreground">Title {{ formatPct(row.champion_probability, 2) }}</p>
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Best Title Edges</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div v-if="plusEvTitleEdges.length > 0" class="space-y-3">
                                <div
                                    v-for="row in plusEvTitleEdges"
                                    :key="`edge-${row.id}`"
                                    class="flex items-center justify-between gap-3 rounded-lg border border-emerald-200/60 bg-emerald-50/50 px-3 py-2 dark:border-emerald-900/40 dark:bg-emerald-950/20"
                                >
                                    <div class="min-w-0">
                                        <p class="truncate text-sm font-semibold">{{ formatTeam(row.team, row.team_id) }}</p>
                                        <p class="text-xs text-muted-foreground">{{ formatAmericanOdds(row.market_odds?.price) }} market · {{ row.actual_region ?? 'TBD' }} No. {{ row.actual_seed ?? '-' }}</p>
                                    </div>
                                    <div class="text-right">
                                        <p class="text-sm font-semibold text-emerald-700 dark:text-emerald-300">+{{ (row.market_edge?.edge_percent_points ?? 0).toFixed(1) }}pp</p>
                                        <p class="text-xs text-muted-foreground">{{ formatPct(row.champion_probability, 2) }} model</p>
                                    </div>
                                </div>
                            </div>
                            <p v-else class="text-sm text-muted-foreground">No positive title edges are currently available in the loaded market data.</p>
                        </CardContent>
                    </Card>
                </div>
            </template>

            <Alert v-else>
                <AlertDescription>No actual tournament field is attached to this season yet. Load the tournament metadata and forecast rows for the selected season to populate this page.</AlertDescription>
            </Alert>
        </div>
    </PredictionsPageShell>
</template>
