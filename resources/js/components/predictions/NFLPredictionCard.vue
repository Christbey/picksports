<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Lock } from 'lucide-vue-next';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

defineProps<{
    prediction: any;
    href: string;
    formatDate: (date: string, time?: string) => string;
    formatSpread: (spread: number) => string;
    formatOdds: (odds: number) => string;
    getBetTypeLabel: (type: string) => string;
    getBetTypeColor: (type: string) => string;
}>();

const isLiveGame = (game: any) => game?.live_win_probability?.is_live === true;

const getWeekLabel = (game: any) => {
    if (!game?.week || !game?.season_type) return '';
    if (game.season_type === 'Regular Season') return `Week ${game.week}`;
    const playoffRounds: Record<number, string> = {
        1: 'Wild Card',
        2: 'Divisional',
        3: 'Conference Championship',
        5: 'Super Bowl',
    };
    return playoffRounds[game.week] || `Playoff Week ${game.week}`;
};

const formatTimeRemaining = (seconds: number) => {
    const minutes = Math.floor(seconds / 60);
    const remainingSeconds = seconds % 60;
    if (minutes >= 60) {
        const hours = Math.floor(minutes / 60);
        const remainingMinutes = minutes % 60;
        return `${hours}h ${remainingMinutes}m`;
    }
    return `${minutes}:${String(remainingSeconds).padStart(2, '0')}`;
};

const getQuarterLabel = (period: number | undefined) => {
    if (!period) return '';
    if (period <= 4) return `Q${period}`;
    return `OT${period - 4}`;
};
</script>

