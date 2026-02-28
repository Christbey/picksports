<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Lock } from 'lucide-vue-next';
import { ref, watch, onMounted, computed } from 'vue';
import NFLPredictionCard from '@/components/predictions/NFLPredictionCard.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import { formatDateShort, formatSpread } from '@/composables/useFormatters';
import { usePredictionList } from '@/composables/usePredictionList';
import type { PredictionListItem, SportPredictionsConfig } from '@/types';

const props = defineProps<{
    config: SportPredictionsConfig;
}>();

const filterMode = computed(() => props.config.filterMode ?? 'date');
const cardVariant = computed(() => props.config.cardVariant ?? 'default');
const availableDates = ref<string[]>([]);
const selectedDate = ref('');
const today = ref('');
const seasonType = ref('');
const week = ref('');

const weekOptions = computed(() => {
    if (!props.config.seasonWeekConfig || !seasonType.value) return [];

    if (seasonType.value === 'Regular Season') {
        return Array.from({ length: props.config.seasonWeekConfig.regularSeasonWeeks }, (_, i) => ({
            value: String(i + 1),
            label: `Week ${i + 1}`,
        }));
    }

    return props.config.seasonWeekConfig.postseasonOptions;
});

const buildParams = (page: number): URLSearchParams => {
    const params = new URLSearchParams({ page: String(page) });

    if (filterMode.value === 'date' && selectedDate.value) {
        params.append('from_date', selectedDate.value);
        params.append('to_date', selectedDate.value);
    }

    if (filterMode.value === 'seasonWeek') {
        if (seasonType.value) params.append('season_type', seasonType.value);
        if (week.value) params.append('week', week.value);
    }

    return params;
};

const {
    items: predictions,
    meta,
    loading,
    error,
    fetchPage: fetchPredictions,
} = usePredictionList<PredictionListItem>(async (page) => {
    if (filterMode.value === 'date' && !selectedDate.value) {
        return { data: [], meta: null };
    }

    const response = await fetch(`/api/v1/${props.config.sport}/predictions?${buildParams(page)}`);
    if (!response.ok) throw new Error('Failed to fetch predictions');
    return response.json();
});

const formatDateLabel = (dateStr: string) => {
    const [y, m, d] = dateStr.split('-').map(Number);
    const date = new Date(y, m - 1, d);
    const label = date.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' });
    return dateStr === today.value ? `${label} (Today)` : label;
};

const fetchAvailableDates = async () => {
    const response = await fetch(`/api/v1/${props.config.sport}/predictions/available-dates`);
    if (!response.ok) throw new Error('Failed to fetch available dates');
    const data = await response.json();
    availableDates.value = data.data;

    const now = new Date();
    if (props.config.useEasternTime) {
        const etDate = new Date(now.toLocaleString('en-US', { timeZone: 'America/New_York' }));
        today.value = `${etDate.getFullYear()}-${String(etDate.getMonth() + 1).padStart(2, '0')}-${String(etDate.getDate()).padStart(2, '0')}`;
    } else {
        today.value = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
    }

    if (availableDates.value.includes(today.value)) {
        selectedDate.value = today.value;
    } else if (availableDates.value.length > 0) {
        const futureDates = availableDates.value.filter((d) => d > today.value);
        const pastDates = availableDates.value.filter((d) => d < today.value);
        selectedDate.value = futureDates.length > 0 ? futureDates[0] : pastDates[pastDates.length - 1];
    }
};

watch(selectedDate, () => {
    if (filterMode.value === 'date') fetchPredictions(1);
});

watch(seasonType, () => {
    week.value = '';
});

const applyFilters = () => {
    fetchPredictions(1);
};

const clearFilters = () => {
    seasonType.value = '';
    week.value = '';
    fetchPredictions(1);
};

const formatDate = (dateString: string, timeString?: string) =>
    formatDateShort(dateString, timeString, props.config.showGameTime);

const formatConfidence = (score: number) => {
    if (props.config.confidenceIsDecimal) {
        return `${(score * 100).toFixed(props.config.confidenceDecimals)}%`;
    }
    return `${score}%`;
};

