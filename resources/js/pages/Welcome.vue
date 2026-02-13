<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import { dashboard, login, register, performance, terms, privacy, responsibleGambling } from '@/routes'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'

interface OverallStats {
  total_predictions: number
  winner_accuracy: number
  avg_spread_error: number
  avg_total_error: number
  win_record: string
}

interface RecentPerformance {
  overall: OverallStats
  roi: {
    total_bets: number
    total_wins: number
    total_losses: number
    total_wagered: number
    total_profit: number
    roi_percentage: number
    win_percentage: number
  }
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

interface PerformanceData {
  overall: OverallStats
  recent: RecentPerformance
  roi: ROIStats
}

const props = withDefaults(
  defineProps<{
    canRegister: boolean
    performance: PerformanceData
  }>(),
  {
    canRegister: true,
  }
)

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
  <Head title="Sports Prediction Analytics" />

  <div class="min-h-screen bg-gradient-to-br from-gray-50 to-gray-100 dark:from-gray-900 dark:to-gray-800">
    <!-- Navigation -->
    <nav class="border-b bg-white/80 backdrop-blur-sm dark:bg-gray-900/80 dark:border-gray-800">
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between items-center h-16">
          <div class="flex items-center">
            <h1 class="text-xl font-bold bg-gradient-to-r from-blue-600 to-purple-600 bg-clip-text text-transparent">
              PickSports
            </h1>
          </div>
          <div class="flex items-center gap-4">
            <Link
              :href="performance()"
              class="text-sm text-gray-700 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white"
            >
              Performance
            </Link>
            <template v-if="$page.props.auth.user">
              <Link :href="dashboard()">
                <Button variant="default">Dashboard</Button>
              </Link>
            </template>
            <template v-else>
              <Link :href="login()">
                <Button variant="ghost">Log in</Button>
              </Link>
              <Link v-if="canRegister" :href="register()">
                <Button>Get Started</Button>
              </Link>
            </template>
          </div>
        </div>
      </div>
    </nav>

    <!-- Hero Section -->
    <section class="py-20 px-4 sm:px-6 lg:px-8">
      <div class="max-w-7xl mx-auto text-center">
        <h2 class="text-4xl sm:text-5xl lg:text-6xl font-bold mb-6">
          <span class="bg-gradient-to-r from-blue-600 via-purple-600 to-pink-600 bg-clip-text text-transparent">
            Data-Driven Sports Predictions
          </span>
        </h2>
        <p class="text-xl text-gray-600 dark:text-gray-300 mb-8 max-w-3xl mx-auto">
          Advanced Elo ratings and efficiency metrics across 7 major sports.
          Transparent track record. No hype, just data.
        </p>
        <div class="flex gap-4 justify-center">
          <Link v-if="!$page.props.auth.user" :href="register()">
            <Button size="lg" class="text-lg px-8">
              Start Free Trial
            </Button>
          </Link>
          <Link :href="performance()">
            <Button size="lg" variant="outline" class="text-lg px-8">
              View Track Record
            </Button>
          </Link>
        </div>
      </div>
    </section>

    <!-- Performance Stats Cards -->
    <section class="py-12 px-4 sm:px-6 lg:px-8">
      <div class="max-w-7xl mx-auto">
        <div class="text-center mb-12">
          <h3 class="text-3xl font-bold mb-2">Our Live Performance</h3>
          <p class="text-gray-600 dark:text-gray-400">
            Real-time accuracy metrics. We show the good and the bad.
          </p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
          <!-- Overall Accuracy Card -->
          <Card>
            <CardHeader>
              <CardTitle class="text-sm font-medium text-muted-foreground">Overall Accuracy</CardTitle>
            </CardHeader>
            <CardContent>
              <div class="text-3xl font-bold mb-1" :class="getAccuracyColor(performance.overall.winner_accuracy)">
                {{ performance.overall.winner_accuracy }}%
              </div>
              <p class="text-sm text-muted-foreground">{{ performance.overall.win_record }}</p>
              <p class="text-xs text-muted-foreground mt-2">
                {{ performance.overall.total_predictions.toLocaleString() }} predictions tracked
              </p>
            </CardContent>
          </Card>

          <!-- 30-Day Performance Card -->
          <Card>
            <CardHeader>
              <CardTitle class="text-sm font-medium text-muted-foreground">Last 30 Days</CardTitle>
            </CardHeader>
            <CardContent>
              <div class="text-3xl font-bold mb-1" :class="getAccuracyColor(performance.recent.overall.winner_accuracy)">
                {{ performance.recent.overall.winner_accuracy }}%
              </div>
              <p class="text-sm text-muted-foreground">{{ performance.recent.overall.win_record }}</p>
              <Badge class="mt-2" :variant="performance.recent.overall.winner_accuracy >= 52 ? 'default' : 'secondary'">
                {{ performance.recent.overall.winner_accuracy >= 52 ? 'Profitable' : 'Break-Even' }}
              </Badge>
            </CardContent>
          </Card>

