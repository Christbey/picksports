<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { Link } from '@inertiajs/vue3'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Input } from '@/components/ui/input'
import { Button } from '@/components/ui/button'
import { Label } from '@/components/ui/label'

interface Prediction {
  id: number
  predicted_spread: number
  predicted_total: number
  win_probability: number
  confidence_score: number
  actual_spread?: number
  actual_total?: number
  spread_error?: number
  total_error?: number
  winner_correct?: boolean
  graded_at?: string
  game: {
    id: number
    game_date: string
    game_time?: string
    status: string
    home_score?: number
    away_score?: number
    home_team: {
      abbreviation: string
      school: string
      mascot: string
    }
    away_team: {
      abbreviation: string
      school: string
      mascot: string
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

    const response = await fetch(`/api/v1/wcbb/predictions?${params}`)
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

const formatDate = (dateString: string, timeString?: string) => {
  // Combine date and time strings if time is provided
  const dateTimeString = timeString ? `${dateString}T${timeString}` : dateString
  const date = new Date(dateTimeString)
  return new Intl.DateTimeFormat('en-US', {
    month: 'short',
    day: 'numeric',
    hour: 'numeric',
    minute: '2-digit'
  }).format(date)
}

onMounted(() => {
  fetchPredictions()
})
</script>

<template>
  <div class="space-y-4">
    <div class="flex items-center justify-between">
      <div>
        <h2 class="text-2xl font-bold">WCBB Predictions</h2>
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
      <Link v-for="prediction in predictions" :key="prediction.id" :href="`/wcbb/games/${prediction.game.id}`" class="block">
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
            {{ prediction.game.away_team.school }} {{ prediction.game.away_team.mascot }} at
            {{ prediction.game.home_team.school }} {{ prediction.game.home_team.mascot }}
          </CardDescription>
        </CardHeader>
        <CardContent class="space-y-4">
          <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
            <div>
              <div class="text-xs text-muted-foreground">Spread</div>
              <div class="text-lg font-semibold">{{ formatSpread(prediction.predicted_spread) }}</div>
            </div>
            <div>
              <div class="text-xs text-muted-foreground">Total</div>
              <div class="text-lg font-semibold">{{ prediction.predicted_total }}</div>
            </div>
            <div>
              <div class="text-xs text-muted-foreground">Win Prob</div>
              <div class="text-lg font-semibold">{{ (prediction.win_probability * 100).toFixed(1) }}%</div>
            </div>
            <div>
              <div class="text-xs text-muted-foreground">Confidence</div>
              <div class="text-lg font-semibold">{{ prediction.confidence_score }}%</div>
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
                  {{ prediction.winner_correct ? '✓ Correct' : '✗ Wrong' }}
                </div>
              </div>
            </div>
            <div class="mt-2 text-xs text-muted-foreground">
              Score: {{ prediction.game.away_score }} - {{ prediction.game.home_score }}
            </div>
          </div>

          <!-- Game Status -->
          <div v-else class="text-sm text-muted-foreground">
            Status: {{ prediction.game.status.replace('STATUS_', '') }}
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
