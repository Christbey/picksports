<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { BarChart3, TrendingDown, TrendingUp, X } from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { useApiV2Client } from '@/composables/useApiV2Client';
import AppLayout from '@/layouts/AppLayout.vue';

type CoverRecord = {
    games: number;
    over: number;
    under: number;
    pushes: number;
    hits: number;
    recommendation: 'Over' | 'Under';
    wins: number;
    losses: number;
    win_rate: number | null;
    record: string;
    recommendation_record: string;
};

type Recommendation = {
    id: number;
    player: {
        id: number | null;
        name: string;
        position: string | null;
        team: string | null;
        headshot: string | null;
        url: string | null;
    };
    market: string;
    line: number;
    recommendation: 'Over' | 'Under';
    odds: number;
    confidence: number;
    stats: {
        season_avg: number;
        recent_avg: number;
        last5_avg: number;
        times_covered_last5: { hits: number; games: number } | null;
        times_covered_season: { hits: number; games: number } | null;
        cover_record: {
            season: CoverRecord | null;
            last_10: CoverRecord | null;
            last_5: CoverRecord | null;
            home_away: CoverRecord | null;
            vs_opponent: CoverRecord | null;
        } | null;
        vs_opponent_avg: number | null;
        consistency: {
            std_dev: number;
            level: string;
            min: number;
            max: number;
        } | null;
    };
    streak: { count: number; type: string; status: string } | null;
    edge: number;
    model_over_probability?: number | null;
    market_over_probability?: number | null;
    edge_probability?: number | null;
    context?: {
        pace_factor: number;
        opponent_factor: number;
        minutes_factor: number;
        combined_factor: number;
    } | null;
    data_quality_score?: number | null;
    match_quality_score?: number | null;
    confidence_decomposition?: {
        schema_version?: string;
        model_edge_score: number;
        data_quality_score: number;
        match_quality_score: number;
        context_factor: number;
        signal_quality?: {
            label?: string;
            tier?: string;
            reason_codes?: string[];
        };
    } | null;
    actual_value?: number | null;
    hit_over?: boolean | null;
    graded_at?: string | null;
    reasoning: string[];
    game: {
        id: number;
        home_team: string;
        away_team: string;
        date: string;
        time: string;
    };
    bookmaker: string;
};

type DateOption = {
    value: string;
    label: string;
};

type GameOption = {
    id: number;
    label: string;
    date: string;
    time: string;
};

type MarketOption = {
    value: string;
    label: string;
};

type PlayerPropsFilters = {
    date: string | null;
    game: string | number | null;
    market: string | null;
};

type PlayerPropsMeta = {
    diagnostics?: {
        raw_prop_count?: number;
        analyzed_prop_count?: number;
        recommendation_candidate_count?: number;
        missing_player_link_count?: number;
    };
    warnings?: string[];
};

type PlayerPropsResponse = {
    sport: string;
    data: Recommendation[];
    dates: DateOption[];
    games: GameOption[];
    markets: MarketOption[];
    filters: PlayerPropsFilters;
    meta?: PlayerPropsMeta;
};

const props = defineProps<{
    sport: string;
    sportSlug: string;
    filters: PlayerPropsFilters;
    sportLabel: string;
    description: string;
}>();

const selectedDate = ref(props.filters.date || '');
const selectedGame = ref(
    props.filters.game !== null ? String(props.filters.game) : '',
);
const selectedMarket = ref(props.filters.market || '');
const recommendations = ref<Recommendation[]>([]);
const dates = ref<DateOption[]>([]);
const games = ref<GameOption[]>([]);
const markets = ref<MarketOption[]>([]);
const boardMeta = ref<PlayerPropsMeta | null>(null);
const loading = ref(true);
const error = ref<string | null>(null);
const visibleLimit = ref(18);
const api = useApiV2Client();
let boardAbortController: AbortController | null = null;

const filteredGames = computed(() => {
    if (!selectedDate.value) {
        return games.value;
    }

    return games.value.filter((game) => game.date === selectedDate.value);
});

const hasActiveFilters = computed(
    () =>
        selectedDate.value !== '' ||
        selectedGame.value !== '' ||
        selectedMarket.value !== '',
);

const visibleRecommendations = computed(() =>
    recommendations.value.slice(0, visibleLimit.value),
);

