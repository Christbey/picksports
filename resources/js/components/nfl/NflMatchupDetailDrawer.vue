<script setup lang="ts">
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import type { ApiV2Prediction, ApiV2Record } from '@/types';

defineProps<{
    prediction: ApiV2Prediction | null;
}>();

const open = defineModel<boolean>('open', { default: false });

function record(value: unknown): ApiV2Record {
    return value && typeof value === 'object' ? (value as ApiV2Record) : {};
}

function numberValue(value: unknown): number | null {
    if (value === null || value === undefined || value === '') return null;

    const numeric = Number(value);

    return Number.isFinite(numeric) ? numeric : null;
}

function teamAbbreviation(team: unknown): string {
    const payload = record(team);

    return String(
        payload.abbreviation ??
            payload.short_display_name ??
            payload.display_name ??
            '',
    );
}

function matchupLabel(prediction: ApiV2Prediction | null): string {
    const game = prediction?.game;
    if (!game) return 'NFL Matchup';

    return `${teamAbbreviation(game.away_team)} @ ${teamAbbreviation(game.home_team)}`;
}

function formatPercent(value?: unknown): string {
    const numeric = numberValue(value);
    if (numeric == null) return '-';

    return `${(numeric * 100).toFixed(1)}%`;
}

function formatNumber(value?: unknown, digits = 1): string {
    const numeric = numberValue(value);
    if (numeric == null) return '-';

    return numeric.toFixed(digits);
}

function formatSigned(value?: unknown, digits = 1): string {
    const numeric = numberValue(value);
    if (numeric == null) return '-';

    return `${numeric > 0 ? '+' : ''}${numeric.toFixed(digits)}`;
}

function labelize(value?: unknown): string {
    const label = String(value ?? '').trim();
    if (!label) return '-';

    return label
        .replaceAll('_', ' ')
        .replaceAll('-', ' ')
        .replace(/\b\w/g, (char) => char.toUpperCase());
}

function proLayer(prediction: ApiV2Prediction | null): ApiV2Record {
    return record(prediction?.pro_signal_layer);
}

function marketContext(prediction: ApiV2Prediction | null): ApiV2Record {
    return record(proLayer(prediction).market_context);
}

function marketScores(prediction: ApiV2Prediction | null): ApiV2Record {
    return record(proLayer(prediction).market_scores);
}

function scoreTier(
    prediction: ApiV2Prediction | null,
    market: 'winner' | 'spread' | 'total',
): string {
    return labelize(record(marketScores(prediction)[market]).tier);
}

function analysisActive(prediction: ApiV2Prediction | null): boolean {
    const analysis = record(prediction?.prediction_analysis);

    return analysis.enabled !== false && analysis.applied === true;
}

function exactSpreadLine(
    prediction: ApiV2Prediction | null,
    line: number,
): boolean {
    const spread = numberValue(marketContext(prediction).market_spread);

    return spread !== null && Math.abs(Math.abs(spread) - line) < 0.05;
}

function shouldShowReasonCode(
    prediction: ApiV2Prediction | null,
    code: string,
): boolean {
    const keyMatch = code.match(/^key_number_edge_(5|7)$/);
    if (!keyMatch) return true;

    return exactSpreadLine(prediction, Number(keyMatch[1]));
}

function reasonCodes(prediction: ApiV2Prediction | null): string[] {
    if (!analysisActive(prediction)) return [];

    const codes = proLayer(prediction).reason_codes;

    return Array.isArray(codes)
        ? codes
              .map((code) => String(code))
              .filter((code) => shouldShowReasonCode(prediction, code))
        : [];
}

function riskFlags(prediction: ApiV2Prediction | null): string[] {
    if (!analysisActive(prediction)) return [];

    const direct = proLayer(prediction).risk_flags;
    if (Array.isArray(direct)) return direct.map((flag) => String(flag));

    const analysis = record(prediction?.prediction_analysis);
    const analysisFlags = analysis.risk_flags;

    return Array.isArray(analysisFlags)
        ? analysisFlags.map((flag) => String(flag))
        : [];
}

function finalScoreLabel(prediction: ApiV2Prediction | null): string {
    const status = String(prediction?.status ?? prediction?.game?.status ?? '');
    if (!status.toLowerCase().includes('final')) return 'Not Final';

    const awayScore = numberValue(prediction?.game?.away_score);
    const homeScore = numberValue(prediction?.game?.home_score);

    if (awayScore == null || homeScore == null) return 'Final';

    return `${teamAbbreviation(prediction?.game?.away_team)} ${awayScore} - ${teamAbbreviation(prediction?.game?.home_team)} ${homeScore}`;
}

function modelResultLabel(prediction: ApiV2Prediction | null): string {
    const status = String(prediction?.status ?? prediction?.game?.status ?? '');
    if (!status.toLowerCase().includes('final')) return 'Pending';

    if (prediction?.winner_correct === true) return 'Projection won';
    if (prediction?.winner_correct === false) return 'Projection lost';

    return 'Ungraded';
}
</script>