<template>
    <Link :href="href" class="block transition-opacity hover:opacity-75">
        <Card class="cursor-pointer transition-colors hover:border-sidebar-border">
            <CardHeader>
                <CardTitle class="flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <div class="flex items-center gap-2">
                            <img
                                v-if="prediction.game.away_team.logo"
                                :src="prediction.game.away_team.logo"
                                :alt="prediction.game.away_team.abbreviation"
                                class="h-8 w-8 object-contain"
                            />
                            <span class="font-semibold">{{ prediction.game.away_team.abbreviation }}</span>
                        </div>
                        <span class="text-muted-foreground">@</span>
                        <div class="flex items-center gap-2">
                            <img
                                v-if="prediction.game.home_team.logo"
                                :src="prediction.game.home_team.logo"
                                :alt="prediction.game.home_team.abbreviation"
                                class="h-8 w-8 object-contain"
                            />
                            <span class="font-semibold">{{ prediction.game.home_team.abbreviation }}</span>
                        </div>
                        <span v-if="prediction.game.week && prediction.game.season_type" class="ml-2 rounded-full border border-sidebar-border bg-sidebar px-2 py-0.5 text-xs text-sidebar-foreground">
                            {{ getWeekLabel(prediction.game) }}
                        </span>
                        <span v-if="prediction.win_probability !== undefined && prediction.win_probability > 0.65" class="rounded-full bg-green-100 px-2 py-0.5 text-xs text-green-700 dark:bg-green-900 dark:text-green-200">
                            Favorite
                        </span>
                    </div>
                    <span class="whitespace-nowrap text-sm font-normal text-muted-foreground">
                        {{ formatDate(prediction.game.game_date, prediction.game.game_time) }}
                    </span>
                </CardTitle>
            </CardHeader>
            <CardContent class="space-y-4">
                <div v-if="isLiveGame(prediction.game)" class="rounded-lg border border-red-200 bg-red-50 p-4 dark:border-red-800 dark:bg-red-950">
                    <div class="mb-3 flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <span class="relative flex h-3 w-3">
                                <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-red-400 opacity-75"></span>
                                <span class="relative inline-flex h-3 w-3 rounded-full bg-red-500"></span>
                            </span>
                            <span class="font-semibold text-red-700 dark:text-red-300">LIVE</span>
                            <span class="text-sm text-muted-foreground">
                                {{ getQuarterLabel(prediction.game.period) }} {{ prediction.game.clock }}
                            </span>
                        </div>
                        <div class="text-sm font-bold">
                            {{ prediction.game.away_score }} - {{ prediction.game.home_score }}
                        </div>
                    </div>
                    <div class="mb-2 text-sm font-medium">Live Win Probability</div>
                    <div class="relative h-8 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700">
                        <div
                            class="absolute left-0 top-0 h-full bg-gradient-to-r from-blue-500 to-blue-600 transition-all duration-500"
                            :style="{ width: `${prediction.game.live_win_probability.away_win_probability * 100}%` }"
                        ></div>
                        <div class="absolute inset-0 flex items-center justify-between px-3">
                            <span class="text-xs font-bold text-white drop-shadow-md">
                                {{ prediction.game.away_team.abbreviation }} {{ (prediction.game.live_win_probability.away_win_probability * 100).toFixed(1) }}%
                            </span>
                            <span class="text-xs font-bold text-gray-800 drop-shadow-md dark:text-white">
                                {{ prediction.game.home_team.abbreviation }} {{ (prediction.game.live_win_probability.home_win_probability * 100).toFixed(1) }}%
                            </span>
                        </div>
                    </div>
                    <div class="mt-2 flex justify-between text-xs text-muted-foreground">
                        <span>Margin: {{ prediction.game.live_win_probability.margin > 0 ? '+' : '' }}{{ prediction.game.live_win_probability.margin }}</span>
                        <span>Time left: {{ formatTimeRemaining(prediction.game.live_win_probability.seconds_remaining) }}</span>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
                    <div>
                        <div class="mb-1 text-xs text-muted-foreground">Spread</div>
                        <div v-if="prediction.predicted_spread !== undefined" class="text-lg font-semibold leading-7">{{ formatSpread(prediction.predicted_spread) }}</div>
                        <div v-else class="leading-7 text-muted-foreground"><Lock class="h-4 w-4" /></div>
                    </div>
                    <div>
                        <div class="mb-1 text-xs text-muted-foreground">{{ isLiveGame(prediction.game) ? 'Pre-Game Prob' : 'Win Prob' }}</div>
                        <div v-if="prediction.win_probability !== undefined" class="flex items-center gap-2">
                            <div class="h-2 w-20 rounded-full bg-gray-200 dark:bg-gray-700">
                                <div
                                    class="h-full rounded-full transition-all"
                                    :class="{
                                        'bg-green-500': prediction.win_probability > 0.6,
                                        'bg-yellow-500': prediction.win_probability >= 0.4 && prediction.win_probability <= 0.6,
                                        'bg-red-500': prediction.win_probability < 0.4,
                                    }"
                                    :style="{ width: `${prediction.win_probability * 100}%` }"
                                ></div>
                            </div>
                            <span class="whitespace-nowrap text-sm font-semibold">{{ (prediction.win_probability * 100).toFixed(1) }}%</span>
                        </div>
                        <div v-else class="leading-7 text-muted-foreground"><Lock class="h-4 w-4" /></div>
                    </div>
                    <div>
                        <div class="mb-1 text-xs text-muted-foreground">Confidence</div>
                        <div v-if="prediction.confidence_score !== undefined" class="flex items-center gap-2">
                            <div class="h-2 w-20 rounded-full bg-gray-200 dark:bg-gray-700">
                                <div class="h-full rounded-full bg-blue-500 transition-all" :style="{ width: `${prediction.confidence_score * 100}%` }"></div>
                            </div>
                            <span class="whitespace-nowrap text-sm font-semibold">{{ (prediction.confidence_score * 100).toFixed(1) }}%</span>
                        </div>
                        <div v-else class="leading-7 text-muted-foreground"><Lock class="h-4 w-4" /></div>
                    </div>
                    <div>
                        <div class="mb-1 text-xs text-muted-foreground">Elo Diff</div>
                        <div v-if="prediction.home_elo !== undefined && prediction.away_elo !== undefined" class="text-lg font-semibold leading-7" :class="{
                            'text-green-600 dark:text-green-400': (prediction.home_elo - prediction.away_elo) > 50,
                            'text-red-600 dark:text-red-400': (prediction.home_elo - prediction.away_elo) < -50,
                        }">
                            {{ (prediction.home_elo - prediction.away_elo).toFixed(1) }}
                        </div>
                        <div v-else class="leading-7 text-muted-foreground"><Lock class="h-4 w-4" /></div>
                    </div>
                </div>

                <div v-if="prediction.away_elo !== undefined || prediction.home_elo !== undefined" class="border-t pt-4">
                    <div class="mb-2 text-sm font-medium">Team Metrics</div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <div class="mb-1 text-xs text-muted-foreground">Away Elo</div>
                            <div v-if="prediction.away_elo !== undefined" class="text-sm font-semibold">{{ prediction.away_elo }}</div>
                            <div v-else class="flex h-5 items-center justify-start text-muted-foreground"><Lock class="h-4 w-4" /></div>
                        </div>
                        <div>
                            <div class="mb-1 text-xs text-muted-foreground">Home Elo</div>
                            <div v-if="prediction.home_elo !== undefined" class="text-sm font-semibold">{{ prediction.home_elo }}</div>
                            <div v-else class="flex h-5 items-center justify-start text-muted-foreground"><Lock class="h-4 w-4" /></div>
                        </div>
                    </div>
                </div>

                <div v-if="prediction.betting_value && prediction.betting_value.length > 0" class="border-t pt-4">
                    <div class="mb-3 flex items-center gap-2">
                        <div class="text-sm font-medium">Betting Value Detected</div>
                        <div class="text-xs text-muted-foreground">(DraftKings)</div>
                        <span v-if="prediction.betting_value.some((bet: any) => Math.abs(bet.edge) > 3)" class="rounded-full bg-yellow-100 px-2 py-0.5 text-xs text-yellow-700 dark:bg-yellow-900 dark:text-yellow-200">
                            High Value
                        </span>
                    </div>
                    <div class="space-y-3">
                        <div
                            v-for="(bet, idx) in prediction.betting_value"
                            :key="idx"
                            class="rounded-md border border-sidebar-border/70 bg-sidebar/50 p-3"
                        >
                            <div class="space-y-2">
                                <div class="flex items-center gap-2">
                                    <span class="rounded px-2 py-0.5 text-xs font-medium" :class="getBetTypeColor(bet.type)">
                                        {{ getBetTypeLabel(bet.type) }}
                                    </span>
                                    <span class="text-sm font-semibold">{{ bet.recommendation }}</span>
                                </div>
                                <div class="text-xs text-muted-foreground">{{ bet.reasoning }}</div>
                                <div class="flex flex-wrap gap-4 text-xs">
                                    <div v-if="bet.model_line !== undefined">
                                        <span class="text-muted-foreground">Model:</span>
                                        <span class="ml-1 font-medium">{{ formatSpread(bet.model_line) }}</span>
                                    </div>
                                    <div v-if="bet.market_line !== undefined">
                                        <span class="text-muted-foreground">Market:</span>
                                        <span class="ml-1 font-medium">{{ formatSpread(bet.market_line) }}</span>
                                    </div>
                                    <div v-if="bet.model_probability !== undefined">
                                        <span class="text-muted-foreground">Model:</span>
                                        <span class="ml-1 font-medium">{{ bet.model_probability }}%</span>
                                    </div>
                                    <div v-if="bet.implied_probability !== undefined">
                                        <span class="text-muted-foreground">Implied:</span>
                                        <span class="ml-1 font-medium">{{ bet.implied_probability }}%</span>
                                    </div>
                                    <div>
                                        <span class="text-muted-foreground">Edge:</span>
                                        <span class="ml-1 font-semibold text-green-600">{{ bet.type === 'moneyline' ? `${bet.edge}%` : `${bet.edge} pts` }}</span>
                                    </div>
                                    <div>
                                        <span class="text-muted-foreground">Odds:</span>
                                        <span class="ml-1 font-medium">{{ formatOdds(bet.odds) }}</span>
                                    </div>
                                    <div v-if="bet.kelly_bet_size_percent !== undefined">
                                        <span class="text-muted-foreground">Kelly:</span>
                                        <span class="ml-1 font-medium">{{ bet.kelly_bet_size_percent }}%</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="flex items-center gap-2">
                    <span
                        v-if="prediction.game.status === 'STATUS_FINAL'"
                        class="inline-flex items-center gap-1.5 rounded-full bg-green-100 px-2.5 py-1 text-xs font-medium text-green-700 dark:bg-green-900 dark:text-green-200"
                    >
                        <span class="h-1.5 w-1.5 rounded-full bg-green-600"></span>
                        Final
                        <span v-if="prediction.game.home_score !== undefined" class="ml-1 font-semibold">
                            {{ prediction.game.away_score }} - {{ prediction.game.home_score }}
                        </span>
                    </span>
                    <span
                        v-else-if="['STATUS_IN_PROGRESS', 'STATUS_HALFTIME', 'STATUS_END_PERIOD'].includes(prediction.game.status)"
                        class="inline-flex items-center gap-1.5 rounded-full bg-red-100 px-2.5 py-1 text-xs font-medium text-red-700 dark:bg-red-900 dark:text-red-200"
                    >
                        <span class="relative flex h-1.5 w-1.5">
                            <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-red-400 opacity-75"></span>
                            <span class="relative inline-flex h-1.5 w-1.5 rounded-full bg-red-600"></span>
                        </span>
                        LIVE
                    </span>
                    <span
                        v-else
                        class="inline-flex items-center gap-1.5 rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-700 dark:bg-gray-800 dark:text-gray-300"
                    >
                        <span class="h-1.5 w-1.5 rounded-full bg-gray-400"></span>
                        Scheduled
                    </span>
                </div>
            </CardContent>
        </Card>
    </Link>
</template>
