<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { Link } from '@inertiajs/vue3'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { Alert, AlertDescription } from '@/components/ui/alert'

interface Prediction {
  id: number
  predicted_spread: number
  predicted_total: number
  win_probability: number
  confidence_score: number
  home_elo: number
  away_elo: number
  home_off_eff?: number
  home_def_eff?: number
  away_off_eff?: number
  away_def_eff?: number
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

const fetchPredictions = async (page = 1) => {
  try {
    loading.value = true
    error.value = null

    const response = await fetch(`/api/v1/wnba/predictions?page=${page}`)
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
        <h2 class="text-2xl font-bold">WNBA Predictions</h2>
        <p class="text-sm text-muted-foreground">
          Predictions based on Elo ratings and team efficiency metrics
        </p>
      </div>
    </div>

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
      <Link v-for="prediction in predictions" :key="prediction.id" :href="`/wnba/games/${prediction.game.id}`" class="block">
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
            {{ prediction.game.away_team.location || prediction.game.away_team.abbreviation }} {{ prediction.game.away_team.name || '' }} at {{ prediction.game.home_team.location || prediction.game.home_team.abbreviation }} {{ prediction.game.home_team.name || '' }}
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
              <div class="text-lg font-semibold">{{ prediction.confidence_score.toFixed(1) }}%</div>
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
              <div v-if="prediction.away_off_eff">
                <div class="text-xs text-muted-foreground">Away Off Eff</div>
                <div class="text-sm font-semibold">{{ prediction.away_off_eff.toFixed(1) }}</div>
              </div>
              <div v-if="prediction.home_off_eff">
                <div class="text-xs text-muted-foreground">Home Off Eff</div>
                <div class="text-sm font-semibold">{{ prediction.home_off_eff.toFixed(1) }}</div>
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
