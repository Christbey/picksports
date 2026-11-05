<script setup lang="ts">
import { computed } from 'vue';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    basketballStatRows,
    basketballStatValue,
    shootingPercentage,
    type BasketballTeamStats,
    type BasketballStatRow,
} from '@/lib/basketballBoxScore';
const props = withDefaults(
    defineProps<{
        awayLabel?: string | null;
        homeLabel?: string | null;
        awayTeamStats: BasketballTeamStats;
        homeTeamStats: BasketballTeamStats;
        getBetterValue: (
            homeValue: number,
            awayValue: number,
            lowerIsBetter?: boolean,
        ) => 'home' | 'away' | null;
        layout?: 'grid' | 'table';
    }>(),
    { layout: 'grid' },
);
const tableRows = computed(() =>
    basketballStatRows.filter(
        (row) =>
            !row.optional ||
            (props.awayTeamStats[row.key] && props.homeTeamStats[row.key]),
    ),
);
const percentage = (stats: BasketballTeamStats, row: BasketballStatRow) =>
    shootingPercentage(stats[row.key] ?? 0, stats[row.attempted!] ?? 0);
const tableValue = (stats: BasketballTeamStats, row: BasketballStatRow) =>
    row.attempted
        ? `${basketballStatValue(stats, row)} (${percentage(stats, row)}%) `
        : basketballStatValue(stats, row);
const betterSide = (row: BasketballStatRow) => {
    const home = props.homeTeamStats[row.key] ?? 0,
        away = props.awayTeamStats[row.key] ?? 0;
    return row.lowerIsBetter
        ? props.getBetterValue(home, away, true)
        : props.getBetterValue(home, away);
};
const betterClass = (row: BasketballStatRow, side: 'home' | 'away') =>
    betterSide(row) === side ? 'text-green-600 dark:text-green-400' : '';
</script>
<template>
    <Card>
        <CardHeader>
            <div class="ui-kicker">Game Data</div>
            <CardTitle class="tracking-tight">Box Score</CardTitle>
        </CardHeader>
        <CardContent>
            <div
                v-if="layout === 'table'"
                role="region"
                tabindex="0"
                aria-label="Basketball box score"
                class="ui-table-wrap max-w-full min-w-0 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
            >
                <table class="w-full">
                    <thead>
                        <tr class="border-b bg-muted/30">
                            <th class="p-2 text-left">Stat</th>
                            <th class="p-2 text-center">
                                {{ awayLabel || 'Away' }}
                            </th>
                            <th class="p-2 text-center">
                                {{ homeLabel || 'Home' }}
                            </th>
                        </tr>
                    </thead>
                    <tbody class="text-sm">
                        <tr
                            v-for="row in tableRows"
                            :key="row.key"
                            :class="
                                row.key === 'fast_break_points'
                                    ? undefined
                                    : 'border-b'
                            "
                        >
                            <td class="p-2 font-medium">{{ row.label }}</td>
                            <td
                                class="p-2 text-center"
                                :class="betterClass(row, 'away')"
                            >
                                {{ tableValue(awayTeamStats, row) }}
                            </td>
                            <td
                                class="p-2 text-center"
                                :class="betterClass(row, 'home')"
                            >
                                {{ tableValue(homeTeamStats, row) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div
                v-else
                class="space-y-4 rounded-xl border border-border/70 p-3"
            >
                <div
                    class="grid grid-cols-7 gap-2 border-b pb-2 text-sm font-medium"
                >
                    <div class="col-span-2 text-right">{{ awayLabel }}</div>
                    <div class="col-span-3 text-center">Stat</div>
                    <div class="col-span-2 text-left">{{ homeLabel }}</div>
                </div>
                <div
                    v-for="row in basketballStatRows"
                    :key="row.key"
                    class="grid grid-cols-7 items-center gap-2 text-sm"
                >
                    <div
                        class="col-span-2 text-right font-medium"
                        :class="betterClass(row, 'away')"
                    >
                        {{ basketballStatValue(awayTeamStats, row, true) }}
                    </div>
                    <div class="col-span-3 text-center text-muted-foreground">
                        <template v-if="row.attempted"
                            >{{ row.compactLabel }} ({{
                                percentage(awayTeamStats, row)
                            }}% -
                            {{ percentage(homeTeamStats, row) }}%)</template
                        ><template v-else>{{ row.label }}</template>
                    </div>
                    <div
                        class="col-span-2 text-left font-medium"
                        :class="betterClass(row, 'home')"
                    >
                        {{ basketballStatValue(homeTeamStats, row, true) }}
                    </div>
                </div>
            </div>
        </CardContent>
    </Card>
</template>
