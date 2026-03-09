<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { BarChart3, TrendingDown, TrendingUp, X } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';

type Recommendation = {
    id: number;
    player: {
        id: number;
        name: string;
        position: string;
        team: string;
        headshot: string;
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
        vs_opponent_avg: number | null;
        consistency: { std_dev: number; level: string; min: number; max: number } | null;
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
        model_edge_score: number;
        data_quality_score: number;
        match_quality_score: number;
        context_factor: number;
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

const props = defineProps<{
    sport: string;
    recommendations: Recommendation[];
    dates: DateOption[];
    games: GameOption[];
    markets: MarketOption[];
    filters: {
        date: string | null;
        game: string | number | null;
        market: string | null;
    };
    sportLabel: string;
    description: string;
}>();

const selectedDate = ref(props.filters.date || '');
const selectedGame = ref(props.filters.game !== null ? String(props.filters.game) : '');
const selectedMarket = ref(props.filters.market || '');

const filteredGames = computed(() => {
    if (!selectedDate.value) {
        return props.games;
    }

    return props.games.filter((game) => game.date === selectedDate.value);
});

const hasActiveFilters = computed(() =>
    selectedDate.value !== '' || selectedGame.value !== '' || selectedMarket.value !== ''
);

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
    const params: Record<string, string> = {};
    if (selectedDate.value) params.date = selectedDate.value;
    if (selectedGame.value) params.game = selectedGame.value;
    if (selectedMarket.value) params.market = selectedMarket.value;

    router.get(window.location.pathname, params, {
        preserveState: true,
        preserveScroll: true,
        preserveUrl: true,
        replace: true,
    });
};

const clearFilters = () => {
    selectedDate.value = '';
    selectedGame.value = '';
    selectedMarket.value = '';
    router.get(window.location.pathname, {}, {
        preserveState: true,
        preserveScroll: true,
        preserveUrl: true,
        replace: true,
    });
};

const getConfidenceColor = (confidence: number) => {
    if (confidence >= 80) return 'bg-green-500';
    if (confidence >= 70) return 'bg-emerald-500';
    if (confidence >= 60) return 'bg-yellow-500';
    return 'bg-gray-500';
};

const formatOdds = (odds: number) => (odds > 0 ? `+${odds}` : odds.toString());

const getSignalBand = (confidence: number) => {
    if (confidence >= 80) return 'Very Strong';
    if (confidence >= 70) return 'Strong';
    if (confidence >= 60) return 'Lean';
    return 'Low';
};

const getSignalBandVariant = (confidence: number) => {
    if (confidence >= 80) return 'default';
    if (confidence >= 70) return 'secondary';
    if (confidence >= 60) return 'outline';
    return 'outline';
};

const getInitials = (name: string) =>
    name
        .split(' ')
        .filter(Boolean)
        .map((part) => part[0])
        .join('')
        .toUpperCase();

const getResultStatus = (rec: Recommendation) => {
    if (!rec.graded_at || rec.actual_value === null || rec.actual_value === undefined) {
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
    const won = (rec.recommendation === 'Over' && didGoOver)
        || (rec.recommendation === 'Under' && !didGoOver);

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

const isModelDetailsOpen = (id: number) => expandedModelDetails.value.includes(id);

const toggleModelDetails = (id: number) => {
    if (isModelDetailsOpen(id)) {
        expandedModelDetails.value = expandedModelDetails.value.filter((item) => item !== id);
        return;
    }

    expandedModelDetails.value = [...expandedModelDetails.value, id];
};
</script>

<template>
    <AppLayout>
        <div class="mx-auto w-full max-w-7xl space-y-6 p-3 md:p-4">
            <div class="space-y-2">
                <p class="ui-kicker">Player Markets</p>
                <h1 class="text-3xl font-semibold tracking-tight">{{ sportLabel }} Player Props</h1>
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
                                <option v-for="date in dates" :key="date.value" :value="date.value">
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
                                <option v-for="game in filteredGames" :key="game.id" :value="game.id.toString()">
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
                                <option v-for="market in markets" :key="market.value" :value="market.value">
                                    {{ market.label }}
                                </option>
                            </select>
                        </div>

                        <div class="flex gap-2">
                            <Button v-if="hasActiveFilters" variant="outline" @click="clearFilters">
                                <X class="mr-2 h-4 w-4" />
                                Clear
                            </Button>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <div v-if="recommendations.length === 0" class="py-16 text-center">
                <BarChart3 class="mx-auto mb-4 h-16 w-16 text-muted-foreground" />
                <h3 class="mb-2 text-xl font-semibold tracking-tight">No Recommendations Available</h3>
                <p class="text-muted-foreground">Check back later or sync player props to see betting recommendations.</p>
            </div>

            <div v-else class="columns-1 gap-5 md:columns-2 lg:columns-3">
                <Card
                    v-for="rec in recommendations"
                    :key="rec.id"
                    class="mb-5 inline-block w-full break-inside-avoid overflow-hidden transition-all duration-200 hover:-translate-y-0.5 hover:shadow-md"
                >
                    <CardHeader>
                        <div class="flex items-start gap-3">
                            <div class="flex min-w-0 flex-1 items-start gap-3">
                                <template v-if="rec.player?.url">
                                    <Link :href="rec.player.url" class="shrink-0">
                                        <Avatar class="h-12 w-12 border-2 border-border transition-colors hover:border-primary">
                                            <AvatarImage :src="rec.player.headshot" :alt="rec.player.name" class="object-cover" />
                                            <AvatarFallback>{{ getInitials(rec.player.name || 'Unknown') }}</AvatarFallback>
                                        </Avatar>
                                    </Link>
                                </template>
                                <template v-else>
                                    <Avatar class="h-12 w-12 border-2 border-border">
                                        <AvatarImage :src="rec.player.headshot" :alt="rec.player.name" class="object-cover" />
                                        <AvatarFallback>{{ getInitials(rec.player.name || 'Unknown') }}</AvatarFallback>
                                    </Avatar>
                                </template>

                                <div class="min-w-0 flex-1 space-y-1">
                                    <template v-if="rec.player?.url">
                                        <Link :href="rec.player.url" class="hover:underline">
                                            <CardTitle class="truncate text-lg tracking-tight">{{ rec.player.name }}</CardTitle>
                                        </Link>
                                    </template>
                                    <template v-else>
                                        <CardTitle class="truncate text-lg tracking-tight">{{ rec.player.name }}</CardTitle>
                                    </template>

                                    <div class="flex flex-wrap items-center gap-2">
                                        <CardDescription v-if="rec.player.position && rec.player.team" class="shrink-0">
                                            {{ rec.player.position }} • {{ rec.player.team }}
                                        </CardDescription>
                                        <Badge :variant="getSignalBandVariant(rec.confidence)" class="max-w-full shrink text-[10px]">
                                            {{ getSignalBand(rec.confidence) }}
                                        </Badge>
                                        <Badge
                                            v-if="rec.streak && rec.streak.count >= 2"
                                            :variant="rec.streak.status === 'hot' ? 'default' : 'secondary'"
                                            class="shrink-0 text-xs"
                                        >
                                            {{ rec.streak.status === 'hot' ? '🔥' : '❄️' }} {{ rec.streak.count }}
                                        </Badge>
                                    </div>
                                    <CardDescription class="truncate text-xs">
                                        {{ rec.game?.away_team }} @ {{ rec.game?.home_team }}
                                    </CardDescription>
                                </div>
                            </div>
                        </div>
                    </CardHeader>

                    <CardContent class="space-y-4">
                        <div class="ui-surface-subtle flex items-center justify-between p-3">
                            <div class="flex items-center gap-2">
                                <component
                                    :is="rec.recommendation === 'Over' ? TrendingUp : TrendingDown"
                                    :class="[
                                        'h-5 w-5',
                                        rec.recommendation === 'Over' ? 'text-green-500' : 'text-red-500',
                                    ]"
                                />
                                <div>
                                    <p class="font-semibold">{{ rec.recommendation }} {{ rec.line }}</p>
                                    <p class="text-xs text-muted-foreground">{{ rec.market }}</p>
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
                                v-if="rec.actual_value !== null && rec.actual_value !== undefined"
                                class="text-xs text-muted-foreground"
                            >
                                Actual: {{ Number(rec.actual_value).toFixed(1) }}
                            </span>
                        </div>

                        <div class="space-y-1.5">
                            <div class="flex justify-between text-xs text-muted-foreground">
                                <span>Signal Strength</span>
                                <span>{{ rec.confidence }}%</span>
                            </div>
                            <div class="h-2 overflow-hidden rounded-full bg-muted">
                                <div
                                    class="h-full transition-all"
                                    :class="getConfidenceColor(rec.confidence)"
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
                                {{ isModelDetailsOpen(rec.id) ? 'Hide Stats' : 'Show Stats' }}
                            </Button>

                            <div
                                v-if="isModelDetailsOpen(rec.id)"
                                class="ui-surface-subtle space-y-2 p-2 text-xs"
                            >
                                <div class="space-y-2">
                                    <div class="flex justify-between text-sm">
                                        <span class="text-muted-foreground">Season Avg</span>
                                        <span class="font-medium">{{ rec.stats?.season_avg ?? 0 }}</span>
                                    </div>
                                    <div class="flex justify-between text-sm">
                                        <span class="text-muted-foreground">Last 10 Games</span>
                                        <span
                                            :class="[
                                                'font-medium',
                                                (rec.stats?.recent_avg ?? 0) > (rec.stats?.season_avg ?? 0)
                                                    ? 'text-green-600 dark:text-green-400'
                                                    : 'text-red-600 dark:text-red-400',
                                            ]"
                                        >
                                            {{ rec.stats?.recent_avg ?? 0 }}
                                        </span>
                                    </div>
                                    <div class="flex justify-between text-sm">
                                        <span class="text-muted-foreground">Last 5 Games</span>
                                        <span
                                            :class="[
                                                'font-medium',
                                                (rec.stats?.last5_avg ?? 0) > (rec.stats?.recent_avg ?? 0)
                                                    ? 'text-green-600 dark:text-green-400'
                                                    : 'text-red-600 dark:text-red-400',
                                            ]"
                                        >
                                            {{ rec.stats?.last5_avg ?? 0 }}
                                        </span>
                                    </div>
                                    <div v-if="rec.stats?.vs_opponent_avg !== null" class="flex justify-between text-sm">
                                        <span class="text-muted-foreground">vs Opponent</span>
                                        <span
                                            :class="[
                                                'font-medium',
                                                rec.stats.vs_opponent_avg > (rec.stats?.season_avg ?? 0)
                                                    ? 'text-green-600 dark:text-green-400'
                                                    : 'text-red-600 dark:text-red-400',
                                            ]"
                                        >
                                            {{ rec.stats.vs_opponent_avg }}
                                        </span>
                                    </div>
                                    <div v-if="rec.stats?.times_covered_last5" class="flex justify-between text-sm">
                                        <span class="text-muted-foreground">Hit {{ rec.recommendation }} (L5)</span>
                                        <span class="font-medium">
                                            {{
                                                rec.recommendation === 'Under'
                                                    ? (rec.stats.times_covered_last5.games - rec.stats.times_covered_last5.hits)
                                                    : rec.stats.times_covered_last5.hits
                                            }}/{{ rec.stats.times_covered_last5.games }}
                                        </span>
                                    </div>
                                    <div v-if="rec.stats?.times_covered_season" class="flex justify-between text-sm">
                                        <span class="text-muted-foreground">Hit {{ rec.recommendation }} (Season)</span>
                                        <span class="font-medium">
                                            {{
                                                rec.recommendation === 'Under'
                                                    ? (rec.stats.times_covered_season.games - rec.stats.times_covered_season.hits)
                                                    : rec.stats.times_covered_season.hits
                                            }}/{{ rec.stats.times_covered_season.games }}
                                        </span>
                                    </div>
                                    <div v-if="rec.stats?.consistency" class="flex justify-between text-sm">
                                        <span class="text-muted-foreground">Consistency</span>
                                        <span class="text-xs font-medium">
                                            {{ rec.stats.consistency.level }} (±{{ rec.stats.consistency.std_dev }})
                                        </span>
                                    </div>
                                    <div class="flex justify-between border-t pt-2 text-sm">
                                        <span class="text-muted-foreground">Edge vs Line</span>
                                        <span
                                            :class="[
                                                'font-bold',
                                                (rec.edge ?? 0) > 0
                                                    ? 'text-green-600 dark:text-green-400'
                                                    : 'text-red-600 dark:text-red-400',
                                            ]"
                                        >
                                            {{ (rec.edge ?? 0) > 0 ? '+' : '' }}{{ rec.edge ?? 0 }}
                                        </span>
                                    </div>
                                    <div
                                        v-if="rec.model_over_probability !== null && rec.market_over_probability !== null"
                                        class="flex justify-between text-sm"
                                    >
                                        <span class="text-muted-foreground">Model vs Market</span>
                                        <span class="font-medium">
                                            {{ rec.model_over_probability?.toFixed(1) }}% vs {{ rec.market_over_probability?.toFixed(1) }}%
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </div>
    </AppLayout>
</template>
