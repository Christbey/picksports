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
const state = ref('present');
const rows = computed(() =>
    evidenceRows(
        props.report,
        market.value,
        cohort.value,
        search.value,
        state.value,
    ),
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
        <details v-if="report.operations" class="rounded border p-3 text-sm">
            <summary class="cursor-pointer font-medium">
                Pipeline coverage, freshness and shadow trials
            </summary>
            <div class="mt-3 grid gap-3 md:grid-cols-2">
                <div>
                    <h3 class="font-medium">Last successful ledger activity</h3>
                    <p
                        v-for="(time, kind) in report.operations.audit
                            .freshness"
                        :key="kind"
                    >
                        {{ label(kind) }}: {{ time ?? 'Not yet observed' }}
                    </p>
                </div>
                <div>
                    <h3 class="font-medium">Grade coverage by market</h3>
                    <div
                        v-for="(reasons, kind) in report.operations.audit
                            .reasons"
                        :key="kind"
                    >
                        <strong>{{ label(kind) }}</strong>
                        <p v-for="(count, reason) in reasons" :key="reason">
                            {{ label(reason) }}: {{ count }} games
                        </p>
                    </div>
                </div>
            </div>
            <h3 class="mt-3 font-medium">Games needing attention (first 50)</h3>
            <p
                v-for="gap in report.operations.audit.games
                    .filter(
                        (g) =>
                            !['graded', 'awaiting_final_result'].includes(
                                g.reason,
                            ),
                    )
                    .slice(0, 50)"
                :key="`${gap.game_id}:${gap.market}`"
            >
                {{ gap.matchup }} · {{ gap.market }}: {{ label(gap.reason) }} ·
                quote: {{ label(gap.quote_status) }}
            </p>
            <h3 class="mt-3 font-medium">Preregistered shadow trials</h3>
            <p v-if="!report.operations.trials.length">
                No trials registered. Confirmation requires a fixed protocol and
                future game outcomes.
            </p>
            <details
                v-for="trial in report.operations.trials"
                :key="trial.artifact_id"
                class="mt-2"
            >
                <summary>
                    {{ trial.protocol?.name ?? trial.artifact_id }} ·
                    {{ label(trial.status) }}
                </summary>
                <p v-for="(period, kind) in trial.periods" :key="kind">
                    {{ kind }}: {{ period.games }} games /
                    {{ period.weeks }} weeks · mean improvement
                    {{ number(period.mean_improvement) }} · corrected lower
                    bound {{ number(period.simultaneous_lower_bound) }}
                </p>
                <p v-for="(passed, check) in trial.checks" :key="check">
                    {{ label(check) }}:
                    {{ passed ? 'Passed' : 'Not satisfied' }}
                </p>
                <p
                    v-for="(
                        periods, candidate
                    ) in trial.candidate_contributions"
                    :key="candidate"
                >
                    {{ candidate }} removal preview:
                    <span v-for="(effect, period) in periods" :key="period"
                        >{{ period }}
                        {{ number(effect.mean_full_minus_ablated_improvement) }}
                        ({{ effect.games }} games) · </span
                    >Same frozen inputs; proposed additions only.
                </p>
                <p>
                    Shadow only. No automatic promotion or published forecast
                    changes.
                </p>
            </details>
            <details class="mt-3">
                <summary>Scheduler heartbeats</summary>
                <p
                    v-for="(heartbeat, index) in report.operations.audit
                        .scheduler_heartbeats"
                    :key="index"
                >
                    {{ heartbeat.command }} · {{ heartbeat.status }} ·
                    {{ heartbeat.ran_at }}
                </p>
            </details>
        </details>
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
                >Condition state
                <select
                    v-model="state"
                    class="ml-2 rounded border bg-background p-2"
                >
                    <option value="present">Matched / triggered</option>
                    <option value="not_matched">Not matched controls</option>
                    <option value="unavailable">
                        Unavailable / unsupported
                    </option>
                    <option value="all">All states</option>
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
                        :key="`${row.signal_type}:${row.signal_key}:${row.definition_hash}:${row.condition_state}:${row.config_hash}:${row.direction}:${row.feature_version}:${row.blend_version}`"
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
                                    <p>
                                        State:
                                        {{ row.condition_state ?? 'triggered' }}
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
                                        <p v-if="row.control_comparison">
                                            Control comparison:
                                            {{
                                                row.control_comparison
                                                    .present_games
                                            }}
                                            exposed /
                                            {{
                                                row.control_comparison
                                                    .control_games
                                            }}
                                            control games in
                                            {{
                                                row.control_comparison
                                                    .common_strata
                                            }}
                                            common strata · improvement
                                            {{
                                                number(
                                                    row.control_comparison
                                                        .comparison_improvement,
                                                )
                                            }}
                                            ·
                                            {{
                                                label(
                                                    row.control_comparison
                                                        .status,
                                                )
                                            }}. Observational, not causal.
                                        </p>
                                        <p
                                            v-for="overlap in row.partial_overlap"
                                            :key="overlap.signal_key"
                                        >
                                            Overlap: {{ overlap.signal_key }} ·
                                            {{ overlap.shared_games }} shared
                                            games ·
                                            {{ percent(overlap.jaccard) }}
                                            Jaccard similarity.
                                        </p>

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
