<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import { computed } from 'vue'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { dashboard, login, register, performance as performanceRoute, terms, privacy, responsibleGambling } from '@/routes'

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
  <Head title="Beat the Books - Advanced Sports Betting Analytics">
    <meta head-key="description" name="description" content="PickSports provides transparent, data-driven sports predictions across major leagues with verified performance and ROI." />
    <meta head-key="og:title" property="og:title" content="Beat the Books - Advanced Sports Betting Analytics" />
    <meta head-key="og:description" property="og:description" content="Data-driven sports betting analytics, transparent results, and live predictions across major sports." />
    <meta head-key="twitter:title" name="twitter:title" content="Beat the Books - Advanced Sports Betting Analytics" />
    <meta head-key="twitter:description" name="twitter:description" content="Data-driven sports betting analytics, transparent results, and live predictions across major sports." />
  </Head>

  <div class="min-h-screen bg-background text-foreground">
    <!-- Navigation -->
    <nav class="sticky top-0 z-50 border-b border-border/70 bg-background/95">
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between items-center h-18">
          <div class="flex items-center gap-3">
            <div class="size-8 rounded-lg bg-foreground flex items-center justify-center">
              <span class="text-background font-bold text-sm">PS</span>
            </div>
            <h1 class="text-lg font-semibold tracking-tight">
              PickSports
            </h1>
          </div>
          <div class="flex items-center gap-4">
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
    <section class="relative overflow-hidden border-b border-border/50">
      <!-- Background gradient -->
      <div class="absolute inset-0 bg-gradient-to-b from-sky-50/70 via-background to-background dark:from-sky-950/20 dark:via-background dark:to-background" />

      <div class="relative max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-20 sm:py-28">
        <div class="text-center">
          <!-- Badge -->
          <div class="ui-chip inline-flex items-center gap-2 mb-7 text-foreground/85">
            Live predictions across 7 major sports
          </div>

          <!-- Main Headline -->
          <h1 class="text-5xl sm:text-6xl lg:text-7xl font-semibold mb-5 tracking-tight text-balance">
            <span>Stop Losing to</span>
            <br />
            <span class="bg-gradient-to-r from-sky-600 via-blue-600 to-cyan-600 bg-clip-text text-transparent">
              The Sportsbooks
            </span>
          </h1>

          <!-- Subheadline -->
          <p class="text-xl sm:text-2xl text-muted-foreground mb-3 max-w-3xl mx-auto font-medium text-balance">
            Advanced ELO ratings and machine learning models built to beat closing lines
          </p>
          <p class="text-lg text-muted-foreground mb-10 max-w-2xl mx-auto text-balance">
            Every prediction is tracked, graded, and published in public. No cherry-picking. No hidden losses.
          </p>

          <!-- CTA Buttons -->
          <div class="flex flex-col sm:flex-row gap-3 justify-center items-center mb-10">
            <Link v-if="!$page.props.auth.user" :href="register()">
              <Button size="lg" class="text-base px-8">
                Start Free
              </Button>
            </Link>
            <Link v-if="!$page.props.auth.user" :href="login()">
              <Button size="lg" variant="secondary" class="text-base px-8">
                View Dashboard Preview
              </Button>
            </Link>
          </div>

          <!-- Social Proof -->
          <p class="text-sm text-muted-foreground">
            Trusted by <span class="font-semibold text-foreground">2,847</span> active bettors
          </p>
        </div>
      </div>
    </section>

    <!-- Performance Stats -->
    <section class="py-18 px-4 sm:px-6 lg:px-8 bg-background/70">
      <div class="max-w-7xl mx-auto">
        <!-- Section Header -->
        <div class="text-center mb-14">
          <h2 class="text-4xl sm:text-5xl font-semibold tracking-tight mb-4 text-balance">
            Transparent Performance
          </h2>
          <p class="text-xl text-muted-foreground max-w-2xl mx-auto">
            Every pick is public before kickoff or tipoff. Here is the live record.
          </p>
        </div>

        <!-- Stats Grid -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-12">
          <!-- Overall Accuracy -->
          <div class="ui-surface group relative p-8 transition-all duration-300 hover:shadow-md">
            <div class="absolute inset-0 bg-gradient-to-br from-orange-500/5 to-pink-500/5 rounded-2xl opacity-0 group-hover:opacity-100 transition-opacity" />
            <div class="relative">
              <div class="text-xs font-semibold text-muted-foreground uppercase tracking-wider mb-3">
                All-Time Win Rate
              </div>
              <div class="text-5xl font-semibold mb-2" :class="getAccuracyColor(overallStats.winner_accuracy)">
                {{ overallStats.winner_accuracy }}%
              </div>
              <div class="text-sm font-medium text-foreground/90 mb-1">
                {{ overallStats.win_record }}
              </div>
              <div class="text-xs text-muted-foreground">
                {{ overallStats.total_predictions.toLocaleString() }} tracked bets
              </div>
            </div>
          </div>

          <!-- Recent Performance -->
          <div class="ui-surface group relative p-8 transition-all duration-300 hover:shadow-md">
            <div class="absolute inset-0 bg-gradient-to-br from-blue-500/5 to-purple-500/5 rounded-2xl opacity-0 group-hover:opacity-100 transition-opacity" />
            <div class="relative">
              <div class="text-xs font-semibold text-muted-foreground uppercase tracking-wider mb-3">
                Last 30 Days
              </div>
              <div class="text-5xl font-semibold mb-2" :class="getAccuracyColor(recentStats.winner_accuracy)">
                {{ recentStats.winner_accuracy }}%
              </div>
              <div class="text-sm font-medium text-foreground/90 mb-3">
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
          <div class="ui-surface group relative p-8 transition-all duration-300 hover:shadow-md">
            <div class="absolute inset-0 bg-gradient-to-br from-green-500/5 to-emerald-500/5 rounded-2xl opacity-0 group-hover:opacity-100 transition-opacity" />
            <div class="relative">
              <div class="text-xs font-semibold text-muted-foreground uppercase tracking-wider mb-3">
                Total ROI
              </div>
              <div class="text-5xl font-semibold mb-2" :class="getROIColor(roiStats.roi_percentage)">
                {{ roiStats.roi_percentage > 0 ? '+' : '' }}{{ roiStats.roi_percentage }}%
              </div>
              <div class="text-sm font-medium text-foreground/90 mb-1">
                <span :class="getROIColor(roiStats.total_profit)">
                  {{ roiStats.total_profit > 0 ? '+' : '' }}${{ roiStats.total_profit.toLocaleString() }}
                </span> profit
              </div>
            </div>
          </div>

          <!-- Spread Accuracy -->
          <div class="ui-surface group relative p-8 transition-all duration-300 hover:shadow-md">
            <div class="absolute inset-0 bg-gradient-to-br from-indigo-500/5 to-violet-500/5 rounded-2xl opacity-0 group-hover:opacity-100 transition-opacity" />
            <div class="relative">
              <div class="text-xs font-semibold text-muted-foreground uppercase tracking-wider mb-3">
                Avg Spread Error
              </div>
              <div class="text-5xl font-semibold mb-2">
                {{ overallStats.avg_spread_error }}
              </div>
              <div class="text-sm font-medium text-foreground/90 mb-1">
                points off
              </div>
              <div class="text-xs text-muted-foreground">
                Industry avg: 12.5 pts
              </div>
            </div>
          </div>
        </div>

        <!-- CTA -->
        <div class="text-center">
          <p class="text-sm text-muted-foreground mt-4">
            Updated live after every game. No cherry-picking. No hiding losses.
          </p>
        </div>
      </div>
    </section>

    <!-- How It Works -->
    <section class="py-18 px-4 sm:px-6 lg:px-8">
      <div class="max-w-7xl mx-auto">
        <div class="text-center mb-14">
          <h2 class="text-4xl sm:text-5xl font-semibold tracking-tight mb-4 text-balance">
            Built For Disciplined Bettors
          </h2>
          <p class="text-xl text-muted-foreground max-w-2xl mx-auto">
            Not hype picks. A repeatable, data-first workflow for finding value.
          </p>
        </div>

        <!-- Main Features Grid -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-12">
          <!-- Advanced ELO Engine -->
          <div class="relative group">
            <div class="relative ui-surface p-8">
              <div class="inline-flex items-center justify-center size-12 rounded-xl bg-gradient-to-br from-sky-500 to-blue-600 mb-6">
                <svg xmlns="http://www.w3.org/2000/svg" class="size-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                </svg>
              </div>
              <h3 class="text-2xl font-semibold tracking-tight mb-3">
                Advanced ELO Rating System
              </h3>
              <p class="text-muted-foreground mb-4 leading-relaxed">
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
            <div class="relative ui-surface p-8">
              <div class="inline-flex items-center justify-center size-12 rounded-xl bg-gradient-to-br from-blue-500 to-purple-500 mb-6">
                <svg xmlns="http://www.w3.org/2000/svg" class="size-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
              </div>
              <h3 class="text-2xl font-semibold tracking-tight mb-3">
                100% Transparent Track Record
              </h3>
              <p class="text-muted-foreground mb-4 leading-relaxed">
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
            <div class="relative ui-surface p-8">
              <div class="inline-flex items-center justify-center size-12 rounded-xl bg-gradient-to-br from-green-500 to-emerald-500 mb-6">
                <svg xmlns="http://www.w3.org/2000/svg" class="size-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
                </svg>
              </div>
              <h3 class="text-2xl font-semibold tracking-tight mb-3">
                +EV Betting Opportunities
              </h3>
              <p class="text-muted-foreground mb-4 leading-relaxed">
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
            <div class="relative ui-surface p-8">
              <div class="inline-flex items-center justify-center size-12 rounded-xl bg-gradient-to-br from-indigo-500 to-violet-500 mb-6">
                <svg xmlns="http://www.w3.org/2000/svg" class="size-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                </svg>
              </div>
              <h3 class="text-2xl font-semibold tracking-tight mb-3">
                Real-Time Game Analysis
              </h3>
              <p class="text-muted-foreground mb-4 leading-relaxed">
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
          <p class="text-sm font-semibold text-muted-foreground uppercase tracking-wider mb-4">
            Complete Coverage Across
          </p>
          <div class="flex flex-wrap justify-center gap-4 text-2xl font-semibold tracking-tight">
            <span>NFL</span>
            <span class="text-muted-foreground/40">•</span>
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
    <section class="relative py-20 px-4 sm:px-6 lg:px-8 overflow-hidden border-t border-border/50 bg-muted/35">
      <!-- Background -->
      <div class="absolute inset-0 bg-gradient-to-b from-sky-100/60 via-background to-background dark:from-sky-950/20 dark:via-background dark:to-background" />

      <div class="relative max-w-4xl mx-auto text-center">
        <h2 class="text-4xl sm:text-5xl font-semibold tracking-tight text-foreground mb-5 text-balance">
          Cut The Noise. Keep The Edge.
        </h2>
        <p class="text-xl text-muted-foreground mb-9 max-w-2xl mx-auto text-balance">
          Use a system that prioritizes expected value, clear reporting, and long-term discipline.
        </p>

        <div class="flex flex-col sm:flex-row gap-4 justify-center items-center mb-8">
          <Link v-if="!$page.props.auth.user" :href="register()">
            <Button size="lg" class="text-base px-10">
              Get Started Free
            </Button>
          </Link>
          <Link v-else :href="dashboard()">
            <Button size="lg" class="text-base px-10">
              Go to Dashboard
            </Button>
          </Link>
        </div>

        <div class="flex flex-col sm:flex-row items-center justify-center gap-6 text-foreground/70 text-sm">
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
    <footer class="bg-background/70 border-t border-border/70">
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
        <!-- Disclaimer -->
        <div class="ui-surface-subtle p-6 mb-8">
          <p class="text-sm text-foreground/90 font-medium text-center">
            <strong>Important:</strong> This platform is for entertainment and educational purposes only.
            Gambling involves risk of loss. Never bet more than you can afford to lose. If you or someone you know has a gambling problem,
            call 1-800-GAMBLER.
          </p>
        </div>

        <!-- Footer Links -->
        <div class="flex flex-col sm:flex-row items-center justify-between gap-4 pb-6 border-b border-border/70">
          <div class="flex items-center gap-2">
            <div class="size-8 rounded-lg bg-gradient-to-br from-sky-500 to-blue-600 flex items-center justify-center">
              <span class="text-white font-bold text-sm">PS</span>
            </div>
            <span class="text-lg font-semibold tracking-tight">PickSports</span>
          </div>

          <div class="flex flex-wrap items-center justify-center gap-6 text-sm">
            <Link :href="performanceRoute()" class="text-muted-foreground hover:text-foreground font-medium transition-colors">
              Performance
            </Link>
            <Link :href="terms()" class="text-muted-foreground hover:text-foreground font-medium transition-colors">
              Terms
            </Link>
            <Link :href="privacy()" class="text-muted-foreground hover:text-foreground font-medium transition-colors">
              Privacy
            </Link>
            <Link :href="responsibleGambling()" class="text-muted-foreground hover:text-foreground font-medium transition-colors">
              Responsible Gambling
            </Link>
          </div>
        </div>

        <!-- Copyright -->
        <div class="pt-6 text-center text-sm text-muted-foreground">
          <p>&copy; 2026 PickSports. All rights reserved. Not affiliated with any professional sports league or gambling operator.</p>
        </div>
      </div>
    </footer>
  </div>
</template>
