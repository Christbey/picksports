<script setup lang="ts">
import {
    formatPropStat,
    sideProbability,
    formatProbabilityEdge,
} from '@/lib/playerPropPresentation';
import type { Recommendation } from '@/lib/playerPropTypes';
import type { PlayerPropPresentation } from '@/composables/usePlayerPropPresentation';
defineProps<{
    rec: Recommendation;
    sport: string;
    isAdmin: boolean;
    presentation: PlayerPropPresentation;
}>();
</script>

<template>
    <div class="ui-surface-subtle space-y-2 p-2 text-xs">
        <div class="space-y-2">
            <div v-if="sport === 'NFL'" class="space-y-1 border-b pb-2">
                <div class="flex justify-between gap-3 text-sm">
                    <span class="text-muted-foreground"
                        >{{
                            rec.stats.season_summary?.season ?? 'Current'
                        }}
                        Regular-season Avg</span
                    >
                    <span class="font-medium">{{
                        formatPropStat(rec.stats.season_avg)
                    }}</span>
                </div>
                <p class="text-[11px] text-muted-foreground">
                    {{ rec.stats.season_summary?.games ?? 0 }}
                    reported games before this matchup.
                    <template
                        v-if="rec.stats.season_summary?.missing_stat_games"
                    >
                        {{ rec.stats.season_summary.missing_stat_games }}
                        games with missing stats excluded.
                    </template>
                    <template v-if="(rec.stats.season_summary?.games ?? 0) < 3"
                        >Small sample.</template
                    >
                </p>
            </div>
            <div class="flex justify-between text-sm">
                <span
                    class="text-muted-foreground"
                    title="Average of up to 82 prior finalized games; may span seasons"
                    >Historical Avg</span
                >
                <span class="font-medium">{{
                    formatPropStat(rec.stats?.historical_avg)
                }}</span>
            </div>
            <div class="flex justify-between text-sm">
                <span class="text-muted-foreground">Last 10 (weighted)</span>
                <span class="font-medium">
                    {{ formatPropStat(rec.stats?.recent_avg) }}
                </span>
            </div>
            <div class="flex justify-between text-sm">
                <span class="text-muted-foreground">Last 5 (weighted)</span>
                <span class="font-medium">
                    {{ formatPropStat(rec.stats?.last5_avg) }}
                </span>
            </div>
            <div class="flex justify-between text-sm">
                <span class="text-muted-foreground"
                    >vs Opponent (weighted)</span
                >
                <span class="font-medium">
                    {{ formatPropStat(rec.stats?.vs_opponent_avg) }}
                </span>
            </div>
            <div
                v-if="presentation.getCoverRecordRows(rec).length"
                class="space-y-1 border-t pt-2"
            >
                <div
                    class="text-[11px] font-semibold tracking-wide text-muted-foreground uppercase"
                >
                    History against this line
                </div>
                <p class="text-[11px] text-muted-foreground">
                    Prior games compared with today's {{ rec.line }} line, not
                    historical bets. Windows may span seasons; pushes are
                    excluded from win rate.
                </p>
                <p
                    v-if="sport === 'NFL'"
                    class="text-[11px] text-muted-foreground"
                >
                    Regular season only. All available, opponent, and conference
                    records use stored history before this matchup. Conference
                    uses current team membership. W–L–P; win % excludes pushes.
                </p>
                <div
                    v-for="[label, record] in presentation.getCoverRecordRows(
                        rec,
                    )"
                    :key="`${rec.id}-${label}`"
                    class="grid grid-cols-[minmax(0,1fr)_auto] items-start gap-x-2 gap-y-1 text-xs"
                >
                    <span class="text-muted-foreground">
                        {{ label }}
                    </span>
                    <span class="col-span-2 row-start-2 font-medium">
                        {{
                            presentation.formatCoverRecord(
                                record,
                                rec.recommendation,
                            )
                        }}
                    </span>
                    <span class="text-muted-foreground">
                        {{ presentation.formatCoverRate(record) }}
                    </span>
                    <span
                        v-if="record"
                        class="col-span-2 text-[11px] text-muted-foreground"
                    >
                        {{ record.games }} games · Raw O/U:
                        {{ record.record }}
                        <template v-if="record.pushes > 0">
                            incl. pushes
                        </template>
                    </span>
                </div>
            </div>
            <div
                v-if="rec.stats?.consistency"
                class="flex justify-between text-sm"
            >
                <span class="text-muted-foreground">Consistency</span>
                <span class="text-xs font-medium">
                    {{ rec.stats.consistency.level }}
                    (±{{ rec.stats.consistency.std_dev }})
                </span>
            </div>
            <div class="flex justify-between border-t pt-2 text-sm">
                <span class="text-muted-foreground"
                    >Probability edge ({{ rec.recommendation }})</span
                >
                <span
                    :class="[
                        'font-bold',
                        (rec.edge_probability ?? 0) > 0
                            ? 'text-green-600 dark:text-green-400'
                            : 'text-muted-foreground',
                    ]"
                >
                    {{ formatProbabilityEdge(rec.edge_probability) }}
                </span>
            </div>
            <div class="flex justify-between text-sm">
                <span class="text-muted-foreground"
                    >{{ rec.recommendation }}: model vs fair market</span
                >
                <span class="font-medium">
                    {{
                        sideProbability(
                            rec.model_over_probability,
                            rec.recommendation,
                        )
                    }}
                    vs
                    {{
                        sideProbability(
                            rec.market_over_probability,
                            rec.recommendation,
                        )
                    }}
                </span>
            </div>
        </div>
    </div>
</template>
