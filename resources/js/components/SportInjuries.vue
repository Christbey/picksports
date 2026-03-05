<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';
import RenderErrorBoundary from '@/components/RenderErrorBoundary.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import type { SportInjuriesConfig } from '@/config/sport-injuries-configs';

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

const filtered = computed(() => {
    const query = search.value.trim().toLowerCase();
    if (query === '') {
        return rows.value;
    }

    return rows.value.filter((row) => {
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
    if (normalized.includes('out') || normalized.includes('doubtful') || normalized.includes('inactive')) {
        return 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300';
    }

    if (normalized.includes('questionable') || normalized.includes('day-to-day') || normalized.includes('probable')) {
        return 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300';
    }

    return 'bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300';
}

async function fetchInjuries() {
    loading.value = true;
    error.value = null;

    try {
        const response = await fetch(`/api/v1/${props.config.sport}/injuries?active=1`);
        if (!response.ok) {
            throw new Error('Failed to load injuries');
        }

        const payload = await response.json();
        rows.value = Array.isArray(payload?.data) ? payload.data : [];
    } catch (e) {
        error.value = e instanceof Error ? e.message : 'Failed to load injuries';
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
            <div class="flex h-full flex-1 flex-col gap-4 p-4">
                <div>
                    <h1 class="text-2xl font-bold">{{ config.title }}</h1>
                    <p class="text-sm text-muted-foreground">{{ config.subtitle }}</p>
                </div>

                <Card>
                    <CardContent class="pt-6">
                        <Input
                            v-model="search"
                            placeholder="Search team, player, status..."
                            class="max-w-md"
                        />
                    </CardContent>
                </Card>

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
                            <CardTitle>{{ group.team }}</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div class="grid gap-2">
                                <div
                                    v-for="injury in group.injuries"
                                    :key="injury.id"
                                    class="rounded-md border border-sidebar-border/70 bg-sidebar/40 p-3"
                                >
                                    <div class="flex flex-wrap items-center gap-2">
                                        <p class="font-semibold">
                                            {{ injury.player_name || 'Unknown Player' }}
                                        </p>
                                        <Badge
                                            :class="statusClass(injury.status)"
                                            class="border-0"
                                        >
                                            {{ injury.status || 'Unknown' }}
                                        </Badge>
                                    </div>
                                    <p class="mt-1 text-sm text-muted-foreground">
                                        {{ injury.detail || injury.type || 'No details available' }}
                                    </p>
                                    <p class="mt-1 text-xs text-muted-foreground">
                                        Injury Date: {{ injury.injury_date || 'N/A' }}
                                        <span class="mx-1">•</span>
                                        Return Date: {{ injury.return_date || 'N/A' }}
                                    </p>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </RenderErrorBoundary>
    </AppLayout>
</template>

