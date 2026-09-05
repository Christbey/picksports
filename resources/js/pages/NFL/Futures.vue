<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import PredictionsPageShell from '@/components/predictions/PredictionsPageShell.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { useApiV2Client } from '@/composables/useApiV2Client';

type MarketOdds = {
    bookmaker: string;
    price: number | null;
    implied_probability: number | null;
    fetched_at: string | null;
};

type MarketEdge = {
    edge_probability: number | null;
    edge_percent_points: number | null;
    fair_price: number | null;
    market_price: number | null;
    has_edge: boolean;
};

type PlayoffForecast = {
    team_id: number;
    team_name: string;
    conference: string | null;
    division: string | null;
    projected_wins: number;
    projected_seed: number | null;
    division_winner_probability: number;
    make_playoffs_probability: number;
    conference_champion_probability: number;
    super_bowl_champion_probability: number;
    market_odds?: MarketOdds | null;
    market_edge?: MarketEdge | null;
};

type ForecastMeta = {
    season?: number;
    simulations?: number | null;
};

const api = useApiV2Client();
const currentYear = new Date().getFullYear();
const selectedSeason = ref(currentYear);
const sortMode = ref<'title' | 'playoffs' | 'wins' | 'edge'>('title');
const onlyPlusEv = ref(false);
const forecasts = ref<PlayoffForecast[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);
const simulations = ref<number | null>(null);
let activeRequest = 0;

const seasonOptions = computed(() =>
    Array.from({ length: 5 }, (_, index) => currentYear - index),
);

const visibleForecasts = computed(() => {
    const rows = onlyPlusEv.value
        ? forecasts.value.filter(
              (row) => (row.market_edge?.edge_probability ?? 0) > 0,
          )
        : forecasts.value;

    return [...rows].sort((left, right) => {
        if (sortMode.value === 'playoffs') {
            return (
                right.make_playoffs_probability - left.make_playoffs_probability
            );
        }
        if (sortMode.value === 'wins') {
            return right.projected_wins - left.projected_wins;
        }
        if (sortMode.value === 'edge') {
            return (
                (right.market_edge?.edge_probability ?? -1) -
                (left.market_edge?.edge_probability ?? -1)
            );
        }

        return (
            right.super_bowl_champion_probability -
            left.super_bowl_champion_probability
        );
    });
});

const topChampion = computed(
    () =>
        [...forecasts.value].sort(
            (left, right) =>
                right.super_bowl_champion_probability -
                left.super_bowl_champion_probability,
        )[0],
);

const playoffField = computed(
    () =>
        forecasts.value.filter(
            (forecast) => forecast.make_playoffs_probability >= 0.5,
        ).length,
);

const plusEvCount = computed(
    () =>
        forecasts.value.filter(
            (forecast) => (forecast.market_edge?.edge_probability ?? 0) > 0,
        ).length,
);

const conferenceLeaders = computed(() =>
    ['AFC', 'NFC']
        .map((conference) => {
            const teams = forecasts.value.filter(
                (forecast) => forecast.conference === conference,
            );
            const champion = [...teams].sort(
                (left, right) =>
                    right.conference_champion_probability -
                    left.conference_champion_probability,
            )[0];

            return {
                conference,
                champion,
                expectedPlayoffTeams: teams.reduce(
                    (total, team) => total + team.make_playoffs_probability,
                    0,
                ),
            };
        })
        .filter((row) => row.champion),
);

const formatPct = (value: number | null | undefined, digits = 1) =>
    value === null || value === undefined
        ? '-'
        : `${(value * 100).toFixed(digits)}%`;

const formatAmericanOdds = (value: number | null | undefined) => {
    if (value === null || value === undefined) return '-';
    return value > 0 ? `+${value}` : `${value}`;
};

const formatEdge = (value: number | null | undefined) => {
    if (value === null || value === undefined) return '-';
    const points = value * 100;
    return `${points >= 0 ? '+' : ''}${points.toFixed(1)}pp`;
};

