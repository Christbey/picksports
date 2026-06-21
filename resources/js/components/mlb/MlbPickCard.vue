<script setup lang="ts">
import { AlertTriangle, ChevronRight, ShieldCheck } from 'lucide-vue-next';
import { computed } from 'vue';
import {
    labelizeMlbCode,
    safeMlbPickStatus,
    tierFromScore,
} from '@/lib/mlbRecommendationLabels';
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
const statusLabel = computed(() => safeMlbPickStatus(props.candidate));
const tierLabel = computed(() => tierFromScore(props.candidate.score));
const ringStyle = computed(() => ({
    background: `conic-gradient(rgb(16 185 129) ${props.candidate.score * 3.6}deg, rgb(63 63 70 / 0.22) 0deg)`,
}));

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

function marketTone(marketType: string): string {
    if (marketType.includes('prop')) return 'border-violet-500/30 bg-violet-500/10 text-violet-700 dark:text-violet-300';
    if (marketType.includes('total')) return 'border-sky-500/30 bg-sky-500/10 text-sky-700 dark:text-sky-300';
    if (marketType.includes('run_line')) return 'border-amber-500/30 bg-amber-500/10 text-amber-700 dark:text-amber-300';

    return 'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300';
}
</script>

<template>
    <button
        type="button"
        class="group relative flex h-full w-full overflow-hidden rounded-lg border bg-card text-left shadow-sm transition hover:-translate-y-0.5 hover:border-emerald-500/40 hover:shadow-md focus:outline-none focus:ring-2 focus:ring-emerald-500/40"
        :class="[
            isHero ? 'min-h-[330px] p-5' : 'min-h-[250px] p-4',
            isList ? 'min-h-0 flex-row items-center gap-4' : 'flex-col',
        ]"
        @click="emit('select', candidate)"
    >
        <div
            class="absolute inset-x-0 top-0 h-1"
            :class="candidate.market_type.includes('prop') ? 'bg-violet-500' : candidate.market_type.includes('total') ? 'bg-sky-500' : 'bg-emerald-500'"
        />
        <div
            v-if="isHero"
            class="pointer-events-none absolute -right-10 -top-10 h-32 w-32 rotate-45 rounded-[1.75rem] border border-emerald-500/20"
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
                    class="mt-4 font-semibold leading-tight"
                    :class="isHero ? 'text-2xl' : 'text-lg'"
                >
                    {{ candidate.label }}
                </h3>
                <div class="mt-2 flex flex-wrap items-center gap-2">
                    <span class="rounded-full border bg-background px-2.5 py-1 text-sm font-bold">
                        {{ formatOdds(candidate.price) }}
                    </span>
                    <span
                        v-if="candidate.line != null"
                        class="rounded-full border bg-background px-2.5 py-1 text-xs font-semibold text-muted-foreground"
                    >
                        Line {{ candidate.line }}
                    </span>
                </div>
                <div
                    v-if="showGameContext"
                    class="mt-1 text-sm text-muted-foreground"
                >
                    {{ candidate.game?.short_name }} ·
                    {{ formatTime(candidate.game?.game_date) }}
                </div>
            </div>

            <div
                class="relative grid h-16 w-16 shrink-0 place-items-center rounded-full"
                :style="ringStyle"
            >
                <div
                    class="grid h-12 w-12 place-items-center rounded-full bg-card text-center"
                >
                    <div class="text-lg font-bold" :class="scoreTone(candidate.score)">
                        {{ candidate.score }}
                    </div>
                </div>
            </div>
        </div>

        <div class="mt-5 grid grid-cols-4 gap-2 text-xs">
            <div class="rounded-lg bg-muted/50 p-2">
                <div class="text-muted-foreground">Price</div>
                <div class="mt-1 font-semibold">{{ formatOdds(candidate.price) }}</div>
            </div>
            <div class="rounded-lg bg-muted/50 p-2">
                <div class="text-muted-foreground">Model</div>
                <div class="mt-1 font-semibold">
                    {{ formatPercent(candidate.model_probability) }}
                </div>
            </div>
            <div class="rounded-lg bg-muted/50 p-2">
                <div class="text-muted-foreground">Market</div>
                <div class="mt-1 font-semibold">
                    {{ formatPercent(candidate.market_probability) }}
                </div>
            </div>
            <div class="rounded-lg bg-muted/50 p-2">
                <div class="text-muted-foreground">Blend</div>
                <div class="mt-1 font-semibold">
                    {{ formatPercent(candidate.blend_probability) }}
                </div>
            </div>
        </div>

        <div class="mt-4 h-2 rounded-full bg-muted">
            <div
                class="h-full rounded-full bg-emerald-500"
                :style="{ width: `${Math.min(candidate.score, 100)}%` }"
            />
        </div>

        <div class="mt-4">
            <div class="mb-2 flex items-center gap-2 text-xs font-semibold">
                <ShieldCheck class="h-3.5 w-3.5 text-emerald-500" />
                Why this exists
            </div>
            <div class="flex flex-wrap gap-1.5">
                <span
                    v-for="reason in candidate.reason_codes.slice(0, 3)"
                    :key="reason"
                    class="rounded-full border bg-background px-2 py-0.5 text-xs text-muted-foreground"
                >
                    {{ labelizeMlbCode(reason) }}
                </span>
            </div>
        </div>

        <div class="mt-4 min-h-9">
            <div
                v-if="candidate.risk_flags.length > 0"
                class="flex items-start gap-2 rounded-lg border border-amber-500/25 bg-amber-500/10 p-2 text-xs text-amber-700 dark:text-amber-300"
            >
                <AlertTriangle class="mt-0.5 h-3.5 w-3.5 shrink-0" />
                <span>
                    {{
                        candidate.risk_flags
                            .slice(0, 3)
                            .map(labelizeMlbCode)
                            .join(' · ')
                    }}
                </span>
            </div>
            <div
                v-else
                class="rounded-lg border border-emerald-500/20 bg-emerald-500/10 p-2 text-xs text-emerald-700 dark:text-emerald-300"
            >
                No major card-level risk flags.
            </div>
        </div>

        <div
            class="mt-auto flex items-center justify-between pt-4 text-xs text-muted-foreground"
        >
            <span>{{ tierLabel }}</span>
            <span class="inline-flex items-center gap-1">
                Details
                <ChevronRight class="h-3.5 w-3.5 transition group-hover:translate-x-0.5" />
            </span>
        </div>
    </button>
</template>
