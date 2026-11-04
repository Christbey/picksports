<script setup lang="ts">
import type { UnwrapNestedRefs } from 'vue';
import type { usePredictionCardGame } from '@/composables/usePredictionCardGame';
import {
    Activity,
    Radio,
    Gauge,
    ArrowUpRight,
    ArrowDownRight,
    Minus,
} from 'lucide-vue-next';
defineProps<{
    game: UnwrapNestedRefs<ReturnType<typeof usePredictionCardGame>>;
}>();
</script>
<template>
    <section
        v-if="game.isLive()"
        data-testid="live-game-insights"
        class="overflow-hidden rounded-xl bg-muted/30"
        aria-label="Live game insights"
    >
        <div class="grid gap-4 p-3 sm:grid-cols-3 sm:gap-6">
            <div class="flex min-w-0 items-start gap-2.5">
                <div
                    class="mt-0.5 flex size-4 shrink-0 items-center justify-center text-sky-600 dark:text-sky-400"
                >
                    <Radio class="h-4 w-4" />
                </div>
                <div class="min-w-0">
                    <div
                        class="text-[11px] font-medium text-muted-foreground uppercase"
                    >
                        Game state
                    </div>
                    <div class="truncate text-sm font-semibold">
                        {{ game.liveGameStateLabel() }}
                    </div>
                    <div
                        class="mt-0.5 flex items-center gap-2 text-xs text-muted-foreground"
                    >
                        <span>{{ game.liveGameStateMeta() }}</span>
                        <span
                            v-if="
                                game.isMlbPrediction() &&
                                game.gameOuts() !== null
                            "
                            class="inline-flex gap-1"
                            :aria-label="`${game.gameOuts()} outs`"
                        >
                            <span
                                v-for="outNumber in 3"
                                :key="outNumber"
                                class="h-1.5 w-1.5 rounded-full border border-sky-500/40"
                                :class="
                                    outNumber <= game.gameOuts()!
                                        ? 'bg-sky-500'
                                        : 'bg-transparent'
                                "
                            />
                        </span>
                    </div>
                </div>
            </div>

            <div class="flex min-w-0 items-start gap-2.5">
                <div
                    class="mt-0.5 flex size-4 shrink-0 items-center justify-center text-sky-600 dark:text-sky-400"
                >
                    <Activity class="h-4 w-4" />
                </div>
                <div class="min-w-0 flex-1">
                    <div
                        class="text-[11px] font-medium text-muted-foreground uppercase"
                    >
                        Win outlook
                    </div>
                    <div class="truncate text-sm font-semibold">
                        {{ game.liveWinOutlookValue() }}
                    </div>
                    <div
                        v-if="game.liveLeaderProbability !== null"
                        role="meter"
                        :aria-label="`${game.liveWinLeaderLabel()} win outlook`"
                        :aria-valuenow="
                            Number(game.liveLeaderProbability.toFixed(1))
                        "
                        aria-valuemin="0"
                        aria-valuemax="100"
                        class="my-1.5 h-1.5 max-w-56 overflow-hidden rounded-full bg-sky-500/15"
                        data-testid="win-outlook-meter"
                    >
                        <div
                            class="h-full rounded-full bg-sky-500 motion-safe:transition-[width]"
                            :style="{
                                width: `${game.liveLeaderProbability}%`,
                                backgroundColor:
                                    game.liveWinnerColor ?? undefined,
                            }"
                        />
                    </div>
                    <div class="truncate text-xs text-muted-foreground">
                        {{ game.liveWinOutlookMeta() }}
                    </div>
                </div>
            </div>

            <div class="flex min-w-0 items-start gap-2.5">
                <div
                    class="mt-0.5 flex size-4 shrink-0 items-center justify-center text-muted-foreground"
                >
                    <Gauge class="h-4 w-4" />
                </div>
                <div class="min-w-0">
                    <div
                        class="text-[11px] font-medium text-muted-foreground uppercase"
                    >
                        Updated total
                    </div>
                    <div class="flex items-center gap-2 text-sm font-semibold">
                        <span>{{ game.liveTotalOutlookValue() }}</span>
                        <span
                            v-if="game.liveTotalDirection"
                            data-testid="total-movement"
                            class="inline-flex items-center gap-1 rounded-full px-1.5 py-0.5 text-[10px] font-medium"
                            :class="
                                game.liveTotalDirection === 'up'
                                    ? 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300'
                                    : game.liveTotalDirection === 'down'
                                      ? 'bg-red-500/10 text-red-700 dark:text-red-300'
                                      : 'bg-muted text-muted-foreground'
                            "
                        >
                            <ArrowUpRight
                                v-if="game.liveTotalDirection === 'up'"
                                class="size-3"
                                aria-hidden="true"
                            />
                            <ArrowDownRight
                                v-else-if="game.liveTotalDirection === 'down'"
                                class="size-3"
                                aria-hidden="true"
                            />
                            <Minus v-else class="size-3" aria-hidden="true" />
                            {{
                                game.liveTotalDirection === 'up'
                                    ? 'Up'
                                    : game.liveTotalDirection === 'down'
                                      ? 'Down'
                                      : 'Steady'
                            }}
                        </span>
                    </div>
                    <div class="truncate text-xs text-muted-foreground">
                        {{ game.liveTotalOutlookMeta() }}
                    </div>
                </div>
            </div>
        </div>
    </section>
</template>
