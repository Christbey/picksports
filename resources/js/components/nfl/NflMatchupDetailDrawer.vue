<script setup lang="ts">
import { computed } from 'vue';
import NflMatchupSignals from '@/components/game-page/NflMatchupSignals.vue';
import NflMarketHistory from '@/components/game-page/NflMarketHistory.vue';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import {
    kickoffLabel,
    nflBoardPresentation,
    numberValue,
    percent,
    record,
    teamLabel,
} from '@/lib/nflBoardPresentation';
import { decisionLabel, researchReason } from '@/lib/researchDecision';
import type { ApiV2Prediction } from '@/types';

const props = defineProps<{ prediction: ApiV2Prediction | null }>();
const open = defineModel<boolean>('open', { default: false });
const view = computed(() =>
    props.prediction ? nflBoardPresentation(props.prediction) : null,
);
const layer = computed(() => record(props.prediction?.pro_signal_layer));
const tiers = computed(() => record(layer.value.market_scores));
const reasons = computed(() => [
    ...new Set(view.value?.researchReasons.map(researchReason) ?? []),
]);
const diagnostics = computed(() =>
    [
        ...new Set([
            ...(Array.isArray(layer.value.reason_codes)
                ? layer.value.reason_codes.map(String)
                : []),
            ...(Array.isArray(layer.value.risk_flags)
                ? layer.value.risk_flags.map(String)
                : []),
        ]),
    ].filter((code) => {
        const match = code.match(/^key_number_edge_(5|7)$/);
        const line = numberValue(
            record(record(props.prediction?.nfl_board).market).home_spread,
        );
        return (
            !match ||
            (line !== null &&
                Math.abs(Math.abs(line) - Number(match[1])) < 0.05)
        );
    }),
);
const labelize = (value: unknown) =>
    String(value ?? 'Unavailable').replaceAll('_', ' ');
const timestamp = (value: string | null | undefined) =>
    value && Number.isFinite(new Date(value).getTime())
        ? new Date(value).toLocaleString(undefined, {
              month: 'short',
              day: 'numeric',
              hour: 'numeric',
              minute: '2-digit',
              timeZoneName: 'short',
          })
        : 'Unavailable';
</script>