const confidenceBarWidth = (score: number) => {
    if (props.config.confidenceIsDecimal) {
        return `${score * 100}%`;
    }
    return `${score}%`;
};

const formatOdds = (odds: number) => (odds > 0 ? `+${odds}` : odds.toString());

const getBetTypeLabel = (type: string) => {
    const labels: Record<string, string> = {
        spread: 'Spread',
        total: 'Total',
        moneyline: 'Moneyline',
    };
    return labels[type] || type;
};

const getBetTypeColor = (type: string) => {
    const colors: Record<string, string> = {
        spread: 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200',
        total: 'bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-200',
        moneyline: 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200',
    };
    return colors[type] || 'bg-gray-100 text-gray-800';
};

const teamDescription = (team: PredictionListItem['game']['home_team']) => {
    if (team.school && team.mascot) {
        return `${team.school} ${team.mascot}`;
    }
    if (team.location && team.name) {
        return `${team.location} ${team.name}`;
    }
    return team.abbreviation;
};

const gameHref = (prediction: PredictionListItem): string => {
    const gameId = prediction.game?.id ?? prediction.game_id;
    return `/${props.config.sport}/games/${gameId}`;
};

onMounted(async () => {
    try {
        if (filterMode.value === 'date') {
            await fetchAvailableDates();
            if (!selectedDate.value) {
                await fetchPredictions(1);
            }
            return;
        }

        await fetchPredictions(1);
    } catch (e) {
        error.value = e instanceof Error ? e.message : 'An error occurred';
    }
});
</script>

