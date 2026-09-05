<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Activity, AlertTriangle, Clock3 } from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import RenderErrorBoundary from '@/components/RenderErrorBoundary.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { useApiV2Client } from '@/composables/useApiV2Client';
import type { SportInjuriesConfig } from '@/config/sport-injuries-configs';
import AppLayout from '@/layouts/AppLayout.vue';
import type { ApiV2SportSlug, BreadcrumbItem } from '@/types';

interface InjuryRow {
    id: number;
    player_id: number;
    team_id: number;
    status: string | null;
    detail: string | null;
    type: string | null;
    injury_date: string | null;
    return_date: string | null;
    source_updated_at: string | null;
    is_active: boolean;
    updated_at: string | null;
    team_abbreviation: string | null;
    player_name: string | null;
    position?: string | null;
    depth_rank?: number | null;
    is_starter?: boolean;
    availability_probability?: number;
    impact_weight?: number;
    expected_impact?: number;
    impact_level?: 'critical' | 'high' | 'medium' | 'low';
    source?: string;
    is_stale?: boolean;
}

interface InjuryFreshness {
    last_observed_at?: string | null;
    latest_source_updated_at?: string | null;
    age_hours?: number | null;
    is_stale?: boolean;
    stale_rows?: number;
    max_age_hours?: number;
}

const props = defineProps<{
    config: SportInjuriesConfig;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: props.config.title,
        href: props.config.breadcrumbHref,
    },
];

const loading = ref(true);
const error = ref<string | null>(null);
const rows = ref<InjuryRow[]>([]);
const freshness = ref<InjuryFreshness | null>(null);
const warnings = ref<string[]>([]);
const search = ref('');
const severityFilter = ref<'all' | 'out' | 'questionable' | 'other'>('all');
const api = useApiV2Client();
const requestController = new AbortController();

const filtered = computed(() => {
    const query = search.value.trim().toLowerCase();
    return rows.value.filter((row) => {
        const severity = severityBucket(row.status);
        if (
            severityFilter.value !== 'all' &&
            severity !== severityFilter.value
        ) {
            return false;
        }

        if (query === '') {
            return true;
        }

        const haystack = [
            row.team_abbreviation ?? '',
            row.player_name ?? '',
            row.status ?? '',
            row.detail ?? '',
            row.type ?? '',
            row.position ?? '',
        ]
            .join(' ')
            .toLowerCase();

        return haystack.includes(query);
    });
});

const grouped = computed(() => {
    const map = new Map<string, InjuryRow[]>();

    for (const row of filtered.value) {
        const key = row.team_abbreviation ?? 'UNK';
        if (!map.has(key)) {
            map.set(key, []);
        }
        map.get(key)!.push(row);
    }

    return Array.from(map.entries())
        .sort((a, b) => a[0].localeCompare(b[0]))
        .map(([team, injuries]) => ({
            team,
            injuries: injuries.sort(
                (a, b) => (b.expected_impact ?? 0) - (a.expected_impact ?? 0),
            ),
            expectedImpact: injuries.reduce(
                (total, injury) => total + (injury.expected_impact ?? 0),
                0,
            ),
            critical: injuries.filter(
                (injury) => injury.impact_level === 'critical',
            ).length,
        }))
        .sort((a, b) => b.expectedImpact - a.expectedImpact);
});

function statusClass(status: string | null): string {
    const normalized = (status ?? '').toLowerCase();
    if (
        normalized.includes('out') ||
        normalized.includes('doubtful') ||
        normalized.includes('inactive') ||
        normalized.includes('injured reserve') ||
        normalized.includes('suspension')
    ) {
        return 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300';
    }

    if (
        normalized.includes('questionable') ||
        normalized.includes('day-to-day') ||
        normalized.includes('probable')
    ) {
        return 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300';
    }

    return 'bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300';
}

function severityBucket(
    status: string | null,
): 'out' | 'questionable' | 'other' {
    const normalized = (status ?? '').toLowerCase();
    if (
        normalized.includes('out') ||
        normalized.includes('doubtful') ||
        normalized.includes('inactive') ||
        normalized.includes('injured reserve') ||
        normalized.includes('suspension')
    ) {
        return 'out';
    }

    if (
        normalized.includes('questionable') ||
        normalized.includes('day-to-day') ||
        normalized.includes('probable')
    ) {
        return 'questionable';
    }

    return 'other';
}

const summary = computed(() => {
    const outCount = rows.value.filter(
        (row) => severityBucket(row.status) === 'out',
    ).length;
    const questionableCount = rows.value.filter(
        (row) => severityBucket(row.status) === 'questionable',
    ).length;
    const otherCount = rows.value.filter(
        (row) => severityBucket(row.status) === 'other',
    ).length;

    return {
        total: rows.value.length,
        teams: new Set(rows.value.map((row) => row.team_abbreviation ?? 'UNK'))
            .size,
        out: outCount,
        questionable: questionableCount,
        other: otherCount,
        highImpact: rows.value.filter((row) =>
            ['critical', 'high'].includes(row.impact_level ?? ''),
        ).length,
    };
});

