<script setup lang="ts">
import { BarChart3, TrendingDown, TrendingUp } from 'lucide-vue-next';
import { computed, onMounted, ref, watch } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { useApiV2Client } from '@/composables/useApiV2Client';
import { useApiV2Resource } from '@/composables/useApiV2Resource';
import type { ApiV2PlayerProp } from '@/types';

type PlayerPropRecommendation = {
    id: number;
    player: {
        id: number;
        name: string;
        position?: string | null;
        team?: string | null;
    };
    market: string;
    line: number;
    recommendation: 'Over' | 'Under';
    odds: number;
    confidence: number;
    stats: {
        season_avg?: number | null;
        recent_avg?: number | null;
        last5_avg?: number | null;
        times_covered_last5?: { hits: number; games: number } | null;
        consistency?: {
            std_dev: number;
            level: string;
        } | null;
    };
    edge: number;
    model_over_probability?: number | null;
    market_over_probability?: number | null;
    edge_probability?: number | null;
    data_quality_score?: number | null;
    signal_quality?: {
        label?: string;
        tier?: string;
        reason_codes?: string[];
    } | null;
    reasoning: string[];
};

type MarketOption = {
    value: string;
    label: string;
};

const props = withDefaults(
    defineProps<{
        sportSlug: string;
        gameId: number;
        title?: string;
        limit?: number;
    }>(),
    {
        title: 'Player Prop Edges',
        limit: 10,
    },
);

const recommendations = ref<PlayerPropRecommendation[]>([]);
const markets = ref<MarketOption[]>([]);
const selectedMarket = ref('');
const responseError = ref<string | null>(null);
const api = useApiV2Client();
const propsResource = useApiV2Resource<ApiV2PlayerProp>(() =>
    api.playerProps.forGame(props.sportSlug, props.gameId, {
        query: {
            per_page: Math.max(props.limit, 25),
        },
    }),
);

const loading = computed(() => propsResource.isLoading.value);
const error = computed(() => responseError.value ?? propsResource.error.value);

const marketOptions = computed(() => [
    { value: '', label: 'All' },
    ...markets.value,
]);

const filteredRecommendations = computed(() => {
    const rows = selectedMarket.value
        ? recommendations.value.filter(
              (rec) => rec.market === marketLabel(selectedMarket.value),
          )
        : recommendations.value;

    return rows.slice(0, props.limit);
});

const shouldRender = computed(
    () => loading.value || filteredRecommendations.value.length > 0,
);

const strongestRecommendation = computed(
    () => recommendations.value[0] ?? null,
);

const normalizeSide = (
    side: ApiV2PlayerProp['recommendation'] extends infer T
        ? T extends { side?: infer S }
            ? S
            : unknown
        : unknown,
): 'Over' | 'Under' =>
    String(side ?? 'Over').toLowerCase() === 'under' ? 'Under' : 'Over';

const idsMatch = (
    left: string | number | null | undefined,
    right: string | number | null | undefined,
) =>
    left !== null &&
    left !== undefined &&
    right !== null &&
    right !== undefined &&
    String(left) === String(right);

const teamLabel = (prop: ApiV2PlayerProp) => {
    const playerTeamId = prop.player?.team_id;

    if (idsMatch(prop.game?.home_team?.id, playerTeamId)) {
        return prop.game.home_team.abbreviation ?? prop.game.home_team.name;
    }

    if (idsMatch(prop.game?.away_team?.id, playerTeamId)) {
        return prop.game.away_team.abbreviation ?? prop.game.away_team.name;
    }

    return prop.player?.position ?? null;
};

const oddsForSide = (prop: ApiV2PlayerProp, side: 'Over' | 'Under') =>
    Number(side === 'Over' ? prop.over_price : prop.under_price) || 0;

const confidenceScore = (prop: ApiV2PlayerProp) =>
    Math.round(Number(prop.recommendation?.confidence_score ?? 0));

const hasPropRecommendation = (prop: ApiV2PlayerProp): boolean => {
    const recommendation = prop.recommendation;

    return !!(
        recommendation?.side &&
        recommendation.confidence_score !== null &&
        recommendation.confidence_score !== undefined &&
        recommendation.predicted_over_probability !== null &&
        recommendation.predicted_over_probability !== undefined &&
        recommendation.market_over_probability !== null &&
        recommendation.market_over_probability !== undefined &&
        recommendation.edge_probability !== null &&
        recommendation.edge_probability !== undefined &&
        recommendation.data_quality_score !== null &&
        recommendation.data_quality_score !== undefined
    );
};

