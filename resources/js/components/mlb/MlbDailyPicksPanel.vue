<script setup lang="ts">
import { AlertTriangle, BarChart3, Target } from 'lucide-vue-next';
import { computed, onMounted, ref } from 'vue';
import { Card, CardContent, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { useApiV2Client } from '@/composables/useApiV2Client';

type DailyPick = {
    id: number;
    market_type: string;
    label: string;
    side: string;
    line?: number | null;
    price?: number | null;
    score: number;
    status: string;
    internal_candidate_label?: string | null;
    model_probability?: number | null;
    market_probability?: number | null;
    blend_probability?: number | null;
    edge_no_vig?: number | null;
    reason_codes: string[];
    risk_flags: string[];
    explanation: string;
    game?: {
        short_name?: string | null;
        game_date?: string | null;
    } | null;
};

type DailyPicksPayload = {
    data: {
        date: string;
        mode: string;
        target_count: number;
        public_promoted_count: number;
        candidate_count: number;
        top_picks: DailyPick[];
        blocked_reasons: string[];
    };
};

const props = defineProps<{
    season?: string | number | null;
}>();

const api = useApiV2Client();
const loading = ref(true);
const error = ref<string | null>(null);
const payload = ref<DailyPicksPayload['data'] | null>(null);

const picks = computed(() => payload.value?.top_picks ?? []);

function formatOdds(value?: number | null): string {
    if (value == null) return '-';
    return value > 0 ? `+${value}` : `${value}`;
}

function formatPercent(value?: number | null): string {
    if (value == null) return '-';
    return `${(value * 100).toFixed(1)}%`;
}

function labelize(value?: string | null): string {
    if (!value) return '';
    return value.replaceAll('_', ' ');
}

function scoreClass(score: number): string {
    if (score >= 80) return 'bg-emerald-500';
    if (score >= 68) return 'bg-sky-500';
    if (score >= 58) return 'bg-amber-500';
    return 'bg-muted-foreground';
}

async function loadPicks(): Promise<void> {
    loading.value = true;
    error.value = null;

    try {
        const response = await api.dailyPicks.index<DailyPicksPayload>('mlb', {
            query: props.season ? { season: props.season } : undefined,
        });

        if (!response?.data) {
            throw new Error('Failed to load MLB daily picks');
        }

        payload.value = response.data;
    } catch (e) {
        error.value =
            e instanceof Error ? e.message : 'Unable to load MLB daily picks';
    } finally {
        loading.value = false;
    }
}

onMounted(() => {
    void loadPicks();
});
</script>

<template>
    <section class="space-y-3">
        <div v-if="loading" class="rounded-lg border bg-card p-4">
            <div class="mb-4 flex items-center justify-between">
                <Skeleton class="h-5 w-48" />
                <Skeleton class="h-8 w-28" />
            </div>
            <div class="grid gap-3 lg:grid-cols-3">
                <Skeleton v-for="i in 3" :key="i" class="h-40 w-full" />
            </div>
        </div>

        <Card v-else-if="error" class="border-destructive/40">
            <CardContent class="py-4 text-sm text-destructive">
                {{ error }}
            </CardContent>
        </Card>

        <Card v-else class="overflow-hidden">
            <CardContent class="space-y-4 p-4 md:p-5">
                <div
                    class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between"
                >
                    <div>
                        <CardTitle class="flex items-center gap-2 text-base">
                            <Target class="h-4 w-4" />
                            Top MLB Tracking Picks
                        </CardTitle>
                        <div class="mt-1 text-sm text-muted-foreground">
                            {{ payload?.date }} ·
                            {{ payload?.candidate_count ?? 0 }} candidates ·
                            {{ payload?.public_promoted_count ?? 0 }} public
                        </div>
                    </div>
                    <span
                        class="inline-flex w-fit items-center gap-2 rounded-full border px-3 py-1 text-xs font-semibold uppercase text-muted-foreground"
                    >
                        <BarChart3 class="h-3.5 w-3.5" />
                        tracking only
                    </span>
                </div>

                <div
                    v-if="picks.length === 0"
                    class="rounded-lg border border-dashed p-4 text-sm text-muted-foreground"
                >
                    No stored tracking picks for this date yet. Run
                    <span class="font-mono">mlb:generate-daily-picks</span>
                    when the slate is ready.
                </div>

                <div v-else class="grid gap-3 lg:grid-cols-3">
                    <article
                        v-for="pick in picks"
                        :key="pick.id"
                        class="rounded-lg border bg-background p-4"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div
                                    class="text-xs font-semibold uppercase tracking-wide text-muted-foreground"
                                >
                                    {{ labelize(pick.market_type) }}
                                </div>
                                <h3 class="mt-1 text-base font-semibold">
                                    {{ pick.label }}
                                </h3>
                                <div class="mt-1 text-sm text-muted-foreground">
                                    {{ pick.game?.short_name }}
                                </div>
                            </div>
                            <div class="shrink-0 text-right">
                                <div class="text-lg font-bold">
                                    {{ pick.score }}
                                </div>
                                <div class="text-xs text-muted-foreground">
                                    score
                                </div>
                            </div>
                        </div>

                        <div class="mt-3 h-2 rounded-full bg-muted">
                            <div
                                class="h-full rounded-full"
                                :class="scoreClass(pick.score)"
                                :style="{ width: `${pick.score}%` }"
                            />
                        </div>

                        <div class="mt-3 grid grid-cols-3 gap-2 text-xs">
                            <div>
                                <div class="text-muted-foreground">Price</div>
                                <div class="font-semibold">
                                    {{ formatOdds(pick.price) }}
                                </div>
                            </div>
                            <div>
                                <div class="text-muted-foreground">Blend</div>
                                <div class="font-semibold">
                                    {{ formatPercent(pick.blend_probability) }}
                                </div>
                            </div>
                            <div>
                                <div class="text-muted-foreground">Edge</div>
                                <div class="font-semibold">
                                    {{ formatPercent(pick.edge_no_vig) }}
                                </div>
                            </div>
                        </div>

                        <div class="mt-3 flex flex-wrap gap-1.5">
                            <span
                                v-for="reason in pick.reason_codes.slice(0, 3)"
                                :key="reason"
                                class="rounded-full border px-2 py-0.5 text-xs"
                            >
                                {{ labelize(reason) }}
                            </span>
                        </div>

                        <div
                            v-if="pick.risk_flags.length > 0"
                            class="mt-3 flex items-start gap-2 rounded-md border border-amber-500/30 bg-amber-500/10 p-2 text-xs text-amber-700 dark:text-amber-300"
                        >
                            <AlertTriangle class="mt-0.5 h-3.5 w-3.5" />
                            <span>
                                {{
                                    pick.risk_flags
                                        .slice(0, 2)
                                        .map(labelize)
                                        .join(' · ')
                                }}
                            </span>
                        </div>

                        <p class="mt-3 text-xs leading-5 text-muted-foreground">
                            {{ pick.explanation }}
                        </p>
                    </article>
                </div>
            </CardContent>
        </Card>
    </section>
</template>