function impactClass(level?: InjuryRow['impact_level']): string {
    if (level === 'critical') {
        return 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300';
    }
    if (level === 'high') {
        return 'bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-300';
    }
    if (level === 'medium') {
        return 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300';
    }

    return 'bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300';
}

function formatTimestamp(value: string | null | undefined): string {
    if (!value) return 'Unknown';

    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return value;

    return new Intl.DateTimeFormat(undefined, {
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    }).format(date);
}

async function fetchInjuries() {
    loading.value = true;
    error.value = null;

    try {
        const payload = await api.injuries.index<InjuryRow>(
            props.config.sport as ApiV2SportSlug,
            {
                query: {
                    active: 1,
                    limit: 500,
                    ...(props.config.actionableOnly ? { actionable: 1 } : {}),
                },
                init: { signal: requestController.signal },
            },
        );
        if (!payload) {
            throw new Error('Failed to load injuries');
        }
        rows.value = Array.isArray(payload?.data) ? payload.data : [];
        freshness.value = (payload?.meta?.freshness as InjuryFreshness) ?? null;
        warnings.value = Array.isArray(payload?.meta?.warnings)
            ? payload.meta.warnings
            : [];
    } catch (e) {
        if (e instanceof DOMException && e.name === 'AbortError') return;
        error.value =
            e instanceof Error ? e.message : 'Failed to load injuries';
    } finally {
        loading.value = false;
    }
}

onMounted(fetchInjuries);
onBeforeUnmount(() => requestController.abort());
</script>

