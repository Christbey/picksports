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
import { fetchJson } from '@/composables/useApiClient';

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
    reasoning: string[];
};

type MarketOption = {
    value: string;
    label: string;
};

type PlayerPropsResponse = {
    data: PlayerPropRecommendation[];
    markets: MarketOption[];
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
const loading = ref(true);
const error = ref<string | null>(null);

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

const strongestRecommendation = computed(
    () => recommendations.value[0] ?? null,
);

const loadProps = async () => {
    loading.value = true;
    error.value = null;

    try {
        const payload = await fetchJson<PlayerPropsResponse>(
            `/api/v1/${props.sportSlug}/player-props?game=${props.gameId}`,
        );

        recommendations.value = payload?.data ?? [];
        markets.value = payload?.markets ?? [];

        if (
            selectedMarket.value &&
            !markets.value.some(
                (market) => market.value === selectedMarket.value,
            )
        ) {
            selectedMarket.value = '';
        }
    } catch (err) {
        error.value =
            err instanceof Error ? err.message : 'Unable to load player props.';
        recommendations.value = [];
    } finally {
        loading.value = false;
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

const confidenceLabel = (confidence: number) => {
    if (confidence >= 85) return 'Best';
    if (confidence >= 75) return 'Strong';
    if (confidence >= 65) return 'Lean';

    return 'Watch';
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
    <Card>
        <CardHeader>
            <div
                class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between"
            >
                <div>
                    <div class="ui-kicker">Pregame Props</div>
                    <CardTitle class="tracking-tight">{{ title }}</CardTitle>
                    <CardDescription>
                        Model projection, recent form, market edge, and risk in
                        one place.
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

            <div v-else-if="error" class="py-8 text-center">
                <BarChart3
                    class="mx-auto mb-3 h-10 w-10 text-muted-foreground"
                />
                <p class="font-medium">Player props unavailable</p>
                <p class="text-sm text-muted-foreground">{{ error }}</p>
            </div>

            <div
                v-else-if="filteredRecommendations.length === 0"
                class="py-8 text-center"
            >
                <BarChart3
                    class="mx-auto mb-3 h-10 w-10 text-muted-foreground"
                />
                <p class="font-medium">No playable props yet</p>
                <p class="text-sm text-muted-foreground">
                    Sync props or loosen the market filter.
                </p>
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
                            <p class="text-xs text-muted-foreground">Season</p>
                            <p class="font-medium">
                                {{ rec.stats?.season_avg ?? '—' }}
                            </p>
                        </div>
                        <div>
                            <p class="text-xs text-muted-foreground">Recent</p>
                            <p class="font-medium">
                                {{ rec.stats?.recent_avg ?? '—' }}
                            </p>
                        </div>
                        <div>
                            <p class="text-xs text-muted-foreground">Last 5</p>
                            <p class="font-medium">
                                {{ rec.stats?.last5_avg ?? '—' }}
                            </p>
                        </div>
                    </div>

                    <div class="flex items-center gap-2 md:justify-end">
                        <Badge variant="secondary">
                            {{ confidenceLabel(rec.confidence) }}
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
