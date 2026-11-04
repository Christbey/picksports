<script setup lang="ts">
import PredictionLiveInsights from '@/components/predictions/PredictionLiveInsights.vue';
import PredictionExternalContext from '@/components/predictions/PredictionExternalContext.vue';
import PredictionSaveActions from '@/components/predictions/PredictionSaveActions.vue';
import { Link } from '@inertiajs/vue3';
import { ChevronDown, ChevronRight, Clock } from 'lucide-vue-next';
import { reactive, ref } from 'vue';
import { usePredictionCardGame } from '@/composables/usePredictionCardGame';
import { usePredictionCardRecommendation } from '@/composables/usePredictionCardRecommendation';
import { usePredictionTracking } from '@/composables/usePredictionTracking';
import type { PredictionCardProps } from '@/types/prediction-card';
const props = defineProps<PredictionCardProps>();
const expanded = ref(false);
const game = usePredictionCardGame(props);
const recommendation = usePredictionCardRecommendation(props, game);
const tracking = reactive(usePredictionTracking(props, game, expanded));
const live = reactive(game);
const context = reactive(recommendation);
const {
    awayTeamLabel,
    homeTeamLabel,
    awayLogo,
    homeLogo,
    isLive,
    isFinal,
    awayScore,
    homeScore,
    showGameScore,
    teamScoreClass,
    showAwayLogo,
    showHomeLogo,
    handleAwayLogoError,
    handleHomeLogoError,
    weekLabel,
    gameDateTimeLabel,
    predictionFreshnessLabel,
    statusBadgeLabel,
    statusBadgeClass,
    liveGameStateLabel,
} = game;
const {
    weatherForecast,
    pregameCalls,
    cfbSignalBadges,
    cfbSignalBadgeClass,
    valueSignal,
    finalResultBadgeClass,
    finalResultLabel,
    dashboardCardClass,
    dashboardRailClass,
    dashboardChipClass,
    dashboardPrimaryPickLabel,
    dashboardSignalLabel,
    dashboardSignalClass,
    totalPickPillLabel,
    totalPickPillStrong,
    totalResultLabel,
    totalResultBadgeClass,
} = recommendation;
</script>

