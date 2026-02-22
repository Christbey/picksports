<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import { ref, onMounted, computed } from 'vue'
import AppLayout from '@/layouts/AppLayout.vue'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Badge } from '@/components/ui/badge'
import BettingAnalysisCard, { type LivePredictionData } from '@/components/BettingAnalysisCard.vue'
import { type BreadcrumbItem } from '@/types'

interface Game {
  id: number
  home_team_id: number
  away_team_id: number
  home_score?: number
  away_score?: number
  status: string
  game_date: string
  game_time: string
  home_team?: { abbreviation: string }
  away_team?: { abbreviation: string }
}

interface Team {
  id: number
  abbreviation: string
  location?: string
  name?: string
  display_name?: string
  logo?: string
  color?: string
}

interface Prediction {
  id: number
  game_id: number
  home_elo: number | string
  away_elo: number | string
  predicted_spread: number | string
  predicted_total?: number | string
  win_probability: number | string
  confidence_score: number | string
  betting_value?: BettingRecommendation[]
  // Live prediction fields
  live_predicted_spread?: number | string | null
  live_win_probability?: number | string | null
  live_predicted_total?: number | string | null
  live_seconds_remaining?: number | null
  live_updated_at?: string | null
}

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

interface TeamTrends {
  team_id: number
  team_abbreviation: string
  team_name: string
  sample_size: number
  user_tier: string
  trends: Record<string, string[]>
  locked_trends: Record<string, string>
}

interface Game {
  id: number
  home_team_id: number
  away_team_id: number
  season: number
  season_type: string
  week: number
  game_date: string
  game_time: string
  venue: string
  status: string
  home_score?: number
  away_score?: number
  home_linescores?: string
  away_linescores?: string
  broadcast_networks?: string
  home_team?: Team
  away_team?: Team
  prediction?: Prediction
}

const props = defineProps<{
  game: Game
}>()

const homeTeam = ref<Team | null>(null)
const awayTeam = ref<Team | null>(null)
const prediction = ref<Prediction | null>(null)
const homeTeamStats = ref<any>(null)
const awayTeamStats = ref<any>(null)
const homeRecentGames = ref<Game[]>([])
const awayRecentGames = ref<Game[]>([])
const homeTrends = ref<TeamTrends | null>(null)
const awayTrends = ref<TeamTrends | null>(null)
const loading = ref(true)
const error = ref<string | null>(null)
const expandedTrendCategories = ref<Set<string>>(new Set(['scoring', 'streaks', 'situational']))

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
  { title: 'NFL', href: '/nfl-predictions' },
  { title: 'Games', href: '/nfl-predictions' },
  { title: `Game ${props.game.id}`, href: `/nfl/games/${props.game.id}` }
])

const formatNumber = (value: number | string | null | undefined, decimals = 1): string => {
  if (value === null || value === undefined) return '-'
  const numValue = typeof value === 'string' ? parseFloat(value) : value
  if (isNaN(numValue)) return '-'
  return numValue.toFixed(decimals)
}

const formatSpread = (spread: number | string): string => {
  const numSpread = typeof spread === 'string' ? parseFloat(spread) : spread
  if (isNaN(numSpread)) return '-'
  return numSpread > 0 ? `+${numSpread.toFixed(1)}` : numSpread.toFixed(1)
}

const formatDate = (dateString: string, timeString: string): string => {
  const dateTime = new Date(`${dateString}T${timeString}`)
  return new Intl.DateTimeFormat('en-US', {
    month: 'long',
    day: 'numeric',
    year: 'numeric'
  }).format(dateTime)
}

const gameStatus = computed(() => {
  switch (props.game.status) {
    case 'STATUS_SCHEDULED':
      return 'Scheduled'
    case 'STATUS_IN_PROGRESS':
      return 'Live'
    case 'STATUS_FINAL':
      return 'Final'
    default:
      return props.game.status.replace('STATUS_', '')
  }
})

const parseLinescores = (linescoresStr: string | null | undefined): any[] => {
  if (!linescoresStr) return []
  try {
    return JSON.parse(linescoresStr)
  } catch {
    return []
  }
}

const homeLinescores = computed(() => parseLinescores(props.game.home_linescores))
const awayLinescores = computed(() => parseLinescores(props.game.away_linescores))

const broadcastNetworks = computed(() => {
  if (!props.game.broadcast_networks) return []
  try {
    return JSON.parse(props.game.broadcast_networks)
  } catch {
    return []
  }
})

const getWeekLabel = computed(() => {
  if (!props.game.week || !props.game.season_type) return ''

  if (props.game.season_type === 'Regular Season') {
    return `Week ${props.game.week}`
  } else {
    const playoffRounds: Record<number, string> = {
      1: 'Wild Card',
      2: 'Divisional',
      3: 'Conference Championship',
      5: 'Super Bowl'
    }
    return playoffRounds[props.game.week] || `Playoff Week ${props.game.week}`
  }
})

const hasLivePrediction = computed(() => {
  return prediction.value?.live_win_probability !== null && prediction.value?.live_win_probability !== undefined
})

