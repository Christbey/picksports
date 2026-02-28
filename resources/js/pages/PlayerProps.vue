<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { TrendingUp, TrendingDown, Target, BarChart3, X } from 'lucide-vue-next';
import { ref, computed } from 'vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
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

const props = defineProps<{
    sport: string;
    recommendations: Recommendation[];
    dates: DateOption[];
    games: GameOption[];
    filters: {
        date: string | null;
        game: string | null;
    };
}>();

const selectedDate = ref(props.filters.date || '');
const selectedGame = ref(props.filters.game || '');

const sportName = computed(() => {
    return props.sport === 'CBB' ? 'College Basketball' : props.sport;
});

const filteredGames = computed(() => {
    if (!selectedDate.value) {
        return props.games;
    }
    return props.games.filter(game => game.date === selectedDate.value);
});

const onDateChange = () => {
    // Clear game selection when date changes
    selectedGame.value = '';
    // Apply filters to refresh games list
    applyFilters();
};

const applyFilters = () => {
    const params: Record<string, string> = {};
    if (selectedDate.value) params.date = selectedDate.value;
    if (selectedGame.value) params.game = selectedGame.value;

    router.get(window.location.pathname, params, {
        preserveState: true,
        preserveScroll: true,
    });
};

const clearFilters = () => {
    selectedDate.value = '';
    selectedGame.value = '';
    router.get(window.location.pathname, {}, {
        preserveState: true,
        preserveScroll: true,
    });
};

const getConfidenceColor = (confidence: number) => {
    if (confidence >= 80) return 'bg-green-500';
    if (confidence >= 70) return 'bg-emerald-500';
    if (confidence >= 60) return 'bg-yellow-500';
    return 'bg-gray-500';
};

const getConfidenceBadge = (confidence: number) => {
    if (confidence >= 80) return 'default';
    if (confidence >= 70) return 'secondary';
    return 'outline';
};

const formatOdds = (odds: number) => {
    return odds > 0 ? `+${odds}` : odds.toString();
};

const formatGameTime = (date: string, time: string) => {
    if (!date || !time) {
        return 'TBD';
    }

    try {
        // Remove seconds from time if present (18:00:00 -> 18:00)
        const timePart = time.split(':').slice(0, 2).join(':');
        const dateTimeString = `${date}T${timePart}:00`;
        const gameDate = new Date(dateTimeString);

        if (isNaN(gameDate.getTime())) {
            return 'TBD';
        }

        return gameDate.toLocaleString('en-US', {
            month: 'short',
            day: 'numeric',
            hour: 'numeric',
            minute: '2-digit',
        });
    } catch (error) {
        return 'TBD';
    }
};

const getInitials = (name: string) => {
    return name
        .split(' ')
        .map((n) => n[0])
        .join('')
        .toUpperCase();
};
</script>