const fetchForecasts = async () => {
    const request = ++activeRequest;
    loading.value = true;
    error.value = null;

    try {
        const payload = await api.forecasts.index<PlayoffForecast>('nfl', {
            query: { season: selectedSeason.value },
        });
        if (request !== activeRequest) return;
        if (!payload) {
            throw new Error('NFL futures could not be loaded.');
        }

        forecasts.value = payload.data ?? [];
        const meta = (payload.meta ?? {}) as ForecastMeta;
        simulations.value = meta.simulations ?? null;

        if (forecasts.value.length === 0) {
            error.value = `No NFL futures forecast is available for ${selectedSeason.value}.`;
        }
    } catch (cause) {
        if (request !== activeRequest) return;
        forecasts.value = [];
        error.value =
            cause instanceof Error
                ? cause.message
                : 'NFL futures could not be loaded.';
    } finally {
        if (request === activeRequest) loading.value = false;
    }
};

watch(selectedSeason, () => void fetchForecasts(), { immediate: true });
</script>

<template>
    <PredictionsPageShell
        title="NFL Futures"
        sport-title="NFL"
        sport-href="/nfl/predictions"
        page-title="Futures"
        banner-storage-key="nfl-futures-banner-dismissed"
        seo-description="NFL playoff, conference championship, and Super Bowl forecasts compared with available futures prices."
    >
        <div class="space-y-5">
            <Card>
                <CardContent class="pt-6">
                    <div class="flex flex-wrap items-end gap-4">
                        <div class="min-w-[180px]">
                            <label for="season" class="ui-kicker">Season</label>
                            <select
                                id="season"
                                v-model.number="selectedSeason"
                                class="ui-select mt-1"
                            >
                                <option
                                    v-for="season in seasonOptions"
                                    :key="season"
                                    :value="season"
                                >
                                    {{ season }}
                                </option>
                            </select>
                        </div>
                        <div class="min-w-[220px]">
                            <label for="sort-mode" class="ui-kicker"
                                >Sort</label
                            >
                            <select
                                id="sort-mode"
                                v-model="sortMode"
                                class="ui-select mt-1"
                            >
                                <option value="title">Super Bowl chance</option>
                                <option value="playoffs">Playoff chance</option>
                                <option value="wins">Projected wins</option>
                                <option value="edge">Best market edge</option>
                            </select>
                        </div>
                        <label
                            class="inline-flex min-h-10 items-center gap-2 text-sm font-medium text-muted-foreground"
                        >
                            <input
                                v-model="onlyPlusEv"
                                type="checkbox"
                                class="h-4 w-4 rounded border-input"
                            />
                            Only +EV
                        </label>
                        <p
                            v-if="simulations"
                            class="ml-auto pb-2 text-xs text-muted-foreground"
                        >
                            {{ simulations.toLocaleString() }} simulations
                        </p>
                    </div>
                </CardContent>
            </Card>

            <Alert v-if="error" variant="destructive">
                <AlertDescription>{{ error }}</AlertDescription>
            </Alert>

            <div v-if="loading" class="space-y-3">
                <Skeleton v-for="row in 8" :key="row" class="h-12 w-full" />
            </div>

            <template v-else>
                <div class="grid gap-4 md:grid-cols-3">
                    <Card>
                        <CardContent class="pt-5">
                            <p class="ui-kicker">Super Bowl Favorite</p>
                            <p class="mt-2 text-xl font-bold">
                                {{ topChampion?.team_name ?? '-' }}
                            </p>
                            <p class="mt-1 text-sm text-muted-foreground">
                                {{
                                    formatPct(
                                        topChampion?.super_bowl_champion_probability,
                                        2,
                                    )
                                }}
                                model probability
                            </p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent class="pt-5">
                            <p class="ui-kicker">Projected Playoff Field</p>
                            <p class="mt-2 text-3xl font-bold">
                                {{ playoffField }}
                            </p>
                            <p class="mt-1 text-xs text-muted-foreground">
                                Teams at or above 50%
                            </p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent class="pt-5">
                            <p class="ui-kicker">Positive Market Edges</p>
                            <p class="mt-2 text-3xl font-bold">
                                {{ plusEvCount }}
                            </p>
                            <p class="mt-1 text-xs text-muted-foreground">
                                Based on currently captured title prices
                            </p>
                        </CardContent>
                    </Card>
                </div>

                <Card v-if="conferenceLeaders.length">
                    <CardHeader>
                        <div class="ui-kicker">Conference View</div>
                        <CardTitle>Projected Conference Leaders</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div class="grid gap-4 md:grid-cols-2">
                            <div
                                v-for="row in conferenceLeaders"
                                :key="row.conference"
                                class="border-l-2 border-primary/50 pl-4"
                            >
                                <p class="ui-kicker">{{ row.conference }}</p>
                                <p class="mt-1 text-lg font-semibold">
                                    {{ row.champion?.team_name }}
                                </p>
                                <p class="text-sm text-muted-foreground">
                                    {{
                                        formatPct(
                                            row.champion
                                                ?.conference_champion_probability,
                                        )
                                    }}
                                    conference title chance ·
                                    {{ row.expectedPlayoffTeams.toFixed(1) }}
                                    expected playoff teams
                                </p>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <div class="ui-kicker">Team Forecasts</div>
                        <CardTitle>Playoff and Super Bowl Outlook</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div class="ui-table-wrap">
                            <table class="w-full min-w-[920px] text-sm">
                                <thead>
                                    <tr class="border-b text-left">
                                        <th class="px-3 py-3 font-medium">
                                            Team
                                        </th>
                                        <th
                                            class="px-3 py-3 text-right font-medium"
                                        >
                                            Wins
                                        </th>
                                        <th
                                            class="px-3 py-3 text-right font-medium"
                                        >
                                            Seed
                                        </th>
                                        <th
                                            class="px-3 py-3 text-right font-medium"
                                        >
                                            Division
                                        </th>
                                        <th
                                            class="px-3 py-3 text-right font-medium"
                                        >
                                            Playoffs
                                        </th>
                                        <th
                                            class="px-3 py-3 text-right font-medium"
                                        >
                                            Conference
                                        </th>
                                        <th
                                            class="px-3 py-3 text-right font-medium"
                                        >
                                            Super Bowl
                                        </th>
                                        <th
                                            class="px-3 py-3 text-right font-medium"
                                        >
                                            Market
                                        </th>
                                        <th
                                            class="px-3 py-3 text-right font-medium"
                                        >
                                            Edge
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="forecast in visibleForecasts"
                                        :key="forecast.team_id"
                                        class="border-b border-border/60 last:border-0"
                                    >
                                        <td class="px-3 py-3">
                                            <p class="font-semibold">
                                                {{ forecast.team_name }}
                                            </p>
                                            <p
                                                class="text-xs text-muted-foreground"
                                            >
                                                {{ forecast.conference }}
                                                {{ forecast.division }}
                                            </p>
                                        </td>
                                        <td
                                            class="px-3 py-3 text-right tabular-nums"
                                        >
                                            {{
                                                forecast.projected_wins.toFixed(
                                                    1,
                                                )
                                            }}
                                        </td>
                                        <td
                                            class="px-3 py-3 text-right tabular-nums"
                                        >
                                            {{
                                                forecast.projected_seed?.toFixed(
                                                    1,
                                                ) ?? '-'
                                            }}
                                        </td>
                                        <td
                                            class="px-3 py-3 text-right tabular-nums"
                                        >
                                            {{
                                                formatPct(
                                                    forecast.division_winner_probability,
                                                )
                                            }}
                                        </td>
                                        <td
                                            class="px-3 py-3 text-right tabular-nums"
                                        >
                                            {{
                                                formatPct(
                                                    forecast.make_playoffs_probability,
                                                )
                                            }}
                                        </td>
                                        <td
                                            class="px-3 py-3 text-right tabular-nums"
                                        >
                                            {{
                                                formatPct(
                                                    forecast.conference_champion_probability,
                                                )
                                            }}
                                        </td>
                                        <td
                                            class="px-3 py-3 text-right font-semibold tabular-nums"
                                        >
                                            {{
                                                formatPct(
                                                    forecast.super_bowl_champion_probability,
                                                )
                                            }}
                                        </td>
                                        <td
                                            class="px-3 py-3 text-right tabular-nums"
                                        >
                                            {{
                                                formatAmericanOdds(
                                                    forecast.market_odds?.price,
                                                )
                                            }}
                                        </td>
                                        <td
                                            class="px-3 py-3 text-right font-medium tabular-nums"
                                            :class="
                                                (forecast.market_edge
                                                    ?.edge_probability ?? 0) > 0
                                                    ? 'text-emerald-600 dark:text-emerald-400'
                                                    : 'text-muted-foreground'
                                            "
                                        >
                                            {{
                                                formatEdge(
                                                    forecast.market_edge
                                                        ?.edge_probability,
                                                )
                                            }}
                                        </td>
                                    </tr>
                                    <tr v-if="visibleForecasts.length === 0">
                                        <td
                                            colspan="9"
                                            class="px-3 py-10 text-center text-muted-foreground"
                                        >
                                            No forecasts match these filters.
                                        </td>
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
