<script setup lang="ts">
import type { NflPropMatchupEvidence } from '@/lib/playerPropTypes';
import { formatPropStat } from '@/lib/playerPropPresentation';

defineProps<{ evidence: NflPropMatchupEvidence; projection?: number }>();
</script>

<template>
    <section class="space-y-2 rounded-lg border p-3 text-xs">
        <h3 class="font-semibold">
            Opponent matchup · {{ evidence.position }}
        </h3>
        <template v-if="evidence.opponent">
            <p>
                Defense rank: {{ evidence.opponent.rank }} of
                {{ evidence.ranked_teams }} covered teams · 1 is strongest
            </p>
            <p>
                Allows
                {{ formatPropStat(evidence.opponent.allowed_average) }} per game
                to this position · {{ evidence.opponent.games }} games
            </p>
            <p>
                Player’s previous opponents allowed
                {{ formatPropStat(evidence.average_opponent_allowed) }} on
                average; average rank
                {{ formatPropStat(evidence.average_opponent_rank) }}.
            </p>
            <p>
                After accounting for opposing offenses and sample size:
                {{ formatPropStat(evidence.opponent.comparison_average) }} vs
                {{ formatPropStat(evidence.average_opponent_adjusted) }} for the
                player’s previous opponents.
            </p>
        </template>
        <template v-if="evidence.status === 'applied'">
            <p class="font-medium">
                Matchup adjustment:
                {{ evidence.factor >= 1 ? '+' : ''
                }}{{ ((evidence.factor - 1) * 100).toFixed(1) }}%
            </p>
            <p v-if="projection != null">
                Projection after adjustments: {{ formatPropStat(projection) }}
            </p>
        </template>
        <p v-else>
            Insufficient comparable data · no matchup adjustment applied.
        </p>
        <p class="text-muted-foreground">
            Catches and yards are compared separately. Position totals are not
            individual player allowances. Coverage:
            {{ evidence.covered_history_games ?? 0 }}/{{
                evidence.historical_games ?? 0
            }}
            player games. Adjustment is not a validated betting edge.
        </p>
    </section>
</template>
