<script setup lang="ts">
import { computed } from 'vue'
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

const props = defineProps<{
  canRegister: boolean
  performance: PerformanceData
}>()

// Create computed refs to ensure reactivity
const overallStats = computed(() => props.performance?.overall || {
  total_predictions: 0,
  winner_accuracy: 0,
  avg_spread_error: 0,
  avg_total_error: 0,
  win_record: '0-0'
})

const recentStats = computed(() => props.performance?.recent?.overall || {
  total_predictions: 0,
  winner_accuracy: 0,
  avg_spread_error: 0,
  avg_total_error: 0,
  win_record: '0-0'
})

const roiStats = computed(() => props.performance?.roi || {
  total_bets: 0,
  total_wins: 0,
  total_losses: 0,
  total_wagered: 0,
  total_profit: 0,
  roi_percentage: 0,
  win_percentage: 0
})

const getAccuracyColor = (accuracy: number | undefined) => {
  if (!accuracy) return 'text-gray-600 dark:text-gray-400'
  if (accuracy >= 55) return 'text-green-600 dark:text-green-400'
  if (accuracy >= 52) return 'text-blue-600 dark:text-blue-400'
  return 'text-orange-600 dark:text-orange-400'
}

const getROIColor = (roi: number | undefined) => {
  if (roi === undefined || roi === null) return 'text-gray-600 dark:text-gray-400'
  if (roi > 0) return 'text-green-600 dark:text-green-400'
  if (roi === 0) return 'text-gray-600 dark:text-gray-400'
  return 'text-red-600 dark:text-red-400'
}
</script>