const emptyStateTitle = computed(() => {
    const diagnostics = boardMeta.value?.diagnostics;
    if (!diagnostics) return 'No Recommendations Available';

    if ((diagnostics.raw_prop_count ?? 0) === 0) {
        return 'No Props Synced For This Filter';
    }

    if ((diagnostics.analyzed_prop_count ?? 0) === 0) {
        return 'Props Need Analysis';
    }

    return 'No Recommendation-Ready Props';
});

const emptyStateMessage = computed(() => {
    const diagnostics = boardMeta.value?.diagnostics;
    const warning = boardMeta.value?.warnings?.[0];
    if (warning) return warning;

    if (!diagnostics) {
        return 'Check back later or sync player props to see betting recommendations.';
    }

    if ((diagnostics.raw_prop_count ?? 0) === 0) {
        return 'No player props were found for the selected date, game, or market.';
    }

    if ((diagnostics.analyzed_prop_count ?? 0) === 0) {
        return 'Player props are synced, but the recommendation analysis has not run for this slate yet.';
    }

    return 'Props were analyzed, but none currently clear the recommendation threshold for this filter.';
});

const onDateChange = () => {
    selectedGame.value = '';
    applyFilters();
};

const onGameChange = () => {
    applyFilters();
};

const onMarketChange = () => {
    applyFilters();
};

const applyFilters = () => {
    void loadBoard();
};

const buildQueryParams = (): Record<string, string> => {
    const params: Record<string, string> = { limit: '75' };
    if (selectedDate.value) params.date = selectedDate.value;
    if (selectedGame.value) params.game = selectedGame.value;
    if (selectedMarket.value) params.market = selectedMarket.value;

    return params;
};

const clearFilters = () => {
    selectedDate.value = '';
    selectedGame.value = '';
    selectedMarket.value = '';
    void loadBoard();
};

const updateBrowserUrl = (filters: PlayerPropsFilters) => {
    const url = new URL(window.location.href);
    url.search = '';

    if (filters.date) url.searchParams.set('date', filters.date);
    if (filters.game !== null && filters.game !== '')
        url.searchParams.set('game', String(filters.game));
    if (filters.market) url.searchParams.set('market', filters.market);

    window.history.replaceState({}, '', `${url.pathname}${url.search}`);
};

const loadBoard = async () => {
    boardAbortController?.abort();
    const controller = new AbortController();
    boardAbortController = controller;
    loading.value = true;
    error.value = null;

    try {
        const payload = await api.playerProps.board<PlayerPropsResponse>(
            props.sportSlug,
            {
                query: buildQueryParams(),
                init: { signal: controller.signal },
            },
        );
        if (!payload) {
            error.value = 'Unable to load player props.';
            recommendations.value = [];
            return;
        }

        recommendations.value = payload.data || [];
        visibleLimit.value = 18;
        dates.value = payload.dates || [];
        games.value = payload.games || [];
        markets.value = payload.markets || [];
        boardMeta.value = payload.meta || null;

        selectedDate.value = payload.filters.date || '';
        selectedGame.value =
            payload.filters.game !== null ? String(payload.filters.game) : '';
        selectedMarket.value = payload.filters.market || '';
        updateBrowserUrl(payload.filters);
    } catch (err) {
        if (controller.signal.aborted) return;
        error.value =
            err instanceof Error ? err.message : 'Unable to load player props.';
        recommendations.value = [];
        boardMeta.value = null;
    } finally {
        if (boardAbortController === controller) {
            loading.value = false;
        }
    }
};

const getConfidenceColor = (rec: Recommendation) => {
    const tier = rec.confidence_decomposition?.signal_quality?.tier;
    if (tier === 'very_strong') return 'bg-green-500';
    if (tier === 'strong') return 'bg-emerald-500';
    if (rec.confidence >= 60) return 'bg-yellow-500';
    return 'bg-gray-500';
};

const formatOdds = (odds: number) => (odds > 0 ? `+${odds}` : odds.toString());

const getSignalBand = (rec: Recommendation) => {
    const label = rec.confidence_decomposition?.signal_quality?.label;
    if (label) return label;

    const confidence = rec.confidence;
    const dataQuality = Number(rec.data_quality_score ?? 0);
    if (confidence >= 88 && dataQuality >= 85) return 'Very Strong';
    if (confidence >= 75 && dataQuality >= 75) return 'Strong';
    if (confidence >= 60) return 'Lean';
    return 'Low';
};