const livePredictionData = computed((): LivePredictionData | undefined => {
  if (!hasLivePrediction.value || !prediction.value) return undefined
  return {
    isLive: true,
    homeScore: props.game.home_score,
    awayScore: props.game.away_score,
    status: props.game.status,
    liveWinProbability: prediction.value.live_win_probability as number | null,
    livePredictedSpread: prediction.value.live_predicted_spread as number | null,
    livePredictedTotal: prediction.value.live_predicted_total as number | null,
    liveSecondsRemaining: prediction.value.live_seconds_remaining,
    preGameWinProbability: Number(prediction.value.win_probability),
    preGamePredictedSpread: Number(prediction.value.predicted_spread),
    preGamePredictedTotal: Number(prediction.value.predicted_total),
  }
})

const getBetterValue = (homeVal: number, awayVal: number, lowerIsBetter = false): 'home' | 'away' | null => {
  if (lowerIsBetter) {
    if (homeVal < awayVal) return 'home'
    if (awayVal < homeVal) return 'away'
  } else {
    if (homeVal > awayVal) return 'home'
    if (awayVal > homeVal) return 'away'
  }
  return null
}

const calculatePercentage = (made: number, attempted: number): string => {
  if (!attempted || attempted === 0) return '0.0'
  return ((made / attempted) * 100).toFixed(1)
}

const getRecentForm = (games: Game[], teamId: number): string => {
  return games.map(g => {
    const isHome = g.home_team_id === teamId
    const teamScore = isHome ? g.home_score : g.away_score
    const oppScore = isHome ? g.away_score : g.home_score
    return teamScore && oppScore && teamScore > oppScore ? 'W' : 'L'
  }).join('-')
}

const getNumericRecord = (games: Game[], teamId: number): string => {
  const wins = games.filter(g => {
    const isHome = g.home_team_id === teamId
    const teamScore = isHome ? g.home_score : g.away_score
    const oppScore = isHome ? g.away_score : g.home_score
    return teamScore && oppScore && teamScore > oppScore
  }).length
  const losses = games.length - wins
  return `${wins}-${losses}`
}

const formatCategoryName = (category: string): string => {
  return category
    .replace(/_/g, ' ')
    .replace(/\b\w/g, l => l.toUpperCase())
}

const toggleTrendCategory = (category: string): void => {
  if (expandedTrendCategories.value.has(category)) {
    expandedTrendCategories.value.delete(category)
  } else {
    expandedTrendCategories.value.add(category)
  }
  expandedTrendCategories.value = new Set(expandedTrendCategories.value)
}

const allTrendCategories = computed(() => {
  const categories = new Set<string>()
  if (homeTrends.value?.trends) {
    Object.keys(homeTrends.value.trends).forEach(k => categories.add(k))
  }
  if (awayTrends.value?.trends) {
    Object.keys(awayTrends.value.trends).forEach(k => categories.add(k))
  }
  if (homeTrends.value?.locked_trends) {
    Object.keys(homeTrends.value.locked_trends).forEach(k => categories.add(k))
  }
  if (awayTrends.value?.locked_trends) {
    Object.keys(awayTrends.value.locked_trends).forEach(k => categories.add(k))
  }
  return Array.from(categories)
})

const isLockedCategory = (category: string): boolean => {
  return !!(homeTrends.value?.locked_trends?.[category] || awayTrends.value?.locked_trends?.[category])
}

const getRequiredTier = (category: string): string => {
  return homeTrends.value?.locked_trends?.[category] || awayTrends.value?.locked_trends?.[category] || ''
}

const formatTierName = (tier: string): string => {
  return tier.charAt(0).toUpperCase() + tier.slice(1)
}

