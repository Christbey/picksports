<script setup lang="ts">
import { AlertTriangle, Medal, ShieldCheck, TrendingUp } from 'lucide-vue-next';
import { computed, onMounted, ref } from 'vue';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { useApiV2Client } from '@/composables/useApiV2Client';

interface SignalRow {
    type: string;
    game_id?: number;
    team_id?: number;
    team_name?: string;
    matchup?: string;
    signal?: string;
    probability?: number;
    win_probability?: number;
    projected_wins?: number;
    edge_points?: number;
    trust_score?: number | null;
    label?: string;
    streak?: string;
    length?: number;
    reason_codes?: string[];
}

interface SignalsPayload {
    season: number;
    as_of_date: string;
    super_bowl: SignalRow[];
    week_one_winners: SignalRow[];
    week_one_covers: SignalRow[];
    streaks: SignalRow[];
}

const props = defineProps<{
    season?: string | number | null;
}>();

const loading = ref(true);
const error = ref<string | null>(null);
const payload = ref<SignalsPayload | null>(null);
const api = useApiV2Client();

const signalGroups = computed(() => [
    {
        key: 'super_bowl',
        title: 'Super Bowl',
        icon: Medal,
        rows: payload.value?.super_bowl ?? [],
        metric: (row: SignalRow) => formatPercent(row.probability),
        detail: (row: SignalRow) =>
            `${formatWins(row.projected_wins)} wins · ${labelize(row.signal)}`,
    },
    {
        key: 'week_one_winners',
        title: 'Week 1 Winners',
        icon: ShieldCheck,
        rows: payload.value?.week_one_winners ?? [],
        metric: (row: SignalRow) => formatPercent(row.win_probability),
        detail: (row: SignalRow) => row.matchup ?? '',
    },
    {
        key: 'week_one_covers',
        title: 'Week 1 Covers',
        icon: TrendingUp,
        rows: payload.value?.week_one_covers ?? [],
        metric: (row: SignalRow) =>
            row.edge_points != null
                ? `${row.edge_points.toFixed(1)} pts`
                : null,
        detail: (row: SignalRow) => row.matchup ?? '',
    },
    {
        key: 'streaks',
        title: 'Streaks',
        icon: AlertTriangle,
        rows: payload.value?.streaks ?? [],
        metric: (row: SignalRow) =>
            row.length != null ? `${row.length}x` : null,
        detail: (row: SignalRow) => row.label ?? labelize(row.streak),
    },
]);

function formatPercent(value?: number | null): string | null {
    if (value == null) return null;
    return `${Math.round(value * 100)}%`;
}

function formatWins(value?: number | null): string {
    if (value == null) return '0.0';
    return value.toFixed(1);
}

function labelize(value?: string | null): string {
    if (!value) return '';
    return value.replaceAll('_', ' ');
}

async function loadSignals(): Promise<void> {
    loading.value = true;
    error.value = null;

    try {
        const response = await api.signals.index<SignalsPayload>('nfl', {
            query: props.season ? { season: props.season } : undefined,
        });
        if (!response?.data) {
            throw new Error('Failed to load NFL signals');
        }

        payload.value = response.data;
    } catch (e) {
        error.value =
            e instanceof Error ? e.message : 'Unable to load NFL signals';
    } finally {
        loading.value = false;
    }
}

onMounted(loadSignals);
</script>

<template>
    <section class="space-y-3">
        <div>
            <h2 class="text-lg font-semibold">NFL Signals</h2>
            <p class="text-sm text-muted-foreground">
                Futures, Week 1, cover edges, and streak context from the live
                model.
            </p>
        </div>

        <div v-if="loading" class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
            <Card v-for="i in 4" :key="i">
                <CardHeader class="space-y-2">
                    <Skeleton class="h-4 w-28" />
                    <Skeleton class="h-3 w-20" />
                </CardHeader>
                <CardContent class="space-y-3">
                    <Skeleton class="h-8 w-full" />
                    <Skeleton class="h-8 w-full" />
                    <Skeleton class="h-8 w-full" />
                </CardContent>
            </Card>
        </div>

        <Card v-else-if="error" class="border-destructive/40">
            <CardContent class="py-4 text-sm text-destructive">
                {{ error }}
            </CardContent>
        </Card>

        <div v-else class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
            <Card v-for="group in signalGroups" :key="group.key">
                <CardHeader class="pb-3">
                    <CardTitle class="flex items-center gap-2 text-sm">
                        <component :is="group.icon" class="h-4 w-4" />
                        {{ group.title }}
                    </CardTitle>
                </CardHeader>
                <CardContent class="space-y-3">
                    <div
                        v-for="row in group.rows.slice(0, 4)"
                        :key="`${group.key}-${row.team_id}-${row.game_id}-${row.label}`"
                        class="flex min-h-12 items-start justify-between gap-3 border-b pb-2 last:border-0 last:pb-0"
                    >
                        <div class="min-w-0">
                            <div class="truncate text-sm font-medium">
                                {{ row.team_name || row.matchup }}
                            </div>
                            <div class="truncate text-xs text-muted-foreground">
                                {{ group.detail(row) }}
                            </div>
                        </div>
                        <div class="shrink-0 text-sm font-semibold">
                            {{ group.metric(row) ?? '-' }}
                        </div>
                    </div>
                    <p
                        v-if="group.rows.length === 0"
                        class="text-sm text-muted-foreground"
                    >
                        No current signals.
                    </p>
                </CardContent>
            </Card>
        </div>
    </section>
</template>