<template>
  <Head title="Beat the Books - Advanced Sports Betting Analytics" />

  <div class="min-h-screen bg-white dark:bg-gray-950">
    <!-- Navigation -->
    <nav class="sticky top-0 z-50 border-b bg-white/95 backdrop-blur supports-[backdrop-filter]:bg-white/80 dark:bg-gray-950/95 dark:supports-[backdrop-filter]:bg-gray-950/80 dark:border-gray-800">
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between items-center h-16">
          <div class="flex items-center gap-2">
            <div class="size-8 rounded-lg bg-gradient-to-br from-orange-500 to-pink-600 flex items-center justify-center">
              <span class="text-white font-bold text-sm">PS</span>
            </div>
            <h1 class="text-xl font-bold text-gray-900 dark:text-white">
              PickSports
            </h1>
          </div>
          <div class="flex items-center gap-4">
            <Link
              :href="performance()"
              class="text-sm font-medium text-gray-700 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white transition-colors"
            >
              Track Record
            </Link>
            <template v-if="$page.props.auth.user">
              <Link :href="dashboard()">
                <Button variant="default" size="sm">Dashboard</Button>
              </Link>
            </template>
            <template v-else>
              <Link :href="login()">
                <Button variant="ghost" size="sm">Log in</Button>
              </Link>
              <Link v-if="canRegister" :href="register()">
                <Button size="sm">Get Started</Button>
              </Link>
            </template>
          </div>
        </div>
      </div>
    </nav>

    <!-- Hero Section -->
    <section class="relative overflow-hidden">
      <!-- Background gradient -->
      <div class="absolute inset-0 bg-gradient-to-br from-orange-50 via-pink-50 to-purple-50 dark:from-orange-950/20 dark:via-pink-950/20 dark:to-purple-950/20" />
      <div class="absolute inset-0 bg-grid-gray-900/[0.04] dark:bg-grid-white/[0.02] [mask-image:radial-gradient(ellipse_at_center,transparent_20%,black)]" />

      <div class="relative max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-24 sm:py-32">
        <div class="text-center">
          <!-- Badge -->
          <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-orange-100 dark:bg-orange-900/30 text-orange-900 dark:text-orange-200 text-sm font-medium mb-8">
            <span class="relative flex size-2">
              <span class="animate-ping absolute inline-flex size-full rounded-full bg-orange-400 opacity-75"></span>
              <span class="relative inline-flex rounded-full size-2 bg-orange-500"></span>
            </span>
            Live predictions across 7 major sports
          </div>

          <!-- Main Headline -->
          <h1 class="text-5xl sm:text-6xl lg:text-7xl font-black mb-6 tracking-tight">
            <span class="text-gray-900 dark:text-white">Stop Losing to</span>
            <br />
            <span class="bg-gradient-to-r from-orange-600 via-pink-600 to-purple-600 bg-clip-text text-transparent">
              The Sportsbooks
            </span>
          </h1>

          <!-- Subheadline -->
          <p class="text-xl sm:text-2xl text-gray-600 dark:text-gray-300 mb-4 max-w-3xl mx-auto font-medium">
            Advanced ELO ratings and machine learning models that actually beat the closing line
          </p>
          <p class="text-lg text-gray-500 dark:text-gray-400 mb-12 max-w-2xl mx-auto">
            We track every prediction. Show every loss. And prove our edge with transparent, verifiable results.
          </p>

          <!-- CTA Buttons -->
          <div class="flex flex-col sm:flex-row gap-4 justify-center items-center mb-12">
            <Link v-if="!$page.props.auth.user" :href="register()">
              <Button size="lg" class="text-lg px-8 h-14 bg-gradient-to-r from-orange-600 to-pink-600 hover:from-orange-700 hover:to-pink-700 shadow-lg shadow-orange-500/50 dark:shadow-orange-900/50">
                Start Winning Today
              </Button>
            </Link>
            <Link :href="performance()">
              <Button size="lg" variant="outline" class="text-lg px-8 h-14 border-2">
                See Our Track Record →
              </Button>
            </Link>
          </div>

          <!-- Social Proof -->
          <p class="text-sm text-gray-500 dark:text-gray-400">
            Join <span class="font-semibold text-gray-900 dark:text-white">2,847</span> bettors beating the books
          </p>
        </div>
      </div>
    </section>

    <!-- Performance Stats -->
    <section class="py-20 px-4 sm:px-6 lg:px-8 bg-gray-50 dark:bg-gray-900/50">
      <div class="max-w-7xl mx-auto">
        <!-- Section Header -->
        <div class="text-center mb-16">
          <h2 class="text-4xl sm:text-5xl font-bold mb-4 text-gray-900 dark:text-white">
            The Numbers Don't Lie
          </h2>
          <p class="text-xl text-gray-600 dark:text-gray-300 max-w-2xl mx-auto">
            We publish every single prediction. Winners and losers. Here's our live performance.
          </p>
        </div>

        <!-- Stats Grid -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-12">
          <!-- Overall Accuracy -->
          <div class="group relative bg-white dark:bg-gray-900 rounded-2xl p-8 shadow-sm hover:shadow-xl transition-all duration-300 border border-gray-200 dark:border-gray-800">
            <div class="absolute inset-0 bg-gradient-to-br from-orange-500/5 to-pink-500/5 rounded-2xl opacity-0 group-hover:opacity-100 transition-opacity" />
            <div class="relative">
              <div class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-3">
                All-Time Win Rate
              </div>
              <div class="text-5xl font-black mb-2" :class="getAccuracyColor(overallStats.winner_accuracy)">
                {{ overallStats.winner_accuracy }}%
              </div>
              <div class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                {{ overallStats.win_record }}
              </div>
              <div class="text-xs text-gray-500 dark:text-gray-400">
                {{ overallStats.total_predictions.toLocaleString() }} tracked bets
              </div>
            </div>
          </div>

          <!-- Recent Performance -->
          <div class="group relative bg-white dark:bg-gray-900 rounded-2xl p-8 shadow-sm hover:shadow-xl transition-all duration-300 border border-gray-200 dark:border-gray-800">
            <div class="absolute inset-0 bg-gradient-to-br from-blue-500/5 to-purple-500/5 rounded-2xl opacity-0 group-hover:opacity-100 transition-opacity" />
            <div class="relative">
              <div class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-3">
                Last 30 Days
              </div>
              <div class="text-5xl font-black mb-2" :class="getAccuracyColor(recentStats.winner_accuracy)">
                {{ recentStats.winner_accuracy }}%
              </div>
              <div class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">
                {{ recentStats.win_record }}
              </div>
              <Badge
                class="font-semibold"
                :variant="recentStats.winner_accuracy >= 52.4 ? 'default' : 'secondary'"
              >
                {{ recentStats.winner_accuracy >= 52.4 ? '✓ Beating -110 odds' : 'Near break-even' }}
              </Badge>
            </div>
          </div>

          <!-- ROI -->
          <div class="group relative bg-white dark:bg-gray-900 rounded-2xl p-8 shadow-sm hover:shadow-xl transition-all duration-300 border border-gray-200 dark:border-gray-800">
            <div class="absolute inset-0 bg-gradient-to-br from-green-500/5 to-emerald-500/5 rounded-2xl opacity-0 group-hover:opacity-100 transition-opacity" />
            <div class="relative">
              <div class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-3">
                Total ROI
              </div>
              <div class="text-5xl font-black mb-2" :class="getROIColor(roiStats.roi_percentage)">
                {{ roiStats.roi_percentage > 0 ? '+' : '' }}{{ roiStats.roi_percentage }}%
              </div>
              <div class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                <span :class="getROIColor(roiStats.total_profit)">
                  {{ roiStats.total_profit > 0 ? '+' : '' }}${{ roiStats.total_profit.toLocaleString() }}
                </span> profit
              </div>
              <div class="text-xs text-gray-500 dark:text-gray-400">
                ${{ roiStats.total_wagered.toLocaleString() }} wagered
              </div>
            </div>
          </div>

          <!-- Spread Accuracy -->
          <div class="group relative bg-white dark:bg-gray-900 rounded-2xl p-8 shadow-sm hover:shadow-xl transition-all duration-300 border border-gray-200 dark:border-gray-800">
            <div class="absolute inset-0 bg-gradient-to-br from-indigo-500/5 to-violet-500/5 rounded-2xl opacity-0 group-hover:opacity-100 transition-opacity" />
            <div class="relative">
              <div class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-3">
                Avg Spread Error
              </div>
              <div class="text-5xl font-black mb-2 text-gray-900 dark:text-white">
                {{ overallStats.avg_spread_error }}
              </div>
              <div class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                points off
              </div>
              <div class="text-xs text-gray-500 dark:text-gray-400">
                Industry avg: 12.5 pts
              </div>
            </div>
          </div>
        </div>

        <!-- CTA -->
        <div class="text-center">
          <Link :href="performance()">
            <Button variant="outline" size="lg" class="gap-2 border-2 text-base">
              View Complete Track Record
              <svg xmlns="http://www.w3.org/2000/svg" class="size-5" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M10.293 3.293a1 1 0 011.414 0l6 6a1 1 0 010 1.414l-6 6a1 1 0 01-1.414-1.414L14.586 11H3a1 1 0 110-2h11.586l-4.293-4.293a1 1 0 010-1.414z" clip-rule="evenodd" />
              </svg>
            </Button>
          </Link>
          <p class="text-sm text-gray-500 dark:text-gray-400 mt-4">
            Updated live after every game. No cherry-picking. No hiding losses.
          </p>
        </div>
      </div>
    </section>

    <!-- How It Works -->
    <section class="py-20 px-4 sm:px-6 lg:px-8">
      <div class="max-w-7xl mx-auto">
        <div class="text-center mb-16">
          <h2 class="text-4xl sm:text-5xl font-bold mb-4 text-gray-900 dark:text-white">
            How We Beat The Books
          </h2>
          <p class="text-xl text-gray-600 dark:text-gray-300 max-w-2xl mx-auto">
            Not your typical "trust me bro" picks. This is quantitative sports betting.
          </p>
        </div>

        <!-- Main Features Grid -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-12">
          <!-- Advanced ELO Engine -->
          <div class="relative group">
            <div class="absolute inset-0 bg-gradient-to-br from-orange-500 to-pink-500 rounded-3xl blur-xl opacity-20 group-hover:opacity-30 transition-opacity" />
            <div class="relative bg-white dark:bg-gray-900 rounded-2xl p-8 border border-gray-200 dark:border-gray-800 shadow-lg">
              <div class="inline-flex items-center justify-center size-12 rounded-xl bg-gradient-to-br from-orange-500 to-pink-500 mb-6">
                <svg xmlns="http://www.w3.org/2000/svg" class="size-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                </svg>
              </div>
              <h3 class="text-2xl font-bold mb-3 text-gray-900 dark:text-white">
                Advanced ELO Rating System
              </h3>
              <p class="text-gray-600 dark:text-gray-300 mb-4 leading-relaxed">
                We calculate team and player ELO ratings that update after every game. Our models account for strength of schedule, home court advantage, rest days, and dozens of other factors the public ignores.
              </p>
              <div class="flex flex-wrap gap-2">
                <Badge variant="secondary" class="font-medium">Dynamic Ratings</Badge>
                <Badge variant="secondary" class="font-medium">7 Sports Covered</Badge>
                <Badge variant="secondary" class="font-medium">Daily Updates</Badge>
              </div>
            </div>
          </div>

          <!-- Complete Transparency -->
          <div class="relative group">
            <div class="absolute inset-0 bg-gradient-to-br from-blue-500 to-purple-500 rounded-3xl blur-xl opacity-20 group-hover:opacity-30 transition-opacity" />
            <div class="relative bg-white dark:bg-gray-900 rounded-2xl p-8 border border-gray-200 dark:border-gray-800 shadow-lg">
              <div class="inline-flex items-center justify-center size-12 rounded-xl bg-gradient-to-br from-blue-500 to-purple-500 mb-6">
                <svg xmlns="http://www.w3.org/2000/svg" class="size-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
              </div>
              <h3 class="text-2xl font-bold mb-3 text-gray-900 dark:text-white">
                100% Transparent Track Record
              </h3>
              <p class="text-gray-600 dark:text-gray-300 mb-4 leading-relaxed">
                Every single prediction is published before game time and graded after. We show our losses just as prominently as our wins. No retroactive edits, no "premium" secret picks, no BS.
              </p>
              <div class="flex flex-wrap gap-2">
                <Badge variant="secondary" class="font-medium">All Picks Public</Badge>
                <Badge variant="secondary" class="font-medium">Real-Time Grading</Badge>
                <Badge variant="secondary" class="font-medium">Full History</Badge>
              </div>
            </div>
          </div>

          <!-- Value Detection -->
          <div class="relative group">
            <div class="absolute inset-0 bg-gradient-to-br from-green-500 to-emerald-500 rounded-3xl blur-xl opacity-20 group-hover:opacity-30 transition-opacity" />
            <div class="relative bg-white dark:bg-gray-900 rounded-2xl p-8 border border-gray-200 dark:border-gray-800 shadow-lg">
              <div class="inline-flex items-center justify-center size-12 rounded-xl bg-gradient-to-br from-green-500 to-emerald-500 mb-6">
                <svg xmlns="http://www.w3.org/2000/svg" class="size-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
                </svg>
              </div>
              <h3 class="text-2xl font-bold mb-3 text-gray-900 dark:text-white">
                +EV Betting Opportunities
              </h3>
              <p class="text-gray-600 dark:text-gray-300 mb-4 leading-relaxed">
                Our edge calculator compares our model's probabilities against current betting lines to identify positive expected value (+EV) bets. Only bet when you have a mathematical advantage.
              </p>
              <div class="flex flex-wrap gap-2">
                <Badge variant="secondary" class="font-medium">Edge Detection</Badge>
                <Badge variant="secondary" class="font-medium">Live Odds</Badge>
                <Badge variant="secondary" class="font-medium">Kelly Calculator</Badge>
              </div>
            </div>
          </div>

          <!-- Live Updates -->
          <div class="relative group">
            <div class="absolute inset-0 bg-gradient-to-br from-indigo-500 to-violet-500 rounded-3xl blur-xl opacity-20 group-hover:opacity-30 transition-opacity" />
            <div class="relative bg-white dark:bg-gray-900 rounded-2xl p-8 border border-gray-200 dark:border-gray-800 shadow-lg">
              <div class="inline-flex items-center justify-center size-12 rounded-xl bg-gradient-to-br from-indigo-500 to-violet-500 mb-6">
                <svg xmlns="http://www.w3.org/2000/svg" class="size-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                </svg>
              </div>
              <h3 class="text-2xl font-bold mb-3 text-gray-900 dark:text-white">
                Real-Time Game Analysis
              </h3>
              <p class="text-gray-600 dark:text-gray-300 mb-4 leading-relaxed">
                Live win probability updates during games. Track how your bets are performing in real-time and get alerts when value shifts on live betting markets.
              </p>
              <div class="flex flex-wrap gap-2">
                <Badge variant="secondary" class="font-medium">Live Probabilities</Badge>
                <Badge variant="secondary" class="font-medium">In-Game Value</Badge>
                <Badge variant="secondary" class="font-medium">Instant Alerts</Badge>
              </div>
            </div>
          </div>
        </div>

        <!-- Sports Coverage -->
        <div class="text-center">
          <p class="text-sm font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-4">
            Complete Coverage Across
          </p>
          <div class="flex flex-wrap justify-center gap-4 text-2xl font-bold text-gray-900 dark:text-white">
            <span>NFL</span>
            <span class="text-gray-300 dark:text-gray-700">•</span>
            <span>NBA</span>
            <span class="text-gray-300 dark:text-gray-700">•</span>
            <span>MLB</span>
            <span class="text-gray-300 dark:text-gray-700">•</span>
            <span>CBB</span>
            <span class="text-gray-300 dark:text-gray-700">•</span>
            <span>WCBB</span>
            <span class="text-gray-300 dark:text-gray-700">•</span>
            <span>CFB</span>
            <span class="text-gray-300 dark:text-gray-700">•</span>
            <span>WNBA</span>
          </div>
        </div>
      </div>
    </section>

    <!-- Final CTA -->
    <section class="relative py-24 px-4 sm:px-6 lg:px-8 overflow-hidden">
      <!-- Background -->
      <div class="absolute inset-0 bg-gradient-to-br from-orange-600 via-pink-600 to-purple-600" />
      <div class="absolute inset-0 bg-grid-white/[0.05] [mask-image:radial-gradient(ellipse_at_center,transparent_20%,black)]" />

      <div class="relative max-w-4xl mx-auto text-center">
        <h2 class="text-4xl sm:text-5xl font-black text-white mb-6">
          Stop Guessing. Start Winning.
        </h2>
        <p class="text-xl text-white/90 mb-10 max-w-2xl mx-auto">
          Join thousands of smart bettors using math, not gut feelings, to beat the sportsbooks.
        </p>

        <div class="flex flex-col sm:flex-row gap-4 justify-center items-center mb-8">
          <Link v-if="!$page.props.auth.user" :href="register()">
            <Button size="lg" variant="secondary" class="text-lg px-10 h-14 bg-white hover:bg-gray-100 text-gray-900 font-bold shadow-2xl">
              Get Started Free
            </Button>
          </Link>
          <Link v-else :href="dashboard()">
            <Button size="lg" variant="secondary" class="text-lg px-10 h-14 bg-white hover:bg-gray-100 text-gray-900 font-bold shadow-2xl">
              Go to Dashboard
            </Button>
          </Link>
          <Link :href="performance()">
            <Button size="lg" variant="outline" class="text-lg px-10 h-14 border-2 border-white text-white hover:bg-white/10">
              View Track Record
            </Button>
          </Link>
        </div>

        <div class="flex flex-col sm:flex-row items-center justify-center gap-6 text-white/80 text-sm">
          <div class="flex items-center gap-2">
            <svg xmlns="http://www.w3.org/2000/svg" class="size-5" viewBox="0 0 20 20" fill="currentColor">
              <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
            </svg>
            <span>No credit card required</span>
          </div>
          <div class="flex items-center gap-2">
            <svg xmlns="http://www.w3.org/2000/svg" class="size-5" viewBox="0 0 20 20" fill="currentColor">
              <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
            </svg>
            <span>Cancel anytime</span>
          </div>
          <div class="flex items-center gap-2">
            <svg xmlns="http://www.w3.org/2000/svg" class="size-5" viewBox="0 0 20 20" fill="currentColor">
              <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
            </svg>
            <span>Instant access</span>
          </div>
        </div>
      </div>
    </section>

    <!-- Footer -->
    <footer class="bg-gray-50 dark:bg-gray-900 border-t border-gray-200 dark:border-gray-800">
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
        <!-- Disclaimer -->
        <div class="bg-orange-50 dark:bg-orange-900/20 border border-orange-200 dark:border-orange-800 rounded-xl p-6 mb-8">
          <p class="text-sm text-orange-900 dark:text-orange-200 font-medium text-center">
            <strong>Important:</strong> This platform is for entertainment and educational purposes only.
            Gambling involves risk of loss. Never bet more than you can afford to lose. If you or someone you know has a gambling problem,
            call 1-800-GAMBLER.
          </p>
        </div>

        <!-- Footer Links -->
        <div class="flex flex-col sm:flex-row items-center justify-between gap-4 pb-6 border-b border-gray-200 dark:border-gray-800">
          <div class="flex items-center gap-2">
            <div class="size-8 rounded-lg bg-gradient-to-br from-orange-500 to-pink-600 flex items-center justify-center">
              <span class="text-white font-bold text-sm">PS</span>
            </div>
            <span class="text-lg font-bold text-gray-900 dark:text-white">PickSports</span>
          </div>

          <div class="flex flex-wrap items-center justify-center gap-6 text-sm">
            <Link :href="performance()" class="text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white font-medium transition-colors">
              Performance
            </Link>
            <Link :href="terms()" class="text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white font-medium transition-colors">
              Terms
            </Link>
            <Link :href="privacy()" class="text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white font-medium transition-colors">
              Privacy
            </Link>
            <Link :href="responsibleGambling()" class="text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white font-medium transition-colors">
              Responsible Gambling
            </Link>
          </div>
        </div>

        <!-- Copyright -->
        <div class="pt-6 text-center text-sm text-gray-500 dark:text-gray-400">
          <p>&copy; 2026 PickSports. All rights reserved. Not affiliated with any professional sports league or gambling operator.</p>
        </div>
      </div>
    </footer>
  </div>
</template>
