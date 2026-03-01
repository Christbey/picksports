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
    school?: string | null;
    mascot?: string | null;
    conference?: string | null;
};

type TournamentForecast = {
    id: number;
    team_id: number;
    season: number;
    projected_seed: number | null;
    auto_bid: boolean;
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
    selection_score: number;
    team: ForecastTeam | null;
};

const forecasts = ref<TournamentForecast[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);
const selectedSeason = ref<number | null>(null);
const availableSeasons = ref<number[]>([]);

const availableSeasonOptions = computed(() =>
    availableSeasons.value.length > 0 ? availableSeasons.value : [selectedSeason.value ?? new Date().getFullYear()],
);

const formatPct = (value: number, digits = 1) => `${(value * 100).toFixed(digits)}%`;
const formatTeam = (team: ForecastTeam | null, fallback: number) => {
    if (!team) return `Team ${fallback}`;
    const full = `${team.school ?? ''} ${team.mascot ?? ''}`.trim();
    return full || team.abbreviation || `Team ${fallback}`;
};

const fetchForecasts = async () => {
    if (!selectedSeason.value) return;

    loading.value = true;
    error.value = null;

    try {
        const response = await fetch(`/api/v1/cbb/tournament-forecasts?season=${selectedSeason.value}`);
        if (!response.ok) {
            throw new Error('Failed to load tournament forecast data');
        }

        const payload = await response.json();
        forecasts.value = payload?.data ?? [];
        availableSeasons.value = payload?.meta?.available_seasons ?? [];
    } catch (e) {
        error.value = e instanceof Error ? e.message : 'An error occurred while loading forecast data.';
        forecasts.value = [];
    } finally {
        loading.value = false;
    }
};

const topByMake = computed(() => [...forecasts.value].sort((a, b) => b.tournament_make_probability - a.tournament_make_probability).slice(0, 24));
const topByTitle = computed(() => [...forecasts.value].sort((a, b) => b.champion_probability - a.champion_probability).slice(0, 24));
const bubbleRisk = computed(() =>
    [...forecasts.value]
        .filter((f) => f.tournament_make_probability > 0.05 && f.tournament_make_probability < 0.95)
        .sort((a, b) => b.first_four_probability - a.first_four_probability)
        .slice(0, 20),
);

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
                    </div>
                </CardContent>
            </Card>

            <Alert v-if="error" variant="destructive">
                <AlertDescription>{{ error }}</AlertDescription>
            </Alert>

            <div v-if="loading" class="space-y-3">
                <Skeleton v-for="i in 8" :key="i" class="h-12 w-full" />
            </div>

            <Card v-else>
                <CardHeader>
                    <CardTitle>Top Tournament Make Probabilities</CardTitle>
                </CardHeader>
                <CardContent>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b text-left">
                                    <th class="p-2 font-medium">Team</th>
                                    <th class="p-2 font-medium">Conf</th>
                                    <th class="p-2 text-right font-medium">Seed</th>
                                    <th class="p-2 text-right font-medium">AQ%</th>
                                    <th class="p-2 text-right font-medium">AL%</th>
                                    <th class="p-2 text-right font-medium">First 4%</th>
                                    <th class="p-2 text-right font-medium">Make%</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="row in topByMake" :key="row.id" class="border-b">
                                    <td class="p-2 font-medium">{{ formatTeam(row.team, row.team_id) }}</td>
                                    <td class="p-2 text-muted-foreground">{{ row.team?.conference ?? '-' }}</td>
                                    <td class="p-2 text-right">{{ row.projected_seed ?? '-' }}</td>
                                    <td class="p-2 text-right">{{ formatPct(row.auto_bid_probability) }}</td>
                                    <td class="p-2 text-right">{{ formatPct(row.at_large_probability) }}</td>
                                    <td class="p-2 text-right">{{ formatPct(row.first_four_probability) }}</td>
                                    <td class="p-2 text-right font-semibold">{{ formatPct(row.tournament_make_probability) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </CardContent>
            </Card>

            <Card v-if="!loading">
                <CardHeader>
                    <CardTitle>Top Championship Probabilities</CardTitle>
                </CardHeader>
                <CardContent>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b text-left">
                                    <th class="p-2 font-medium">Team</th>
                                    <th class="p-2 text-right font-medium">Seed</th>
                                    <th class="p-2 text-right font-medium">Final 4%</th>
                                    <th class="p-2 text-right font-medium">Title Game%</th>
                                    <th class="p-2 text-right font-medium">Champion%</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="row in topByTitle" :key="row.id" class="border-b">
                                    <td class="p-2 font-medium">{{ formatTeam(row.team, row.team_id) }}</td>
                                    <td class="p-2 text-right">{{ row.projected_seed ?? '-' }}</td>
                                    <td class="p-2 text-right">{{ formatPct(row.final_four_probability, 2) }}</td>
                                    <td class="p-2 text-right">{{ formatPct(row.title_game_probability, 2) }}</td>
                                    <td class="p-2 text-right font-semibold">{{ formatPct(row.champion_probability, 2) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </CardContent>
            </Card>

            <Card v-if="!loading && bubbleRisk.length > 0">
                <CardHeader>
                    <CardTitle>Bubble / Bid-Thief Risk</CardTitle>
                </CardHeader>
                <CardContent>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b text-left">
                                    <th class="p-2 font-medium">Team</th>
                                    <th class="p-2 text-right font-medium">Bid Thief%</th>
                                    <th class="p-2 text-right font-medium">First 4 (AQ)%</th>
                                    <th class="p-2 text-right font-medium">First 4 (AL)%</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="row in bubbleRisk" :key="row.id" class="border-b">
                                    <td class="p-2 font-medium">{{ formatTeam(row.team, row.team_id) }}</td>
                                    <td class="p-2 text-right">{{ formatPct(row.bid_thief_probability, 2) }}</td>
                                    <td class="p-2 text-right">{{ formatPct(row.first_four_auto_probability, 2) }}</td>
                                    <td class="p-2 text-right">{{ formatPct(row.first_four_at_large_probability, 2) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </CardContent>
            </Card>
        </div>
    </PredictionsPageShell>
</template>
