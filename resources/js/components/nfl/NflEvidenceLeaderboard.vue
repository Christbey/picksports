<script setup lang="ts">
import { computed, ref } from 'vue';
import {
    evidenceRows,
    type EvidenceLeaderboard,
} from '@/lib/nflEvidenceLeaderboard';

const props = defineProps<{ report: EvidenceLeaderboard }>();
const emit = defineEmits<{
    scope: [
        value: {
            evidence_season: number;
            evidence_model: string;
            evidence_week: number;
        },
    ];
}>();
const season = ref(props.report.scope.to_season);
const model = ref(props.report.scope.model_version);
const week = ref(props.report.scope.validation_from_week);
const market = ref('spread');
const cohort = ref('all');
const search = ref('');
const rows = computed(() =>
    evidenceRows(props.report, market.value, cohort.value, search.value),
);
const percent = (n: number | null) =>
    n === null ? 'Unavailable' : `${(n * 100).toFixed(1)}%`;
const number = (n: number | null) =>
    n === null ? 'Unavailable' : n.toFixed(3);
const label = (text: string) => text.replaceAll('_', ' ');
</script>

<template>
    <section
        class="space-y-4 rounded-md border bg-card p-4"
        aria-labelledby="evidence-heading"
    >
        <div>
            <h2 id="evidence-heading" class="text-lg font-semibold">
                NFL evidence leaderboard
            </h2>
            <p class="text-sm text-muted-foreground">
                {{ report.scope.from_season }}–{{ report.scope.to_season }} ·
                One pregame observation per game, signal definition and model
                version. Ranked by the lower bound of the 95% hit-rate interval,
                with samples of at least {{ report.minimum_games }} games first.
                These are exploratory associations, not approved picks or
                isolated signal effects.
            </p>
            <p class="mt-1 text-xs break-all text-muted-foreground">
                Model: {{ report.scope.model_version }}
            </p>
        </div>
        <form
            class="flex flex-wrap items-end gap-3"
            @submit.prevent="
                emit('scope', {
                    evidence_season: season,
                    evidence_model: model,
                    evidence_week: week,
                })
            "
        >
            <label class="text-sm"
                >Season<input
                    v-model.number="season"
                    type="number"
                    min="2000"
                    max="2100"
                    required
                    class="ml-2 w-24 rounded border bg-background p-2"
            /></label>
            <label class="text-sm"
                >Model version<input
                    v-model="model"
                    type="text"
                    maxlength="128"
                    required
                    class="ml-2 w-64 rounded border bg-background p-2"
            /></label>
            <label class="text-sm"
                >Later results begin at week<input
                    v-model.number="week"
                    type="number"
                    min="2"
                    max="22"
                    required
                    class="ml-2 w-20 rounded border bg-background p-2"
            /></label>
            <button type="submit" class="rounded border px-3 py-2 text-sm">
                Apply scope
            </button>
        </form>
        <div class="flex flex-wrap gap-3">
            <label class="text-sm"
                >Market
                <select
                    v-model="market"
                    class="ml-2 rounded border bg-background p-2"
                >
                    <option value="spread">Spread</option>
                    <option value="winner">Winner</option>
                    <option value="total">Total</option>
                </select>
            </label>
            <label class="text-sm"
                >Matchup
                <select
                    v-model="cohort"
                    class="ml-2 rounded border bg-background p-2"
                >
                    <option value="all">All games</option>
                    <option value="divisional">Divisional</option>
                    <option value="conference_non_divisional">
                        Conference, outside division
                    </option>
                    <option value="interconference">Interconference</option>
                    <option value="unknown">Unknown alignment</option>
                </select>
            </label>
            <label class="text-sm"
                >Evidence
                <input
                    v-model="search"
                    type="search"
                    placeholder="Find a signal"
                    class="ml-2 rounded border bg-background p-2"
                />
            </label>
        </div>
        <p v-if="rows.length === 0" class="text-sm text-muted-foreground">
            No eligible evidence in this scope. Missing grades are not losses.
        </p>
        <div v-else class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b">
                        <th class="p-2">Evidence</th>
                        <th class="p-2">W–L–P</th>
                        <th class="p-2">Hit rate / 95% interval</th>
                        <th class="p-2">Baseline improvement</th>
                        <th class="p-2">Coverage</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in rows.slice(0, 50)"
                        :key="`${row.signal_type}:${row.signal_key}:${row.definition_hash}:${row.config_hash}:${row.direction}:${row.feature_version}:${row.blend_version}`"
                        class="border-b align-top"
                    >
                        <td class="max-w-sm p-2">
                            <details>
                                <summary class="cursor-pointer font-medium">
                                    {{ row.label }}
                                </summary>
                                <div
                                    class="mt-2 space-y-1 text-xs text-muted-foreground"
                                >
                                    <p>
                                        {{ label(row.signal_type) }} ·
                                        Direction:
                                        {{
                                            row.direction ??
                                            'context / model direction'
                                        }}
                                    </p>
                                    <p>Configuration: {{ row.config_hash }}</p>
                                    <p>
                                        Grading basis:
                                        {{
                                            row.direction_basis
                                                .map(label)
                                                .join(', ')
                                        }}
                                    </p>
                                    <p>
                                        Definition:
                                        {{ row.definition_hash.slice(0, 12) }} ·
                                        {{ row.feature_version }} ·
                                        {{ row.blend_version }}
                                    </p>
                                    <template v-if="cohort === 'all'">
                                        <p>
                                            Before week
                                            {{
                                                report.scope
                                                    .validation_from_week
                                            }}: {{ row.discovery.wins }}–{{
                                                row.discovery.losses
                                            }}
                                            ({{ row.discovery.sample }} games)
                                        </p>
                                        <p>
                                            Week
                                            {{
                                                report.scope
                                                    .validation_from_week
                                            }}
                                            onward: {{ row.later.wins }}–{{
                                                row.later.losses
                                            }}
                                            ({{ row.later.sample }} games).
                                            Descriptive split; not held-out
                                            validation.
                                        </p>
                                        <p
                                            v-for="week in row.weeks"
                                            :key="week.window"
                                        >
                                            {{ week.window }}:
                                            {{ week.wins }}–{{ week.losses }}–{{
                                                week.pushes
                                            }}
                                            · improvement
                                            {{ number(week.baseline_lift) }}
                                        </p>
                                        <p
                                            v-if="
                                                row.identical_sample_signals
                                                    .length
                                            "
                                        >
                                            Same game sample as:
                                            {{
                                                row.identical_sample_signals.join(
                                                    ', ',
                                                )
                                            }}. Shared evidence, not independent
                                            confirmation.
                                        </p>
                                    </template>
                                    <p>
                                        Model error:
                                        {{ number(row.mean_absolute_error) }} ·
                                        Brier: {{ number(row.brier) }}
                                    </p>
                                </div>
                            </details>
                        </td>
                        <td class="p-2 whitespace-nowrap">
                            {{ row.wins }}–{{ row.losses }}–{{ row.pushes }}
                        </td>
                        <td class="p-2 whitespace-nowrap">
                            {{ percent(row.hit_rate) }}<br /><span
                                class="text-xs text-muted-foreground"
                                >{{ percent(row.wilson_lower) }}–{{
                                    percent(row.wilson_upper)
                                }}</span
                            >
                        </td>
                        <td class="p-2">
                            {{ number(row.baseline_lift) }}<br /><span
                                class="text-xs text-muted-foreground"
                                >{{ row.paired_baseline_sample }} paired
                                games</span
                            >
                        </td>
                        <td class="p-2">
                            {{ row.sample }} decided ·
                            {{ row.ungraded }} ungraded<br /><span
                                class="text-xs text-muted-foreground"
                                >{{
                                    row.sample < report.minimum_games
                                        ? 'Insufficient evidence'
                                        : 'Exploratory only'
                                }}</span
                            >
                        </td>
                    </tr>
                </tbody>
            </table>
            <p class="mt-2 text-xs text-muted-foreground">
                Showing {{ Math.min(50, rows.length) }} of
                {{ rows.length }} matching entries. Narrow the search to see
                more.
            </p>
        </div>
        <p class="text-xs text-muted-foreground">
            Positive improvement means lower error: winner comparisons use the
            stored model baseline; spread and total comparisons use the stored
            market line. This is not betting ROI.
        </p>
        <details class="text-sm">
            <summary class="cursor-pointer font-medium">
                Evidence coverage and remaining gaps
            </summary>
            <ul class="mt-3 space-y-3">
                <li v-for="item in report.inventory" :key="item.evidence">
                    <strong>{{ item.evidence }}</strong>
                    <p>{{ item.collection }}. {{ item.grading }}.</p>
                    <p class="text-muted-foreground">Gap: {{ item.gap }}</p>
                </li>
            </ul>
            <p
                v-for="limitation in report.limitations"
                :key="limitation"
                class="mt-2 text-xs text-muted-foreground"
            >
                {{ limitation }}
            </p>
        </details>
    </section>
</template>