const getSignalBandVariant = (rec: Recommendation) => {
    const tier = rec.confidence_decomposition?.signal_quality?.tier;
    if (tier === 'very_strong') return 'default';
    if (tier === 'strong') return 'secondary';
    if (rec.confidence >= 60) return 'outline';
    return 'outline';
};

const getCoverRecordRows = (rec: Recommendation) => {
    const record = rec.stats?.cover_record;
    if (!record) return [];

    return [
        ['Season', record.season],
        ['Last 10', record.last_10],
        ['Last 5', record.last_5],
        ['Home/Away', record.home_away],
        ['vs Opponent', record.vs_opponent],
    ].filter((row): row is [string, CoverRecord] => row[1] !== null);
};

const formatCoverRecord = (
    record: CoverRecord,
    recommendation: 'Over' | 'Under',
) => `${recommendation} ${record.recommendation_record}`;

const formatCoverRate = (record: CoverRecord) =>
    record.win_rate === null ? 'N/A' : `${record.win_rate.toFixed(1)}%`;

const getInitials = (name: string) =>
    name
        .split(' ')
        .filter(Boolean)
        .map((part) => part[0])
        .join('')
        .toUpperCase();

const getResultStatus = (rec: Recommendation) => {
    if (
        !rec.graded_at ||
        rec.actual_value === null ||
        rec.actual_value === undefined
    ) {
        return {
            label: 'Pending',
            variant: 'outline' as const,
            className: 'text-muted-foreground border-border',
        };
    }

    const actual = Number(rec.actual_value ?? 0);
    const line = Number(rec.line ?? 0);
    if (Math.abs(actual - line) < 0.0001) {
        return {
            label: 'Push',
            variant: 'secondary' as const,
            className: '',
        };
    }

    const didGoOver = Boolean(rec.hit_over);
    const won =
        (rec.recommendation === 'Over' && didGoOver) ||
        (rec.recommendation === 'Under' && !didGoOver);

    if (won) {
        return {
            label: 'Won',
            variant: 'default' as const,
            className: 'bg-emerald-600 hover:bg-emerald-600 text-white',
        };
    }

    return {
        label: 'Lost',
        variant: 'destructive' as const,
        className: '',
    };
};

const expandedModelDetails = ref<number[]>([]);

const isModelDetailsOpen = (id: number) =>
    expandedModelDetails.value.includes(id);

const toggleModelDetails = (id: number) => {
    if (isModelDetailsOpen(id)) {
        expandedModelDetails.value = expandedModelDetails.value.filter(
            (item) => item !== id,
        );
        return;
    }

    expandedModelDetails.value = [...expandedModelDetails.value, id];
};

onMounted(() => {
    void loadBoard();
});

onBeforeUnmount(() => {
    boardAbortController?.abort();
});
</script>