const edgeScore = (prop: ApiV2PlayerProp) => {
    const edge = prop.recommendation?.edge_probability;

    if (edge === null || edge === undefined) {
        return 0;
    }

    return Math.abs(Number(edge)) <= 1 ? Number(edge) * 100 : Number(edge);
};

const mapPlayerProp = (prop: ApiV2PlayerProp): PlayerPropRecommendation => {
    const side = normalizeSide(prop.recommendation?.side);

    return {
        id: Number(prop.id),
        player: {
            id: Number(prop.player_id ?? prop.player?.id ?? 0),
            name:
                prop.player?.display_name ??
                prop.player?.full_name ??
                prop.player_name ??
                'Unknown player',
            position: prop.player?.position ?? null,
            team: teamLabel(prop),
        },
        market: prop.market ?? 'Player prop',
        line: Number(prop.line ?? 0),
        recommendation: side,
        odds: oddsForSide(prop, side),
        confidence: confidenceScore(prop),
        stats: {
            season_avg: null,
            recent_avg: null,
            last5_avg: null,
        },
        edge: edgeScore(prop),
        model_over_probability:
            prop.recommendation?.predicted_over_probability ?? null,
        market_over_probability:
            prop.recommendation?.market_over_probability ?? null,
        edge_probability: prop.recommendation?.edge_probability ?? null,
        data_quality_score: prop.recommendation?.data_quality_score ?? null,
        signal_quality:
            (prop.recommendation?.signal_quality as
                | PlayerPropRecommendation['signal_quality']
                | undefined) ?? null,
        reasoning: [],
    };
};

const marketOptionsFor = (rows: PlayerPropRecommendation[]): MarketOption[] => {
    return [...new Set(rows.map((row) => row.market).filter(Boolean))]
        .sort((a, b) => a.localeCompare(b))
        .map((market) => ({ value: market, label: market }));
};

const loadProps = async () => {
    responseError.value = null;

    const payload = await propsResource.execute();

    if (!payload) {
        responseError.value = 'Unable to load player props.';
        recommendations.value = [];
        markets.value = [];

        return;
    }

    recommendations.value = payload.data
        .filter(hasPropRecommendation)
        .map(mapPlayerProp);
    markets.value = marketOptionsFor(recommendations.value);

    if (
        selectedMarket.value &&
        !markets.value.some((market) => market.value === selectedMarket.value)
    ) {
        selectedMarket.value = '';
    }
};

const marketLabel = (value: string) =>
    markets.value.find((market) => market.value === value)?.label ?? value;

const formatOdds = (odds: number) => (odds > 0 ? `+${odds}` : String(odds));

const signedNumber = (value: number | null | undefined, decimals = 1) => {
    if (value === null || value === undefined || Number.isNaN(Number(value))) {
        return '0.0';
    }

    const numeric = Number(value);

    return `${numeric > 0 ? '+' : ''}${numeric.toFixed(decimals)}`;
};

const recommendationTone = (rec: PlayerPropRecommendation) =>
    rec.recommendation === 'Over'
        ? 'text-emerald-600 dark:text-emerald-400'
        : 'text-red-600 dark:text-red-400';

const confidenceLabel = (rec: PlayerPropRecommendation) => {
    if (rec.signal_quality?.label) return rec.signal_quality.label;

    const dataQuality = Number(rec.data_quality_score ?? 0);
    if (rec.confidence >= 88 && dataQuality >= 85) return 'Very Strong';
    if (rec.confidence >= 75 && dataQuality >= 75) return 'Strong';
    if (rec.confidence >= 65) return 'Lean';

    return 'Watch';
};

const formatPercent = (value: number | null | undefined) => {
    if (value === null || value === undefined || Number.isNaN(Number(value))) {
        return '-';
    }

    const numeric = Number(value);

    return `${(Math.abs(numeric) <= 1 ? numeric * 100 : numeric).toFixed(1)}%`;
};

onMounted(loadProps);

watch(
    () => props.gameId,
    () => {
        void loadProps();
    },
);
</script>