<template>
    <div :class="compact ? 'bg-card' : ''">
        <div
            v-if="compact"
            class="grid grid-cols-[1fr_auto] items-center gap-x-5 gap-y-3 px-4 py-5 sm:grid-cols-[minmax(0,1fr)_minmax(15rem,1fr)_auto] sm:px-6"
        >
            <Link
                :href="href"
                class="min-w-0 rounded-sm focus-visible:outline-2 focus-visible:outline-ring"
            >
                <div
                    class="mb-2 flex items-center gap-2 text-xs text-muted-foreground"
                >
                    <span
                        v-if="isLive()"
                        class="size-1.5 rounded-full bg-sky-500"
                    />
                    <span>{{
                        isLive()
                            ? liveGameStateLabel()
                            : isFinal()
                              ? 'Final'
                              : gameDateTimeLabel()
                    }}</span>
                </div>
                <div
                    class="flex items-center gap-2 text-sm font-semibold sm:text-base"
                >
                    <img
                        v-if="showAwayLogo()"
                        :src="awayLogo()!"
                        alt=""
                        class="size-5 shrink-0 object-contain"
                        @error="handleAwayLogoError"
                    />
                    <span class="truncate">{{ awayTeamLabel() }}</span>
                    <span v-if="showGameScore()" class="ml-auto tabular-nums">{{
                        awayScore()
                    }}</span>
                </div>
                <div
                    class="mt-1.5 flex items-center gap-2 text-sm font-semibold sm:text-base"
                >
                    <img
                        v-if="showHomeLogo()"
                        :src="homeLogo()!"
                        alt=""
                        class="size-5 shrink-0 object-contain"
                        @error="handleHomeLogoError"
                    />
                    <span class="truncate">{{ homeTeamLabel() }}</span>
                    <span v-if="showGameScore()" class="ml-auto tabular-nums">{{
                        homeScore()
                    }}</span>
                </div>
            </Link>
            <section
                class="col-span-full row-start-2 sm:col-span-1 sm:row-auto"
                aria-label="Pregame model bets"
            >
                <p class="mb-2 text-xs text-muted-foreground">Pregame model</p>
                <dl class="space-y-1.5 text-sm">
                    <div class="grid grid-cols-[3.5rem_1fr] gap-3">
                        <dt class="text-muted-foreground">Win</dt>
                        <dd class="font-medium">{{ pregameCalls.winner }}</dd>
                    </div>
                    <div class="grid grid-cols-[3.5rem_1fr] gap-3">
                        <dt class="text-muted-foreground">Cover</dt>
                        <dd class="font-medium">{{ pregameCalls.spread }}</dd>
                    </div>
                    <div class="grid grid-cols-[3.5rem_1fr] gap-3">
                        <dt class="text-muted-foreground">Total</dt>
                        <dd class="font-medium">{{ pregameCalls.total }}</dd>
                    </div>
                </dl>
            </section>
            <button
                type="button"
                :aria-expanded="expanded"
                :aria-label="`${expanded ? 'Hide' : 'Show'} analysis for ${awayTeamLabel()} at ${homeTeamLabel()}`"
                class="col-start-2 row-start-1 flex items-center gap-1 rounded-md px-2 py-2 text-xs font-medium text-muted-foreground hover:bg-muted hover:text-foreground focus-visible:outline-2 focus-visible:outline-ring sm:col-auto sm:row-auto"
                @click="expanded = !expanded"
            >
                {{ expanded ? 'Less' : 'Analysis'
                }}<ChevronDown
                    class="size-4 transition-transform"
                    :class="{ 'rotate-180': expanded }"
                />
            </button>
        </div>
        <section
            v-if="sport === 'cfb'"
            aria-label="Game weather forecast"
            class="border-t border-border/50 px-4 py-3 text-xs sm:px-6"
        >
            <p class="font-medium">{{ weatherForecast.summary }}</p>
            <details class="mt-1 text-muted-foreground">
                <summary class="cursor-pointer">
                    Forecast source and timing
                </summary>
                <p class="mt-1">{{ weatherForecast.detail }}</p>
            </details>
        </section>
        <div
            v-if="!compact || expanded"
            class="group relative flex w-full overflow-hidden rounded-lg border border-border bg-card p-4 text-left"
            :class="
                compact
                    ? 'rounded-none border-x-0 border-b-0 bg-muted/20'
                    : dashboardCardClass()
            "
        >
            <div
                v-if="!compact"
                class="absolute inset-y-0 left-0 w-0.5"
                :class="dashboardRailClass()"
            />
            <div class="flex min-w-0 flex-1 flex-col gap-3 pl-1">
                <p
                    v-if="compact && valueSignal()?.spread_assessment"
                    class="text-xs text-muted-foreground"
                    :title="
                        valueSignal()?.spread_assessment?.risk_flags.join(', ')
                    "
                >
                    {{
                        valueSignal()?.decision_summary ??
                        valueSignal()?.spread_assessment?.summary
                    }}
                </p>
                <details
                    v-if="compact && valueSignal()?.decision_notes?.length"
                    class="text-xs text-muted-foreground"
                >
                    <summary class="cursor-pointer">Why this status</summary>
                    <ul class="mt-1 list-disc space-y-1 pl-4">
                        <li
                            v-for="note in valueSignal()?.decision_notes"
                            :key="note"
                        >
                            {{ note }}
                        </li>
                    </ul>
                </details>
                <details
                    v-if="compact && valueSignal()?.football_signals"
                    class="text-xs text-muted-foreground"
                >
                    <summary class="cursor-pointer">
                        Football signals:
                        {{ valueSignal()?.football_signals?.catalog_count }}
                        rules ·
                        {{ valueSignal()?.football_signals?.applied }}
                        contributions
                    </summary>
                    <p class="mt-1">
                        Checked for both teams:
                        {{ valueSignal()?.football_signals?.triggered }}
                        conditions triggered;
                        {{ valueSignal()?.football_signals?.missing_inputs }}
                        missing inputs. A triggered rule contributes points only
                        when its historical correction has supporting evidence.
                    </p>
                    <ul class="mt-1 list-disc space-y-1 pl-4">
                        <li
                            v-for="signal in valueSignal()?.football_signals?.signals.filter(
                                (item) => item.matched,
                            )"
                            :key="`${signal.id}-${signal.side}`"
                        >
                            {{ signal.side }} · {{ signal.label }} ·
                            {{ signal.market }}:
                            {{ signal.contribution_points.toFixed(2) }} points ·
                            {{ signal.status.replaceAll('_', ' ') }}
                            ({{ signal.sample_games }} historical games)
                        </li>
                    </ul>
                </details>
                <details
                    v-if="
                        compact &&
                        valueSignal()?.signal_contributions?.signals?.length
                    "
                    class="text-xs text-muted-foreground"
                >
                    <summary class="cursor-pointer">
                        Signal impact:
                        {{
                            valueSignal()?.signal_contributions
                                ?.active_spread_signals
                        }}
                        spread,
                        {{
                            valueSignal()?.signal_contributions
                                ?.active_total_signals
                        }}
                        total
                    </summary>
                    <p class="mt-1">
                        Positive spread points favor the home team; positive
                        total points raise the total. Impacts compare the
                        forecast with that signal removed and are not
                        independent votes.
                    </p>
                    <ul class="mt-1 list-disc space-y-1 pl-4">
                        <li
                            v-for="signal in valueSignal()?.signal_contributions?.signals?.filter(
                                (item) => item.active,
                            )"
                            :key="`${signal.signal}-${signal.market}`"
                        >
                            {{ signal.signal.replaceAll('_', ' ') }} ·
                            {{ signal.market }}:
                            {{ signal.contribution_points > 0 ? '+' : ''
                            }}{{ signal.contribution_points.toFixed(1) }} points
                        </li>
                    </ul>
                </details>
                <Link :href="href" class="flex min-w-0 flex-1 flex-col gap-3">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div
                                class="flex flex-wrap items-center gap-2 text-sm"
                            >
                                <div
                                    class="flex items-center gap-2 font-semibold"
                                >
                                    <img
                                        v-if="showAwayLogo()"
                                        :src="awayLogo()!"
                                        :alt="awayTeamLabel()"
                                        class="h-6 w-6 rounded-full object-contain"
                                        @error="handleAwayLogoError"
                                    />
                                    <span
                                        class="inline-flex items-center gap-1.5"
                                        :class="teamScoreClass('away')"
                                    >
                                        <span>{{ awayTeamLabel() }}</span>
                                        <span
                                            v-if="showGameScore()"
                                            class="text-lg font-bold tabular-nums"
                                        >
                                            {{ awayScore() }}
                                        </span>
                                    </span>
                                    <span class="text-muted-foreground">@</span>
                                    <img
                                        v-if="showHomeLogo()"
                                        :src="homeLogo()!"
                                        :alt="homeTeamLabel()"
                                        class="h-6 w-6 rounded-full object-contain"
                                        @error="handleHomeLogoError"
                                    />
                                    <span
                                        class="inline-flex items-center gap-1.5"
                                        :class="teamScoreClass('home')"
                                    >
                                        <span>{{ homeTeamLabel() }}</span>
                                        <span
                                            v-if="showGameScore()"
                                            class="text-lg font-bold tabular-nums"
                                        >
                                            {{ homeScore() }}
                                        </span>
                                    </span>
                                </div>
                                <span
                                    v-if="!isLive() && gameDateTimeLabel()"
                                    class="inline-flex items-center gap-1 rounded-full border bg-background/75 px-2.5 py-1 text-xs text-muted-foreground"
                                >
                                    <Clock class="h-3.5 w-3.5" />
                                    {{ gameDateTimeLabel() }}
                                </span>
                                <span
                                    v-if="weekLabel()"
                                    :class="dashboardChipClass()"
                                >
                                    {{ weekLabel() }}
                                </span>
                                <span
                                    class="inline-flex items-center gap-1.5"
                                    :class="statusBadgeClass()"
                                >
                                    <span
                                        v-if="isLive()"
                                        class="h-1.5 w-1.5 rounded-full bg-sky-500 motion-safe:animate-pulse"
                                    />
                                    {{ statusBadgeLabel() }}
                                </span>
                                <span
                                    v-if="predictionFreshnessLabel()"
                                    :class="dashboardChipClass()"
                                >
                                    {{ predictionFreshnessLabel() }}
                                </span>
                            </div>

                            <div
                                v-if="!isLive()"
                                class="mt-3 flex flex-wrap items-center gap-2"
                            >
                                <span
                                    class="rounded-full border border-sky-500/25 bg-sky-500/10 px-2.5 py-1 text-xs font-semibold text-sky-700 dark:text-sky-300"
                                >
                                    {{ dashboardPrimaryPickLabel() }}
                                </span>
                                <span
                                    v-if="dashboardSignalLabel()"
                                    class="rounded-full border px-2.5 py-1 text-xs font-semibold"
                                    :class="dashboardSignalClass()"
                                >
                                    {{ dashboardSignalLabel() }}
                                </span>
                                <span
                                    v-if="totalPickPillLabel()"
                                    class="rounded-full border px-2.5 py-1 text-xs font-semibold"
                                    :class="
                                        totalPickPillStrong()
                                            ? 'border-sky-500/25 bg-sky-500/10 text-sky-700 dark:text-sky-300'
                                            : 'border-muted bg-muted/40 text-muted-foreground'
                                    "
                                >
                                    {{ totalPickPillLabel() }}
                                </span>
                                <span
                                    v-if="isFinal() && finalResultLabel()"
                                    class="rounded-full border px-2.5 py-1 text-xs font-semibold"
                                    :class="finalResultBadgeClass()"
                                >
                                    Projection {{ finalResultLabel() }}
                                </span>
                                <span
                                    v-if="isFinal() && totalResultLabel()"
                                    class="rounded-full border px-2.5 py-1 text-xs font-semibold"
                                    :class="totalResultBadgeClass()"
                                >
                                    O/U {{ totalResultLabel() }}
                                </span>
                                <span
                                    v-for="badge in cfbSignalBadges()"
                                    :key="badge.label"
                                    class="rounded-full border px-2.5 py-1 text-xs font-semibold"
                                    :class="cfbSignalBadgeClass(badge.tone)"
                                >
                                    {{ badge.label }}
                                </span>
                            </div>
                        </div>

                        <span
                            v-if="isLive()"
                            class="shrink-0 rounded-full border bg-muted/30 px-3 py-1 text-xs font-medium text-muted-foreground"
                        >
                            {{ dashboardPrimaryPickLabel() }}
                        </span>
                    </div>

                    <PredictionLiveInsights v-if="isLive()" :game="live" />

                    <div class="flex justify-end">
                        <span
                            class="inline-flex items-center gap-1 text-xs font-medium text-muted-foreground transition-colors group-hover:text-foreground"
                        >
                            View game
                            <ChevronRight
                                class="size-3.5 transition group-hover:translate-x-0.5"
                            />
                        </span>
                    </div>
                </Link>

                <PredictionExternalContext :context="context" />

                <PredictionSaveActions
                    :tracking="tracking"
                    :final="isFinal()"
                />
            </div>
        </div>
    </div>
</template>
