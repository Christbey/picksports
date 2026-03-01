<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import { Badge } from '@/components/ui/badge'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import AppLayout from '@/layouts/AppLayout.vue'

interface OverallStats {
  total_predictions: number
  winner_accuracy: number
  avg_spread_error: number
  avg_total_error: number
  win_record: string
}

interface SportStats {
  label: string
  total_graded: number
  winner_correct: number
  winner_accuracy: number
  avg_spread_error: number
  avg_total_error: number
  win_record: string
}

interface ROIStats {
  total_bets: number
  total_wins: number
  total_losses: number
  total_wagered: number
  total_profit: number
  roi_percentage: number
  win_percentage: number
}

interface RecentPerformance {
  overall: OverallStats
  by_sport: Record<string, SportStats>
  roi: ROIStats
}

interface SeasonSportStats {
  label: string
  total_graded: number
  winner_correct: number
  winner_accuracy: number
  win_record: string
}

defineProps<{
  overall: OverallStats
  by_sport: Record<string, SportStats>
  recent: RecentPerformance
  season_to_date: Record<string, SeasonSportStats>
  roi: ROIStats
}>()

const getAccuracyColor = (accuracy: number) => {
  if (accuracy >= 55) return 'text-green-600 dark:text-green-400'
  if (accuracy >= 52) return 'text-blue-600 dark:text-blue-400'
  return 'text-orange-600 dark:text-orange-400'
}

const getROIColor = (roi: number) => {
  if (roi > 0) return 'text-green-600 dark:text-green-400'
  if (roi === 0) return 'text-gray-600 dark:text-gray-400'
  return 'text-red-600 dark:text-red-400'
}
</script>

