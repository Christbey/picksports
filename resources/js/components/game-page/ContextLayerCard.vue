<script setup lang="ts">
type ContextLayer = Record<string, unknown>;

const props = defineProps<{
    contextLayer?: ContextLayer | null;
}>();

const asRecord = (value: unknown): Record<string, unknown> =>
    value && typeof value === 'object'
        ? (value as Record<string, unknown>)
        : {};

const numberValue = (value: unknown): number | null =>
    typeof value === 'number'
        ? value
        : typeof value === 'string' &&
            value.trim() !== '' &&
            !Number.isNaN(Number(value))
          ? Number(value)
          : null;

const stringValue = (value: unknown, fallback = 'Unknown'): string =>
    typeof value === 'string' && value.trim() !== '' ? value : fallback;

const arrayValue = (value: unknown): string[] =>
    Array.isArray(value)
        ? value.map((item) => String(item)).filter((item) => item.length > 0)
        : [];

const formatNumber = (value: unknown, decimals = 1): string => {
    const numeric = numberValue(value);

    return numeric === null ? 'N/A' : numeric.toFixed(decimals);
};

const series = asRecord(props.contextLayer?.series_total_trend);
const overtime = asRecord(props.contextLayer?.overtime_adjusted_total);
const nonOt = asRecord(props.contextLayer?.non_ot_series_average);
const spikes = asRecord(props.contextLayer?.quarter_scoring_spikes);
const foulRisk = asRecord(props.contextLayer?.playoff_foul_late_game_risk);
const injuries = asRecord(props.contextLayer?.injury_impact);
const market = asRecord(props.contextLayer?.market_movement);
const conflict = asRecord(props.contextLayer?.model_vs_series_conflict);
const historical = asRecord(props.contextLayer?.historical_spot_reference);
const reasonCodes = arrayValue(props.contextLayer?.reason_codes);
const riskFlags = arrayValue(props.contextLayer?.risk_flags);
</script>

<template>
    <section v-if="contextLayer" class="ui-surface p-5 md:p-6">
        <div class="flex items-start justify-between gap-4">
            <div>
                <h3 class="ui-kicker">Context Layer</h3>
                <p class="mt-2 text-sm text-muted-foreground">
                    Series, overtime, market, injury, and variance checks behind
                    the bet decision.
                </p>
            </div>
            <span
                v-if="conflict.has_conflict === true"
                class="rounded border border-border px-2 py-1 text-xs font-semibold text-muted-foreground uppercase"
            >
                Conflict
            </span>
        </div>

        <div class="mt-5 grid gap-3 sm:grid-cols-2">
            <div class="ui-surface-subtle p-4">
                <p
                    class="text-xs font-semibold text-muted-foreground uppercase"
                >
                    Series Total Trend
                </p>
                <p class="mt-2 text-2xl font-semibold tracking-tight">
                    {{ formatNumber(series.average_total) }}
                </p>
                <p class="mt-1 text-sm text-muted-foreground">
                    {{ stringValue(series.direction) }} vs market
                    {{ formatNumber(series.market_total) }}
                </p>
            </div>

            <div class="ui-surface-subtle p-4">
                <p
                    class="text-xs font-semibold text-muted-foreground uppercase"
                >
                    OT Adjusted
                </p>
                <p class="mt-2 text-2xl font-semibold tracking-tight">
                    {{ formatNumber(overtime.average) }}
                </p>
                <p class="mt-1 text-sm text-muted-foreground">
                    {{ overtime.overtime_games ?? 0 }} OT games in sample
                </p>
            </div>

            <div class="ui-surface-subtle p-4">
                <p
                    class="text-xs font-semibold text-muted-foreground uppercase"
                >
                    Non-OT Average
                </p>
                <p class="mt-2 text-2xl font-semibold tracking-tight">
                    {{ formatNumber(nonOt.average) }}
                </p>
                <p class="mt-1 text-sm text-muted-foreground">
                    {{ nonOt.sample_size ?? 0 }} regulation games sampled
                </p>
            </div>

            <div class="ui-surface-subtle p-4">
                <p
                    class="text-xs font-semibold text-muted-foreground uppercase"
                >
                    Quarter Spikes
                </p>
                <p class="mt-2 text-2xl font-semibold tracking-tight">
                    {{ spikes.count ?? 0 }}
                </p>
                <p class="mt-1 text-sm text-muted-foreground">
                    Max quarter total
                    {{ formatNumber(spikes.max_quarter_total) }}
                </p>
            </div>
        </div>

        <div class="mt-5 grid gap-4 text-sm md:grid-cols-2">
            <div>
                <p class="font-semibold text-foreground">Risk Context</p>
                <ul class="mt-2 space-y-1 text-muted-foreground">
                    <li>
                        Late-game foul risk:
                        {{ stringValue(foulRisk.risk, 'low') }}
                    </li>
                    <li>
                        Injury importance:
                        {{ stringValue(injuries.level, 'none') }}
                    </li>
                    <li>
                        Model vs series:
                        {{
                            conflict.has_conflict === true
                                ? 'conflict'
                                : 'aligned'
                        }}
                    </li>
                </ul>
            </div>

            <div>
                <p class="font-semibold text-foreground">Market Movement</p>
                <ul class="mt-2 space-y-1 text-muted-foreground">
                    <li>Total move: {{ formatNumber(market.total_move) }}</li>
                    <li>
                        Home spread move:
                        {{ formatNumber(market.home_spread_move) }}
                    </li>
                    <li>{{ market.snapshot_count ?? 0 }} odds snapshots</li>
                </ul>
            </div>
        </div>

        <div
            v-if="historical.available === true"
            class="ui-surface-subtle mt-5 p-4"
        >
            <p class="text-xs font-semibold text-muted-foreground uppercase">
                Historical Spot Reference
            </p>
            <div class="mt-3 grid gap-3 sm:grid-cols-4">
                <div>
                    <p class="text-xl font-semibold tracking-tight">
                        {{ historical.sample_size ?? 0 }}
                    </p>
                    <p class="text-xs text-muted-foreground">Similar spots</p>
                </div>
                <div>
                    <p class="text-xl font-semibold tracking-tight">
                        {{ formatNumber(historical.hit_rate) }}%
                    </p>
                    <p class="text-xs text-muted-foreground">Bet hit rate</p>
                </div>
                <div>
                    <p class="text-xl font-semibold tracking-tight">
                        {{ formatNumber(historical.winner_accuracy) }}%
                    </p>
                    <p class="text-xs text-muted-foreground">Winner accuracy</p>
                </div>
                <div>
                    <p class="text-xl font-semibold tracking-tight">
                        {{ formatNumber(historical.avg_total_error) }}
                    </p>
                    <p class="text-xs text-muted-foreground">Avg total error</p>
                </div>
            </div>
        </div>

        <div
            v-if="reasonCodes.length || riskFlags.length"
            class="mt-5 flex flex-wrap gap-2"
        >
            <span
                v-for="code in [...riskFlags, ...reasonCodes]"
                :key="code"
                class="rounded border border-border px-2 py-1 text-xs text-muted-foreground"
            >
                {{ code.replaceAll('_', ' ') }}
            </span>
        </div>
    </section>
</template>
