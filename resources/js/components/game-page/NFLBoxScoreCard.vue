<script setup lang="ts">
import { computed } from 'vue';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { nflBoxScoreRows } from '@/lib/nflBoxScore';
import type { NflTeamStats } from '@/types';

const props = defineProps<{
    awayLabel?: string | null;
    homeLabel?: string | null;
    awayTeamStats: NflTeamStats;
    homeTeamStats: NflTeamStats;
}>();
const rows = computed(() =>
    nflBoxScoreRows(props.awayTeamStats, props.homeTeamStats),
);
</script>

<template>
    <Card>
        <CardHeader>
            <div class="ui-kicker">Game Data</div>
            <CardTitle class="tracking-tight">Box Score</CardTitle>
            <p class="text-xs text-muted-foreground">
                — means the source has not supplied that statistic.
            </p>
        </CardHeader>
        <CardContent>
            <div class="ui-table-wrap">
                <div class="min-w-[600px] space-y-4">
                    <div
                        class="grid grid-cols-7 gap-2 border-b bg-muted/30 px-2 py-2 text-sm font-medium"
                    >
                        <div class="col-span-2 text-right">{{ awayLabel }}</div>
                        <div class="col-span-3 text-center">Stat</div>
                        <div class="col-span-2 text-left">{{ homeLabel }}</div>
                    </div>
                    <div
                        v-for="row in rows"
                        :key="row.label"
                        class="grid grid-cols-7 items-center gap-2 text-sm"
                    >
                        <div
                            class="col-span-2 text-right font-medium"
                            :class="
                                row.better === 'away'
                                    ? 'text-green-600 dark:text-green-400'
                                    : ''
                            "
                        >
                            {{ row.away }}
                        </div>
                        <div
                            class="col-span-3 text-center text-muted-foreground"
                        >
                            {{ row.label }}
                        </div>
                        <div
                            class="col-span-2 text-left font-medium"
                            :class="
                                row.better === 'home'
                                    ? 'text-green-600 dark:text-green-400'
                                    : ''
                            "
                        >
                            {{ row.home }}
                        </div>
                    </div>
                </div>
            </div>
        </CardContent>
    </Card>
</template>
