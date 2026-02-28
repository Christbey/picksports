<script setup lang="ts">
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

interface BasketballTeamStats {
    field_goals_made: number;
    field_goals_attempted: number;
    three_point_made: number;
    three_point_attempted: number;
    free_throws_made: number;
    free_throws_attempted: number;
    rebounds: number;
    assists: number;
    turnovers: number;
    steals: number;
    blocks: number;
    points_in_paint?: number | null;
    fast_break_points?: number | null;
}

const props = withDefaults(defineProps<{
    awayLabel?: string | null;
    homeLabel?: string | null;
    awayTeamStats: BasketballTeamStats;
    homeTeamStats: BasketballTeamStats;
    getBetterValue: (homeValue: number, awayValue: number, lowerIsBetter?: boolean) => 'home' | 'away' | null;
    layout?: 'grid' | 'table';
}>(), {
    layout: 'grid',
});

const calculatePercentage = (made: number, attempted: number): string => {
    if (!attempted || attempted === 0) return '0.0';
    return ((made / attempted) * 100).toFixed(1);
};
</script>

<template>
    <Card>
        <CardHeader>
            <CardTitle>Box Score</CardTitle>
        </CardHeader>
        <CardContent>
            <div v-if="layout === 'table'" class="overflow-x-auto">
                <table class="w-full">
                    <thead>
                        <tr class="border-b">
                            <th class="p-2 text-left">Stat</th>
                            <th class="p-2 text-center">{{ awayLabel || 'Away' }}</th>
                            <th class="p-2 text-center">{{ homeLabel || 'Home' }}</th>
                        </tr>
                    </thead>
                    <tbody class="text-sm">
                        <tr class="border-b">
                            <td class="p-2 font-medium">Field Goals</td>
                            <td class="p-2 text-center" :class="props.getBetterValue(homeTeamStats.field_goals_made, awayTeamStats.field_goals_made) === 'away' ? 'text-green-600 dark:text-green-400' : ''">
                                {{ awayTeamStats.field_goals_made }}-{{ awayTeamStats.field_goals_attempted }} ({{ calculatePercentage(awayTeamStats.field_goals_made, awayTeamStats.field_goals_attempted) }}%)
                            </td>
                            <td class="p-2 text-center" :class="props.getBetterValue(homeTeamStats.field_goals_made, awayTeamStats.field_goals_made) === 'home' ? 'text-green-600 dark:text-green-400' : ''">
                                {{ homeTeamStats.field_goals_made }}-{{ homeTeamStats.field_goals_attempted }} ({{ calculatePercentage(homeTeamStats.field_goals_made, homeTeamStats.field_goals_attempted) }}%)
                            </td>
                        </tr>
                        <tr class="border-b">
                            <td class="p-2 font-medium">3-Point</td>
                            <td class="p-2 text-center" :class="props.getBetterValue(homeTeamStats.three_point_made, awayTeamStats.three_point_made) === 'away' ? 'text-green-600 dark:text-green-400' : ''">
                                {{ awayTeamStats.three_point_made }}-{{ awayTeamStats.three_point_attempted }} ({{ calculatePercentage(awayTeamStats.three_point_made, awayTeamStats.three_point_attempted) }}%)
                            </td>
                            <td class="p-2 text-center" :class="props.getBetterValue(homeTeamStats.three_point_made, awayTeamStats.three_point_made) === 'home' ? 'text-green-600 dark:text-green-400' : ''">
                                {{ homeTeamStats.three_point_made }}-{{ homeTeamStats.three_point_attempted }} ({{ calculatePercentage(homeTeamStats.three_point_made, homeTeamStats.three_point_attempted) }}%)
                            </td>
                        </tr>
                        <tr class="border-b">
                            <td class="p-2 font-medium">Free Throws</td>
                            <td class="p-2 text-center" :class="props.getBetterValue(homeTeamStats.free_throws_made, awayTeamStats.free_throws_made) === 'away' ? 'text-green-600 dark:text-green-400' : ''">
                                {{ awayTeamStats.free_throws_made }}-{{ awayTeamStats.free_throws_attempted }} ({{ calculatePercentage(awayTeamStats.free_throws_made, awayTeamStats.free_throws_attempted) }}%)
                            </td>
                            <td class="p-2 text-center" :class="props.getBetterValue(homeTeamStats.free_throws_made, awayTeamStats.free_throws_made) === 'home' ? 'text-green-600 dark:text-green-400' : ''">
                                {{ homeTeamStats.free_throws_made }}-{{ homeTeamStats.free_throws_attempted }} ({{ calculatePercentage(homeTeamStats.free_throws_made, homeTeamStats.free_throws_attempted) }}%)
                            </td>
                        </tr>
                        <tr class="border-b">
                            <td class="p-2 font-medium">Rebounds</td>
                            <td class="p-2 text-center" :class="props.getBetterValue(homeTeamStats.rebounds, awayTeamStats.rebounds) === 'away' ? 'text-green-600 dark:text-green-400' : ''">
                                {{ awayTeamStats.rebounds }}
                            </td>
                            <td class="p-2 text-center" :class="props.getBetterValue(homeTeamStats.rebounds, awayTeamStats.rebounds) === 'home' ? 'text-green-600 dark:text-green-400' : ''">
                                {{ homeTeamStats.rebounds }}
                            </td>
                        </tr>
                        <tr class="border-b">
                            <td class="p-2 font-medium">Assists</td>
                            <td class="p-2 text-center" :class="props.getBetterValue(homeTeamStats.assists, awayTeamStats.assists) === 'away' ? 'text-green-600 dark:text-green-400' : ''">
                                {{ awayTeamStats.assists }}
                            </td>
                            <td class="p-2 text-center" :class="props.getBetterValue(homeTeamStats.assists, awayTeamStats.assists) === 'home' ? 'text-green-600 dark:text-green-400' : ''">
                                {{ homeTeamStats.assists }}
                            </td>
                        </tr>
                        <tr class="border-b">
                            <td class="p-2 font-medium">Turnovers</td>
                            <td class="p-2 text-center" :class="props.getBetterValue(homeTeamStats.turnovers, awayTeamStats.turnovers, true) === 'away' ? 'text-green-600 dark:text-green-400' : ''">
                                {{ awayTeamStats.turnovers }}
                            </td>
                            <td class="p-2 text-center" :class="props.getBetterValue(homeTeamStats.turnovers, awayTeamStats.turnovers, true) === 'home' ? 'text-green-600 dark:text-green-400' : ''">
                                {{ homeTeamStats.turnovers }}
                            </td>
                        </tr>
                        <tr class="border-b">
                            <td class="p-2 font-medium">Steals</td>
                            <td class="p-2 text-center" :class="props.getBetterValue(homeTeamStats.steals, awayTeamStats.steals) === 'away' ? 'text-green-600 dark:text-green-400' : ''">
                                {{ awayTeamStats.steals }}
                            </td>
                            <td class="p-2 text-center" :class="props.getBetterValue(homeTeamStats.steals, awayTeamStats.steals) === 'home' ? 'text-green-600 dark:text-green-400' : ''">
                                {{ homeTeamStats.steals }}
                            </td>
                        </tr>
                        <tr class="border-b">
                            <td class="p-2 font-medium">Blocks</td>
                            <td class="p-2 text-center" :class="props.getBetterValue(homeTeamStats.blocks, awayTeamStats.blocks) === 'away' ? 'text-green-600 dark:text-green-400' : ''">
                                {{ awayTeamStats.blocks }}
                            </td>
                            <td class="p-2 text-center" :class="props.getBetterValue(homeTeamStats.blocks, awayTeamStats.blocks) === 'home' ? 'text-green-600 dark:text-green-400' : ''">
                                {{ homeTeamStats.blocks }}
                            </td>
                        </tr>
                        <tr v-if="awayTeamStats.points_in_paint && homeTeamStats.points_in_paint" class="border-b">
                            <td class="p-2 font-medium">Points in Paint</td>
                            <td class="p-2 text-center" :class="props.getBetterValue(homeTeamStats.points_in_paint, awayTeamStats.points_in_paint) === 'away' ? 'text-green-600 dark:text-green-400' : ''">
                                {{ awayTeamStats.points_in_paint }}
                            </td>
                            <td class="p-2 text-center" :class="props.getBetterValue(homeTeamStats.points_in_paint, awayTeamStats.points_in_paint) === 'home' ? 'text-green-600 dark:text-green-400' : ''">
                                {{ homeTeamStats.points_in_paint }}
                            </td>
                        </tr>
                        <tr v-if="awayTeamStats.fast_break_points && homeTeamStats.fast_break_points">
                            <td class="p-2 font-medium">Fast Break Points</td>
                            <td class="p-2 text-center" :class="props.getBetterValue(homeTeamStats.fast_break_points, awayTeamStats.fast_break_points) === 'away' ? 'text-green-600 dark:text-green-400' : ''">
                                {{ awayTeamStats.fast_break_points }}
                            </td>
                            <td class="p-2 text-center" :class="props.getBetterValue(homeTeamStats.fast_break_points, awayTeamStats.fast_break_points) === 'home' ? 'text-green-600 dark:text-green-400' : ''">
                                {{ homeTeamStats.fast_break_points }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-else class="space-y-4">
                <div class="grid grid-cols-7 gap-2 border-b pb-2 text-sm font-medium">
                    <div class="col-span-2 text-right">{{ awayLabel }}</div>
                    <div class="col-span-3 text-center">Stat</div>
                    <div class="col-span-2 text-left">{{ homeLabel }}</div>
                </div>

                <div class="grid grid-cols-7 gap-2 items-center text-sm">
                    <div class="col-span-2 text-right font-medium" :class="props.getBetterValue(homeTeamStats.field_goals_made, awayTeamStats.field_goals_made) === 'away' ? 'text-green-600 dark:text-green-400' : ''">
                        {{ awayTeamStats.field_goals_made }}-{{ awayTeamStats.field_goals_attempted }}
                    </div>
                    <div class="col-span-3 text-center text-muted-foreground">
                        FG ({{ calculatePercentage(awayTeamStats.field_goals_made, awayTeamStats.field_goals_attempted) }}% - {{ calculatePercentage(homeTeamStats.field_goals_made, homeTeamStats.field_goals_attempted) }}%)
                    </div>
                    <div class="col-span-2 text-left font-medium" :class="props.getBetterValue(homeTeamStats.field_goals_made, awayTeamStats.field_goals_made) === 'home' ? 'text-green-600 dark:text-green-400' : ''">
                        {{ homeTeamStats.field_goals_made }}-{{ homeTeamStats.field_goals_attempted }}
                    </div>
                </div>

                <div class="grid grid-cols-7 gap-2 items-center text-sm">
                    <div class="col-span-2 text-right font-medium" :class="props.getBetterValue(homeTeamStats.three_point_made, awayTeamStats.three_point_made) === 'away' ? 'text-green-600 dark:text-green-400' : ''">
                        {{ awayTeamStats.three_point_made }}-{{ awayTeamStats.three_point_attempted }}
                    </div>
                    <div class="col-span-3 text-center text-muted-foreground">
                        3PT ({{ calculatePercentage(awayTeamStats.three_point_made, awayTeamStats.three_point_attempted) }}% - {{ calculatePercentage(homeTeamStats.three_point_made, homeTeamStats.three_point_attempted) }}%)
                    </div>
                    <div class="col-span-2 text-left font-medium" :class="props.getBetterValue(homeTeamStats.three_point_made, awayTeamStats.three_point_made) === 'home' ? 'text-green-600 dark:text-green-400' : ''">
                        {{ homeTeamStats.three_point_made }}-{{ homeTeamStats.three_point_attempted }}
                    </div>
                </div>

                <div class="grid grid-cols-7 gap-2 items-center text-sm">
                    <div class="col-span-2 text-right font-medium" :class="props.getBetterValue(homeTeamStats.free_throws_made, awayTeamStats.free_throws_made) === 'away' ? 'text-green-600 dark:text-green-400' : ''">
                        {{ awayTeamStats.free_throws_made }}-{{ awayTeamStats.free_throws_attempted }}
                    </div>
                    <div class="col-span-3 text-center text-muted-foreground">
                        FT ({{ calculatePercentage(awayTeamStats.free_throws_made, awayTeamStats.free_throws_attempted) }}% - {{ calculatePercentage(homeTeamStats.free_throws_made, homeTeamStats.free_throws_attempted) }}%)
                    </div>
                    <div class="col-span-2 text-left font-medium" :class="props.getBetterValue(homeTeamStats.free_throws_made, awayTeamStats.free_throws_made) === 'home' ? 'text-green-600 dark:text-green-400' : ''">
                        {{ homeTeamStats.free_throws_made }}-{{ homeTeamStats.free_throws_attempted }}
                    </div>
                </div>

                <div class="grid grid-cols-7 gap-2 items-center text-sm">
                    <div class="col-span-2 text-right font-medium" :class="props.getBetterValue(homeTeamStats.rebounds, awayTeamStats.rebounds) === 'away' ? 'text-green-600 dark:text-green-400' : ''">
                        {{ awayTeamStats.rebounds }}
                    </div>
                    <div class="col-span-3 text-center text-muted-foreground">Rebounds</div>
                    <div class="col-span-2 text-left font-medium" :class="props.getBetterValue(homeTeamStats.rebounds, awayTeamStats.rebounds) === 'home' ? 'text-green-600 dark:text-green-400' : ''">
                        {{ homeTeamStats.rebounds }}
                    </div>
                </div>

                <div class="grid grid-cols-7 gap-2 items-center text-sm">
                    <div class="col-span-2 text-right font-medium" :class="props.getBetterValue(homeTeamStats.assists, awayTeamStats.assists) === 'away' ? 'text-green-600 dark:text-green-400' : ''">
                        {{ awayTeamStats.assists }}
                    </div>
                    <div class="col-span-3 text-center text-muted-foreground">Assists</div>
                    <div class="col-span-2 text-left font-medium" :class="props.getBetterValue(homeTeamStats.assists, awayTeamStats.assists) === 'home' ? 'text-green-600 dark:text-green-400' : ''">
                        {{ homeTeamStats.assists }}
                    </div>
                </div>

                <div class="grid grid-cols-7 gap-2 items-center text-sm">
                    <div class="col-span-2 text-right font-medium" :class="props.getBetterValue(homeTeamStats.turnovers, awayTeamStats.turnovers, true) === 'away' ? 'text-green-600 dark:text-green-400' : ''">
                        {{ awayTeamStats.turnovers }}
                    </div>
                    <div class="col-span-3 text-center text-muted-foreground">Turnovers</div>
                    <div class="col-span-2 text-left font-medium" :class="props.getBetterValue(homeTeamStats.turnovers, awayTeamStats.turnovers, true) === 'home' ? 'text-green-600 dark:text-green-400' : ''">
                        {{ homeTeamStats.turnovers }}
                    </div>
                </div>

                <div class="grid grid-cols-7 gap-2 items-center text-sm">
                    <div class="col-span-2 text-right font-medium" :class="props.getBetterValue(homeTeamStats.steals, awayTeamStats.steals) === 'away' ? 'text-green-600 dark:text-green-400' : ''">
                        {{ awayTeamStats.steals }}
                    </div>
                    <div class="col-span-3 text-center text-muted-foreground">Steals</div>
                    <div class="col-span-2 text-left font-medium" :class="props.getBetterValue(homeTeamStats.steals, awayTeamStats.steals) === 'home' ? 'text-green-600 dark:text-green-400' : ''">
                        {{ homeTeamStats.steals }}
                    </div>
                </div>

                <div class="grid grid-cols-7 gap-2 items-center text-sm">
                    <div class="col-span-2 text-right font-medium" :class="props.getBetterValue(homeTeamStats.blocks, awayTeamStats.blocks) === 'away' ? 'text-green-600 dark:text-green-400' : ''">
                        {{ awayTeamStats.blocks }}
                    </div>
                    <div class="col-span-3 text-center text-muted-foreground">Blocks</div>
                    <div class="col-span-2 text-left font-medium" :class="props.getBetterValue(homeTeamStats.blocks, awayTeamStats.blocks) === 'home' ? 'text-green-600 dark:text-green-400' : ''">
                        {{ homeTeamStats.blocks }}
                    </div>
                </div>

                <div class="grid grid-cols-7 gap-2 items-center text-sm">
                    <div class="col-span-2 text-right font-medium" :class="props.getBetterValue(homeTeamStats.points_in_paint ?? 0, awayTeamStats.points_in_paint ?? 0) === 'away' ? 'text-green-600 dark:text-green-400' : ''">
                        {{ awayTeamStats.points_in_paint || '-' }}
                    </div>
                    <div class="col-span-3 text-center text-muted-foreground">Points in Paint</div>
                    <div class="col-span-2 text-left font-medium" :class="props.getBetterValue(homeTeamStats.points_in_paint ?? 0, awayTeamStats.points_in_paint ?? 0) === 'home' ? 'text-green-600 dark:text-green-400' : ''">
                        {{ homeTeamStats.points_in_paint || '-' }}
                    </div>
                </div>

                <div class="grid grid-cols-7 gap-2 items-center text-sm">
                    <div class="col-span-2 text-right font-medium" :class="props.getBetterValue(homeTeamStats.fast_break_points ?? 0, awayTeamStats.fast_break_points ?? 0) === 'away' ? 'text-green-600 dark:text-green-400' : ''">
                        {{ awayTeamStats.fast_break_points || '-' }}
                    </div>
                    <div class="col-span-3 text-center text-muted-foreground">Fast Break Points</div>
                    <div class="col-span-2 text-left font-medium" :class="props.getBetterValue(homeTeamStats.fast_break_points ?? 0, awayTeamStats.fast_break_points ?? 0) === 'home' ? 'text-green-600 dark:text-green-400' : ''">
                        {{ homeTeamStats.fast_break_points || '-' }}
                    </div>
                </div>
            </div>
        </CardContent>
    </Card>
</template>
