<script setup lang="ts">
import { candidateRecommendation } from '@/lib/predictionRecommendation';
import type { ApiV2Prediction } from '@/types';
import type { MlbDailyPick } from '@/types/mlb-daily-picks';
import {
    numberValue,
    formatPercent,
    formatSignedPercent,
    formatNumber,
    formatOdds,
    modelSourceLabel,
} from '@/lib/mlbMatchupPresentation';
defineProps<{ prediction: ApiV2Prediction; candidate?: MlbDailyPick | null }>();
</script>

<template>
    <section class="rounded-2xl border p-4">
        <div class="mb-4">
            <div class="text-sm font-semibold">Model vs Market</div>
            <p class="mt-1 text-sm leading-6 text-muted-foreground">
                This compares our model to sportsbook odds. The blended read
                leans the model back toward the market, and edge is the possible
                value gap we are tracking.
            </p>
        </div>
        <div class="grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
            <div class="rounded-xl bg-muted/45 p-3">
                <div class="text-xs text-muted-foreground">Model says</div>
                <div class="mt-1 font-semibold">
                    {{
                        formatPercent(
                            candidate?.model_probability ??
                                prediction.market_aware_projection
                                    ?.model_probability ??
                                numberValue(
                                    prediction.projection?.home_win_probability,
                                ),
                        )
                    }}
                </div>
                <div class="mt-1 text-[11px] text-muted-foreground">
                    {{ modelSourceLabel(candidate) }}
                </div>
            </div>
            <div class="rounded-xl bg-muted/45 p-3">
                <div class="text-xs text-muted-foreground">Market says</div>
                <div class="mt-1 font-semibold">
                    {{
                        formatPercent(
                            candidate?.market_probability ??
                                prediction.market_aware_projection
                                    ?.market_probability ??
                                candidateRecommendation(prediction)
                                    ?.no_vig_implied_probability,
                        )
                    }}
                </div>
                <div class="mt-1 text-[11px] text-muted-foreground">
                    What the odds imply.
                </div>
            </div>
            <div class="rounded-xl bg-muted/45 p-3">
                <div class="text-xs text-muted-foreground">Blended read</div>
                <div class="mt-1 font-semibold">
                    {{
                        formatPercent(
                            candidate?.blend_probability ??
                                prediction.market_aware_projection
                                    ?.blended_probability,
                        )
                    }}
                </div>
                <div class="mt-1 text-[11px] text-muted-foreground">
                    Model plus market caution.
                </div>
            </div>
            <div class="rounded-xl bg-muted/45 p-3">
                <div class="text-xs text-muted-foreground">Edge</div>
                <div class="mt-1 font-semibold">
                    {{
                        formatSignedPercent(
                            candidate?.edge_no_vig ??
                                candidate?.edge_raw ??
                                candidateRecommendation(prediction)
                                    ?.no_vig_edge ??
                                candidateRecommendation(prediction)?.raw_edge,
                        )
                    }}
                </div>
                <div class="mt-1 text-[11px] text-muted-foreground">
                    Positive means possible value, not a guarantee.
                </div>
            </div>
        </div>

        <div class="mt-4 grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
            <div>
                <div class="text-muted-foreground">Model margin</div>
                <div class="font-semibold">
                    {{
                        formatNumber(
                            numberValue(
                                prediction.projection?.predicted_spread,
                            ),
                        )
                    }}
                </div>
            </div>
            <div>
                <div class="text-muted-foreground">Model total</div>
                <div class="font-semibold">
                    {{
                        formatNumber(
                            numberValue(prediction.projection?.predicted_total),
                        )
                    }}
                </div>
            </div>
            <div>
                <div class="text-muted-foreground">Odds price</div>
                <div class="font-semibold">
                    {{ formatOdds(candidate?.price) }}
                </div>
            </div>
            <div>
                <div class="text-muted-foreground">Market line</div>
                <div class="font-semibold">
                    {{ formatNumber(candidate?.line) }}
                </div>
            </div>
        </div>
    </section>
</template>