<template>
    <Card v-if="shouldRender">
        <CardHeader>
            <div
                class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between"
            >
                <div>
                    <div class="ui-kicker">Pregame Props</div>
                    <CardTitle class="tracking-tight">{{ title }}</CardTitle>
                    <CardDescription>
                        Model probability, market price, edge, and data quality
                        in one place.
                    </CardDescription>
                </div>

                <div
                    v-if="strongestRecommendation"
                    class="ui-surface-subtle min-w-0 p-3 lg:w-72"
                >
                    <p class="text-xs font-medium text-muted-foreground">
                        Strongest prop
                    </p>
                    <p class="mt-1 truncate font-semibold">
                        {{ strongestRecommendation.player.name }}
                    </p>
                    <div class="mt-1 flex flex-wrap items-center gap-2 text-sm">
                        <span
                            :class="recommendationTone(strongestRecommendation)"
                        >
                            {{ strongestRecommendation.recommendation }}
                            {{ strongestRecommendation.line }}
                        </span>
                        <span class="text-muted-foreground">
                            {{ strongestRecommendation.market }}
                        </span>
                    </div>
                </div>
            </div>
        </CardHeader>

        <CardContent class="space-y-4">
            <div
                v-if="marketOptions.length > 1"
                class="flex gap-2 overflow-x-auto pb-1"
            >
                <Button
                    v-for="market in marketOptions"
                    :key="market.value"
                    type="button"
                    size="sm"
                    :variant="
                        selectedMarket === market.value ? 'default' : 'outline'
                    "
                    class="shrink-0"
                    @click="selectedMarket = market.value"
                >
                    {{ market.label }}
                </Button>
            </div>

            <div
                v-if="loading"
                class="py-8 text-center text-sm text-muted-foreground"
            >
                Loading player props...
            </div>

            <div v-else-if="error && filteredRecommendations.length > 0" class="py-8 text-center">
                <BarChart3
                    class="mx-auto mb-3 h-10 w-10 text-muted-foreground"
                />
                <p class="font-medium">Player props unavailable</p>
                <p class="text-sm text-muted-foreground">{{ error }}</p>
            </div>

            <div v-else class="space-y-2">
                <div
                    v-for="rec in filteredRecommendations"
                    :key="rec.id"
                    class="grid gap-3 border-b py-3 last:border-b-0 md:grid-cols-[minmax(0,1.3fr)_minmax(0,1fr)_auto] md:items-center"
                >
                    <div class="min-w-0">
                        <div class="flex min-w-0 items-center gap-2">
                            <component
                                :is="
                                    rec.recommendation === 'Over'
                                        ? TrendingUp
                                        : TrendingDown
                                "
                                :class="[
                                    'h-4 w-4 shrink-0',
                                    recommendationTone(rec),
                                ]"
                            />
                            <p class="truncate font-semibold">
                                {{ rec.player.name }}
                            </p>
                            <Badge
                                variant="outline"
                                class="shrink-0 text-[10px]"
                            >
                                {{ rec.player.team }}
                            </Badge>
                        </div>
                        <p class="mt-1 text-sm text-muted-foreground">
                            {{ rec.market }} • {{ rec.recommendation }}
                            {{ rec.line }} • {{ formatOdds(rec.odds) }}
                        </p>
                    </div>

                    <div class="grid grid-cols-3 gap-2 text-sm">
                        <div>
                            <p class="text-xs text-muted-foreground">Model</p>
                            <p class="font-medium">
                                {{ formatPercent(rec.model_over_probability) }}
                            </p>
                        </div>
                        <div>
                            <p class="text-xs text-muted-foreground">Market</p>
                            <p class="font-medium">
                                {{ formatPercent(rec.market_over_probability) }}
                            </p>
                        </div>
                        <div>
                            <p class="text-xs text-muted-foreground">Quality</p>
                            <p class="font-medium">
                                {{ formatPercent(rec.data_quality_score) }}
                            </p>
                        </div>
                    </div>

                    <div class="flex items-center gap-2 md:justify-end">
                        <Badge variant="secondary">
                            {{ confidenceLabel(rec) }}
                            {{ rec.confidence }}%
                        </Badge>
                        <Badge
                            variant="outline"
                            :class="
                                rec.edge >= 0
                                    ? 'border-emerald-500/30 text-emerald-600 dark:text-emerald-400'
                                    : 'border-red-500/30 text-red-600 dark:text-red-400'
                            "
                        >
                            Edge {{ signedNumber(rec.edge) }}
                        </Badge>
                    </div>
                </div>
            </div>
        </CardContent>
    </Card>
</template>
