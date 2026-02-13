<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { Link } from '@inertiajs/vue3'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Input } from '@/components/ui/input'
import { Button } from '@/components/ui/button'
import { Label } from '@/components/ui/label'
import { Lock } from 'lucide-vue-next'

interface BettingRecommendation {
  type: 'spread' | 'total' | 'moneyline'
  recommendation: string
  bet_team?: string
  model_line?: number
  market_line?: number
  model_probability?: number
  implied_probability?: number
  edge: number
  odds: number
  kelly_bet_size_percent?: number
  confidence: number
  reasoning: string
}

interface Prediction {
  id: number
  predicted_spread?: number
  predicted_total?: number
  win_probability?: number
  confidence_score?: number
  home_elo?: number
  away_elo?: number
  home_off_eff?: number
  home_def_eff?: number
  away_off_eff?: number
  away_def_eff?: number
  betting_value?: BettingRecommendation[]
  game: {
    id: number
    game_date: string
    game_time: string
    status: string
    home_score?: number
    away_score?: number
    home_team: {
      abbreviation: string
      location?: string
      name?: string
      logo?: string
      color?: string
    }
    away_team: {
      abbreviation: string
      location?: string
      name?: string
      logo?: string
      color?: string
    }
  }
}

interface PaginationMeta {
  current_page: number
  last_page: number
  per_page: number
  total: number
}

const predictions = ref<Prediction[]>([])
const meta = ref<PaginationMeta | null>(null)
const loading = ref(true)
const error = ref<string | null>(null)

// Default to today's date in local timezone
const now = new Date()
const year = now.getFullYear()
const month = String(now.getMonth() + 1).padStart(2, '0')
const day = String(now.getDate()).padStart(2, '0')
const today = `${year}-${month}-${day}`

const fromDate = ref(today)
const toDate = ref(today)

const fetchPredictions = async (page = 1) => {
  try {
    loading.value = true
    error.value = null

    const params = new URLSearchParams({ page: page.toString() })
    if (fromDate.value) params.append('from_date', fromDate.value)
    if (toDate.value) params.append('to_date', toDate.value)

    const response = await fetch(`/api/v1/nba/predictions?${params}`)
    if (!response.ok) throw new Error('Failed to fetch predictions')

    const data = await response.json()
    predictions.value = data.data
    meta.value = data.meta
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'An error occurred'
  } finally {
    loading.value = false
  }
}

const applyFilters = () => {
  fetchPredictions(1)
}

const clearFilters = () => {
  fromDate.value = ''
  toDate.value = ''
  fetchPredictions(1)
}

const formatSpread = (spread: number) => {
  return spread > 0 ? `+${spread}` : spread.toString()
}

const formatDate = (dateString: string) => {
  const date = new Date(dateString)
  return new Intl.DateTimeFormat('en-US', {
    month: 'short',
    day: 'numeric'
  }).format(date)
}

const formatOdds = (odds: number) => {
  return odds > 0 ? `+${odds}` : odds.toString()
}

const getBetTypeLabel = (type: string) => {
  const labels: Record<string, string> = {
    'spread': 'Spread',
    'total': 'Total',
    'moneyline': 'Moneyline'
  }
  return labels[type] || type
}

const getBetTypeColor = (type: string) => {
  const colors: Record<string, string> = {
    'spread': 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200',
    'total': 'bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-200',
    'moneyline': 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200'
  }
  return colors[type] || 'bg-gray-100 text-gray-800'
}

onMounted(() => {
  fetchPredictions()
})
</script>

<template>
  <div class="space-y-4">
    <div class="flex items-center justify-between">
      <div>
        <h2 class="text-2xl font-bold">NBA Predictions</h2>
        <p class="text-sm text-muted-foreground">
          Predictions based on Elo ratings and advanced metrics
        </p>
      </div>
    </div>

    <!-- Date Filters -->
    <Card>
      <CardContent class="pt-6">
        <div class="flex flex-wrap items-end gap-4">
          <div class="flex-1 min-w-[200px]">
            <Label for="from-date">From Date</Label>
            <Input
              id="from-date"
              type="date"
              v-model="fromDate"
              class="mt-1"
            />
          </div>
          <div class="flex-1 min-w-[200px]">
            <Label for="to-date">To Date</Label>
            <Input
              id="to-date"
              type="date"
              v-model="toDate"
              class="mt-1"
            />
          </div>
          <div class="flex gap-2">
            <Button @click="applyFilters" :disabled="loading">
              Apply Filters
            </Button>
            <Button @click="clearFilters" variant="outline" :disabled="loading">
              Clear
            </Button>
          </div>
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
      <Link v-for="prediction in predictions" :key="prediction.id" :href="`/nba/games/${prediction.game.id}`" class="block">
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
              {{ formatDate(prediction.game.game_date) }}
            </span>
          </CardTitle>
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
                    :style="{ width: `${prediction.confidence_score * 100}%` }"
                  ></div>
                </div>
                <span class="text-sm font-semibold whitespace-nowrap">{{ (prediction.confidence_score * 100).toFixed(1) }}%</span>
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

          <!-- Team Metrics -->
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

          <!-- Game Status -->
          <div class="flex items-center gap-2">
            <!-- Final Status -->
            <span
              v-if="prediction.game.status === 'STATUS_FINAL'"
              class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-200"
            >
              <span class="h-1.5 w-1.5 rounded-full bg-green-600"></span>
              Final
              <span v-if="prediction.game.home_score !== undefined" class="ml-1 font-semibold">
                {{ prediction.game.away_score }} - {{ prediction.game.home_score }}
              </span>
            </span>

            <!-- Live Status -->
            <span
              v-else-if="['STATUS_IN_PROGRESS', 'STATUS_HALFTIME', 'STATUS_END_PERIOD'].includes(prediction.game.status)"
              class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium bg-red-100 text-red-700 dark:bg-red-900 dark:text-red-200"
            >
              <span class="relative flex h-1.5 w-1.5">
                <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-red-400 opacity-75"></span>
                <span class="relative inline-flex h-1.5 w-1.5 rounded-full bg-red-600"></span>
              </span>
              LIVE
            </span>

            <!-- Scheduled Status -->
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