onMounted(async () => {
  try {
    loading.value = true
    error.value = null

    const [gameRes, predictionRes, teamStatsRes] = await Promise.all([
      fetch(`/api/v1/nfl/games/${props.game.id}`),
      fetch(`/api/v1/nfl/games/${props.game.id}/prediction`),
      fetch(`/api/v1/nfl/games/${props.game.id}/team-stats`)
    ])

    if (gameRes.ok) {
      const gameData = await gameRes.json()
      const fullGame = gameData.data
      homeTeam.value = fullGame.home_team || fullGame.homeTeam
      awayTeam.value = fullGame.away_team || fullGame.awayTeam

      if (fullGame.home_team?.id || fullGame.homeTeam?.id) {
        const homeTeamId = fullGame.home_team?.id || fullGame.homeTeam?.id
        const [homeGamesRes, homeTrendsRes] = await Promise.all([
          fetch(`/api/v1/nfl/teams/${homeTeamId}/games`),
          fetch(`/api/v1/nfl/teams/${homeTeamId}/trends?games=21&season=${props.game.season}&before_date=${props.game.game_date}`)
        ])
        if (homeGamesRes.ok) {
          const gamesData = await homeGamesRes.json()
          homeRecentGames.value = (gamesData.data || [])
            .filter((g: Game) => g.status === 'STATUS_FINAL' && g.id !== props.game.id)
            .slice(0, 5)
        }
        if (homeTrendsRes.ok) {
          homeTrends.value = await homeTrendsRes.json()
        }
      }

      if (fullGame.away_team?.id || fullGame.awayTeam?.id) {
        const awayTeamId = fullGame.away_team?.id || fullGame.awayTeam?.id
        const [awayGamesRes, awayTrendsRes] = await Promise.all([
          fetch(`/api/v1/nfl/teams/${awayTeamId}/games`),
          fetch(`/api/v1/nfl/teams/${awayTeamId}/trends?games=21&season=${props.game.season}&before_date=${props.game.game_date}`)
        ])
        if (awayGamesRes.ok) {
          const gamesData = await awayGamesRes.json()
          awayRecentGames.value = (gamesData.data || [])
            .filter((g: Game) => g.status === 'STATUS_FINAL' && g.id !== props.game.id)
            .slice(0, 5)
        }
        if (awayTrendsRes.ok) {
          awayTrends.value = await awayTrendsRes.json()
        }
      }
    }

    if (predictionRes.ok) {
      const predictionData = await predictionRes.json()
      prediction.value = Array.isArray(predictionData.data) && predictionData.data.length > 0
        ? predictionData.data[0]
        : predictionData.data
    }

    if (teamStatsRes.ok) {
      const teamStatsData = await teamStatsRes.json()
      const stats = teamStatsData.data || []
      homeTeamStats.value = stats.find((s: any) => s.team_type === 'home') || null
      awayTeamStats.value = stats.find((s: any) => s.team_type === 'away') || null
    }
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'An error occurred'
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <Head :title="`${awayTeam?.abbreviation || 'Away'} @ ${homeTeam?.abbreviation || 'Home'}`" />

  <AppLayout :breadcrumbs="breadcrumbs">
    <div class="flex h-full flex-1 flex-col gap-4 p-4">
      <Alert v-if="error" variant="destructive">
        <AlertDescription>{{ error }}</AlertDescription>
      </Alert>

      <div v-if="loading" class="space-y-4">
        <Skeleton class="h-32 w-full" />
        <Skeleton class="h-64 w-full" />
        <Skeleton class="h-64 w-full" />
      </div>

      <template v-else>
        <!-- Matchup Hero -->
        <div class="rounded-xl overflow-hidden bg-gradient-to-r from-green-600 to-green-800 dark:from-green-800 dark:to-green-950 text-white shadow-lg">
          <div class="px-6 py-8">
            <div class="flex flex-col md:flex-row items-center justify-between gap-6">
              <div class="flex-1 flex flex-col items-center md:items-end gap-2">
                <div class="relative">
                  <div
                    v-if="awayTeam?.color"
                    class="absolute inset-0 rounded-full opacity-20 blur-xl"
                    :style="{ backgroundColor: `#${awayTeam.color}` }"
                  ></div>
                  <img
                    v-if="awayTeam?.logo"
                    :src="awayTeam.logo"
                    :alt="awayTeam.abbreviation"
                    class="w-20 h-20 object-contain relative z-10 drop-shadow-lg"
                  />
                </div>
                <div class="text-center md:text-right">
                  <div class="text-xl md:text-2xl font-bold">
                    {{ awayTeam?.display_name || `${awayTeam?.location} ${awayTeam?.name}`.trim() || awayTeam?.abbreviation }}
                  </div>
                  <div class="text-sm text-white/70">Away</div>
                </div>
              </div>

              <div class="text-center min-w-[120px]">
                <div v-if="(game.status === 'STATUS_FINAL' || game.status === 'STATUS_IN_PROGRESS' || game.status === 'STATUS_HALFTIME') && game.away_score !== undefined && game.home_score !== undefined" class="text-4xl md:text-5xl font-bold tracking-tight">
                  {{ game.away_score }} - {{ game.home_score }}
                </div>
                <div v-else class="text-2xl md:text-3xl font-bold text-white/70">
                  vs
                </div>
                <Badge class="mt-2 bg-white/20 text-white border-white/30 hover:bg-white/30" :class="{ 'animate-pulse !bg-red-600 !border-red-500': game.status === 'STATUS_IN_PROGRESS' || game.status === 'STATUS_HALFTIME' }">{{ gameStatus }}</Badge>
              </div>

              <div class="flex-1 flex flex-col items-center md:items-start gap-2">
                <div class="relative">
                  <div
                    v-if="homeTeam?.color"
                    class="absolute inset-0 rounded-full opacity-20 blur-xl"
                    :style="{ backgroundColor: `#${homeTeam.color}` }"
                  ></div>
                  <img
                    v-if="homeTeam?.logo"
                    :src="homeTeam.logo"
                    :alt="homeTeam.abbreviation"
                    class="w-20 h-20 object-contain relative z-10 drop-shadow-lg"
                  />
                </div>
                <div class="text-center md:text-left">
                  <div class="text-xl md:text-2xl font-bold">
                    {{ homeTeam?.display_name || `${homeTeam?.location} ${homeTeam?.name}`.trim() || homeTeam?.abbreviation }}
                  </div>
                  <div class="text-sm text-white/70">Home</div>
                </div>
              </div>
            </div>
          </div>
          <!-- Game Info Bar -->
          <div class="bg-black/20 px-6 py-3 flex flex-wrap items-center justify-center gap-x-6 gap-y-1 text-sm text-white/80">
            <span>{{ formatDate(game.game_date, game.game_time) }}</span>
            <span v-if="getWeekLabel">{{ game.season_type }} - {{ getWeekLabel }}</span>
            <span v-if="game.venue" class="flex items-center gap-1">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.05 4.05a7 7 0 119.9 9.9L10 18.9l-4.95-4.95a7 7 0 010-9.9zM10 11a2 2 0 100-4 2 2 0 000 4z" clip-rule="evenodd" /></svg>
              {{ game.venue }}
            </span>
            <span v-if="broadcastNetworks.length > 0" class="flex items-center gap-1">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor"><path d="M2 6a2 2 0 012-2h6a2 2 0 012 2v8a2 2 0 01-2 2H4a2 2 0 01-2-2V6zm12.553 1.106A1 1 0 0014 8v4a1 1 0 00.553.894l2 1A1 1 0 0018 13V7a1 1 0 00-1.447-.894l-2 1z" /></svg>
              {{ broadcastNetworks.join(', ') }}
            </span>
          </div>
        </div>

        <!-- Prediction Model -->
        <Card v-if="prediction">
          <CardHeader>
            <CardTitle>Prediction Model</CardTitle>
          </CardHeader>
          <CardContent>
            <!-- Win Probability Bar -->
            <div class="mb-6">
              <div class="flex items-center justify-between text-sm font-medium mb-2">
                <span>{{ awayTeam?.abbreviation }} {{ formatNumber((1 - Number(prediction.win_probability)) * 100, 0) }}%</span>
                <span>{{ homeTeam?.abbreviation }} {{ formatNumber(Number(prediction.win_probability) * 100, 0) }}%</span>
              </div>
              <div class="flex h-3 rounded-full overflow-hidden">
                <div class="bg-green-500 dark:bg-green-600 transition-all" :style="{ width: `${(1 - Number(prediction.win_probability)) * 100}%` }"></div>
                <div class="bg-green-800 dark:bg-green-400 transition-all" :style="{ width: `${Number(prediction.win_probability) * 100}%` }"></div>
              </div>
            </div>
            <!-- Stat Cards -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
              <div class="text-center p-3 rounded-lg border">
                <div class="text-sm text-muted-foreground">Away ELO</div>
                <div class="text-2xl font-bold">{{ formatNumber(prediction.away_elo, 0) }}</div>
                <div class="text-xs text-muted-foreground mt-0.5">{{ awayTeam?.abbreviation }}</div>
              </div>
              <div class="text-center p-3 rounded-lg border">
                <div class="text-sm text-muted-foreground">Home ELO</div>
                <div class="text-2xl font-bold">{{ formatNumber(prediction.home_elo, 0) }}</div>
                <div class="text-xs text-muted-foreground mt-0.5">{{ homeTeam?.abbreviation }}</div>
              </div>
              <div class="text-center p-3 rounded-lg border border-primary/20 bg-primary/5">
                <div class="text-sm text-muted-foreground">Spread</div>
                <div class="text-2xl font-bold text-primary">{{ formatSpread(prediction.predicted_spread) }}</div>
                <div class="text-xs text-muted-foreground mt-0.5">{{ Number(prediction.predicted_spread) < 0 ? (homeTeam?.abbreviation || 'Home') : (awayTeam?.abbreviation || 'Away') }} favored</div>
              </div>
              <div class="text-center p-3 rounded-lg border border-primary/20 bg-primary/5">
                <div class="text-sm text-muted-foreground">Win Prob</div>
                <div class="text-2xl font-bold text-primary">{{ formatNumber(Number(prediction.win_probability) * 100, 1) }}%</div>
                <div class="text-xs text-muted-foreground mt-0.5">{{ homeTeam?.abbreviation }}</div>
              </div>
            </div>
          </CardContent>
        </Card>

      <!-- Linescore Table -->
      <Card v-if="homeLinescores.length > 0 && awayLinescores.length > 0 && game.status === 'STATUS_FINAL'">
        <CardHeader>
          <CardTitle>Quarter by Quarter</CardTitle>
        </CardHeader>
        <CardContent>
          <div class="overflow-x-auto">
            <table class="w-full text-sm">
              <thead>
                <tr class="border-b">
                  <th class="text-left p-2 text-muted-foreground font-medium">Team</th>
                  <th class="text-center p-2 text-muted-foreground font-medium" v-for="quarter in homeLinescores" :key="quarter.period">
                    Q{{ quarter.period }}
                  </th>
                  <th class="text-center p-2 font-bold">Final</th>
                </tr>
              </thead>
              <tbody>
                <tr class="border-b">
                  <td class="p-2 font-medium">
                    <span class="flex items-center gap-2">
                      <img v-if="awayTeam?.logo" :src="awayTeam.logo" :alt="awayTeam.abbreviation" class="h-5 w-5 object-contain" />
                      {{ awayTeam?.abbreviation }}
                    </span>
                  </td>
                  <td class="text-center p-2" v-for="quarter in awayLinescores" :key="quarter.period">
                    {{ quarter.value }}
                  </td>
                  <td class="text-center p-2 font-bold" :class="game.away_score > game.home_score ? 'text-green-600 dark:text-green-400' : ''">{{ game.away_score }}</td>
                </tr>
                <tr>
                  <td class="p-2 font-medium">
                    <span class="flex items-center gap-2">
                      <img v-if="homeTeam?.logo" :src="homeTeam.logo" :alt="homeTeam.abbreviation" class="h-5 w-5 object-contain" />
                      {{ homeTeam?.abbreviation }}
                    </span>
                  </td>
                  <td class="text-center p-2" v-for="quarter in homeLinescores" :key="quarter.period">
                    {{ quarter.value }}
                  </td>
                  <td class="text-center p-2 font-bold" :class="game.home_score > game.away_score ? 'text-green-600 dark:text-green-400' : ''">{{ game.home_score }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </CardContent>
      </Card>

      <Card v-if="homeTeamStats && awayTeamStats && game.status === 'STATUS_FINAL'">
        <CardHeader>
          <CardTitle>Box Score</CardTitle>
        </CardHeader>
        <CardContent>
          <div class="overflow-x-auto">
          <div class="space-y-4 min-w-[600px]">
            <div class="grid grid-cols-7 gap-2 text-sm font-medium border-b pb-2">
              <div class="col-span-2 text-right">{{ awayTeam?.abbreviation }}</div>
              <div class="col-span-3 text-center">Stat</div>
              <div class="col-span-2 text-left">{{ homeTeam?.abbreviation }}</div>
            </div>

            <div class="grid grid-cols-7 gap-2 items-center text-sm">
              <div class="col-span-2 text-right font-medium" :class="getBetterValue(homeTeamStats.total_yards, awayTeamStats.total_yards) === 'away' ? 'text-green-600 dark:text-green-400' : ''">
                {{ awayTeamStats.total_yards }}
              </div>
              <div class="col-span-3 text-center text-muted-foreground">
                Total Yards
              </div>
              <div class="col-span-2 text-left font-medium" :class="getBetterValue(homeTeamStats.total_yards, awayTeamStats.total_yards) === 'home' ? 'text-green-600 dark:text-green-400' : ''">
                {{ homeTeamStats.total_yards }}
              </div>
            </div>

            <div class="grid grid-cols-7 gap-2 items-center text-sm">
              <div class="col-span-2 text-right font-medium" :class="getBetterValue(homeTeamStats.passing_yards, awayTeamStats.passing_yards) === 'away' ? 'text-green-600 dark:text-green-400' : ''">
                {{ awayTeamStats.passing_completions }}-{{ awayTeamStats.passing_attempts }}, {{ awayTeamStats.passing_yards }}
              </div>
              <div class="col-span-3 text-center text-muted-foreground">
                Passing (C-A, Yds)
              </div>
              <div class="col-span-2 text-left font-medium" :class="getBetterValue(homeTeamStats.passing_yards, awayTeamStats.passing_yards) === 'home' ? 'text-green-600 dark:text-green-400' : ''">
                {{ homeTeamStats.passing_completions }}-{{ homeTeamStats.passing_attempts }}, {{ homeTeamStats.passing_yards }}
              </div>
            </div>

            <div class="grid grid-cols-7 gap-2 items-center text-sm">
              <div class="col-span-2 text-right font-medium" :class="getBetterValue(homeTeamStats.rushing_yards, awayTeamStats.rushing_yards) === 'away' ? 'text-green-600 dark:text-green-400' : ''">
                {{ awayTeamStats.rushing_attempts }}, {{ awayTeamStats.rushing_yards }}
              </div>
              <div class="col-span-3 text-center text-muted-foreground">
                Rushing (Att, Yds)
              </div>
              <div class="col-span-2 text-left font-medium" :class="getBetterValue(homeTeamStats.rushing_yards, awayTeamStats.rushing_yards) === 'home' ? 'text-green-600 dark:text-green-400' : ''">
                {{ homeTeamStats.rushing_attempts }}, {{ homeTeamStats.rushing_yards }}
              </div>
            </div>

            <div class="grid grid-cols-7 gap-2 items-center text-sm">
              <div class="col-span-2 text-right font-medium" :class="getBetterValue(homeTeamStats.first_downs, awayTeamStats.first_downs) === 'away' ? 'text-green-600 dark:text-green-400' : ''">
                {{ awayTeamStats.first_downs }}
              </div>
              <div class="col-span-3 text-center text-muted-foreground">
                First Downs
              </div>
              <div class="col-span-2 text-left font-medium" :class="getBetterValue(homeTeamStats.first_downs, awayTeamStats.first_downs) === 'home' ? 'text-green-600 dark:text-green-400' : ''">
                {{ homeTeamStats.first_downs }}
              </div>
            </div>

            <div class="grid grid-cols-7 gap-2 items-center text-sm">
              <div class="col-span-2 text-right font-medium" :class="getBetterValue(homeTeamStats.third_down_conversions / (homeTeamStats.third_down_attempts || 1), awayTeamStats.third_down_conversions / (awayTeamStats.third_down_attempts || 1)) === 'away' ? 'text-green-600 dark:text-green-400' : ''">
                {{ awayTeamStats.third_down_conversions }}-{{ awayTeamStats.third_down_attempts }} ({{ calculatePercentage(awayTeamStats.third_down_conversions, awayTeamStats.third_down_attempts) }}%)
              </div>
              <div class="col-span-3 text-center text-muted-foreground">
                3rd Down
              </div>
              <div class="col-span-2 text-left font-medium" :class="getBetterValue(homeTeamStats.third_down_conversions / (homeTeamStats.third_down_attempts || 1), awayTeamStats.third_down_conversions / (awayTeamStats.third_down_attempts || 1)) === 'home' ? 'text-green-600 dark:text-green-400' : ''">
                {{ homeTeamStats.third_down_conversions }}-{{ homeTeamStats.third_down_attempts }} ({{ calculatePercentage(homeTeamStats.third_down_conversions, homeTeamStats.third_down_attempts) }}%)
              </div>
            </div>

            <div class="grid grid-cols-7 gap-2 items-center text-sm">
              <div class="col-span-2 text-right font-medium" :class="getBetterValue(homeTeamStats.fourth_down_conversions / (homeTeamStats.fourth_down_attempts || 1), awayTeamStats.fourth_down_conversions / (awayTeamStats.fourth_down_attempts || 1)) === 'away' ? 'text-green-600 dark:text-green-400' : ''">
                {{ awayTeamStats.fourth_down_conversions }}-{{ awayTeamStats.fourth_down_attempts }}
              </div>
              <div class="col-span-3 text-center text-muted-foreground">
                4th Down
              </div>
              <div class="col-span-2 text-left font-medium" :class="getBetterValue(homeTeamStats.fourth_down_conversions / (homeTeamStats.fourth_down_attempts || 1), awayTeamStats.fourth_down_conversions / (awayTeamStats.fourth_down_attempts || 1)) === 'home' ? 'text-green-600 dark:text-green-400' : ''">
                {{ homeTeamStats.fourth_down_conversions }}-{{ homeTeamStats.fourth_down_attempts }}
              </div>
            </div>

            <div class="grid grid-cols-7 gap-2 items-center text-sm">
              <div class="col-span-2 text-right font-medium" :class="getBetterValue(homeTeamStats.red_zone_scores / (homeTeamStats.red_zone_attempts || 1), awayTeamStats.red_zone_scores / (awayTeamStats.red_zone_attempts || 1)) === 'away' ? 'text-green-600 dark:text-green-400' : ''">
                {{ awayTeamStats.red_zone_scores }}-{{ awayTeamStats.red_zone_attempts }}
              </div>
              <div class="col-span-3 text-center text-muted-foreground">
                Red Zone
              </div>
              <div class="col-span-2 text-left font-medium" :class="getBetterValue(homeTeamStats.red_zone_scores / (homeTeamStats.red_zone_attempts || 1), awayTeamStats.red_zone_scores / (awayTeamStats.red_zone_attempts || 1)) === 'home' ? 'text-green-600 dark:text-green-400' : ''">
                {{ homeTeamStats.red_zone_scores }}-{{ homeTeamStats.red_zone_attempts }}
              </div>
            </div>

            <div class="grid grid-cols-7 gap-2 items-center text-sm">
              <div class="col-span-2 text-right font-medium" :class="getBetterValue(homeTeamStats.interceptions + homeTeamStats.fumbles_lost, awayTeamStats.interceptions + awayTeamStats.fumbles_lost, true) === 'away' ? 'text-green-600 dark:text-green-400' : ''">
                {{ awayTeamStats.interceptions + awayTeamStats.fumbles_lost }} ({{ awayTeamStats.interceptions }} INT, {{ awayTeamStats.fumbles_lost }} FUM)
              </div>
              <div class="col-span-3 text-center text-muted-foreground">
                Turnovers
              </div>
              <div class="col-span-2 text-left font-medium" :class="getBetterValue(homeTeamStats.interceptions + homeTeamStats.fumbles_lost, awayTeamStats.interceptions + awayTeamStats.fumbles_lost, true) === 'home' ? 'text-green-600 dark:text-green-400' : ''">
                {{ homeTeamStats.interceptions + homeTeamStats.fumbles_lost }} ({{ homeTeamStats.interceptions }} INT, {{ homeTeamStats.fumbles_lost }} FUM)
              </div>
            </div>

            <div class="grid grid-cols-7 gap-2 items-center text-sm">
              <div class="col-span-2 text-right font-medium" :class="getBetterValue(homeTeamStats.sacks_allowed, awayTeamStats.sacks_allowed, true) === 'away' ? 'text-green-600 dark:text-green-400' : ''">
                {{ awayTeamStats.sacks_allowed }}
              </div>
              <div class="col-span-3 text-center text-muted-foreground">
                Sacks Allowed
              </div>
              <div class="col-span-2 text-left font-medium" :class="getBetterValue(homeTeamStats.sacks_allowed, awayTeamStats.sacks_allowed, true) === 'home' ? 'text-green-600 dark:text-green-400' : ''">
                {{ homeTeamStats.sacks_allowed }}
              </div>
            </div>

            <div class="grid grid-cols-7 gap-2 items-center text-sm">
              <div class="col-span-2 text-right font-medium" :class="getBetterValue(homeTeamStats.penalty_yards, awayTeamStats.penalty_yards, true) === 'away' ? 'text-green-600 dark:text-green-400' : ''">
                {{ awayTeamStats.penalties }}-{{ awayTeamStats.penalty_yards }}
              </div>
              <div class="col-span-3 text-center text-muted-foreground">
                Penalties (Pen-Yds)
              </div>
              <div class="col-span-2 text-left font-medium" :class="getBetterValue(homeTeamStats.penalty_yards, awayTeamStats.penalty_yards, true) === 'home' ? 'text-green-600 dark:text-green-400' : ''">
                {{ homeTeamStats.penalties }}-{{ homeTeamStats.penalty_yards }}
              </div>
            </div>

            <div class="grid grid-cols-7 gap-2 items-center text-sm">
              <div class="col-span-2 text-right font-medium" :class="getBetterValue(homeTeamStats.time_of_possession, awayTeamStats.time_of_possession) === 'away' ? 'text-green-600 dark:text-green-400' : ''">
                {{ awayTeamStats.time_of_possession }}
              </div>
              <div class="col-span-3 text-center text-muted-foreground">
                Time of Possession
              </div>
              <div class="col-span-2 text-left font-medium" :class="getBetterValue(homeTeamStats.time_of_possession, awayTeamStats.time_of_possession) === 'home' ? 'text-green-600 dark:text-green-400' : ''">
                {{ homeTeamStats.time_of_possession }}
              </div>
            </div>
          </div>
          </div>
        </CardContent>
      </Card>

      <!-- Live Analysis & Betting Value Combined Section -->
      <Card v-if="hasLivePrediction || (prediction?.betting_value && prediction.betting_value.length > 0)">
        <CardHeader>
          <CardTitle class="flex items-center gap-2">
            <span v-if="hasLivePrediction" class="relative flex h-3 w-3">
              <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-red-400 opacity-75"></span>
              <span class="relative inline-flex rounded-full h-3 w-3 bg-red-500"></span>
            </span>
            <span>{{ hasLivePrediction ? 'Live Analysis' : 'Betting Value Detected' }}</span>
            <span v-if="!hasLivePrediction && prediction?.betting_value?.length" class="text-sm font-normal text-muted-foreground">(DraftKings)</span>
          </CardTitle>
        </CardHeader>
        <CardContent>
          <BettingAnalysisCard
            :betting-value="prediction?.betting_value"
            :live-prediction="livePredictionData"
          />
        </CardContent>
      </Card>

      <!-- Team Trends Comparison -->
      <Card v-if="homeTrends || awayTrends">
        <CardHeader>
          <CardTitle class="flex items-center justify-between">
            <span>Team Trends Comparison</span>
            <span class="text-sm font-normal text-muted-foreground">{{ game.season }} Season ({{ homeTrends?.sample_size || awayTrends?.sample_size || 0 }} games)</span>
          </CardTitle>
        </CardHeader>
        <CardContent>
          <div class="space-y-4">
            <div v-for="category in allTrendCategories" :key="category" class="border rounded-lg overflow-hidden">
              <button
                @click="!isLockedCategory(category) && toggleTrendCategory(category)"
                class="w-full flex items-center justify-between p-3 bg-muted/50 transition-colors text-left"
                :class="isLockedCategory(category) ? 'cursor-not-allowed opacity-75' : 'hover:bg-muted/70'"
              >
                <span class="font-medium flex items-center gap-2">
                  {{ formatCategoryName(category) }}
                  <span v-if="isLockedCategory(category)" class="inline-flex items-center gap-1 text-xs font-normal bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-200 px-2 py-0.5 rounded">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3" viewBox="0 0 20 20" fill="currentColor">
                      <path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd" />
                    </svg>
                    {{ formatTierName(getRequiredTier(category)) }}
                  </span>
                </span>
                <span v-if="!isLockedCategory(category)" class="text-muted-foreground text-sm">
                  {{ expandedTrendCategories.has(category) ? '−' : '+' }}
                </span>
              </button>
              <!-- Locked category content -->
              <div v-if="isLockedCategory(category)" class="p-4 bg-muted/30">
                <div class="text-center py-4">
                  <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8 mx-auto text-muted-foreground/50 mb-2" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd" />
                  </svg>
                  <p class="text-sm text-muted-foreground mb-3">
                    {{ formatCategoryName(category) }} trends require a <strong>{{ formatTierName(getRequiredTier(category)) }}</strong> subscription
                  </p>
                  <Link href="/subscription/plans" class="inline-flex items-center gap-1 text-sm font-medium text-primary hover:underline">
                    Upgrade to unlock
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor">
                      <path fill-rule="evenodd" d="M10.293 3.293a1 1 0 011.414 0l6 6a1 1 0 010 1.414l-6 6a1 1 0 01-1.414-1.414L14.586 11H3a1 1 0 110-2h11.586l-4.293-4.293a1 1 0 010-1.414z" clip-rule="evenodd" />
                    </svg>
                  </Link>
                </div>
              </div>
              <!-- Unlocked category content -->
              <div v-else-if="expandedTrendCategories.has(category)" class="p-4">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                  <!-- Away Team Trends -->
                  <div>
                    <div class="flex items-center gap-2 mb-3">
                      <img
                        v-if="awayTeam?.logo"
                        :src="awayTeam.logo"
                        :alt="awayTeam.abbreviation"
                        class="w-6 h-6 object-contain"
                      />
                      <span class="font-medium text-sm">{{ awayTeam?.abbreviation }}</span>
                    </div>
                    <ul v-if="awayTrends?.trends?.[category]?.length" class="space-y-2">
                      <li
                        v-for="(trend, idx) in awayTrends.trends[category]"
                        :key="idx"
                        class="text-sm text-muted-foreground flex items-start gap-2"
                      >
                        <span class="text-muted-foreground/60">•</span>
                        <span>{{ trend }}</span>
                      </li>
                    </ul>
                    <p v-else class="text-sm text-muted-foreground/60 italic">No trends available</p>
                  </div>
                  <!-- Home Team Trends -->
                  <div>
                    <div class="flex items-center gap-2 mb-3">
                      <img
                        v-if="homeTeam?.logo"
                        :src="homeTeam.logo"
                        :alt="homeTeam.abbreviation"
                        class="w-6 h-6 object-contain"
                      />
                      <span class="font-medium text-sm">{{ homeTeam?.abbreviation }}</span>
                    </div>
                    <ul v-if="homeTrends?.trends?.[category]?.length" class="space-y-2">
                      <li
                        v-for="(trend, idx) in homeTrends.trends[category]"
                        :key="idx"
                        class="text-sm text-muted-foreground flex items-start gap-2"
                      >
                        <span class="text-muted-foreground/60">•</span>
                        <span>{{ trend }}</span>
                      </li>
                    </ul>
                    <p v-else class="text-sm text-muted-foreground/60 italic">No trends available</p>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </CardContent>
      </Card>

      <div v-if="homeRecentGames.length > 0 || awayRecentGames.length > 0" class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <Card v-if="awayRecentGames.length > 0">
          <CardHeader>
            <CardTitle>{{ awayTeam?.abbreviation }} Recent Games</CardTitle>
            <div class="text-sm text-muted-foreground">
              {{ getNumericRecord(awayRecentGames, game.away_team_id) }}
            </div>
          </CardHeader>
          <CardContent>
            <div class="space-y-2">
              <Link
                v-for="recentGame in awayRecentGames"
                :key="recentGame.id"
                :href="`/nfl/games/${recentGame.id}`"
                class="block p-3 rounded-md border border-sidebar-border/70 hover:bg-sidebar/50 transition-colors"
              >
                <div class="flex items-center justify-between">
                  <div class="text-sm font-medium">
                    <span v-if="recentGame.home_team_id === game.away_team_id">vs</span>
                    <span v-else>@</span>
                    {{ recentGame.home_team_id === game.away_team_id ? recentGame.away_team?.abbreviation : recentGame.home_team?.abbreviation }}
                  </div>
                  <div class="text-sm font-semibold">
                    <span
                      :class="{
                        'text-green-600 dark:text-green-400': (recentGame.home_team_id === game.away_team_id && (recentGame.home_score || 0) > (recentGame.away_score || 0)) || (recentGame.away_team_id === game.away_team_id && (recentGame.away_score || 0) > (recentGame.home_score || 0)),
                        'text-red-600 dark:text-red-400': (recentGame.home_team_id === game.away_team_id && (recentGame.home_score || 0) < (recentGame.away_score || 0)) || (recentGame.away_team_id === game.away_team_id && (recentGame.away_score || 0) < (recentGame.home_score || 0))
                      }"
                    >
                      {{ (recentGame.home_team_id === game.away_team_id && (recentGame.home_score || 0) > (recentGame.away_score || 0)) || (recentGame.away_team_id === game.away_team_id && (recentGame.away_score || 0) > (recentGame.home_score || 0)) ? 'W' : 'L' }}
                      {{ recentGame.home_team_id === game.away_team_id ? recentGame.home_score : recentGame.away_score }}-{{ recentGame.home_team_id === game.away_team_id ? recentGame.away_score : recentGame.home_score }}
                    </span>
                  </div>
                </div>
              </Link>
            </div>
          </CardContent>
        </Card>

        <Card v-if="homeRecentGames.length > 0">
          <CardHeader>
            <CardTitle>{{ homeTeam?.abbreviation }} Recent Games</CardTitle>
            <div class="text-sm text-muted-foreground">
              {{ getNumericRecord(homeRecentGames, game.home_team_id) }}
            </div>
          </CardHeader>
          <CardContent>
            <div class="space-y-2">
              <Link
                v-for="recentGame in homeRecentGames"
                :key="recentGame.id"
                :href="`/nfl/games/${recentGame.id}`"
                class="block p-3 rounded-md border border-sidebar-border/70 hover:bg-sidebar/50 transition-colors"
              >
                <div class="flex items-center justify-between">
                  <div class="text-sm font-medium">
                    <span v-if="recentGame.home_team_id === game.home_team_id">vs</span>
                    <span v-else>@</span>
                    {{ recentGame.home_team_id === game.home_team_id ? recentGame.away_team?.abbreviation : recentGame.home_team?.abbreviation }}
                  </div>
                  <div class="text-sm font-semibold">
                    <span
                      :class="{
                        'text-green-600 dark:text-green-400': (recentGame.home_team_id === game.home_team_id && (recentGame.home_score || 0) > (recentGame.away_score || 0)) || (recentGame.away_team_id === game.home_team_id && (recentGame.away_score || 0) > (recentGame.home_score || 0)),
                        'text-red-600 dark:text-red-400': (recentGame.home_team_id === game.home_team_id && (recentGame.home_score || 0) < (recentGame.away_score || 0)) || (recentGame.away_team_id === game.home_team_id && (recentGame.away_score || 0) < (recentGame.home_score || 0))
                      }"
                    >
                      {{ (recentGame.home_team_id === game.home_team_id && (recentGame.home_score || 0) > (recentGame.away_score || 0)) || (recentGame.away_team_id === game.home_team_id && (recentGame.away_score || 0) > (recentGame.home_score || 0)) ? 'W' : 'L' }}
                      {{ recentGame.home_team_id === game.home_team_id ? recentGame.home_score : recentGame.away_score }}-{{ recentGame.home_team_id === game.home_team_id ? recentGame.away_score : recentGame.home_score }}
                    </span>
                  </div>
                </div>
              </Link>
            </div>
          </CardContent>
        </Card>
      </div>
      </template>
    </div>
  </AppLayout>
</template>
