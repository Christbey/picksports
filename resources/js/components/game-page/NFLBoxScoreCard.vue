<script setup lang="ts">
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import type { NflTeamStats } from '@/types';

defineProps<{
    awayLabel?: string | null;
    homeLabel?: string | null;
    awayTeamStats: NflTeamStats;
    homeTeamStats: NflTeamStats;
    getBetterValue: (homeValue: number, awayValue: number, lowerIsBetter?: boolean) => 'home' | 'away' | null;
    calculatePercentage: (made: number, attempted: number) => string;
}>();
</script>

<template>
    <Card>
        <CardHeader>
            <CardTitle>Box Score</CardTitle>
        </CardHeader>
        <CardContent>
            <div class="overflow-x-auto">
                <div class="min-w-[600px] space-y-4">
                    <div class="grid grid-cols-7 gap-2 border-b pb-2 text-sm font-medium">
                        <div class="col-span-2 text-right">{{ awayLabel }}</div>
                        <div class="col-span-3 text-center">Stat</div>
                        <div class="col-span-2 text-left">{{ homeLabel }}</div>
                    </div>

                    <div class="grid grid-cols-7 gap-2 items-center text-sm">
                        <div class="col-span-2 text-right font-medium" :class="getBetterValue(homeTeamStats.total_yards, awayTeamStats.total_yards) === 'away' ? 'text-green-600 dark:text-green-400' : ''">
                            {{ awayTeamStats.total_yards }}
                        </div>
                        <div class="col-span-3 text-center text-muted-foreground">Total Yards</div>
                        <div class="col-span-2 text-left font-medium" :class="getBetterValue(homeTeamStats.total_yards, awayTeamStats.total_yards) === 'home' ? 'text-green-600 dark:text-green-400' : ''">
                            {{ homeTeamStats.total_yards }}
                        </div>
                    </div>

                    <div class="grid grid-cols-7 gap-2 items-center text-sm">
                        <div class="col-span-2 text-right font-medium" :class="getBetterValue(homeTeamStats.passing_yards, awayTeamStats.passing_yards) === 'away' ? 'text-green-600 dark:text-green-400' : ''">
                            {{ awayTeamStats.passing_completions }}-{{ awayTeamStats.passing_attempts }}, {{ awayTeamStats.passing_yards }}
                        </div>
                        <div class="col-span-3 text-center text-muted-foreground">Passing (C-A, Yds)</div>
                        <div class="col-span-2 text-left font-medium" :class="getBetterValue(homeTeamStats.passing_yards, awayTeamStats.passing_yards) === 'home' ? 'text-green-600 dark:text-green-400' : ''">
                            {{ homeTeamStats.passing_completions }}-{{ homeTeamStats.passing_attempts }}, {{ homeTeamStats.passing_yards }}
                        </div>
                    </div>

                    <div class="grid grid-cols-7 gap-2 items-center text-sm">
                        <div class="col-span-2 text-right font-medium" :class="getBetterValue(homeTeamStats.rushing_yards, awayTeamStats.rushing_yards) === 'away' ? 'text-green-600 dark:text-green-400' : ''">
                            {{ awayTeamStats.rushing_attempts }}, {{ awayTeamStats.rushing_yards }}
                        </div>
                        <div class="col-span-3 text-center text-muted-foreground">Rushing (Att, Yds)</div>
                        <div class="col-span-2 text-left font-medium" :class="getBetterValue(homeTeamStats.rushing_yards, awayTeamStats.rushing_yards) === 'home' ? 'text-green-600 dark:text-green-400' : ''">
                            {{ homeTeamStats.rushing_attempts }}, {{ homeTeamStats.rushing_yards }}
                        </div>
                    </div>

                    <div class="grid grid-cols-7 gap-2 items-center text-sm">
                        <div class="col-span-2 text-right font-medium" :class="getBetterValue(homeTeamStats.first_downs, awayTeamStats.first_downs) === 'away' ? 'text-green-600 dark:text-green-400' : ''">
                            {{ awayTeamStats.first_downs }}
                        </div>
                        <div class="col-span-3 text-center text-muted-foreground">First Downs</div>
                        <div class="col-span-2 text-left font-medium" :class="getBetterValue(homeTeamStats.first_downs, awayTeamStats.first_downs) === 'home' ? 'text-green-600 dark:text-green-400' : ''">
                            {{ homeTeamStats.first_downs }}
                        </div>
                    </div>

                    <div class="grid grid-cols-7 gap-2 items-center text-sm">
                        <div class="col-span-2 text-right font-medium" :class="getBetterValue(homeTeamStats.third_down_conversions / (homeTeamStats.third_down_attempts || 1), awayTeamStats.third_down_conversions / (awayTeamStats.third_down_attempts || 1)) === 'away' ? 'text-green-600 dark:text-green-400' : ''">
                            {{ awayTeamStats.third_down_conversions }}-{{ awayTeamStats.third_down_attempts }} ({{ calculatePercentage(awayTeamStats.third_down_conversions, awayTeamStats.third_down_attempts) }}%)
                        </div>
                        <div class="col-span-3 text-center text-muted-foreground">3rd Down</div>
                        <div class="col-span-2 text-left font-medium" :class="getBetterValue(homeTeamStats.third_down_conversions / (homeTeamStats.third_down_attempts || 1), awayTeamStats.third_down_conversions / (awayTeamStats.third_down_attempts || 1)) === 'home' ? 'text-green-600 dark:text-green-400' : ''">
                            {{ homeTeamStats.third_down_conversions }}-{{ homeTeamStats.third_down_attempts }} ({{ calculatePercentage(homeTeamStats.third_down_conversions, homeTeamStats.third_down_attempts) }}%)
                        </div>
                    </div>

                    <div class="grid grid-cols-7 gap-2 items-center text-sm">
                        <div class="col-span-2 text-right font-medium" :class="getBetterValue(homeTeamStats.fourth_down_conversions / (homeTeamStats.fourth_down_attempts || 1), awayTeamStats.fourth_down_conversions / (awayTeamStats.fourth_down_attempts || 1)) === 'away' ? 'text-green-600 dark:text-green-400' : ''">
                            {{ awayTeamStats.fourth_down_conversions }}-{{ awayTeamStats.fourth_down_attempts }}
                        </div>
                        <div class="col-span-3 text-center text-muted-foreground">4th Down</div>
                        <div class="col-span-2 text-left font-medium" :class="getBetterValue(homeTeamStats.fourth_down_conversions / (homeTeamStats.fourth_down_attempts || 1), awayTeamStats.fourth_down_conversions / (awayTeamStats.fourth_down_attempts || 1)) === 'home' ? 'text-green-600 dark:text-green-400' : ''">
                            {{ homeTeamStats.fourth_down_conversions }}-{{ homeTeamStats.fourth_down_attempts }}
                        </div>
                    </div>

                    <div class="grid grid-cols-7 gap-2 items-center text-sm">
                        <div class="col-span-2 text-right font-medium" :class="getBetterValue(homeTeamStats.red_zone_scores / (homeTeamStats.red_zone_attempts || 1), awayTeamStats.red_zone_scores / (awayTeamStats.red_zone_attempts || 1)) === 'away' ? 'text-green-600 dark:text-green-400' : ''">
                            {{ awayTeamStats.red_zone_scores }}-{{ awayTeamStats.red_zone_attempts }}
                        </div>
                        <div class="col-span-3 text-center text-muted-foreground">Red Zone</div>
                        <div class="col-span-2 text-left font-medium" :class="getBetterValue(homeTeamStats.red_zone_scores / (homeTeamStats.red_zone_attempts || 1), awayTeamStats.red_zone_scores / (awayTeamStats.red_zone_attempts || 1)) === 'home' ? 'text-green-600 dark:text-green-400' : ''">
                            {{ homeTeamStats.red_zone_scores }}-{{ homeTeamStats.red_zone_attempts }}
                        </div>
                    </div>

                    <div class="grid grid-cols-7 gap-2 items-center text-sm">
                        <div class="col-span-2 text-right font-medium" :class="getBetterValue(homeTeamStats.interceptions + homeTeamStats.fumbles_lost, awayTeamStats.interceptions + awayTeamStats.fumbles_lost, true) === 'away' ? 'text-green-600 dark:text-green-400' : ''">
                            {{ awayTeamStats.interceptions + awayTeamStats.fumbles_lost }} ({{ awayTeamStats.interceptions }} INT, {{ awayTeamStats.fumbles_lost }} FUM)
                        </div>
                        <div class="col-span-3 text-center text-muted-foreground">Turnovers</div>
                        <div class="col-span-2 text-left font-medium" :class="getBetterValue(homeTeamStats.interceptions + homeTeamStats.fumbles_lost, awayTeamStats.interceptions + awayTeamStats.fumbles_lost, true) === 'home' ? 'text-green-600 dark:text-green-400' : ''">
                            {{ homeTeamStats.interceptions + homeTeamStats.fumbles_lost }} ({{ homeTeamStats.interceptions }} INT, {{ homeTeamStats.fumbles_lost }} FUM)
                        </div>
                    </div>

                    <div class="grid grid-cols-7 gap-2 items-center text-sm">
                        <div class="col-span-2 text-right font-medium" :class="getBetterValue(homeTeamStats.sacks_allowed, awayTeamStats.sacks_allowed, true) === 'away' ? 'text-green-600 dark:text-green-400' : ''">
                            {{ awayTeamStats.sacks_allowed }}
                        </div>
                        <div class="col-span-3 text-center text-muted-foreground">Sacks Allowed</div>
                        <div class="col-span-2 text-left font-medium" :class="getBetterValue(homeTeamStats.sacks_allowed, awayTeamStats.sacks_allowed, true) === 'home' ? 'text-green-600 dark:text-green-400' : ''">
                            {{ homeTeamStats.sacks_allowed }}
                        </div>
                    </div>

                    <div class="grid grid-cols-7 gap-2 items-center text-sm">
                        <div class="col-span-2 text-right font-medium" :class="getBetterValue(homeTeamStats.penalty_yards, awayTeamStats.penalty_yards, true) === 'away' ? 'text-green-600 dark:text-green-400' : ''">
                            {{ awayTeamStats.penalties }}-{{ awayTeamStats.penalty_yards }}
                        </div>
                        <div class="col-span-3 text-center text-muted-foreground">Penalties (Pen-Yds)</div>
                        <div class="col-span-2 text-left font-medium" :class="getBetterValue(homeTeamStats.penalty_yards, awayTeamStats.penalty_yards, true) === 'home' ? 'text-green-600 dark:text-green-400' : ''">
                            {{ homeTeamStats.penalties }}-{{ homeTeamStats.penalty_yards }}
                        </div>
                    </div>

                    <div class="grid grid-cols-7 gap-2 items-center text-sm">
                        <div class="col-span-2 text-right font-medium" :class="getBetterValue(homeTeamStats.time_of_possession, awayTeamStats.time_of_possession) === 'away' ? 'text-green-600 dark:text-green-400' : ''">
                            {{ awayTeamStats.time_of_possession }}
                        </div>
                        <div class="col-span-3 text-center text-muted-foreground">Time of Possession</div>
                        <div class="col-span-2 text-left font-medium" :class="getBetterValue(homeTeamStats.time_of_possession, awayTeamStats.time_of_possession) === 'home' ? 'text-green-600 dark:text-green-400' : ''">
                            {{ homeTeamStats.time_of_possession }}
                        </div>
                    </div>
                </div>
            </div>
        </CardContent>
    </Card>
</template>