<template>
  <div class="space-y-4">
    <div class="flex items-center justify-between">
      <div>
        <h2 class="text-2xl font-bold">{{ config.title }}</h2>
        <p class="text-sm text-muted-foreground">
          {{ config.subtitle }}
        </p>
      </div>
    </div>

    <Card v-if="filterMode !== 'none'">
      <CardContent class="pt-6">
        <div class="flex flex-wrap items-end gap-4">
          <div v-if="filterMode === 'date'" class="flex-1 min-w-[200px]">
            <Label for="game-date">Game Date</Label>
            <select
              id="game-date"
              v-model="selectedDate"
              :disabled="availableDates.length === 0"
              class="mt-1 flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-base ring-offset-background focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
            >
              <option v-if="availableDates.length === 0" value="">Loading dates...</option>
              <option v-for="date in availableDates" :key="date" :value="date">
                {{ formatDateLabel(date) }}
              </option>
            </select>
          </div>
          <template v-else-if="filterMode === 'seasonWeek'">
            <div class="flex-1 min-w-[200px]">
              <Label for="season-type">Season Type</Label>
              <select
                id="season-type"
                v-model="seasonType"
                class="mt-1 flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-base ring-offset-background focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
              >
                <option value="">All Season Types</option>
                <option value="Regular Season">Regular Season</option>
                <option value="Postseason">Postseason</option>
              </select>
            </div>
            <div class="flex-1 min-w-[200px]">
              <Label for="week">Week</Label>
              <select
                id="week"
                v-model="week"
                :disabled="!seasonType"
                class="mt-1 flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-base ring-offset-background focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
              >
                <option value="">All Weeks</option>
                <option
                  v-for="option in weekOptions"
                  :key="option.value"
                  :value="option.value"
                >
                  {{ option.label }}
                </option>
              </select>
            </div>
            <div class="flex gap-2">
              <Button @click="applyFilters" :disabled="loading">
                Apply Filters
              </Button>
              <Button @click="clearFilters" variant="outline" :disabled="loading">
                Clear
              </Button>
            </div>
          </template>
        </div>
      </CardContent>
    </Card>

    <Alert v-if="error" variant="destructive">
      <AlertDescription>{{ error }}</AlertDescription>
    </Alert>

    <div v-if="loading" class="grid gap-4">
      <Card v-for="i in 3" :key="i">
        <CardHeader>
          <Skeleton class="h-4 w-48" />
          <Skeleton class="h-3 w-32" />
        </CardHeader>
        <CardContent>
          <Skeleton class="h-20 w-full" />
        </CardContent>
      </Card>
    </div>

    <div v-else class="grid gap-4">
      <template v-for="prediction in predictions" :key="prediction.id">
        <NFLPredictionCard
          v-if="cardVariant === 'nfl'"
          :prediction="prediction"
          :href="gameHref(prediction)"
          :format-date="formatDate"
          :format-spread="formatSpread"
          :format-odds="formatOdds"
          :get-bet-type-label="getBetTypeLabel"
          :get-bet-type-color="getBetTypeColor"
        />
      <Link v-else :href="gameHref(prediction)" class="block">
        <Card class="cursor-pointer transition-colors hover:bg-muted/50">
          <CardHeader>
            <CardTitle class="flex items-center justify-between">
              <div class="flex items-center gap-3">
                <div class="flex items-center gap-2">
                  <img
                    v-if="prediction.game.away_team.logo"
                    :src="prediction.game.away_team.logo"
                    :alt="prediction.game.away_team.abbreviation"
                    class="w-8 h-8 object-contain"
                  />
                  <span class="font-semibold">{{ prediction.game.away_team.abbreviation }}</span>
                </div>
                <span class="text-muted-foreground">@</span>
                <div class="flex items-center gap-2">
                  <img
                    v-if="prediction.game.home_team.logo"
                    :src="prediction.game.home_team.logo"
                    :alt="prediction.game.home_team.abbreviation"
                    class="w-8 h-8 object-contain"
                  />
                  <span class="font-semibold">{{ prediction.game.home_team.abbreviation }}</span>
                </div>
                <span v-if="prediction.win_probability !== undefined && prediction.win_probability > 0.65" class="ml-2 text-xs px-2 py-0.5 rounded-full bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-200">
                  Favorite
                </span>
              </div>
              <span class="text-sm font-normal text-muted-foreground whitespace-nowrap">
                {{ formatDate(prediction.game.game_date, prediction.game.game_time) }}
              </span>
            </CardTitle>
            <CardDescription v-if="prediction.game.away_team.school || prediction.game.away_team.location">
              {{ teamDescription(prediction.game.away_team) }} at
              {{ teamDescription(prediction.game.home_team) }}
              <span v-if="prediction.game.week && prediction.game.season_type" class="ml-2 text-xs">
                - {{ prediction.game.season_type === 'Regular Season' ? `Week ${prediction.game.week}` : 'Postseason' }}
              </span>
            </CardDescription>
          </CardHeader>
          <CardContent class="space-y-4">
            <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
              <div>
                <div class="text-xs text-muted-foreground mb-1">Spread</div>
                <div v-if="prediction.predicted_spread !== undefined" class="text-lg font-semibold leading-7">{{ formatSpread(prediction.predicted_spread) }}</div>
                <div v-else class="text-muted-foreground leading-7">
                  <Lock class="h-4 w-4" />
                </div>
              </div>
              <div>
                <div class="text-xs text-muted-foreground mb-1">Total</div>
                <div v-if="prediction.predicted_total !== undefined" class="text-lg font-semibold leading-7">{{ prediction.predicted_total }}</div>
                <div v-else class="text-muted-foreground leading-7">
                  <Lock class="h-4 w-4" />
                </div>
              </div>
              <div>
                <div class="text-xs text-muted-foreground mb-1">Win Prob</div>
                <div v-if="prediction.win_probability !== undefined" class="flex items-center gap-2">
                  <div class="h-2 w-20 rounded-full bg-gray-200 dark:bg-gray-700">
                    <div
                      class="h-full rounded-full transition-all"
                      :class="{
                        'bg-green-500': prediction.win_probability > 0.6,
                        'bg-yellow-500': prediction.win_probability >= 0.4 && prediction.win_probability <= 0.6,
                        'bg-red-500': prediction.win_probability < 0.4
                      }"
                      :style="{ width: `${prediction.win_probability * 100}%` }"
                    ></div>
                  </div>
                  <span class="text-sm font-semibold whitespace-nowrap">{{ (prediction.win_probability * 100).toFixed(1) }}%</span>
                </div>
                <div v-else class="text-muted-foreground leading-7">
                  <Lock class="h-4 w-4" />
                </div>
              </div>
              <div>
                <div class="text-xs text-muted-foreground mb-1">Confidence</div>
                <div v-if="prediction.confidence_score !== undefined" class="flex items-center gap-2">
                  <div class="h-2 w-20 rounded-full bg-gray-200 dark:bg-gray-700">
                    <div
                      class="h-full rounded-full bg-blue-500 transition-all"
                      :style="{ width: confidenceBarWidth(prediction.confidence_score) }"
                    ></div>
                  </div>
                  <span class="text-sm font-semibold whitespace-nowrap">{{ formatConfidence(prediction.confidence_score) }}</span>
                </div>
                <div v-else class="text-muted-foreground leading-7">
                  <Lock class="h-4 w-4" />
                </div>
              </div>
            </div>

            <!-- Betting Recommendations -->
            <div v-if="prediction.betting_value && prediction.betting_value.length > 0" class="border-t pt-4">
              <div class="mb-2 flex items-center gap-2">
                <div class="text-sm font-medium">Betting Value Detected</div>
                <div class="text-xs text-muted-foreground">(DraftKings)</div>
              </div>
              <div class="space-y-3">
                <div v-for="bet in prediction.betting_value" :key="`${prediction.id}-${bet.type}`" class="rounded-md border p-3">
                  <div class="flex items-start justify-between gap-2">
                    <div class="flex-1 space-y-1">
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
            </div>

            <!-- NBA-style Team Metrics (Elo + Efficiency) -->
            <div v-if="prediction.away_elo !== undefined || prediction.home_elo !== undefined" class="border-t pt-4">
              <div class="mb-2 text-sm font-medium">Team Metrics</div>
              <div class="grid grid-cols-2 gap-4">
                <div>
                  <div class="text-xs text-muted-foreground mb-1">Away Elo</div>
                  <div v-if="prediction.away_elo !== undefined" class="text-sm font-semibold leading-5">{{ prediction.away_elo }}</div>
                  <div v-else class="text-muted-foreground leading-5">
                    <Lock class="h-4 w-4" />
                  </div>
                </div>
                <div>
                  <div class="text-xs text-muted-foreground mb-1">Home Elo</div>
                  <div v-if="prediction.home_elo !== undefined" class="text-sm font-semibold leading-5">{{ prediction.home_elo }}</div>
                  <div v-else class="text-muted-foreground leading-5">
                    <Lock class="h-4 w-4" />
                  </div>
                </div>
                <div v-if="prediction.away_off_eff !== undefined">
                  <div class="text-xs text-muted-foreground mb-1">Away Off Eff</div>
                  <div class="text-sm font-semibold leading-5">{{ prediction.away_off_eff.toFixed(1) }}</div>
                </div>
                <div v-if="prediction.home_off_eff !== undefined">
                  <div class="text-xs text-muted-foreground mb-1">Home Off Eff</div>
                  <div class="text-sm font-semibold leading-5">{{ prediction.home_off_eff.toFixed(1) }}</div>
                </div>
              </div>
            </div>

            <!-- MLB-style Team & Pitcher Metrics -->
            <div v-if="prediction.away_team_elo !== undefined || prediction.home_team_elo !== undefined" class="border-t pt-4">
              <div class="mb-2 text-sm font-medium">Team & Pitcher Metrics</div>
              <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
                <div>
                  <div class="text-xs text-muted-foreground">Away Team Elo</div>
                  <div v-if="prediction.away_team_elo !== undefined" class="text-sm font-semibold">{{ prediction.away_team_elo }}</div>
                  <div v-else class="text-muted-foreground"><Lock class="h-4 w-4" /></div>
                </div>
                <div>
                  <div class="text-xs text-muted-foreground">Home Team Elo</div>
                  <div v-if="prediction.home_team_elo !== undefined" class="text-sm font-semibold">{{ prediction.home_team_elo }}</div>
                  <div v-else class="text-muted-foreground"><Lock class="h-4 w-4" /></div>
                </div>
                <div>
                  <div class="text-xs text-muted-foreground">Away Pitcher Elo</div>
                  <div v-if="prediction.away_pitcher_elo !== undefined" class="text-sm font-semibold">{{ prediction.away_pitcher_elo }}</div>
                  <div v-else class="text-muted-foreground"><Lock class="h-4 w-4" /></div>
                </div>
                <div>
                  <div class="text-xs text-muted-foreground">Home Pitcher Elo</div>
                  <div v-if="prediction.home_pitcher_elo !== undefined" class="text-sm font-semibold">{{ prediction.home_pitcher_elo }}</div>
                  <div v-else class="text-muted-foreground"><Lock class="h-4 w-4" /></div>
                </div>
              </div>
            </div>

            <!-- Graded Results -->
            <div v-if="prediction.graded_at" class="border-t pt-4">
              <div class="mb-2 text-sm font-medium">Actual Results</div>
              <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
                <div>
                  <div class="text-xs text-muted-foreground">Actual Spread</div>
                  <div class="text-sm font-semibold">{{ formatSpread(prediction.actual_spread!) }}</div>
                </div>
                <div>
                  <div class="text-xs text-muted-foreground">Actual Total</div>
                  <div class="text-sm font-semibold">{{ prediction.actual_total }}</div>
                </div>
                <div>
                  <div class="text-xs text-muted-foreground">Spread Error</div>
                  <div class="text-sm" :class="prediction.spread_error! < 10 ? 'text-green-600' : 'text-orange-600'">
                    {{ prediction.spread_error }} pts
                  </div>
                </div>
                <div>
                  <div class="text-xs text-muted-foreground">Winner</div>
                  <div class="text-sm font-semibold" :class="prediction.winner_correct ? 'text-green-600' : 'text-red-600'">
                    {{ prediction.winner_correct ? 'Correct' : 'Wrong' }}
                  </div>
                </div>
              </div>
              <div class="mt-2 text-xs text-muted-foreground">
                Score: {{ prediction.game.away_score }} - {{ prediction.game.home_score }}
              </div>
            </div>

            <!-- Game Status (ungraded games) -->
            <div v-else class="flex items-center gap-2">
              <!-- Live -->
              <span
                v-if="['STATUS_IN_PROGRESS', 'STATUS_HALFTIME', 'STATUS_END_PERIOD'].includes(prediction.game.status)"
                class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium bg-red-100 text-red-700 dark:bg-red-900 dark:text-red-200"
              >
                <span class="relative flex h-1.5 w-1.5">
                  <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-red-400 opacity-75"></span>
                  <span class="relative inline-flex h-1.5 w-1.5 rounded-full bg-red-600"></span>
                </span>
                LIVE
              </span>
              <!-- Final with score -->
              <span
                v-else-if="prediction.game.status === 'STATUS_FINAL' && prediction.game.home_score !== undefined"
                class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300"
              >
                <span class="h-1.5 w-1.5 rounded-full bg-gray-400"></span>
                Final: {{ prediction.game.away_score }} - {{ prediction.game.home_score }}
              </span>
              <!-- Scheduled / other -->
              <span
                v-else
                class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300"
              >
                <span class="h-1.5 w-1.5 rounded-full bg-gray-400"></span>
                Scheduled
              </span>
            </div>
          </CardContent>
        </Card>
      </Link>
      </template>
    </div>

    <!-- Pagination -->
    <div v-if="meta && meta.last_page > 1" class="flex items-center justify-center gap-2">
      <button
        @click="fetchPredictions(meta.current_page - 1)"
        :disabled="meta.current_page === 1"
        class="rounded border px-3 py-1 text-sm disabled:opacity-50"
      >
        Previous
      </button>
      <span class="text-sm text-muted-foreground">
        Page {{ meta.current_page }} of {{ meta.last_page }}
      </span>
      <button
        @click="fetchPredictions(meta.current_page + 1)"
        :disabled="meta.current_page === meta.last_page"
        class="rounded border px-3 py-1 text-sm disabled:opacity-50"
      >
        Next
      </button>
    </div>
  </div>
</template>
