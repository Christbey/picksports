<script setup lang="ts">
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import {
    labelizeMlbCode,
    safeMlbPickStatus,
} from '@/lib/mlbRecommendationLabels';
import type { MlbDailyPick } from '@/types/mlb-daily-picks';

defineProps<{
    candidate: MlbDailyPick | null;
}>();

const open = defineModel<boolean>('open', { default: false });

function formatPercent(value?: number | null): string {
    if (value == null) return '-';
    return `${(value * 100).toFixed(1)}%`;
}

function formatNumber(value?: number | null): string {
    if (value == null) return '-';
    return Number(value).toFixed(2);
}

function formatOdds(value?: number | null): string {
    if (value == null) return '-';
    return value > 0 ? `+${value}` : `${value}`;
}
</script>

<template>
    <Sheet v-model:open="open">
        <SheetContent side="right" class="w-full overflow-y-auto sm:max-w-xl">
            <SheetHeader v-if="candidate">
                <SheetTitle>{{ candidate.label }}</SheetTitle>
                <SheetDescription>
                    {{ safeMlbPickStatus(candidate) }} ·
                    {{ labelizeMlbCode(candidate.market_type) }}
                </SheetDescription>
            </SheetHeader>

            <div v-if="candidate" class="mt-6 space-y-5">
                <section class="rounded-lg border p-4">
                    <div class="mb-3 text-sm font-semibold">
                        Market-Aware Projection
                    </div>
                    <div class="grid grid-cols-2 gap-3 text-sm">
                        <div>
                            <div class="text-muted-foreground">Price</div>
                            <div class="font-semibold">
                                {{ formatOdds(candidate.price) }}
                            </div>
                        </div>
                        <div>
                            <div class="text-muted-foreground">Line</div>
                            <div class="font-semibold">
                                {{ formatNumber(candidate.line) }}
                            </div>
                        </div>
                        <div>
                            <div class="text-muted-foreground">Model</div>
                            <div class="font-semibold">
                                {{ formatPercent(candidate.model_probability) }}
                            </div>
                        </div>
                        <div>
                            <div class="text-muted-foreground">Market</div>
                            <div class="font-semibold">
                                {{
                                    formatPercent(candidate.market_probability)
                                }}
                            </div>
                        </div>
                        <div>
                            <div class="text-muted-foreground">Blend</div>
                            <div class="font-semibold">
                                {{ formatPercent(candidate.blend_probability) }}
                            </div>
                        </div>
                        <div>
                            <div class="text-muted-foreground">No-vig edge</div>
                            <div class="font-semibold">
                                {{ formatPercent(candidate.edge_no_vig) }}
                            </div>
                        </div>
                    </div>
                </section>

                <section class="rounded-lg border p-4">
                    <div class="mb-3 text-sm font-semibold">Why</div>
                    <div class="flex flex-wrap gap-2">
                        <span
                            v-for="reason in candidate.reason_codes"
                            :key="reason"
                            class="rounded-full border px-2.5 py-1 text-xs"
                        >
                            {{ labelizeMlbCode(reason) }}
                        </span>
                    </div>
                </section>

                <section class="rounded-lg border p-4">
                    <div class="mb-3 text-sm font-semibold">Risk</div>
                    <div
                        v-if="candidate.risk_flags.length"
                        class="flex flex-wrap gap-2"
                    >
                        <span
                            v-for="risk in candidate.risk_flags"
                            :key="risk"
                            class="rounded-full border border-amber-500/30 bg-amber-500/10 px-2.5 py-1 text-xs text-amber-700 dark:text-amber-300"
                        >
                            {{ labelizeMlbCode(risk) }}
                        </span>
                    </div>
                    <div v-else class="text-sm text-muted-foreground">
                        No major risk flags on this candidate.
                    </div>
                </section>

                <section class="rounded-lg border p-4">
                    <div class="mb-3 text-sm font-semibold">Diagnostics</div>
                    <pre
                        class="max-h-72 overflow-auto rounded-lg bg-muted p-3 text-xs leading-5"
                        >{{
                            JSON.stringify(
                                {
                                    feature_snapshot:
                                        candidate.feature_snapshot,
                                    market_snapshot: candidate.market_snapshot,
                                },
                                null,
                                2,
                            )
                        }}</pre
                    >
                </section>
            </div>
        </SheetContent>
    </Sheet>
</template>
