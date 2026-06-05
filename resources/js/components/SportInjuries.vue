<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';
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
const search = ref('');
const severityFilter = ref<'all' | 'out' | 'questionable' | 'other'>('all');
const api = useApiV2Client();

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
        .map(([team, injuries]) => ({ team, injuries }));
});

function statusClass(status: string | null): string {
    const normalized = (status ?? '').toLowerCase();
    if (
        normalized.includes('out') ||
        normalized.includes('doubtful') ||
        normalized.includes('inactive')
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
        normalized.includes('inactive')
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
    };
});

async function fetchInjuries() {
    loading.value = true;
    error.value = null;

    try {
        const payload = await api.injuries.index<InjuryRow>(
            props.config.sport as ApiV2SportSlug,
            { query: { active: 1 } },
        );
        if (!payload) {
            throw new Error('Failed to load injuries');
        }
        rows.value = Array.isArray(payload?.data) ? payload.data : [];
    } catch (e) {
        error.value =
            e instanceof Error ? e.message : 'Failed to load injuries';
    } finally {
        loading.value = false;
    }
}

onMounted(fetchInjuries);
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
                    <span v-if="search" class="ui-chip text-foreground/80"
                        >Search: "{{ search }}"</span
                    >
                </div>

                <Alert v-if="error" variant="destructive">
                    <AlertDescription>{{ error }}</AlertDescription>
                </Alert>

                <Card v-else-if="loading">
                    <CardContent class="py-8 text-sm text-muted-foreground">
                        Loading injuries...
                    </CardContent>
                </Card>

                <Card v-else-if="grouped.length === 0">
                    <CardContent class="py-8 text-sm text-muted-foreground">
                        No active injuries found.
                    </CardContent>
                </Card>

                <div v-else class="grid gap-4">
                    <Card v-for="group in grouped" :key="group.team">
                        <CardHeader>
                            <div class="ui-kicker">Team</div>
                            <CardTitle class="tracking-tight">{{
                                group.team
                            }}</CardTitle>
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
