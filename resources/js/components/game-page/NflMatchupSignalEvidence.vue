<script setup lang="ts">
import { computed } from 'vue';
import NflMatchupCatalog from './NflMatchupCatalog.vue';
import {
    distinctMatchupSignals,
    signalStatus,
    type NflMatchupSignalData,
} from '@/lib/nflMatchupSignals';

const props = defineProps<{ data: NflMatchupSignalData }>();
const highlights = computed(() =>
    distinctMatchupSignals(props.data.matchup.signals).slice(0, 4),
);
const team = (id: number) =>
    Object.values(props.data.situational).find((side) => side.team_id === id)
        ?.label ?? `Team ${id}`;
const value = (number: number | null | undefined) =>
    number == null || !Number.isFinite(number)
        ? 'Unavailable'
        : number.toFixed(3);
const timestamp = (date: string | null) =>
    date && !Number.isNaN(Date.parse(date))
        ? new Date(date).toLocaleString(undefined, { timeZoneName: 'short' })
        : 'Unavailable';
const unavailable = computed(() =>
    props.data.matchup.catalog.filter(
        (entry) => entry.support === 'unavailable',
    ),
);
</script>

<template>
    <div class="mt-3 min-w-0 space-y-4">
        <p class="text-sm text-muted-foreground">
            Descriptive evidence, not approved bets or extra model confidence.
            Overlapping rules are not independent confirmations.
        </p>
        <p class="text-xs text-muted-foreground">
            {{ data.matchup.season }} regular season, before
            {{ timestamp(data.matchup.cutoff_at) }}. Rankings require
            {{ data.matchup.minimum_games }} qualifying games per team and
            {{ data.matchup.minimum_league_teams }} qualifying teams. No
            automatic prior-season fallback.
        </p>
        <p
            v-if="data.matchup.window === 'previous_season'"
            class="rounded-lg border p-3 text-sm"
        >
            Historical context: {{ data.matchup.season }} only, not the current
            season. Rosters and coaches may have changed. This sample is not
            blended into your prediction.
        </p>
        <div v-if="highlights.length" class="grid gap-3 md:grid-cols-2">
            <article
                v-for="signal in highlights"
                :key="`${signal.id}:${signal.offense_team_id}`"
                class="min-w-0 rounded-lg border p-3"
            >
                <h3 class="text-sm font-medium">
                    {{ team(signal.offense_team_id) }} offense ·
                    {{ team(signal.defense_team_id) }} defense
                </h3>
                <p class="mt-1 text-sm">{{ signal.label }}</p>
                <p class="mt-2 text-xs text-muted-foreground">
                    Offense {{ value(signal.evidence.offense.value) }} · rank
                    {{ signal.evidence.offense.rank ?? '—' }} ·
                    {{ signal.evidence.offense.games }} games<br />
                    Defense {{ value(signal.evidence.defense.value) }} · rank
                    {{ signal.evidence.defense.rank ?? '—' }} ·
                    {{ signal.evidence.defense.games }} games
                </p>
            </article>
        </div>
        <p v-else class="rounded-lg border p-3 text-sm">
            No supported matchup conditions are confirmed for this sample.
            Missing history is not evidence of a disadvantage.
        </p>
        <NflMatchupCatalog :data="data" />
        <details>
            <summary
                class="min-h-11 cursor-pointer content-center text-sm font-medium"
            >
                Situational W–L–T records
            </summary>
            <p class="mb-3 text-xs text-muted-foreground">
                Current and previous 3 regular seasons, before kickoff. These
                are historical subsets, not a claim that every situation applies
                to this game. At least 3 games required; ties remain separate.
            </p>
            <div class="grid gap-4 md:grid-cols-2">
                <section
                    v-for="side in data.situational"
                    :key="side.label"
                    class="min-w-0"
                >
                    <h3 class="font-medium">{{ side.label }}</h3>
                    <dl class="divide-y text-sm">
                        <div
                            v-for="record in side.records.filter(
                                (r) =>
                                    r.sample_size > 0 &&
                                    r.status !== 'unavailable',
                            )"
                            :key="record.id"
                            class="py-2"
                        >
                            <dt>{{ record.label }}</dt>
                            <dd class="tabular-nums">
                                {{ record.record.wins }}–{{
                                    record.record.losses
                                }}–{{ record.record.ties }} ·
                                {{ record.sample_size }} games<span
                                    v-if="
                                        record.sample_size <
                                        record.minimum_sample
                                    "
                                >
                                    · Insufficient history</span
                                >
                            </dd>
                            <dd class="text-xs text-muted-foreground">
                                {{ record.definition }} ·
                                {{ record.from_date }} –
                                {{ record.through_date }}
                            </dd>
                        </div>
                    </dl>
                    <p
                        v-if="!side.records.some((r) => r.sample_size > 0)"
                        class="text-sm text-muted-foreground"
                    >
                        No qualifying history.
                    </p>
                    <details class="mt-2">
                        <summary
                            class="min-h-11 cursor-pointer content-center text-xs"
                        >
                            Coverage and limitations
                        </summary>
                        <p
                            v-for="record in side.records.filter(
                                (r) =>
                                    r.sample_size === 0 ||
                                    r.status === 'unavailable',
                            )"
                            :key="record.id"
                            class="text-xs text-muted-foreground"
                        >
                            {{ record.label }}:
                            {{ signalStatus(record.status) }}
                        </p>
                        <p
                            v-for="(market, key) in side.market_records ?? {}"
                            :key="key"
                            class="mt-2 text-xs text-muted-foreground"
                        >
                            {{ key.toUpperCase() }}:
                            {{ signalStatus(market.status) }}.
                            {{ market.reason }}
                        </p>
                        <p
                            v-for="limitation in side.limitations"
                            :key="limitation"
                            class="mt-2 text-xs text-muted-foreground"
                        >
                            {{ limitation }}
                        </p>
                    </details>
                </section>
            </div>
        </details>
        <details>
            <summary
                class="min-h-11 cursor-pointer content-center text-sm font-medium"
            >
                Data coverage &amp; definitions
            </summary>
            <p class="text-xs text-muted-foreground">
                {{ data.matchup.summary.supported_rules }} matchup rule
                definitions implemented. {{ unavailable.length }} catalog
                definitions still need data or implementation. Situational
                records are evaluated separately above. Unavailable inputs do
                not block the existing forecast.
            </p>
            <p
                v-for="limitation in data.matchup.limitations ?? []"
                :key="limitation"
                class="mt-2 text-xs text-muted-foreground"
            >
                {{ limitation }}
            </p>
            <p class="mt-2 text-xs text-muted-foreground">
                Calculated {{ timestamp(data.generated_at) }}. Cached for up to
                2 minutes. No AI research calls.
            </p>
        </details>
    </div>
</template>