<template>
    <AppLayout>
        <Head :title="`${sport} Player Props`" />

        <div class="container mx-auto space-y-8 py-8">
            <!-- Header -->
            <div class="space-y-2">
                <h1 class="text-3xl font-bold tracking-tight">{{ sportName }} Player Props</h1>
                <p class="text-muted-foreground">
                    Data-driven player prop bets based on statistical analysis and recent form
                </p>
            </div>

            <!-- Filters -->
            <Card>
                <CardContent class="pt-6">
                    <div class="flex flex-wrap items-end gap-4">
                        <div class="flex-1 min-w-[200px] space-y-2">
                            <Label for="date">Game Date</Label>
                            <select
                                id="date"
                                v-model="selectedDate"
                                @change="onDateChange"
                                class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm transition-colors file:border-0 file:bg-transparent file:text-sm file:font-medium placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                <option value="">All dates</option>
                                <option v-for="date in dates" :key="date.value" :value="date.value">
                                    {{ date.label }}
                                </option>
                            </select>
                        </div>
                        <div class="flex-1 min-w-[200px] space-y-2">
                            <Label for="game">Matchup</Label>
                            <select
                                id="game"
                                v-model="selectedGame"
                                class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm transition-colors file:border-0 file:bg-transparent file:text-sm file:font-medium placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50"
                                :disabled="filteredGames.length === 0"
                            >
                                <option value="">All games</option>
                                <option v-for="game in filteredGames" :key="game.id" :value="game.id.toString()">
                                    {{ game.label }}
                                </option>
                            </select>
                        </div>
                        <div class="flex gap-2">
                            <Button @click="applyFilters">Apply Filters</Button>
                            <Button v-if="filters.date || filters.game" @click="clearFilters" variant="outline">
                                <X class="h-4 w-4 mr-2" />
                                Clear
                            </Button>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <!-- No recommendations message -->
            <div v-if="recommendations.length === 0" class="text-center py-16">
                <BarChart3 class="mx-auto h-16 w-16 text-muted-foreground mb-4" />
                <h3 class="text-xl font-semibold mb-2">No Recommendations Available</h3>
                <p class="text-muted-foreground">
                    Check back later or sync player props to see betting recommendations.
                </p>
            </div>

            <!-- Recommendations Grid -->
            <div v-else class="grid gap-6 md:grid-cols-2 lg:grid-cols-3">
                <template v-for="rec in recommendations" :key="rec?.id || Math.random()">
                <Card v-if="rec && rec.player" class="hover:shadow-lg transition-shadow">
                    <CardHeader>
                        <div class="flex items-start justify-between gap-3">
                            <div class="flex items-start gap-3 flex-1 min-w-0">
                                <Link :href="rec.player?.url || '#'" class="flex-shrink-0">
                                    <Avatar class="h-12 w-12 border-2 border-border hover:border-primary transition-colors">
                                        <AvatarImage :src="rec.player?.headshot" :alt="rec.player?.name" class="object-cover" />
                                        <AvatarFallback>{{ getInitials(rec.player?.name || 'Unknown') }}</AvatarFallback>
                                    </Avatar>
                                </Link>
                                <div class="space-y-1 flex-1 min-w-0">
                                    <Link :href="rec.player?.url || '#'" class="hover:underline">
                                        <CardTitle class="text-lg truncate">{{ rec.player?.name }}</CardTitle>
                                    </Link>
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <CardDescription v-if="rec.player?.position && rec.player?.team" class="shrink-0">
                                            {{ rec.player.position }} • {{ rec.player.team }}
                                        </CardDescription>
                                        <Badge
                                            v-if="rec.streak && rec.streak.count >= 2"
                                            :variant="rec.streak.status === 'hot' ? 'default' : 'secondary'"
                                            class="text-xs shrink-0"
                                        >
                                            {{ rec.streak.status === 'hot' ? '🔥' : '❄️' }} {{ rec.streak.count }}
                                        </Badge>
                                    </div>
                                    <CardDescription class="text-xs truncate">
                                        {{ rec.game?.away_team }} @ {{ rec.game?.home_team }}
                                    </CardDescription>
                                </div>
                            </div>
                            <Badge :variant="getConfidenceBadge(rec.confidence)" class="flex-shrink-0 h-fit">
                                {{ rec.confidence }}%
                            </Badge>
                        </div>
                    </CardHeader>

                    <CardContent class="space-y-4">
                        <!-- Recommendation -->
                        <div class="flex items-center justify-between p-3 bg-muted rounded-lg">
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

                        <!-- Stats Comparison -->
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
                            <div v-if="rec.stats?.vs_opponent_avg" class="flex justify-between text-sm">
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
                                <span class="text-muted-foreground">
                                    Hit {{ rec.recommendation }} (L5)
                                </span>
                                <span class="font-medium">
                                    {{ rec.recommendation === 'Under'
                                        ? (rec.stats.times_covered_last5.games - rec.stats.times_covered_last5.hits)
                                        : rec.stats.times_covered_last5.hits
                                    }}/{{ rec.stats.times_covered_last5.games }}
                                </span>
                            </div>
                            <div v-if="rec.stats?.times_covered_season" class="flex justify-between text-sm">
                                <span class="text-muted-foreground">
                                    Hit {{ rec.recommendation }} (Season)
                                </span>
                                <span class="font-medium">
                                    {{ rec.recommendation === 'Under'
                                        ? (rec.stats.times_covered_season.games - rec.stats.times_covered_season.hits)
                                        : rec.stats.times_covered_season.hits
                                    }}/{{ rec.stats.times_covered_season.games }}
                                </span>
                            </div>
                            <div v-if="rec.stats?.consistency" class="flex justify-between text-sm">
                                <span class="text-muted-foreground">Consistency</span>
                                <span class="font-medium text-xs">
                                    {{ rec.stats.consistency.level }} (±{{ rec.stats.consistency.std_dev }})
                                </span>
                            </div>
                            <div class="flex justify-between text-sm border-t pt-2">
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
                        </div>

                        <!-- Confidence Bar -->
                        <div class="space-y-1">
                            <div class="flex justify-between text-xs text-muted-foreground">
                                <span>Confidence</span>
                                <span>{{ rec.confidence }}%</span>
                            </div>
                            <div class="h-2 bg-muted rounded-full overflow-hidden">
                                <div
                                    :class="getConfidenceColor(rec.confidence)"
                                    :style="{ width: `${rec.confidence}%` }"
                                    class="h-full transition-all"
                                />
                            </div>
                        </div>

                        <!-- Reasoning -->
                        <div class="space-y-1">
                            <p class="text-xs font-semibold text-muted-foreground">Analysis</p>
                            <ul class="text-xs space-y-1">
                                <li v-for="(reason, idx) in rec.reasoning" :key="idx" class="flex items-start gap-1">
                                    <Target class="h-3 w-3 mt-0.5 text-muted-foreground flex-shrink-0" />
                                    <span>{{ reason }}</span>
                                </li>
                            </ul>
                        </div>

                    </CardContent>
                </Card>
                </template>
            </div>
        </div>
    </AppLayout>
</template>
