<script setup lang="ts">
import { Clock, Target } from 'lucide-vue-next';
import MlbPickCard from '@/components/mlb/MlbPickCard.vue';
import { useMlbDailyPicksBoardContext } from '@/composables/useMlbDailyPicksBoard';
const {
    marketTabs,
    filteredCandidates,
    visibleCandidates,
    hiddenCandidateCount,
    emptyState,
    selectPick,
    selectedTab,
} = useMlbDailyPicksBoardContext();
</script>
<template>
    <div class="space-y-5">
        <section class="rounded-lg border bg-card p-4 shadow-sm md:p-5">
            <div
                class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
            >
                <div>
                    <div
                        class="flex items-center gap-2 text-sm font-semibold text-emerald-600 uppercase dark:text-emerald-400"
                    >
                        <Target class="h-4 w-4" />
                        Daily Candidates
                    </div>
                    <h2 class="mt-1 text-2xl font-bold tracking-normal">
                        Best Available Per Game
                    </h2>
                </div>
                <div
                    class="rounded-full border border-emerald-500/25 bg-emerald-500/10 px-3 py-1 text-xs font-semibold text-emerald-700 dark:text-emerald-300"
                >
                    {{ visibleCandidates.length }} shown
                </div>
            </div>

            <div
                v-if="filteredCandidates.length === 0"
                class="mt-4 rounded-lg border border-dashed bg-background/70 p-5"
            >
                <div class="flex min-w-0 gap-4">
                    <div
                        class="grid h-12 w-12 shrink-0 place-items-center rounded-lg border bg-card text-amber-500"
                    >
                        <component :is="emptyState.icon" class="h-5 w-5" />
                    </div>
                    <div class="min-w-0">
                        <h3 class="text-lg font-semibold">
                            {{ emptyState.title }}
                        </h3>
                        <p
                            class="mt-1 max-w-2xl text-sm leading-6 text-muted-foreground"
                        >
                            {{ emptyState.body }}
                        </p>
                        <div
                            class="mt-3 inline-flex items-center gap-2 rounded-full border border-amber-500/25 bg-amber-500/10 px-3 py-1 text-xs font-semibold text-amber-700 dark:text-amber-300"
                        >
                            <Clock class="h-3.5 w-3.5" />
                            Tracking mode is active
                        </div>
                    </div>
                </div>
            </div>

            <div v-else class="mt-4 space-y-4">
                <div class="flex flex-wrap gap-2">
                    <button
                        v-for="tab in marketTabs"
                        :key="tab.key"
                        type="button"
                        class="rounded-full border px-3.5 py-2 text-sm font-semibold whitespace-nowrap transition"
                        :class="
                            selectedTab === tab.key
                                ? 'border-emerald-500 bg-emerald-500 text-white shadow-sm'
                                : 'bg-card text-muted-foreground hover:border-emerald-500/30 hover:bg-muted'
                        "
                        @click="selectedTab = tab.key"
                    >
                        {{ tab.label }}
                        <span class="ml-1 opacity-75">{{ tab.count }}</span>
                    </button>
                </div>

                <div
                    class="rounded-lg border bg-background/70 p-3 text-xs leading-5 text-muted-foreground"
                >
                    Showing the highest-scored candidate per game for this
                    filter. Open details for full model, market, reasons, and
                    risks.
                </div>

                <div class="grid gap-3">
                    <MlbPickCard
                        v-for="candidate in visibleCandidates"
                        :key="candidate.id"
                        :candidate="candidate"
                        variant="compact"
                        @select="selectPick"
                    />
                </div>

                <div
                    v-if="hiddenCandidateCount > 0"
                    class="rounded-lg border border-dashed bg-background/70 p-3 text-xs text-muted-foreground"
                >
                    {{ hiddenCandidateCount }} lower-ranked candidate(s) are
                    hidden to keep this board readable.
                </div>
            </div>
        </section>
    </div>
</template>
