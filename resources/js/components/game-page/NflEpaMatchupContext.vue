<script setup lang="ts">
import { computed } from 'vue';
import {
    epaInterpretation,
    type MatchupMetricEvidence,
    type NflMatchupSignal,
} from '@/lib/nflMatchupSignals';
const props = defineProps<{ signal: NflMatchupSignal }>();
const interpretation = computed(() => epaInterpretation(props.signal));
const number = (n: number | null | undefined) =>
    n == null || !Number.isFinite(n) ? 'unavailable' : n.toFixed(3);
const sampleText = (sample: MatchupMetricEvidence | null) =>
    !sample || sample.eligible !== true
        ? 'Unavailable: qualifying sample incomplete'
        : `${number(sample.value)} EPA/play · rank ${sample.rank ?? 'unavailable'} · ${sample.plays ?? 'unknown'} plays · ${sample.games} games`;
const splitLabel: Record<string, string> = {
    pass_epa: 'Passing EPA',
    rush_epa: 'Rushing EPA',
    early_epa: 'Early-down EPA',
    pressure_rate: 'Pressure rate',
};
</script>

<template>
    <div v-if="interpretation" class="space-y-2 text-xs text-muted-foreground">
        <p class="font-medium text-foreground">{{ interpretation }}</p>
        <p>
            Rank bands describe the matchup; they do not establish a betting
            edge.
        </p>
        <details v-if="signal.evidence.epa_context">
            <summary class="min-h-11 cursor-pointer content-center">
                Samples, opponent adjustment and recent form
            </summary>
            <p
                v-for="(sample, side) in {
                    Offense: signal.evidence.offense,
                    Defense: signal.evidence.defense,
                }"
                :key="side"
            >
                {{ side }} coverage: {{ sample.plays ?? 'unknown' }} eligible
                plays · {{ sample.games }} of
                {{ sample.scheduled_games ?? 'unknown' }} completed games in the
                selected baseline. Latest included game:
                {{ sample.through_date ?? 'unavailable' }}.
                <template v-if="sample.eligible"
                    >League team-average EPA:
                    {{ number(sample.league_average) }}.</template
                >
                <template v-if="sample.sampling_interval"
                    >90% game-resampling range:
                    {{ number(sample.sampling_interval.lower) }} to
                    {{ number(sample.sampling_interval.upper) }}
                    EPA/play.</template
                >
                <template v-if="sample.recent_three"
                    >Last 3 included games:
                    {{ number(sample.recent_three.value) }} EPA/play ({{
                        sample.recent_three.plays
                    }}
                    plays).</template
                >
            </p>
            <p>
                Sampling ranges describe uncertainty in the observed average,
                not future scoring or rank confidence. Few games may understate
                uncertainty. Recent games overlap the season sample.
            </p>
            <p>
                Opponent-adjusted offense:
                {{
                    sampleText(
                        signal.evidence.epa_context.opponent_adjusted.offense,
                    )
                }}.
            </p>
            <p>
                Opponent-adjusted defense:
                {{
                    sampleText(
                        signal.evidence.epa_context.opponent_adjusted.defense,
                    )
                }}.
            </p>
            <p>
                {{ signal.evidence.epa_context.opponent_adjusted.definition }}
            </p>
            <details>
                <summary class="min-h-11 cursor-pointer content-center">
                    How the units match up
                </summary>
                <p
                    v-for="split in signal.evidence.epa_context.splits"
                    :key="split.metric"
                >
                    {{ splitLabel[split.metric] ?? split.metric }}:
                    <template v-if="split.metric === 'pressure_rate'"
                        >offense pressure allowed
                        {{
                            split.offense?.eligible
                                ? number(split.offense.value)
                                : 'unavailable'
                        }}; defense pressure generated
                        {{
                            split.defense?.eligible
                                ? number(split.defense.value)
                                : 'unavailable'
                        }}
                        (rates per dropback).</template
                    >
                    <template v-else
                        >offense {{ sampleText(split.offense) }}; defense
                        {{ sampleText(split.defense) }}.</template
                    >
                    <template v-if="split.source">
                        Source: {{ split.source }}.</template
                    >
                </p>
            </details>
        </details>
    </div>
</template>