<template>
    <Sheet v-model:open="open">
        <SheetContent class="w-full overflow-y-auto sm:max-w-xl">
            <SheetHeader>
                <SheetTitle>{{ matchupLabel(prediction) }}</SheetTitle>
                <SheetDescription>
                    NFL model, market, signal, and risk context.
                </SheetDescription>
            </SheetHeader>

            <div v-if="prediction" class="mt-6 space-y-5">
                <section class="grid gap-3 sm:grid-cols-2">
                    <div class="rounded-xl border bg-card p-3">
                        <div
                            class="text-xs font-semibold text-muted-foreground"
                        >
                            Moneyline Pick
                        </div>
                        <div class="mt-1 text-lg font-bold">
                            {{ prediction.pick?.label ?? '-' }}
                        </div>
                        <div class="text-sm text-muted-foreground">
                            Home win
                            {{ formatPercent(prediction.win_probability) }}
                        </div>
                    </div>
                    <div class="rounded-xl border bg-card p-3">
                        <div
                            class="text-xs font-semibold text-muted-foreground"
                        >
                            Result
                        </div>
                        <div class="mt-1 text-lg font-bold">
                            {{ modelResultLabel(prediction) }}
                        </div>
                        <div class="text-sm text-muted-foreground">
                            {{ finalScoreLabel(prediction) }}
                        </div>
                    </div>
                </section>

                <section class="rounded-xl border bg-card p-4">
                    <h3 class="text-sm font-semibold">Projection</h3>
                    <div class="mt-3 grid gap-3 sm:grid-cols-3">
                        <div>
                            <div class="text-xs text-muted-foreground">
                                Spread
                            </div>
                            <div class="font-semibold">
                                {{ formatSigned(prediction.predicted_spread) }}
                            </div>
                        </div>
                        <div>
                            <div class="text-xs text-muted-foreground">
                                Total
                            </div>
                            <div class="font-semibold">
                                {{ formatNumber(prediction.predicted_total) }}
                            </div>
                        </div>
                        <div>
                            <div class="text-xs text-muted-foreground">
                                Confidence
                            </div>
                            <div class="font-semibold">
                                {{
                                    formatNumber(prediction.confidence_score, 1)
                                }}
                            </div>
                        </div>
                    </div>
                </section>

                <section class="rounded-xl border bg-card p-4">
                    <h3 class="text-sm font-semibold">Market Context</h3>
                    <div class="mt-3 grid gap-3 sm:grid-cols-2">
                        <div>
                            <div class="text-xs text-muted-foreground">
                                Market Spread
                            </div>
                            <div class="font-semibold">
                                {{
                                    formatSigned(
                                        marketContext(prediction).market_spread,
                                    )
                                }}
                            </div>
                        </div>
                        <div>
                            <div class="text-xs text-muted-foreground">
                                Spread Edge
                            </div>
                            <div class="font-semibold">
                                {{
                                    formatSigned(
                                        marketContext(prediction).spread_edge,
                                    )
                                }}
                            </div>
                        </div>
                        <div>
                            <div class="text-xs text-muted-foreground">
                                Market Total
                            </div>
                            <div class="font-semibold">
                                {{
                                    formatNumber(
                                        marketContext(prediction).market_total,
                                    )
                                }}
                            </div>
                        </div>
                        <div>
                            <div class="text-xs text-muted-foreground">
                                Total Edge
                            </div>
                            <div class="font-semibold">
                                {{
                                    formatSigned(
                                        marketContext(prediction).total_edge,
                                    )
                                }}
                            </div>
                        </div>
                    </div>
                </section>

                <section
                    v-if="analysisActive(prediction)"
                    class="rounded-xl border bg-card p-4"
                >
                    <h3 class="text-sm font-semibold">Signal Tiers</h3>
                    <div class="mt-3 grid gap-3 sm:grid-cols-3">
                        <div>
                            <div class="text-xs text-muted-foreground">
                                Moneyline
                            </div>
                            <div class="font-semibold">
                                {{ scoreTier(prediction, 'winner') }}
                            </div>
                        </div>
                        <div>
                            <div class="text-xs text-muted-foreground">
                                Spread
                            </div>
                            <div class="font-semibold">
                                {{ scoreTier(prediction, 'spread') }}
                            </div>
                        </div>
                        <div>
                            <div class="text-xs text-muted-foreground">
                                Total
                            </div>
                            <div class="font-semibold">
                                {{ scoreTier(prediction, 'total') }}
                            </div>
                        </div>
                    </div>
                </section>

                <section
                    v-if="analysisActive(prediction)"
                    class="rounded-xl border bg-card p-4"
                >
                    <h3 class="text-sm font-semibold">Reason Codes</h3>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <span
                            v-for="code in reasonCodes(prediction)"
                            :key="code"
                            class="rounded-full border bg-background px-2.5 py-1 text-xs font-semibold text-muted-foreground"
                        >
                            {{ labelize(code) }}
                        </span>
                        <span
                            v-if="reasonCodes(prediction).length === 0"
                            class="text-sm text-muted-foreground"
                        >
                            No reason codes available.
                        </span>
                    </div>
                </section>

                <section
                    v-if="analysisActive(prediction)"
                    class="rounded-xl border bg-card p-4"
                >
                    <h3 class="text-sm font-semibold">Risk Flags</h3>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <span
                            v-for="flag in riskFlags(prediction)"
                            :key="flag"
                            class="rounded-full border border-amber-500/30 bg-amber-500/10 px-2.5 py-1 text-xs font-semibold text-amber-700 dark:text-amber-300"
                        >
                            {{ labelize(flag) }}
                        </span>
                        <span
                            v-if="riskFlags(prediction).length === 0"
                            class="text-sm text-muted-foreground"
                        >
                            No risk flags available.
                        </span>
                    </div>
                </section>
            </div>
        </SheetContent>
    </Sheet>
</template>
