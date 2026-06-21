<script setup lang="ts">
import { ChevronRight } from 'lucide-vue-next';
import { computed } from 'vue';
import { labelizeMlbCode, tierFromScore } from '@/lib/mlbRecommendationLabels';
import type { MlbDailyPick } from '@/types/mlb-daily-picks';

const props = withDefaults(
    defineProps<{
        candidate: MlbDailyPick;
        variant?: 'hero' | 'compact' | 'list' | 'detail';
        showGameContext?: boolean;
        showDiagnostics?: boolean;
    }>(),
    {
        variant: 'compact',
        showGameContext: true,
        showDiagnostics: false,
    },
);

const emit = defineEmits<{
    select: [candidate: MlbDailyPick];
}>();

const isHero = computed(() => props.variant === 'hero');
const isList = computed(() => props.variant === 'list');
const statusLabel = computed(() => {
    if (props.candidate.is_tracking_only || !props.candidate.is_public) {
        return tierFromScore(props.candidate.score);
    }

    return 'Validated';
});

function formatOdds(value?: number | null): string {
    if (value == null) return '-';
    return value > 0 ? `+${value}` : `${value}`;
}

function formatPercent(value?: number | null): string {
    if (value == null) return '-';
    return `${(value * 100).toFixed(1)}%`;
}

function formatTime(value?: string | null): string {
    if (!value) return 'Time pending';

    return new Intl.DateTimeFormat(undefined, {
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    }).format(new Date(value));
}

function scoreTone(score: number): string {
    if (score >= 80) return 'text-emerald-500';
    if (score >= 68) return 'text-sky-500';
    if (score >= 58) return 'text-amber-500';
    return 'text-muted-foreground';
}

function conciseMarketLine(candidate: MlbDailyPick): string {
    const parts = [
        candidate.line != null ? `Line ${candidate.line}` : null,
        candidate.price != null ? formatOdds(candidate.price) : null,
        candidate.book,
    ];

    return parts.filter(Boolean).join(' | ');
}

function marketTone(marketType: string): string {
    if (marketType.includes('prop'))
        return 'border-violet-500/30 bg-violet-500/10 text-violet-700 dark:text-violet-300';
    if (marketType.includes('total'))
        return 'border-sky-500/30 bg-sky-500/10 text-sky-700 dark:text-sky-300';
    if (marketType.includes('run_line'))
        return 'border-amber-500/30 bg-amber-500/10 text-amber-700 dark:text-amber-300';

    return 'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300';
}
</script>

<template>
    <button
        type="button"
        class="group relative flex h-full w-full overflow-hidden rounded-lg border bg-card text-left shadow-sm transition hover:-translate-y-0.5 hover:border-emerald-500/40 hover:shadow-md focus:ring-2 focus:ring-emerald-500/40 focus:outline-none"
        :class="[
            isHero ? 'min-h-[178px] p-4' : 'min-h-[132px] p-4',
            isList ? 'min-h-0 flex-row items-center gap-4' : 'flex-col',
        ]"
        @click="emit('select', candidate)"
    >
        <div
            class="absolute inset-x-0 top-0 h-1"
            :class="
                candidate.market_type.includes('prop')
                    ? 'bg-violet-500'
                    : candidate.market_type.includes('total')
                      ? 'bg-sky-500'
                      : 'bg-emerald-500'
            "
        />

        <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <span
                        class="rounded-full border px-2.5 py-1 text-xs font-semibold"
                        :class="marketTone(candidate.market_type)"
                    >
                        {{ labelizeMlbCode(candidate.market_type) }}
                    </span>
                    <span
                        class="rounded-full border px-2.5 py-1 text-xs font-medium text-muted-foreground"
                    >
                        {{ statusLabel }}
                    </span>
                </div>
                <h3
                    class="mt-3 leading-tight font-semibold"
                    :class="isHero ? 'text-xl' : 'text-base'"
                >
                    {{ candidate.label }}
                </h3>
                <div
                    v-if="showGameContext"
                    class="mt-1 text-sm text-muted-foreground"
                >
                    {{ candidate.game?.short_name }} ·
                    {{ formatTime(candidate.game?.game_date) }}
                </div>
                <div class="mt-2 text-xs font-medium text-muted-foreground">
                    {{
                        conciseMarketLine(candidate) || 'Market context pending'
                    }}
                </div>
            </div>

            <div
                class="grid h-12 w-12 shrink-0 place-items-center rounded-xl border bg-background/80"
            >
                <div class="text-center">
                    <div
                        class="text-base font-black"
                        :class="scoreTone(candidate.score)"
                    >
                        {{ candidate.score }}
                    </div>
                    <div
                        class="text-[9px] font-semibold text-muted-foreground uppercase"
                    >
                        Score
                    </div>
                </div>
            </div>
        </div>

        <div
            class="mt-auto flex flex-wrap items-center justify-between gap-3 border-t pt-3 text-xs text-muted-foreground"
        >
            <div class="flex min-w-0 flex-wrap items-center gap-x-3 gap-y-1">
                <span
                    >Model
                    {{ formatPercent(candidate.model_probability) }}</span
                >
                <span
                    >Market
                    {{ formatPercent(candidate.market_probability) }}</span
                >
                <span v-if="candidate.reason_codes.length">
                    {{ candidate.reason_codes.length }} reasons
                </span>
                <span
                    v-if="candidate.risk_flags.length"
                    class="text-amber-600 dark:text-amber-400"
                >
                    {{ candidate.risk_flags.length }} risks
                </span>
            </div>
            <span class="inline-flex items-center gap-1">
                Details
                <ChevronRight
                    class="h-3.5 w-3.5 transition group-hover:translate-x-0.5"
                />
            </span>
        </div>
    </button>
</template>
