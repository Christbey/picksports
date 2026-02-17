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

interface Prediction {
  id: number
  predicted_spread?: number
  predicted_total?: number
  win_probability?: number
  confidence_score?: number
  home_team_elo?: number
  away_team_elo?: number
  home_pitcher_elo?: number
  away_pitcher_elo?: number
  home_combined_elo?: number
  away_combined_elo?: number
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
    }
    away_team: {
      abbreviation: string
      location?: string
      name?: string
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

// Default to today's date
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

    const response = await fetch(`/api/v1/mlb/predictions?${params}`)
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

const formatDate = (dateString: string, timeString: string) => {
  const dateTime = new Date(`${dateString}T${timeString}`)
  return new Intl.DateTimeFormat('en-US', {
    month: 'short',
    day: 'numeric',
    hour: 'numeric',
    minute: '2-digit'
  }).format(dateTime)
}

onMounted(() => {
  fetchPredictions()
})
</script>

<template>
  <div class="space-y-4">
    <div class="flex items-center justify-between">
      <div>
        <h2 class="text-2xl font-bold">MLB Predictions</h2>
        <p class="text-sm text-muted-foreground">
          Predictions based on team and pitcher Elo ratings
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
      <Link v-for="prediction in predictions" :key="prediction.id" :href="`/mlb/games/${prediction.game.id}`" class="block">
        <Card class="cursor-pointer transition-colors hover:bg-muted/50">
        <CardHeader>
          <CardTitle class="flex items-center justify-between">
            <span>
              {{ prediction.game.away_team.abbreviation }} @ {{ prediction.game.home_team.abbreviation }}
            </span>
            <span class="text-sm font-normal text-muted-foreground">
              {{ formatDate(prediction.game.game_date, prediction.game.game_time) }}
            </span>
          </CardTitle>
          <CardDescription>
            {{ prediction.game.away_team.location || prediction.game.away_team.abbreviation }} {{ prediction.game.away_team.name || '' }} at
            {{ prediction.game.home_team.location || prediction.game.home_team.abbreviation }} {{ prediction.game.home_team.name || '' }}
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
              <div v-if="prediction.win_probability !== undefined" class="text-lg font-semibold leading-7">{{ (prediction.win_probability * 100).toFixed(1) }}%</div>
              <div v-else class="text-muted-foreground leading-7">
                <Lock class="h-4 w-4" />
              </div>
            </div>
            <div>
              <div class="text-xs text-muted-foreground mb-1">Confidence</div>
              <div v-if="prediction.confidence_score !== undefined" class="text-lg font-semibold leading-7">{{ (prediction.confidence_score * 100).toFixed(0) }}%</div>
              <div v-else class="text-muted-foreground leading-7">
                <Lock class="h-4 w-4" />
              </div>
            </div>
          </div>

          <!-- Elo Ratings -->
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

          <!-- Game Status -->
          <div class="text-sm text-muted-foreground">
            <span v-if="prediction.game.status === 'STATUS_FINAL' && prediction.game.home_score !== undefined">
              Final: {{ prediction.game.away_score }} - {{ prediction.game.home_score }}
            </span>
            <span v-else>
              Status: {{ prediction.game.status.replace('STATUS_', '').replace('scheduled', 'Scheduled') }}
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
