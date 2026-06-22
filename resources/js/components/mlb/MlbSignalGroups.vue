<script setup lang="ts">
import type { MlbSignalGroup } from '@/types/mlb-daily-picks';

const props = withDefaults(
    defineProps<{
        groups?: MlbSignalGroup[] | null;
        maxGroups?: number | null;
        showDrivers?: boolean;
    }>(),
    {
        groups: () => [],
        maxGroups: null,
        showDrivers: true,
    },
);

const GROUP_ORDER = [
    'risk',
    'market',
    'mound',
    'lineup',
    'run_environment',
    'bullpen',
];

function orderedGroups(): MlbSignalGroup[] {
    const groups = [...(props.groups ?? [])].filter(
        (group) => group && group.summary,
    );

    groups.sort((a, b) => {
        const aIndex = GROUP_ORDER.indexOf(a.key);
        const bIndex = GROUP_ORDER.indexOf(b.key);

        return (aIndex === -1 ? 99 : aIndex) - (bIndex === -1 ? 99 : bIndex);
    });

    return typeof props.maxGroups === 'number'
        ? groups.slice(0, props.maxGroups)
        : groups;
}

function groupClass(status: string): string {
    if (status === 'positive') {
        return 'border-emerald-500/25 bg-emerald-500/[0.04]';
    }

    if (status === 'warning') {
        return 'border-amber-500/25 bg-amber-500/[0.05]';
    }

    if (status === 'risk') {
        return 'border-red-500/25 bg-red-500/[0.04]';
    }

    return 'border-border bg-muted/20';
}

function statusDotClass(status: string): string {
    if (status === 'positive') return 'bg-emerald-500';
    if (status === 'warning') return 'bg-amber-500';
    if (status === 'risk') return 'bg-red-500';

    return 'bg-sky-500';
}

function driverClass(impact: string): string {
    if (impact === 'positive') {
        return 'border-emerald-500/25 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300';
    }

    if (impact === 'warning') {
        return 'border-amber-500/30 bg-amber-500/10 text-amber-700 dark:text-amber-300';
    }

    if (impact === 'risk') {
        return 'border-red-500/30 bg-red-500/10 text-red-700 dark:text-red-300';
    }

    return 'border-border bg-background text-muted-foreground';
}
</script>

<template>
    <div v-if="orderedGroups().length" class="grid gap-3">
        <section
            v-for="group in orderedGroups()"
            :key="group.key"
            class="rounded-2xl border p-4"
            :class="groupClass(group.status)"
        >
            <div class="flex items-start gap-3">
                <span
                    class="mt-1 h-2.5 w-2.5 shrink-0 rounded-full"
                    :class="statusDotClass(group.status)"
                />
                <div class="min-w-0 flex-1">
                    <div
                        class="flex flex-wrap items-center justify-between gap-2"
                    >
                        <h4 class="text-sm font-semibold">
                            {{ group.label }}
                        </h4>
                        <span
                            v-if="group.score_delta"
                            class="text-xs font-medium text-muted-foreground"
                        >
                            {{ group.score_delta > 0 ? '+' : ''
                            }}{{ group.score_delta }}
                        </span>
                    </div>
                    <p class="mt-1 text-sm leading-6 text-muted-foreground">
                        {{ group.summary }}
                    </p>

                    <div
                        v-if="showDrivers && group.drivers?.length"
                        class="mt-3 flex flex-wrap gap-2"
                    >
                        <span
                            v-for="driver in group.drivers.slice(0, 4)"
                            :key="driver.key"
                            class="rounded-full border px-2.5 py-1 text-xs"
                            :class="driverClass(driver.impact)"
                        >
                            {{ driver.label }}
                        </span>
                    </div>
                </div>
            </div>
        </section>
    </div>
    <div v-else class="rounded-2xl border p-4 text-sm text-muted-foreground">
        Signal groups have not been generated for this matchup yet.
    </div>
</template>