<template>
    <AppLayout>
        <div class="mx-auto w-full max-w-7xl space-y-6 p-3 md:p-4">
            <div class="space-y-2">
                <p class="ui-kicker">Player Markets</p>
                <h1 class="text-3xl font-semibold tracking-tight">
                    {{ sportLabel }} Player Props
                </h1>
                <p class="text-muted-foreground">{{ description }}</p>
            </div>

            <Card>
                <CardContent class="pt-6">
                    <div class="flex min-w-0 flex-wrap items-end gap-4">
                        <div class="min-w-[200px] flex-1 space-y-2">
                            <Label for="date">Game Date</Label>
                            <select
                                id="date"
                                v-model="selectedDate"
                                @change="onDateChange"
                                class="ui-select"
                            >
                                <option value="">All dates</option>
                                <option
                                    v-for="date in dates"
                                    :key="date.value"
                                    :value="date.value"
                                >
                                    {{ date.label }}
                                </option>
                            </select>
                        </div>

                        <div class="min-w-[200px] flex-1 space-y-2">
                            <Label for="game">Matchup</Label>
                            <select
                                id="game"
                                v-model="selectedGame"
                                @change="onGameChange"
                                class="ui-select"
                                :disabled="filteredGames.length === 0"
                            >
                                <option value="">All games</option>
                                <option
                                    v-for="game in filteredGames"
                                    :key="game.id"
                                    :value="game.id.toString()"
                                >
                                    {{ game.label }}
                                </option>
                            </select>
                        </div>

                        <div class="min-w-[180px] flex-1 space-y-2">
                            <Label for="market">Prop Type</Label>
                            <select
                                id="market"
                                v-model="selectedMarket"
                                @change="onMarketChange"
                                class="ui-select"
                            >
                                <option value="">All props</option>
                                <option
                                    v-for="market in markets"
                                    :key="market.value"
                                    :value="market.value"
                                >
                                    {{ market.label }}
                                </option>
                            </select>
                        </div>

                        <div class="flex gap-2">
                            <Button
                                v-if="hasActiveFilters"
                                variant="outline"
                                @click="clearFilters"
                            >
                                <X class="mr-2 h-4 w-4" />
                                Clear
                            </Button>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <div v-if="loading" class="py-16 text-center">
                <p class="text-muted-foreground">Loading player props...</p>
            </div>

            <div v-else-if="error" class="py-16 text-center">
                <h3 class="mb-2 text-xl font-semibold tracking-tight">
                    Unable To Load Player Props
                </h3>
                <p class="text-muted-foreground">{{ error }}</p>
            </div>

            <div
                v-else-if="recommendations.length === 0"
                class="py-16 text-center"
            >
                <BarChart3
                    class="mx-auto mb-4 h-16 w-16 text-muted-foreground"
                />
                <h3 class="mb-2 text-xl font-semibold tracking-tight">
                    {{ emptyStateTitle }}
                </h3>
                <p class="text-muted-foreground">
                    {{ emptyStateMessage }}
                </p>
            </div>

            <template v-else>
                <div class="columns-1 gap-5 md:columns-2 lg:columns-3">
                    <Card
                        v-for="rec in visibleRecommendations"
                        :key="rec.id"
                        class="mb-5 inline-block w-full break-inside-avoid overflow-hidden transition-all duration-200 hover:-translate-y-0.5 hover:shadow-md"
                    >
                        <CardHeader>
                            <div class="flex items-start gap-3">
                                <div
                                    class="flex min-w-0 flex-1 items-start gap-3"
                                >
                                    <template v-if="rec.player?.url">
                                        <Link
                                            :href="rec.player.url"
                                            class="shrink-0"
                                        >
                                            <Avatar
                                                class="h-12 w-12 border-2 border-border transition-colors hover:border-primary"
                                            >
                                                <AvatarImage
                                                    v-if="rec.player.headshot"
                                                    :src="rec.player.headshot"
                                                    :alt="rec.player.name"
                                                    loading="lazy"
                                                    decoding="async"
                                                    class="object-cover"
                                                />
                                                <AvatarFallback>{{
                                                    getInitials(
                                                        rec.player.name ||
                                                            'Unknown',
                                                    )
                                                }}</AvatarFallback>
                                            </Avatar>
                                        </Link>
                                    </template>
                                    <template v-else>
                                        <Avatar
                                            class="h-12 w-12 border-2 border-border"
                                        >
                                            <AvatarImage
                                                v-if="rec.player.headshot"
                                                :src="rec.player.headshot"
                                                :alt="rec.player.name"
                                                loading="lazy"
                                                decoding="async"
                                                class="object-cover"
                                            />
                                            <AvatarFallback>{{
                                                getInitials(
                                                    rec.player.name ||
                                                        'Unknown',
                                                )
                                            }}</AvatarFallback>
                                        </Avatar>
                                    </template>

                                    <div class="min-w-0 flex-1 space-y-1">
                                        <template v-if="rec.player?.url">
                                            <Link
                                                :href="rec.player.url"
                                                class="hover:underline"
                                            >
                                                <CardTitle
                                                    class="truncate text-lg tracking-tight"
                                                    >{{
                                                        rec.player.name
                                                    }}</CardTitle
                                                >
                                            </Link>
                                        </template>
                                        <template v-else>
                                            <CardTitle
                                                class="truncate text-lg tracking-tight"
                                                >{{
                                                    rec.player.name
                                                }}</CardTitle
                                            >
                                        </template>

                                        <div
                                            class="flex flex-wrap items-center gap-2"
                                        >
                                            <CardDescription
                                                v-if="
                                                    rec.player.position &&
                                                    rec.player.team
                                                "
                                                class="shrink-0"
                                            >
                                                {{ rec.player.position }} •
                                                {{ rec.player.team }}
                                            </CardDescription>
                                            <Badge
                                                :variant="
                                                    getSignalBandVariant(rec)
                                                "
                                                class="max-w-full shrink text-[10px]"
                                            >
                                                {{ getSignalBand(rec) }}
                                            </Badge>
                                            <Badge
                                                v-if="
                                                    rec.streak &&
                                                    rec.streak.count >= 2
                                                "
                                                :variant="
                                                    rec.streak.status === 'hot'
                                                        ? 'default'
                                                        : 'secondary'
                                                "
                                                class="shrink-0 text-xs"
                                            >
                                                {{
                                                    rec.streak.status === 'hot'
                                                        ? '🔥'
                                                        : '❄️'
                                                }}
                                                {{ rec.streak.count }}
                                            </Badge>
                                        </div>
                                        <CardDescription
                                            class="truncate text-xs"
                                        >
                                            {{ rec.game?.away_team }} @
                                            {{ rec.game?.home_team }}
                                        </CardDescription>
                                    </div>
                                </div>
                            </div>
                        </CardHeader>

                        <CardContent class="space-y-4">
                            <div
                                class="ui-surface-subtle flex items-center justify-between p-3"
                            >
                                <div class="flex items-center gap-2">
                                    <component
                                        :is="
                                            rec.recommendation === 'Over'
                                                ? TrendingUp
                                                : TrendingDown
                                        "
                                        :class="[
                                            'h-5 w-5',
                                            rec.recommendation === 'Over'
                                                ? 'text-green-500'
                                                : 'text-red-500',
                                        ]"
                                    />
                                    <div>
                                        <p class="font-semibold">
                                            {{ rec.recommendation }}
                                            {{ rec.line }}
                                        </p>
                                        <p
                                            class="text-xs text-muted-foreground"
                                        >
                                            {{ rec.market }}
                                        </p>
                                    </div>
                                </div>
                                <Badge variant="outline" class="font-mono">
                                    {{ formatOdds(rec.odds) }}
                                </Badge>
                            </div>

                            <div class="flex items-center justify-between">
                                <Badge
                                    :variant="getResultStatus(rec).variant"
                                    :class="getResultStatus(rec).className"
                                >
                                    {{ getResultStatus(rec).label }}
                                </Badge>
                                <span
                                    v-if="
                                        rec.actual_value !== null &&
                                        rec.actual_value !== undefined
                                    "
                                    class="text-xs text-muted-foreground"
                                >
                                    Actual:
                                    {{ Number(rec.actual_value).toFixed(1) }}
                                </span>
                            </div>

                            <div class="space-y-1.5">
                                <div
                                    class="flex justify-between text-xs text-muted-foreground"
                                >
                                    <span>Signal Score</span>
                                    <span>{{ rec.confidence }}/100</span>
                                </div>
                                <div
                                    class="h-2 overflow-hidden rounded-full bg-muted"
                                >
                                    <div
                                        class="h-full transition-all"
                                        :class="getConfidenceColor(rec)"
                                        :style="{ width: `${rec.confidence}%` }"
                                    />
                                </div>
                            </div>

                            <div class="space-y-2">
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    class="h-7 px-2 text-xs text-muted-foreground"
                                    @click="toggleModelDetails(rec.id)"
                                >
                                    {{
                                        isModelDetailsOpen(rec.id)
                                            ? 'Hide Stats'
                                            : 'Show Stats'
                                    }}
                                </Button>

                                <div
                                    v-if="isModelDetailsOpen(rec.id)"
                                    class="ui-surface-subtle space-y-2 p-2 text-xs"
                                >
                                    <div class="space-y-2">
                                        <div
                                            class="flex justify-between text-sm"
                                        >
                                            <span class="text-muted-foreground"
                                                >Season Avg</span
                                            >
                                            <span class="font-medium">{{
                                                rec.stats?.season_avg ?? 0
                                            }}</span>
                                        </div>
                                        <div
                                            class="flex justify-between text-sm"
                                        >
                                            <span class="text-muted-foreground"
                                                >Last 10 Games</span
                                            >
                                            <span
                                                :class="[
                                                    'font-medium',
                                                    (rec.stats?.recent_avg ??
                                                        0) >
                                                    (rec.stats?.season_avg ?? 0)
                                                        ? 'text-green-600 dark:text-green-400'
                                                        : 'text-red-600 dark:text-red-400',
                                                ]"
                                            >
                                                {{ rec.stats?.recent_avg ?? 0 }}
                                            </span>
                                        </div>
                                        <div
                                            class="flex justify-between text-sm"
                                        >
                                            <span class="text-muted-foreground"
                                                >Last 5 Games</span
                                            >
                                            <span
                                                :class="[
                                                    'font-medium',
                                                    (rec.stats?.last5_avg ??
                                                        0) >
                                                    (rec.stats?.recent_avg ?? 0)
                                                        ? 'text-green-600 dark:text-green-400'
                                                        : 'text-red-600 dark:text-red-400',
                                                ]"
                                            >
                                                {{ rec.stats?.last5_avg ?? 0 }}
                                            </span>
                                        </div>
                                        <div
                                            v-if="
                                                rec.stats?.vs_opponent_avg !==
                                                null
                                            "
                                            class="flex justify-between text-sm"
                                        >
                                            <span class="text-muted-foreground"
                                                >vs Opponent</span
                                            >
                                            <span
                                                :class="[
                                                    'font-medium',
                                                    rec.stats.vs_opponent_avg >
                                                    (rec.stats?.season_avg ?? 0)
                                                        ? 'text-green-600 dark:text-green-400'
                                                        : 'text-red-600 dark:text-red-400',
                                                ]"
                                            >
                                                {{ rec.stats.vs_opponent_avg }}
                                            </span>
                                        </div>
                                        <div
                                            v-if="
                                                getCoverRecordRows(rec).length
                                            "
                                            class="space-y-1 border-t pt-2"
                                        >
                                            <div
                                                class="text-[11px] font-semibold tracking-wide text-muted-foreground uppercase"
                                            >
                                                Cover Record
                                            </div>
                                            <div
                                                v-for="[
                                                    label,
                                                    record,
                                                ] in getCoverRecordRows(rec)"
                                                :key="`${rec.id}-${label}`"
                                                class="grid grid-cols-[76px_1fr_auto] items-center gap-2 text-xs"
                                            >
                                                <span
                                                    class="text-muted-foreground"
                                                >
                                                    {{ label }}
                                                </span>
                                                <span class="font-medium">
                                                    {{
                                                        formatCoverRecord(
                                                            record,
                                                            rec.recommendation,
                                                        )
                                                    }}
                                                </span>
                                                <span
                                                    class="text-muted-foreground"
                                                >
                                                    {{
                                                        formatCoverRate(record)
                                                    }}
                                                </span>
                                                <span
                                                    class="col-span-3 text-[11px] text-muted-foreground"
                                                >
                                                    Raw O/U:
                                                    {{ record.record }}
                                                    <template
                                                        v-if="record.pushes > 0"
                                                    >
                                                        incl. pushes
                                                    </template>
                                                </span>
                                            </div>
                                        </div>
                                        <div
                                            v-if="rec.stats?.consistency"
                                            class="flex justify-between text-sm"
                                        >
                                            <span class="text-muted-foreground"
                                                >Consistency</span
                                            >
                                            <span class="text-xs font-medium">
                                                {{
                                                    rec.stats.consistency.level
                                                }}
                                                (±{{
                                                    rec.stats.consistency
                                                        .std_dev
                                                }})
                                            </span>
                                        </div>
                                        <div
                                            class="flex justify-between border-t pt-2 text-sm"
                                        >
                                            <span class="text-muted-foreground"
                                                >Edge vs Line</span
                                            >
                                            <span
                                                :class="[
                                                    'font-bold',
                                                    (rec.edge ?? 0) > 0
                                                        ? 'text-green-600 dark:text-green-400'
                                                        : 'text-red-600 dark:text-red-400',
                                                ]"
                                            >
                                                {{
                                                    (rec.edge ?? 0) > 0
                                                        ? '+'
                                                        : ''
                                                }}{{ rec.edge ?? 0 }}
                                            </span>
                                        </div>
                                        <div
                                            v-if="
                                                rec.model_over_probability !==
                                                    null &&
                                                rec.market_over_probability !==
                                                    null
                                            "
                                            class="flex justify-between text-sm"
                                        >
                                            <span class="text-muted-foreground"
                                                >Model vs Market</span
                                            >
                                            <span class="font-medium">
                                                {{
                                                    rec.model_over_probability?.toFixed(
                                                        1,
                                                    )
                                                }}% vs
                                                {{
                                                    rec.market_over_probability?.toFixed(
                                                        1,
                                                    )
                                                }}%
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                </div>
                <div
                    v-if="
                        visibleRecommendations.length < recommendations.length
                    "
                    class="flex justify-center"
                >
                    <Button variant="outline" @click="visibleLimit += 18">
                        Show more props
                    </Button>
                </div>
            </template>
        </div>
    </AppLayout>
</template>