<template>
    <Head :title="config.title" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <RenderErrorBoundary title="Injuries Render Error">
            <div class="flex h-full flex-1 flex-col gap-5 p-3 md:p-4">
                <div>
                    <p class="ui-kicker">Availability</p>
                    <h1 class="text-3xl font-semibold tracking-tight">
                        {{ config.title }}
                    </h1>
                    <p class="text-sm text-muted-foreground">
                        {{ config.subtitle }}
                    </p>
                </div>

                <Alert
                    v-if="freshness?.is_stale"
                    class="border-amber-500/40 bg-amber-500/10"
                >
                    <AlertTriangle class="size-4 text-amber-600" />
                    <AlertDescription
                        class="text-amber-900 dark:text-amber-100"
                    >
                        Injury data is stale. Last successful sync:
                        {{ formatTimestamp(freshness.last_observed_at) }}. Do
                        not use this board for a new betting decision until the
                        sync recovers.
                    </AlertDescription>
                </Alert>

                <Alert v-else-if="freshness?.last_observed_at">
                    <Clock3 class="size-4" />
                    <AlertDescription>
                        Last successful sync
                        {{ formatTimestamp(freshness.last_observed_at) }}.
                    </AlertDescription>
                </Alert>

                <Card>
                    <CardContent class="pt-6">
                        <div class="flex flex-wrap items-end gap-3">
                            <div class="min-w-[240px] flex-1">
                                <Input
                                    v-model="search"
                                    placeholder="Search team, player, status..."
                                    class="w-full"
                                />
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <Button
                                    :variant="
                                        severityFilter === 'all'
                                            ? 'default'
                                            : 'outline'
                                    "
                                    size="sm"
                                    class="h-9"
                                    @click="severityFilter = 'all'"
                                >
                                    All
                                </Button>
                                <Button
                                    :variant="
                                        severityFilter === 'out'
                                            ? 'default'
                                            : 'outline'
                                    "
                                    size="sm"
                                    class="h-9"
                                    @click="severityFilter = 'out'"
                                >
                                    Out
                                </Button>
                                <Button
                                    :variant="
                                        severityFilter === 'questionable'
                                            ? 'default'
                                            : 'outline'
                                    "
                                    size="sm"
                                    class="h-9"
                                    @click="severityFilter = 'questionable'"
                                >
                                    Q / D2D
                                </Button>
                                <Button
                                    :variant="
                                        severityFilter === 'other'
                                            ? 'default'
                                            : 'outline'
                                    "
                                    size="sm"
                                    class="h-9"
                                    @click="severityFilter = 'other'"
                                >
                                    Other
                                </Button>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <div
                    class="flex flex-wrap items-center gap-2 text-xs text-muted-foreground"
                >
                    <span class="ui-chip text-foreground/80"
                        >{{ summary.total }} injuries</span
                    >
                    <span class="ui-chip text-foreground/80"
                        >{{ summary.teams }} teams</span
                    >
                    <span class="ui-chip text-red-700 dark:text-red-300"
                        >Out: {{ summary.out }}</span
                    >
                    <span class="ui-chip text-amber-700 dark:text-amber-300"
                        >Q/D2D: {{ summary.questionable }}</span
                    >
                    <span class="ui-chip text-foreground/80"
                        >Other: {{ summary.other }}</span
                    >
                    <span
                        v-if="summary.highImpact > 0"
                        class="ui-chip text-orange-700 dark:text-orange-300"
                    >
                        High impact: {{ summary.highImpact }}
                    </span>
                    <span v-if="search" class="ui-chip text-foreground/80"
                        >Search: "{{ search }}"</span
                    >
                </div>

                <Alert v-if="error" variant="destructive">
                    <AlertDescription>{{ error }}</AlertDescription>
                </Alert>

                <Alert v-else-if="warnings.length > 0 && !freshness?.is_stale">
                    <AlertTriangle class="size-4" />
                    <AlertDescription>{{
                        warnings.join(' ')
                    }}</AlertDescription>
                </Alert>

                <Card v-else-if="loading">
                    <CardContent class="py-8 text-sm text-muted-foreground">
                        Loading injuries...
                    </CardContent>
                </Card>

                <Card v-else-if="grouped.length === 0">
                    <CardContent class="py-8 text-sm text-muted-foreground">
                        No actionable injuries found.
                    </CardContent>
                </Card>

                <div v-else class="grid gap-4">
                    <Card v-for="group in grouped" :key="group.team">
                        <CardHeader>
                            <div
                                class="flex flex-wrap items-start justify-between gap-3"
                            >
                                <div>
                                    <div class="ui-kicker">Team</div>
                                    <CardTitle class="tracking-tight">{{
                                        group.team
                                    }}</CardTitle>
                                </div>
                                <div
                                    v-if="group.expectedImpact > 0"
                                    class="text-right"
                                >
                                    <div class="ui-kicker">Expected impact</div>
                                    <div
                                        class="flex items-center justify-end gap-1.5 font-semibold"
                                    >
                                        <Activity class="size-4" />
                                        {{ group.expectedImpact.toFixed(2) }}
                                    </div>
                                </div>
                            </div>
                        </CardHeader>
                        <CardContent>
                            <div class="grid gap-2">
                                <div
                                    v-for="injury in group.injuries"
                                    :key="injury.id"
                                    class="ui-surface-subtle p-3"
                                >
                                    <div
                                        class="flex flex-wrap items-center gap-2"
                                    >
                                        <p class="font-semibold">
                                            {{
                                                injury.player_name ||
                                                'Unknown Player'
                                            }}
                                        </p>
                                        <Badge
                                            :class="statusClass(injury.status)"
                                            class="border-0"
                                        >
                                            {{ injury.status || 'Unknown' }}
                                        </Badge>
                                        <Badge
                                            v-if="injury.impact_level"
                                            :class="
                                                impactClass(injury.impact_level)
                                            "
                                            class="border-0"
                                        >
                                            {{ injury.impact_level }} impact
                                        </Badge>
                                    </div>
                                    <div
                                        v-if="
                                            injury.position || injury.is_starter
                                        "
                                        class="mt-1 flex flex-wrap gap-2 text-xs font-medium text-foreground/80"
                                    >
                                        <span v-if="injury.position">
                                            {{ injury.position }}
                                        </span>
                                        <span v-if="injury.is_starter"
                                            >Starter</span
                                        >
                                        <span
                                            v-else-if="
                                                injury.depth_rank !== null &&
                                                injury.depth_rank !== undefined
                                            "
                                        >
                                            Depth {{ injury.depth_rank }}
                                        </span>
                                        <span
                                            v-if="
                                                injury.availability_probability !==
                                                undefined
                                            "
                                        >
                                            {{
                                                Math.round(
                                                    injury.availability_probability *
                                                        100,
                                                )
                                            }}% availability
                                        </span>
                                    </div>
                                    <p
                                        class="mt-1 text-sm text-muted-foreground"
                                    >
                                        {{
                                            injury.detail ||
                                            injury.type ||
                                            'No details available'
                                        }}
                                    </p>
                                    <div
                                        class="mt-1 flex flex-wrap items-center gap-2 text-xs text-muted-foreground"
                                    >
                                        <span class="ui-chip text-foreground/75"
                                            >Injury:
                                            {{
                                                injury.injury_date || 'N/A'
                                            }}</span
                                        >
                                        <span class="ui-chip text-foreground/75"
                                            >Return:
                                            {{
                                                injury.return_date || 'N/A'
                                            }}</span
                                        >
                                        <span
                                            v-if="
                                                injury.expected_impact !==
                                                undefined
                                            "
                                            class="ui-chip text-foreground/75"
                                        >
                                            Impact:
                                            {{
                                                injury.expected_impact.toFixed(
                                                    2,
                                                )
                                            }}
                                        </span>
                                        <span
                                            class="ui-chip text-foreground/75"
                                        >
                                            Updated:
                                            {{
                                                formatTimestamp(
                                                    injury.source_updated_at,
                                                )
                                            }}
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </RenderErrorBoundary>
    </AppLayout>
</template>
