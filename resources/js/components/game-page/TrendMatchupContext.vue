<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { formatDateLong } from '@/composables/useFormatters';
import { useTrendsComparisonContext } from '@/composables/useTrendsComparison';
const {
    formatMatchupWinRate,
    matchupSampleLabel,
    matchupHistoryLabel,
    matchupRows,
    awayLabel,
    homeLabel,
} = useTrendsComparisonContext();
</script>
<template>
    <div
        v-if="matchupRows.length > 0"
        class="rounded-lg border border-border/70 bg-muted/25 p-3"
    >
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h4 class="text-sm font-semibold">Both teams · Matchup History</h4>
            <Badge variant="outline" class="text-[11px]">
                {{ matchupRows.length }}
                {{ matchupRows.length === 1 ? 'split' : 'splits' }}
            </Badge>
        </div>
        <ul
            role="list"
            aria-label="Matchup history"
            class="mt-3 grid gap-2 lg:grid-cols-2"
        >
            <li
                v-for="row in matchupRows"
                :key="row.key"
                class="rounded-lg border border-border/60 bg-background/55 p-3"
            >
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div>
                        <p class="text-sm font-semibold">
                            {{ row.label }}
                        </p>
                        <p
                            v-if="row.subtitle"
                            class="mt-0.5 text-xs text-muted-foreground"
                        >
                            {{ row.subtitle }}
                        </p>
                    </div>
                    <Badge variant="secondary" class="text-[11px]">
                        {{ matchupHistoryLabel(row) }}
                    </Badge>
                </div>
                <div class="mt-3 grid grid-cols-2 gap-2 text-sm">
                    <div class="rounded-md bg-card/70 px-3 py-2">
                        <p class="text-[11px] text-muted-foreground">
                            {{ awayLabel || 'Away' }}
                            {{ formatMatchupWinRate(row.away) }}
                        </p>
                        <p class="mt-1 font-semibold">
                            {{ row.away.display }}
                        </p>
                    </div>
                    <div class="rounded-md bg-card/70 px-3 py-2">
                        <p class="text-[11px] text-muted-foreground">
                            {{ homeLabel || 'Home' }}
                            {{ formatMatchupWinRate(row.home) }}
                        </p>
                        <p class="mt-1 font-semibold">
                            {{ row.home.display }}
                        </p>
                    </div>
                </div>
                <p class="mt-2 text-xs text-muted-foreground">
                    {{ matchupSampleLabel(row) }}
                </p>
                <p v-if="row.latest_meeting" class="mt-2 text-sm">
                    Last meeting:
                    {{ formatDateLong(row.latest_meeting.game_date) }}
                    ({{ row.latest_meeting.season }} season) ·
                    {{ row.latest_meeting.away_abbreviation || 'Away' }}
                    {{ row.latest_meeting.away_score }}–{{
                        row.latest_meeting.home_score
                    }}
                    {{ row.latest_meeting.home_abbreviation || 'Home' }}
                </p>
            </li>
        </ul>
    </div>
</template>