<template>
    <Sheet v-model:open="open">
        <SheetContent
            class="w-full overflow-y-auto px-4 pb-6 sm:max-w-xl sm:px-6"
        >
            <SheetHeader class="px-0 pr-8">
                <SheetTitle
                    >{{ teamLabel(prediction?.game?.away_team) }} @
                    {{ teamLabel(prediction?.game?.home_team) }}</SheetTitle
                >
                <SheetDescription
                    >{{ prediction ? kickoffLabel(prediction) : '' }} · Model
                    forecasts, not bet approvals.</SheetDescription
                >
            </SheetHeader>
            <div v-if="prediction && view" class="space-y-5">
                <section class="rounded-xl border bg-card p-4">
                    <div class="text-xs text-muted-foreground">
                        {{ view.forecastSource }}
                    </div>
                    <div class="mt-1 flex items-baseline justify-between gap-3">
                        <h3 class="text-xl font-semibold">
                            {{ view.winner }} to win
                        </h3>
                        <span
                            class="text-lg font-semibold text-sky-600 dark:text-sky-300"
                            >{{ percent(view.winnerProbability) }}</span
                        >
                    </div>
                    <dl class="mt-4 grid grid-cols-2 gap-3 text-sm">
                        <div>
                            <dt class="text-muted-foreground">
                                Projected margin
                            </dt>
                            <dd class="font-medium">{{ view.marginLabel }}</dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">
                                Projected total
                            </dt>
                            <dd class="font-medium">
                                {{ view.total?.toFixed(1) ?? 'Unavailable' }}
                            </dd>
                        </div>
                    </dl>
                    <p class="mt-3 text-xs text-muted-foreground">
                        Updated {{ timestamp(view.forecastAt) }}
                    </p>
                    <p v-if="view.final" class="mt-3 border-t pt-3 text-sm">
                        {{ prediction.game?.away_score }}–{{
                            prediction.game?.home_score
                        }}
                        final · {{ view.result }}
                    </p>
                </section>
                <section>
                    <h3 class="text-sm font-semibold">
                        {{
                            view.historicalMarket
                                ? 'Recorded pregame market'
                                : 'Market comparison'
                        }}
                    </h3>
                    <dl class="mt-2 divide-y text-sm">
                        <div class="flex justify-between gap-3 py-2">
                            <dt class="text-muted-foreground">Spread lean</dt>
                            <dd class="text-right font-medium">
                                {{ view.spreadLean
                                }}<span
                                    v-if="view.spreadEdge !== null"
                                    class="block text-xs font-normal text-muted-foreground"
                                    >{{ view.spreadEdge.toFixed(1) }}-point
                                    model difference</span
                                >
                            </dd>
                        </div>
                        <div class="flex justify-between gap-3 py-2">
                            <dt class="text-muted-foreground">
                                Home market line
                            </dt>
                            <dd>{{ view.marketSpread }}</dd>
                        </div>
                        <div class="flex justify-between gap-3 py-2">
                            <dt class="text-muted-foreground">Total lean</dt>
                            <dd class="text-right font-medium">
                                {{ view.totalLean
                                }}<span
                                    v-if="view.totalEdge !== null"
                                    class="block text-xs font-normal text-muted-foreground"
                                    >{{ view.totalEdge.toFixed(1) }}-point model
                                    difference</span
                                >
                            </dd>
                        </div>
                    </dl>
                    <p class="mt-2 text-xs text-muted-foreground">
                        {{ view.marketBook || 'No verified market'
                        }}<template v-if="view.marketAt">
                            · {{ timestamp(view.marketAt) }}</template
                        >
                    </p>
                </section>
                <section
                    class="rounded-xl border p-3"
                    :class="
                        ['hold', 'stale', 'missing'].includes(
                            view.researchStatus,
                        )
                            ? 'border-amber-500/30 bg-amber-500/5'
                            : ''
                    "
                >
                    <h3 class="text-sm font-semibold">
                        {{ view.researchLabel }}
                    </h3>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ decisionLabel(view.researchDecision) }}
                    </p>
                    <p
                        v-if="view.researchStatus === 'stale'"
                        class="mt-2 text-sm"
                    >
                        This assessment needs revalidation against the latest
                        forecast and evidence.
                    </p>
                    <ul
                        v-if="reasons.length"
                        class="mt-2 list-disc space-y-1 pl-4 text-sm"
                    >
                        <li v-for="reason in reasons" :key="reason">
                            {{ reason }}
                        </li>
                    </ul>
                    <p
                        v-if="view.researchAt"
                        class="mt-2 text-xs text-muted-foreground"
                    >
                        Last assessed {{ timestamp(view.researchAt) }}
                    </p>
                </section>
                <NflMarketHistory
                    v-if="prediction.game_id ?? prediction.game?.id"
                    :game-id="Number(prediction.game_id ?? prediction.game?.id)"
                />
                <NflMatchupSignals
                    v-if="prediction.game_id ?? prediction.game?.id"
                    :game-id="Number(prediction.game_id ?? prediction.game?.id)"
                />
                <details class="rounded-xl border p-3">
                    <summary class="cursor-pointer text-sm font-medium">
                        Model diagnostics
                    </summary>
                    <p class="mt-3 text-xs text-muted-foreground">
                        Signal scores and tiers are internal model diagnostics,
                        not win probabilities.
                    </p>
                    <dl class="mt-3 grid grid-cols-2 gap-3 text-sm">
                        <div>
                            <dt class="text-muted-foreground">Signal score</dt>
                            <dd>
                                {{
                                    numberValue(layer.score)?.toFixed(1) ??
                                    'Unavailable'
                                }}
                            </dd>
                        </div>
                        <div
                            v-for="market in ['winner', 'spread', 'total']"
                            :key="market"
                        >
                            <dt class="text-muted-foreground capitalize">
                                {{ market }} tier
                            </dt>
                            <dd>{{ labelize(record(tiers[market]).tier) }}</dd>
                        </div>
                    </dl>
                    <ul
                        v-if="diagnostics.length"
                        class="mt-3 list-disc space-y-1 pl-4 text-xs text-muted-foreground"
                    >
                        <li v-for="code in diagnostics" :key="code">
                            {{ labelize(code) }}
                        </li>
                    </ul>
                </details>
                <a
                    :href="`/nfl/games/${prediction.game_id ?? prediction.game?.id}`"
                    class="inline-flex min-h-11 items-center text-sm font-semibold text-sky-600 underline underline-offset-4 dark:text-sky-300"
                    >Full game analysis →</a
                >
            </div>
        </SheetContent>
    </Sheet>
</template>
