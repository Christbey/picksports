<script setup lang="ts">
import { finiteValue } from '@/lib/gameOutcome';
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

const periodFor = (entry: LineScoreEntry, index: number) =>
    entry.period ?? index + 1;
const periods = computed(() =>
    [
        ...new Set([
            ...props.homeLinescores.map(periodFor),
            ...props.awayLinescores.map(periodFor),
        ]),
    ]
        .filter((period) => Number.isInteger(period) && period > 0)
        .sort((a, b) => a - b),
);
const scoreFor = (entries: LineScoreEntry[], period: number) => {
    const matches = entries.filter(
        (entry, index) => periodFor(entry, index) === period,
    );
    if (matches.length !== 1) return '—';
    const value = finiteValue(matches[0].value);
    return value !== null && value >= 0 ? value : '—';
};
const isAhead = (
    side: number | null | undefined,
    other: number | null | undefined,
) => {
    const value = finiteValue(side),
        opponent = finiteValue(other);
    return value !== null && opponent !== null && value > opponent;
};
const getPeriodLabel = (period: number) =>
    `${props.usePeriodNumbers ? (props.periodPrefix ?? 'Q') : 'H'}${period}`;
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
                                v-for="period in periods"
                                :key="period"
                                scope="col"
                                class="px-2 py-2 text-center font-medium text-muted-foreground"
                            >
                                {{ getPeriodLabel(period) }}
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
                                v-for="period in periods"
                                :key="`a-${period}`"
                            >
                                {{ scoreFor(awayLinescores, period) }}
                            </td>
                            <td
                                class="bg-muted/35 px-3 py-2.5 text-center font-bold"
                                :class="
                                    isAhead(awayScore, homeScore)
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
                                v-for="period in periods"
                                :key="`h-${period}`"
                            >
                                {{ scoreFor(homeLinescores, period) }}
                            </td>
                            <td
                                class="bg-muted/35 px-3 py-2.5 text-center font-bold"
                                :class="
                                    isAhead(homeScore, awayScore)
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
