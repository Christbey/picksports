<script setup lang="ts">
import { ref, onMounted, computed, watch } from 'vue'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Label } from '@/components/ui/label'
import { Button } from '@/components/ui/button'

interface Prediction {
  id: number
  predicted_spread: number
  win_probability: number
  confidence_score: number
  home_elo: number
  away_elo: number
  home_fpi?: number
  away_fpi?: number
  game: {
    game_date: string
    game_time: string
    status: string
    week?: number
    season_type?: string
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

const seasonType = ref<string>('')
const week = ref<string>('')

const weekOptions = computed(() => {
  if (seasonType.value === 'Regular Season') {
    return Array.from({ length: 15 }, (_, i) => ({
      value: String(i + 1),
      label: `Week ${i + 1}`
    }))
  } else if (seasonType.value === 'Postseason') {
    return [
      { value: '1', label: 'Bowl Games' },
      { value: '2', label: 'Playoffs' },
      { value: '3', label: 'Championship' }
    ]
  }
  return []
})

watch(seasonType, () => {
  week.value = ''
})

const fetchPredictions = async (page = 1) => {
  try {
    loading.value = true
    error.value = null

    const params = new URLSearchParams({ page: page.toString() })
    if (seasonType.value) params.append('season_type', seasonType.value)
    if (week.value) params.append('week', week.value)

    const response = await fetch(`/api/v1/cfb/predictions?${params}`)
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
  seasonType.value = ''
  week.value = ''
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

const getWeekLabel = (game: Prediction['game']) => {
  if (!game.week || !game.season_type) return ''

  if (game.season_type === 'Regular Season') {
    return `Week ${game.week}`
  } else {
    return 'Postseason'
  }
}

onMounted(() => {
  fetchPredictions()
})
</script>

<template>
  <div class="space-y-4">
    <div class="flex items-center justify-between">
      <div>
        <h2 class="text-2xl font-bold">CFB Predictions</h2>
        <p class="text-sm text-muted-foreground">
          Predictions based on Elo ratings and FPI metrics
        </p>
      </div>
    </div>

    <!-- Season & Week Filters -->
    <Card>
      <CardContent class="pt-6">
        <div class="flex flex-wrap items-end gap-4">
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
      <Card v-for="prediction in predictions" :key="prediction.id">
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
            <span>{{ prediction.game.away_team.location || prediction.game.away_team.abbreviation }} {{ prediction.game.away_team.name || '' }} at {{ prediction.game.home_team.location || prediction.game.home_team.abbreviation }} {{ prediction.game.home_team.name || '' }}</span>
            <span v-if="prediction.game.week && prediction.game.season_type" class="ml-2 text-xs">
              - {{ getWeekLabel(prediction.game) }}
            </span>
          </CardDescription>
        </CardHeader>
        <CardContent class="space-y-4">
          <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
            <div>
              <div class="text-xs text-muted-foreground">Spread</div>
              <div class="text-lg font-semibold">{{ formatSpread(prediction.predicted_spread) }}</div>
            </div>
            <div>
              <div class="text-xs text-muted-foreground">Win Prob</div>
              <div class="text-lg font-semibold">{{ (prediction.win_probability * 100).toFixed(1) }}%</div>
            </div>
            <div>
              <div class="text-xs text-muted-foreground">Confidence</div>
              <div class="text-lg font-semibold">{{ prediction.confidence_score.toFixed(1) }}%</div>
            </div>
            <div>
              <div class="text-xs text-muted-foreground">Elo Diff</div>
              <div class="text-lg font-semibold">{{ (prediction.home_elo - prediction.away_elo).toFixed(1) }}</div>
            </div>
          </div>

          <!-- Team Metrics -->
          <div class="border-t pt-4">
            <div class="mb-2 text-sm font-medium">Team Metrics</div>
            <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
              <div>
                <div class="text-xs text-muted-foreground">Away Elo</div>
                <div class="text-sm font-semibold">{{ prediction.away_elo }}</div>
              </div>
              <div>
                <div class="text-xs text-muted-foreground">Home Elo</div>
                <div class="text-sm font-semibold">{{ prediction.home_elo }}</div>
              </div>
              <div v-if="prediction.away_fpi">
                <div class="text-xs text-muted-foreground">Away FPI</div>
                <div class="text-sm font-semibold">{{ prediction.away_fpi }}</div>
              </div>
              <div v-if="prediction.home_fpi">
                <div class="text-xs text-muted-foreground">Home FPI</div>
                <div class="text-sm font-semibold">{{ prediction.home_fpi }}</div>
              </div>
            </div>
          </div>

          <!-- Game Status -->
          <div class="text-sm text-muted-foreground">
            <span v-if="prediction.game.status === 'STATUS_FINAL' && prediction.game.home_score !== undefined">
              Final: {{ prediction.game.away_score }} - {{ prediction.game.home_score }}
            </span>
            <span v-else>
              Status: {{ prediction.game.status.replace('STATUS_', '') }}
            </span>
          </div>
        </CardContent>
      </Card>
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