<template>
  <AppLayout>
    <Head title="Performance Dashboard">
      <meta head-key="description" name="description" content="Track PickSports prediction accuracy, ROI, and sport-by-sport performance with transparent historical results." />
      <meta head-key="og:title" property="og:title" content="Performance Dashboard" />
      <meta head-key="og:description" property="og:description" content="View prediction accuracy, win record, and ROI performance across all supported sports." />
      <meta head-key="twitter:title" name="twitter:title" content="Performance Dashboard" />
      <meta head-key="twitter:description" name="twitter:description" content="View prediction accuracy, win record, and ROI performance across all supported sports." />
      <component
        :is="'script'"
        head-key="schema-webpage-performance"
        type="application/ld+json"
        v-html='JSON.stringify({"@context":"https://schema.org","@type":"WebPage","name":"PickSports Performance Dashboard","url":"https://picksports.app/performance","description":"Transparent PickSports model performance and ROI history."})'
      />
    </Head>

    <div class="py-12">
      <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
        <!-- Header -->
        <div>
          <h1 class="text-3xl font-bold">Performance Dashboard</h1>
          <p class="mt-2 text-muted-foreground">
            Transparent track record of prediction accuracy and betting performance
          </p>
        </div>

        <!-- Overall Stats -->
        <Card>
          <CardHeader>
            <CardTitle>Overall Performance</CardTitle>
            <CardDescription>All-time prediction accuracy across all sports</CardDescription>
          </CardHeader>
          <CardContent>
            <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
              <div>
                <div class="text-sm text-muted-foreground">Total Predictions</div>
                <div class="text-2xl font-bold">{{ overall.total_predictions }}</div>
              </div>
              <div>
                <div class="text-sm text-muted-foreground">Win Record</div>
                <div class="text-2xl font-bold">{{ overall.win_record }}</div>
              </div>
              <div>
                <div class="text-sm text-muted-foreground">Winner Accuracy</div>
                <div class="text-2xl font-bold" :class="getAccuracyColor(overall.winner_accuracy)">
                  {{ overall.winner_accuracy }}%
                </div>
              </div>
              <div>
                <div class="text-sm text-muted-foreground">Avg Spread Error</div>
                <div class="text-2xl font-bold">{{ overall.avg_spread_error }} pts</div>
              </div>
              <div>
                <div class="text-sm text-muted-foreground">Avg Total Error</div>
                <div class="text-2xl font-bold">{{ overall.avg_total_error }} pts</div>
              </div>
            </div>
          </CardContent>
        </Card>

        <!-- ROI Tracking -->
        <Card>
          <CardHeader>
            <CardTitle>Return on Investment (ROI)</CardTitle>
            <CardDescription>
              Hypothetical returns if betting $100 on every prediction at -110 odds
            </CardDescription>
          </CardHeader>
          <CardContent>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
              <div>
                <div class="text-sm text-muted-foreground">Total Wagered</div>
                <div class="text-2xl font-bold">${{ roi.total_wagered.toLocaleString() }}</div>
              </div>
              <div>
                <div class="text-sm text-muted-foreground">Total Profit/Loss</div>
                <div class="text-2xl font-bold" :class="getROIColor(roi.total_profit)">
                  ${{ roi.total_profit.toLocaleString() }}
                </div>
              </div>
              <div>
                <div class="text-sm text-muted-foreground">ROI Percentage</div>
                <div class="text-2xl font-bold" :class="getROIColor(roi.roi_percentage)">
                  {{ roi.roi_percentage > 0 ? '+' : '' }}{{ roi.roi_percentage }}%
                </div>
              </div>
              <div>
                <div class="text-sm text-muted-foreground">Win Rate</div>
                <div class="text-2xl font-bold" :class="getAccuracyColor(roi.win_percentage)">
                  {{ roi.win_percentage }}%
                </div>
              </div>
            </div>
            <div class="mt-4 text-sm text-muted-foreground">
              <strong>Note:</strong> Break-even at -110 odds requires 52.38% win rate. ROI calculations assume standard $100 bets on each prediction.
            </div>
          </CardContent>
        </Card>

        <!-- Recent Performance (Last 30 Days) -->
        <Card>
          <CardHeader>
            <CardTitle>Last 30 Days</CardTitle>
            <CardDescription>Recent prediction performance</CardDescription>
          </CardHeader>
          <CardContent>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-4">
              <div>
                <div class="text-sm text-muted-foreground">Predictions</div>
                <div class="text-xl font-bold">{{ recent.overall.total_predictions }}</div>
              </div>
              <div>
                <div class="text-sm text-muted-foreground">Win Record</div>
                <div class="text-xl font-bold">{{ recent.overall.win_record }}</div>
              </div>
              <div>
                <div class="text-sm text-muted-foreground">Accuracy</div>
                <div class="text-xl font-bold" :class="getAccuracyColor(recent.overall.winner_accuracy)">
                  {{ recent.overall.winner_accuracy }}%
                </div>
              </div>
              <div>
                <div class="text-sm text-muted-foreground">30-Day ROI</div>
                <div class="text-xl font-bold" :class="getROIColor(recent.roi.roi_percentage)">
                  {{ recent.roi.roi_percentage > 0 ? '+' : '' }}{{ recent.roi.roi_percentage }}%
                </div>
              </div>
            </div>
          </CardContent>
        </Card>

        <!-- Performance by Sport -->
        <Card>
          <CardHeader>
            <CardTitle>Performance by Sport</CardTitle>
            <CardDescription>Breakdown of accuracy across all supported sports</CardDescription>
          </CardHeader>
          <CardContent>
            <div class="space-y-4">
              <div v-for="(stats, sport) in by_sport" :key="sport" class="border-b pb-4 last:border-0">
                <div class="flex items-center justify-between mb-2">
                  <h3 class="text-lg font-semibold">{{ stats.label }}</h3>
                  <Badge>{{ stats.win_record }}</Badge>
                </div>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                  <div>
                    <div class="text-xs text-muted-foreground">Total Graded</div>
                    <div class="text-lg font-semibold">{{ stats.total_graded }}</div>
                  </div>
                  <div>
                    <div class="text-xs text-muted-foreground">Winner Accuracy</div>
                    <div class="text-lg font-semibold" :class="getAccuracyColor(stats.winner_accuracy)">
                      {{ stats.winner_accuracy }}%
                    </div>
                  </div>
                  <div>
                    <div class="text-xs text-muted-foreground">Avg Spread Error</div>
                    <div class="text-lg font-semibold">{{ stats.avg_spread_error }} pts</div>
                  </div>
                  <div>
                    <div class="text-xs text-muted-foreground">Avg Total Error</div>
                    <div class="text-lg font-semibold">{{ stats.avg_total_error }} pts</div>
                  </div>
                </div>
              </div>
            </div>
          </CardContent>
        </Card>

        <!-- Season-to-Date Stats -->
        <Card>
          <CardHeader>
            <CardTitle>Season-to-Date Performance</CardTitle>
            <CardDescription>Current season performance by sport</CardDescription>
          </CardHeader>
          <CardContent>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
              <div v-for="(stats, sport) in season_to_date" :key="sport" class="border rounded-lg p-4">
                <h4 class="font-semibold mb-2">{{ stats.label }}</h4>
                <div class="space-y-2">
                  <div class="flex justify-between">
                    <span class="text-sm text-muted-foreground">Record:</span>
                    <span class="font-semibold">{{ stats.win_record }}</span>
                  </div>
                  <div class="flex justify-between">
                    <span class="text-sm text-muted-foreground">Accuracy:</span>
                    <span class="font-semibold" :class="getAccuracyColor(stats.winner_accuracy)">
                      {{ stats.winner_accuracy }}%
                    </span>
                  </div>
                  <div class="flex justify-between">
                    <span class="text-sm text-muted-foreground">Total:</span>
                    <span class="font-semibold">{{ stats.total_graded }}</span>
                  </div>
                </div>
              </div>
            </div>
          </CardContent>
        </Card>

        <!-- Methodology Transparency -->
        <Card>
          <CardHeader>
            <CardTitle>Our Methodology</CardTitle>
            <CardDescription>How we make predictions</CardDescription>
          </CardHeader>
          <CardContent class="space-y-3">
            <p class="text-sm">
              All predictions are generated using advanced Elo rating systems and efficiency metrics. We track every prediction and show both successful and unsuccessful outcomes for complete transparency.
            </p>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
              <div class="space-y-2">
                <h4 class="font-semibold text-sm">What We Track:</h4>
                <ul class="text-sm space-y-1 text-muted-foreground list-disc list-inside">
                  <li>Winner prediction accuracy</li>
                  <li>Spread prediction error</li>
                  <li>Total points prediction error</li>
                  <li>Hypothetical betting ROI</li>
                </ul>
              </div>
              <div class="space-y-2">
                <h4 class="font-semibold text-sm">Break-Even Benchmarks:</h4>
                <ul class="text-sm space-y-1 text-muted-foreground list-disc list-inside">
                  <li>52.38% win rate needed at -110 odds</li>
                  <li>55%+ win rate is excellent</li>
                  <li>50-52% is break-even territory</li>
                  <li>Below 50% is unprofitable</li>
                </ul>
              </div>
            </div>
          </CardContent>
        </Card>

        <!-- Disclaimer -->
        <div class="text-sm text-muted-foreground text-center p-4 border rounded-lg">
          <strong>Important Disclaimer:</strong> These predictions are for entertainment purposes only. Past performance does not guarantee future results. ROI calculations are hypothetical and do not reflect actual betting outcomes. Always gamble responsibly.
        </div>
      </div>
    </div>
  </AppLayout>
</template>