          <!-- ROI Card -->
          <Card>
            <CardHeader>
              <CardTitle class="text-sm font-medium text-muted-foreground">Return on Investment</CardTitle>
            </CardHeader>
            <CardContent>
              <div class="text-3xl font-bold mb-1" :class="getROIColor(performance.roi.roi_percentage)">
                {{ performance.roi.roi_percentage > 0 ? '+' : '' }}{{ performance.roi.roi_percentage }}%
              </div>
              <p class="text-sm text-muted-foreground">
                ${{ performance.roi.total_profit.toLocaleString() }} profit
              </p>
              <p class="text-xs text-muted-foreground mt-2">
                On ${{ performance.roi.total_wagered.toLocaleString() }} wagered
              </p>
            </CardContent>
          </Card>

          <!-- Spread Accuracy Card -->
          <Card>
            <CardHeader>
              <CardTitle class="text-sm font-medium text-muted-foreground">Spread Accuracy</CardTitle>
            </CardHeader>
            <CardContent>
              <div class="text-3xl font-bold mb-1">
                {{ performance.overall.avg_spread_error }}
              </div>
              <p class="text-sm text-muted-foreground">Avg point error</p>
              <p class="text-xs text-muted-foreground mt-2">
                Lower is better
              </p>
            </CardContent>
          </Card>
        </div>

        <div class="text-center">
          <Link :href="performance()">
            <Button variant="outline" size="lg">
              View Full Performance Dashboard →
            </Button>
          </Link>
        </div>
      </div>
    </section>

    <!-- Features Section -->
    <section class="py-20 px-4 sm:px-6 lg:px-8 bg-white dark:bg-gray-900">
      <div class="max-w-7xl mx-auto">
        <div class="text-center mb-16">
          <h3 class="text-3xl font-bold mb-2">Why Choose PickSports?</h3>
          <p class="text-gray-600 dark:text-gray-400">
            Advanced analytics meets transparent performance tracking
          </p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
          <Card>
            <CardHeader>
              <CardTitle>7 Major Sports</CardTitle>
              <CardDescription>
                NFL, NBA, MLB, College Basketball (Men's & Women's), WNBA, College Football
              </CardDescription>
            </CardHeader>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle>Advanced Elo Ratings</CardTitle>
              <CardDescription>
                Dynamic team and player ratings that update after every game
              </CardDescription>
            </CardHeader>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle>Complete Transparency</CardTitle>
              <CardDescription>
                Every prediction tracked and graded. We show wins and losses.
              </CardDescription>
            </CardHeader>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle>Real-Time Updates</CardTitle>
              <CardDescription>
                Live game predictions and probability updates during games
              </CardDescription>
            </CardHeader>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle>Betting Value Analysis</CardTitle>
              <CardDescription>
                Identify +EV betting opportunities with our edge calculator
              </CardDescription>
            </CardHeader>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle>No Hype, Just Data</CardTitle>
              <CardDescription>
                Evidence-based predictions. Break-even benchmark: 52.38% at -110 odds
              </CardDescription>
            </CardHeader>
          </Card>
        </div>
      </div>
    </section>

    <!-- CTA Section -->
    <section class="py-20 px-4 sm:px-6 lg:px-8">
      <div class="max-w-4xl mx-auto text-center">
        <h3 class="text-3xl font-bold mb-4">Ready to Get Started?</h3>
        <p class="text-xl text-gray-600 dark:text-gray-300 mb-8">
          Join users making data-driven betting decisions across all major sports
        </p>
        <div class="flex gap-4 justify-center">
          <Link v-if="!$page.props.auth.user" :href="register()">
            <Button size="lg" class="text-lg px-8">
              Start Free Trial
            </Button>
          </Link>
          <Link v-else :href="dashboard()">
            <Button size="lg" class="text-lg px-8">
              Go to Dashboard
            </Button>
          </Link>
        </div>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-4">
          No credit card required. Cancel anytime.
        </p>
      </div>
    </section>

    <!-- Footer -->
    <footer class="border-t bg-white dark:bg-gray-900 dark:border-gray-800">
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <div class="text-center text-sm text-gray-600 dark:text-gray-400">
          <p class="mb-4">
            <strong>Disclaimer:</strong> For entertainment purposes only. Gambling involves risk.
            Please bet responsibly.
          </p>
          <div class="flex flex-wrap justify-center gap-x-6 gap-y-2 mb-4">
            <Link :href="terms()" class="hover:text-gray-900 dark:hover:text-white underline">
              Terms of Service
            </Link>
            <Link :href="privacy()" class="hover:text-gray-900 dark:hover:text-white underline">
              Privacy Policy
            </Link>
            <Link :href="responsibleGambling()" class="hover:text-gray-900 dark:hover:text-white underline">
              Responsible Gambling
            </Link>
          </div>
          <p>&copy; 2026 PickSports. All rights reserved.</p>
        </div>
      </div>
    </footer>
  </div>
</template>
