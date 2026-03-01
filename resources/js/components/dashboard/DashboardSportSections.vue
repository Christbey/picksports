<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Sparkles, Target } from 'lucide-vue-next';
import BettingAnalysisCard from '@/components/BettingAnalysisCard.vue';
import UpgradeCard from '@/components/UpgradeCard.vue';
import {
    useDashboardPresentation,
} from '@/composables/useDashboardView';
import type { DashboardPrediction, DashboardSport } from '@/types';

defineProps<{
    sports: DashboardSport[];
}>();

const {
    getSportHeaderColor,
    getGameUrl,
    hasLiveData,
    buildLivePredictionData,
} = useDashboardPresentation();

const upgradeFeatures = [
    'Unlimited daily predictions',
    'All sports coverage',
    'Advanced betting analytics',
    'Priority support',
];

function toLivePredictionData(prediction: DashboardPrediction) {
    return buildLivePredictionData(prediction);
}

function winProbPercent(prediction: DashboardPrediction): number {
    const prob = hasLiveData(prediction)
        ? prediction.live_win_probability ?? prediction.win_probability
        : prediction.win_probability;

    return Math.max(0, Math.min(100, prob * 100));
}
</script>

<template>
    <div class="grid gap-6 lg:grid-cols-[1fr_300px]">
        <div class="space-y-6">
            <div
                v-for="sport in sports"
                :key="sport.name"
                class="overflow-hidden rounded-xl border border-sidebar-border/70 bg-white dark:border-sidebar-border dark:bg-sidebar"
            >
                <div
                    class="flex items-center justify-between bg-gradient-to-r p-4"
                    :class="getSportHeaderColor(sport.color)"
                >
                    <div>
                        <h2 class="text-xl font-bold text-white">{{ sport.name }}</h2>
                        <p class="text-sm text-white/80">{{ sport.fullName }}</p>
                    </div>
                    <div
                        class="rounded-full bg-white/20 px-3 py-1 text-sm font-medium text-white"
                    >
                        {{ sport.predictions.length }}
                        {{ sport.predictions.length === 1 ? 'game' : 'games' }}
                    </div>
                </div>

                <div class="space-y-3 p-4">
                    <Link
                        v-for="prediction in sport.predictions"
                        :key="`${prediction.sport}-${prediction.game_id}`"
                        :href="getGameUrl(prediction.sport, prediction.game_id)"
                        class="block rounded-lg border border-sidebar-border/70 bg-sidebar-accent/30 p-3 transition-all hover:border-sidebar-border hover:bg-sidebar-accent/50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 md:p-4 dark:border-sidebar-border"
                    >
                        <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                            <div class="flex flex-col gap-2">
                                <div
                                    v-if="prediction.is_live"
                                    class="flex items-center gap-1.5 self-start rounded-full bg-red-100 px-2 py-0.5 dark:bg-red-900/50"
                                >
                                    <span class="h-2 w-2 animate-pulse rounded-full bg-red-500"></span>
                                    <span class="text-xs font-semibold text-red-600 dark:text-red-400">LIVE</span>
                                </div>
                                <div
                                    v-else-if="prediction.is_final"
                                    class="flex items-center gap-1.5 self-start rounded-full bg-gray-100 px-2 py-0.5 dark:bg-gray-800"
                                >
                                    <span class="text-xs font-semibold text-gray-600 dark:text-gray-400">FINAL</span>
                                </div>

                                <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:gap-4">
                                    <div class="flex items-center gap-2">
                                        <img :src="prediction.away_logo" :alt="prediction.away_team" class="h-8 w-8 object-contain md:h-10 md:w-10" />
                                        <span class="text-sm font-semibold md:text-base">{{ prediction.away_team }}</span>
                                        <span
                                            v-if="prediction.is_live || prediction.is_final"
                                            class="ml-auto text-base font-bold md:text-lg"
                                        >
                                            {{ prediction.away_score }}
                                        </span>
                                    </div>
                                    <span class="hidden text-muted-foreground sm:inline">@</span>
                                    <div class="flex items-center gap-2">
                                        <img :src="prediction.home_logo" :alt="prediction.home_team" class="h-8 w-8 object-contain md:h-10 md:w-10" />
                                        <span class="text-sm font-semibold md:text-base">{{ prediction.home_team }}</span>
                                        <span
                                            v-if="prediction.is_live || prediction.is_final"
                                            class="ml-auto text-base font-bold md:text-lg"
                                        >
                                            {{ prediction.home_score }}
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 gap-2 sm:grid-cols-1 md:min-w-[180px]">
                                <div class="rounded-md border border-sidebar-border/60 bg-white/70 p-2 dark:bg-sidebar/60">
                                    <div class="mb-1 flex items-center gap-1.5 text-[11px] font-medium uppercase tracking-wide text-muted-foreground">
                                        <Target class="h-3.5 w-3.5" />
                                        Win %
                                    </div>
                                    <div class="text-base font-bold" :class="{ 'text-red-500': hasLiveData(prediction) }">
                                        {{ winProbPercent(prediction).toFixed(1) }}%
                                    </div>
                                    <div class="mt-1.5 h-1.5 w-full overflow-hidden rounded-full bg-sidebar-accent">
                                        <div
                                            class="h-full rounded-full bg-emerald-500 transition-all"
                                            :style="{ width: `${winProbPercent(prediction)}%` }"
                                        />
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div
                            v-if="(prediction.betting_value && prediction.betting_value.length > 0) || hasLiveData(prediction)"
                            class="mt-4 border-t border-sidebar-border/70 pt-4"
                        >
                            <div class="mb-2 flex items-center gap-2">
                                <div class="inline-flex items-center gap-1.5 rounded-full bg-sidebar-accent/70 px-2.5 py-1 text-xs font-semibold uppercase tracking-wide">
                                    <Sparkles class="h-3.5 w-3.5" />
                                    {{ hasLiveData(prediction) ? 'Live Signals' : 'Value Signals' }}
                                </div>
                                <div
                                    v-if="!hasLiveData(prediction) && prediction.betting_value?.length"
                                    class="text-xs text-muted-foreground"
                                >
                                    Vegas
                                </div>
                            </div>
                            <BettingAnalysisCard
                                :betting-value="prediction.betting_value"
                                :live-prediction="toLivePredictionData(prediction)"
                                :compact="true"
                            />
                        </div>
                    </Link>
                </div>
            </div>
        </div>

        <div class="hidden lg:block">
            <div class="sticky top-4">
                <UpgradeCard :features="upgradeFeatures" />
            </div>
        </div>
    </div>

    <div class="lg:hidden">
        <UpgradeCard variant="compact" :features="upgradeFeatures" />
    </div>
</template>
