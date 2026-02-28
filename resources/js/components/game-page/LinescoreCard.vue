<script setup lang="ts">
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import type { GamePageTeam, LineScoreEntry } from '@/types';

defineProps<{
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

const getPeriodLabel = (period: unknown, index: number, usePeriodNumbers?: boolean, periodPrefix?: string): string => {
    if (!usePeriodNumbers) return `H${index + 1}`;
    if (!period || typeof period !== 'object') return `${periodPrefix ?? 'Q'}${index + 1}`;

    const periodValue = (period as { period?: unknown }).period;
    return `${periodPrefix ?? 'Q'}${typeof periodValue === 'number' ? periodValue : index + 1}`;
};
</script>

<template>
    <Card>
        <CardHeader>
            <CardTitle>{{ title }}</CardTitle>
        </CardHeader>
        <CardContent>
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead>
                        <tr class="border-b">
                            <th class="text-left p-2 text-muted-foreground font-medium">Team</th>
                            <th
                                v-for="(period, index) in (usePeriodNumbers ? homeLinescores : Array.from({ length: Math.max(homeLinescores.length, awayLinescores.length) }))"
                                :key="index"
                                class="text-center p-2 text-muted-foreground font-medium"
                            >
                                {{ getPeriodLabel(period, index, usePeriodNumbers, periodPrefix) }}
                            </th>
                            <th class="text-center p-2 font-bold">{{ usePeriodNumbers ? 'Final' : 'Total' }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="border-b">
                            <td class="p-2 font-medium">
                                <span class="flex items-center gap-2">
                                    <img v-if="awayTeam?.logo" :src="awayTeam.logo" :alt="awayTeam.abbreviation || 'Away'" class="h-5 w-5 object-contain" />
                                    {{ awayTeam?.abbreviation || 'Away' }}
                                </span>
                            </td>
                            <td class="text-center p-2" v-for="(score, index) in awayLinescores" :key="`a-${index}`">
                                {{ score.value }}
                            </td>
                            <td class="text-center p-2 font-bold" :class="(awayScore ?? -1) > (homeScore ?? -1) ? 'text-green-600 dark:text-green-400' : ''">{{ awayScore ?? '-' }}</td>
                        </tr>
                        <tr>
                            <td class="p-2 font-medium">
                                <span class="flex items-center gap-2">
                                    <img v-if="homeTeam?.logo" :src="homeTeam.logo" :alt="homeTeam.abbreviation || 'Home'" class="h-5 w-5 object-contain" />
                                    {{ homeTeam?.abbreviation || 'Home' }}
                                </span>
                            </td>
                            <td class="text-center p-2" v-for="(score, index) in homeLinescores" :key="`h-${index}`">
                                {{ score.value }}
                            </td>
                            <td class="text-center p-2 font-bold" :class="(homeScore ?? -1) > (awayScore ?? -1) ? 'text-green-600 dark:text-green-400' : ''">{{ homeScore ?? '-' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </CardContent>
    </Card>
</template>
