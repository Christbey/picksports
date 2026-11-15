<script setup lang="ts">
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { computed } from 'vue';
import type { GamePageTeam, LineScoreEntry } from '@/types';

const props = defineProps<{
    title: string;
    awayTeam: GamePageTeam | null;
    homeTeam: GamePageTeam | null;
    awayLinescores: LineScoreEntry[];
    homeLinescores: LineScoreEntry[];
    awayScore: number | null | undefined;
    homeScore: number | null | undefined;
    usePeriodNumbers?: boolean;
    periodPrefix?: string;
}>();

const periodCount = computed(() =>
    Math.max(props.awayLinescores.length, props.homeLinescores.length),
);

const getPeriodLabel = (
    period: unknown,
    index: number,
    usePeriodNumbers?: boolean,
    periodPrefix?: string,
): string => {
    if (!usePeriodNumbers) return `H${index + 1}`;
    if (!period || typeof period !== 'object')
        return `${periodPrefix ?? 'Q'}${index + 1}`;

    const periodValue = (period as { period?: unknown }).period;
    return `${periodPrefix ?? 'Q'}${typeof periodValue === 'number' ? periodValue : index + 1}`;
};
</script>

<template>
    <Card class="gap-3 overflow-hidden pt-4 pb-0">
        <CardHeader class="px-4 sm:px-5">
            <CardTitle class="tracking-tight">{{ title }}</CardTitle>
        </CardHeader>
        <CardContent class="px-0">
            <div class="overflow-x-auto border-t border-border">
                <table class="w-full text-sm tabular-nums" :aria-label="title">
                    <thead>
                        <tr class="border-b border-border bg-muted/50 text-xs">
                            <th
                                scope="col"
                                class="px-4 py-2 text-left font-medium text-muted-foreground sm:px-5"
                            >
                                Team
                            </th>
                            <th
                                v-for="(_, index) in periodCount"
                                :key="index"
                                scope="col"
                                class="px-2 py-2 text-center font-medium text-muted-foreground"
                            >
                                {{
                                    getPeriodLabel(
                                        homeLinescores[index] ??
                                            awayLinescores[index],
                                        index,
                                        usePeriodNumbers,
                                        periodPrefix,
                                    )
                                }}
                            </th>
                            <th
                                scope="col"
                                class="bg-muted/60 px-3 py-2 text-center font-semibold"
                            >
                                {{ usePeriodNumbers ? 'Final' : 'Total' }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            class="border-b border-border transition-colors hover:bg-muted/40"
                        >
                            <th
                                scope="row"
                                class="px-4 py-2.5 text-left font-semibold sm:px-5"
                            >
                                <span class="flex items-center gap-2">
                                    <img
                                        v-if="awayTeam?.logo"
                                        :src="awayTeam.logo"
                                        alt=""
                                        class="h-5 w-5 object-contain"
                                    />
                                    {{ awayTeam?.abbreviation || 'Away' }}
                                </span>
                            </th>
                            <td
                                class="p-2 text-center"
                                v-for="(_, index) in periodCount"
                                :key="index"
                            >
                                {{ awayLinescores[index]?.value ?? '—' }}
                            </td>
                            <td
                                class="bg-muted/35 px-3 py-2.5 text-center font-bold"
                                :class="
                                    (awayScore ?? -1) > (homeScore ?? -1)
                                        ? 'text-green-600 dark:text-green-400'
                                        : ''
                                "
                            >
                                {{ awayScore ?? '-' }}
                            </td>
                        </tr>
                        <tr class="transition-colors hover:bg-muted/40">
                            <th
                                scope="row"
                                class="px-4 py-2.5 text-left font-semibold sm:px-5"
                            >
                                <span class="flex items-center gap-2">
                                    <img
                                        v-if="homeTeam?.logo"
                                        :src="homeTeam.logo"
                                        alt=""
                                        class="h-5 w-5 object-contain"
                                    />
                                    {{ homeTeam?.abbreviation || 'Home' }}
                                </span>
                            </th>
                            <td
                                class="p-2 text-center"
                                v-for="(_, index) in periodCount"
                                :key="index"
                            >
                                {{ homeLinescores[index]?.value ?? '—' }}
                            </td>
                            <td
                                class="bg-muted/35 px-3 py-2.5 text-center font-bold"
                                :class="
                                    (homeScore ?? -1) > (awayScore ?? -1)
                                        ? 'text-green-600 dark:text-green-400'
                                        : ''
                                "
                            >
                                {{ homeScore ?? '-' }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </CardContent>
    </Card>
</template>
