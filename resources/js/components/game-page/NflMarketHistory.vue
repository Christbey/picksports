<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import unitMetrics from '@/lib/nflUnitMetrics.json';

type RecordRow = {
    id: string;
    label: string;
    status: string;
    reason: string | null;
    sample_size: number;
    unique_games: number;
    small_sample: boolean;
    ats: { wins: number; losses: number; pushes: number };
    outright: { wins: number; losses: number; ties: number };
    ats_win_pct: number | null;
    average_margin: number | null;
    average_cover_margin: number | null;
    from_date: string | null;
    through_date: string | null;
};
type Evidence = {
    unit_filter?: {
        unit: string;
        band: string;
        label: string;
        metric: string;
        description: string;
        status: 'available' | 'unavailable';
    };
    since_season: number;
    target_season: number;
    before_date: string;
    market: {
        home_line: number;
        source: string;
        bookmaker: string | null;
        observed_at: string | null;
    } | null;
    coverage: {
        completed_games: number;
        games_with_verified_line: number;
        excluded_missing_or_conflicting_line: number;
        through_date: string | null;
    };
    sides: Record<
        'home' | 'away',
        {
            team: string;
            line: number | null;
            venue: string | null;
            coach: string | null;
            qb: string | null;
            qb_identity_source: string | null;
            identity_coverage: {
                cohort_games: number;
                coach_known_games: number;
                qb_known_games: number;
            };
            rows: RecordRow[];
        }
    >;
    limitations: string[];
};
// Opt in only when the endpoint supports the unit-filter contract.
const props = defineProps<{ gameId: number; enableUnitFilters?: boolean }>();
const data = ref<Evidence | null>(null);
const side = ref<'home' | 'away'>('away');
const since = ref(2009);
const homeLine = ref('');
const unit = ref('defense');
const metric = ref('points_allowed_per_game');
const strength = ref('all');
const activeUnit = computed(
    () => unitMetrics.find((item) => item.id === unit.value)!,
);
const activeMetric = computed(
    () => activeUnit.value.metrics.find((item) => item.id === metric.value)!,
);
function changeUnit() {
    metric.value = activeUnit.value.metrics[0].id;
    void load();
}
const expanded = ref(false);
const loading = ref(false);
const error = ref('');
let request: AbortController | null = null;
const selected = computed(() => data.value?.sides[side.value]);
const signed = (line: number | null) =>
    line === null ? 'No line' : line > 0 ? `+${line}` : `${line}`;
async function load() {
    request?.abort();
    const controller = new AbortController();
    request = controller;
    loading.value = true;
    error.value = '';
    data.value = null;
    const params = new URLSearchParams({ since: String(since.value) });
    if (props.enableUnitFilters) {
        params.set('opponent_unit', unit.value);
        params.set('opponent_metric', metric.value);
        params.set('strength_band', strength.value);
    }
    if (homeLine.value.trim() !== '')
        params.set('home_line', homeLine.value.trim());
    try {
        const response = await fetch(
            `/api/v2/sports/nfl/games/${props.gameId}/market-history?${params}`,
            {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
                signal: controller.signal,
            },
        );
        if (!response.ok)
            throw new Error(
                response.status === 422
                    ? 'Use a starting season from 2009 through this game’s season and a line in 0.5-point steps.'
                    : 'Historical lines are unavailable right now. No record is inferred.',
            );
        const payload = await response.json();
        if (
            props.enableUnitFilters &&
            (payload.data?.unit_filter?.unit !== params.get('opponent_unit') ||
                payload.data?.unit_filter?.metric !==
                    params.get('opponent_metric') ||
                payload.data?.unit_filter?.band !== params.get('strength_band'))
        )
            throw new Error(
                'This source cannot apply the selected unit, metric and strength. No unfiltered record is shown.',
            );
        if (request === controller) data.value = payload.data;
    } catch (cause) {
        if (request === controller && !controller.signal.aborted)
            error.value =
                cause instanceof Error
                    ? cause.message
                    : 'Could not load historical lines.';
    } finally {
        if (request === controller) loading.value = false;
    }
}
function toggle(event: Event) {
    expanded.value = (event.target as HTMLDetailsElement).open;
    if (expanded.value && !data.value && !loading.value) void load();
}
watch(
    () => props.gameId,
    () => {
        request?.abort();
        request = null;
        data.value = null;
        loading.value = false;
        homeLine.value = '';
        unit.value = 'defense';
        metric.value = 'points_allowed_per_game';
        strength.value = 'all';
        error.value = '';
        since.value = 2009;
        if (expanded.value) void load();
    },
);
onBeforeUnmount(() => request?.abort());
</script>

<template>
    <details
        class="min-w-0 rounded-xl border bg-card p-4 sm:p-5"
        @toggle="toggle"
    >
        <summary class="min-h-11 cursor-pointer content-center font-semibold">
            Historical spread evidence · ATS &amp; outright
        </summary>
        <p class="mt-2 text-sm text-muted-foreground">
            How teams performed when priced at this exact line. Context beside
            your prediction—not a change to the model.
        </p>
        <form
            v-if="expanded"
            class="mt-4 grid grid-cols-2 gap-3"
            @submit.prevent="load"
        >
            <label class="text-sm"
                >Since season
                <input
                    v-model="since"
                    type="number"
                    min="2009"
                    :max="data?.target_season"
                    required
                    class="mt-1 min-h-11 w-full rounded-md border bg-background px-3"
                />
            </label>
            <label class="text-sm"
                >Home spread (optional)
                <input
                    v-model="homeLine"
                    type="number"
                    min="-60"
                    max="60"
                    step="0.5"
                    placeholder="Use saved quote"
                    class="mt-1 min-h-11 w-full rounded-md border bg-background px-3"
                />
            </label>
            <p class="col-span-2 text-xs text-muted-foreground">
                Negative = home favorite. A typed line is hypothetical, not a
                sportsbook quote. Clear it to use the saved pregame quote.
            </p>
            <fieldset
                v-if="enableUnitFilters"
                class="col-span-2 grid min-w-0 grid-cols-2 gap-3 rounded-lg border p-3"
            >
                <legend class="px-1 text-sm font-medium">
                    Historical opponent strength
                </legend>
                <label class="col-span-2 text-sm"
                    >Opponent unit
                    <select
                        v-model="unit"
                        class="mt-1 min-h-11 w-full rounded-md border bg-background px-2"
                        @change="changeUnit"
                    >
                        <option
                            v-for="item in unitMetrics"
                            :key="item.id"
                            :value="item.id"
                        >
                            {{ item.label }}
                        </option>
                    </select>
                </label>
                <label class="col-span-2 text-sm"
                    >Metric
                    <select
                        v-model="metric"
                        class="mt-1 min-h-11 w-full rounded-md border bg-background px-2"
                        @change="load"
                    >
                        <option
                            v-for="item in activeUnit.metrics"
                            :key="item.id"
                            :value="item.id"
                        >
                            {{ item.label }}
                        </option>
                    </select>
                </label>
                <label class="col-span-2 text-sm"
                    >Strength
                    <select
                        v-model="strength"
                        class="mt-1 min-h-11 w-full rounded-md border bg-background px-2"
                        @change="load"
                    >
                        <option value="all">All strengths</option>
                        <option value="top_5">Top 5 · ranks 1–5</option>
                        <option value="top_10">Top 10 · ranks 1–10</option>
                        <option value="bottom_10">
                            Bottom 10 · ranks 23–32
                        </option>
                        <option value="bottom_5">Bottom 5 · ranks 28–32</option>
                    </select>
                </label>
                <p class="col-span-2 text-xs text-muted-foreground">
                    {{ activeMetric.description }}
                    {{
                        activeMetric.direction === 'lower' ? 'Lower' : 'Higher'
                    }}
                    is better. Use rankings entering each historical game, never
                    final-season rankings. Top means best—not highest raw value.
                </p>
            </fieldset>
            <button
                type="submit"
                :disabled="loading"
                class="col-span-2 min-h-11 rounded-md border px-3 text-sm font-medium"
            >
                {{ loading ? 'Loading records…' : 'Show historical records' }}
            </button>
        </form>
        <p v-if="error" role="alert" class="mt-3 text-sm">{{ error }}</p>
        <div v-if="data && selected" class="mt-4 space-y-4">
            <div
                class="grid grid-cols-2 gap-2"
                role="group"
                aria-label="Team perspective"
            >
                <button
                    v-for="key in ['away', 'home'] as const"
                    :key="key"
                    type="button"
                    :aria-pressed="side === key"
                    class="min-h-11 rounded-md border px-2 text-sm font-medium"
                    :class="
                        side === key ? 'bg-primary text-primary-foreground' : ''
                    "
                    @click="side = key"
                >
                    {{ data.sides[key].team }}
                    {{ signed(data.sides[key].line) }}
                </button>
            </div>
            <div class="space-y-1 text-sm">
                <p class="font-medium">
                    {{ selected.team }} {{ signed(selected.line) }} ·
                    {{ selected.venue ?? 'Unknown venue' }} · since
                    {{ data.since_season }}
                </p>
                <div
                    v-if="data.unit_filter"
                    class="rounded-lg border bg-muted/40 p-3"
                    role="status"
                >
                    <p class="font-medium">{{ data.unit_filter.label }}</p>
                    <p class="mt-1 text-xs text-muted-foreground">
                        {{ data.unit_filter.description }}
                    </p>
                    <p class="mt-2 text-xs text-muted-foreground">
                        Current opponent rank: not assessed in this preview.
                        Historical filters do not establish today’s matchup
                        strength.
                    </p>
                </div>
                <p v-if="!data.market" class="text-muted-foreground">
                    No verified pregame spread. Enter a home line above to
                    explore a hypothetical scenario.
                </p>
                <p v-else class="text-xs text-muted-foreground">
                    {{
                        data.market.source === 'user_selected'
                            ? 'Hypothetical line selected by you'
                            : data.market.source ===
                                'historical_closing_archive'
                              ? 'Retrospective closing archive'
                              : 'Saved pregame quote'
                    }}
                    <template v-if="data.market.bookmaker">
                        · {{ data.market.bookmaker }}</template
                    >
                    <template v-if="data.market.observed_at">
                        · captured
                        {{
                            new Date(data.market.observed_at).toLocaleString()
                        }}</template
                    >
                </p>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <caption class="sr-only">
                        Exact historical closing-line records, since
                        {{
                            data.since_season
                        }}
                    </caption>
                    <thead class="border-b text-xs text-muted-foreground">
                        <tr>
                            <th class="py-2 pr-2">Cohort</th>
                            <th class="px-2 py-2">ATS<br />W–L–P</th>
                            <th class="px-2 py-2">Outright<br />W–L–T</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in selected.rows.slice(0, 4)"
                            :key="row.id"
                            class="border-b last:border-0"
                        >
                            <td class="py-3 pr-2">
                                <span>{{ row.label }}</span
                                ><span
                                    class="block text-xs text-muted-foreground"
                                    >{{
                                        row.status === 'available'
                                            ? `${row.sample_size} team appearances${row.small_sample ? ' · small sample' : ''}`
                                            : (row.reason ??
                                              'No matching games')
                                    }}</span
                                >
                            </td>
                            <td
                                class="px-2 py-3 whitespace-nowrap tabular-nums"
                            >
                                {{
                                    row.status === 'available'
                                        ? `${row.ats.wins}–${row.ats.losses}–${row.ats.pushes}`
                                        : '—'
                                }}
                            </td>
                            <td
                                class="px-2 py-3 whitespace-nowrap tabular-nums"
                            >
                                {{
                                    row.status === 'available'
                                        ? `${row.outright.wins}–${row.outright.losses}–${row.outright.ties}`
                                        : '—'
                                }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <details class="rounded-lg border p-3">
                <summary
                    class="min-h-11 cursor-pointer content-center text-sm font-medium"
                >
                    Coach, QB &amp; record details
                </summary>
                <p class="my-3 text-xs text-muted-foreground">
                    Game-recorded coach: {{ selected.coach ?? 'Unavailable' }}.
                    Starting QB: {{ selected.qb ?? 'Unavailable'
                    }}<template
                        v-if="selected.qb_identity_source === 'game_exact_name'"
                    >
                        (name-only match)</template
                    >.
                </p>
                <p class="mb-3 text-xs text-muted-foreground">
                    Of {{ selected.identity_coverage.cohort_games }} matching
                    team/venue games,
                    {{ selected.identity_coverage.coach_known_games }} have
                    coach identities and
                    {{ selected.identity_coverage.qb_known_games }} have usable
                    QB identities. Missing identities are excluded from those
                    splits.
                </p>
                <dl class="space-y-3 text-sm">
                    <div v-for="row in selected.rows" :key="row.id">
                        <dt class="font-medium">{{ row.label }}</dt>
                        <dd
                            v-if="row.status === 'available'"
                            class="text-muted-foreground"
                        >
                            ATS {{ row.ats.wins }}–{{ row.ats.losses }}–{{
                                row.ats.pushes
                            }}
                            · Outright {{ row.outright.wins }}–{{
                                row.outright.losses
                            }}–{{ row.outright.ties }}<br />
                            {{ row.sample_size }} team appearances /
                            {{ row.unique_games }} games<template
                                v-if="row.small_sample"
                            >
                                · Small sample (under 20 appearances)</template
                            ><br />
                            {{ row.from_date }} → {{ row.through_date }}<br />
                            Average scoring margin
                            {{ signed(row.average_margin) }} · Average cover
                            margin {{ signed(row.average_cover_margin) }}
                        </dd>
                        <dd v-else class="text-muted-foreground">
                            {{ row.reason ?? 'No matching historical games.' }}
                        </dd>
                    </div>
                </dl>
            </details>
            <p class="text-xs text-muted-foreground">
                ATS uses each game’s actual closing spread, not today’s line
                applied to unrelated games. Outright records are not returns at
                moneyline prices. Samples overlap; at pick’em, the all-team row
                counts both sides.
            </p>
            <details class="text-xs text-muted-foreground">
                <summary class="min-h-11 cursor-pointer content-center">
                    Sources &amp; coverage ·
                    {{ data.coverage.games_with_verified_line }} /
                    {{ data.coverage.completed_games }} games have usable lines
                </summary>
                <p class="mb-2">
                    History before {{ data.before_date }}. Latest covered date:
                    {{ data.coverage.through_date ?? 'None' }}.
                    {{ data.coverage.excluded_missing_or_conflicting_line }}
                    games excluded for missing or conflicting archive lines.
                    Source: nflverse closing-line archive; not live markets.
                </p>
                <ul class="list-disc space-y-2 pl-4">
                    <li
                        v-for="limitation in data.limitations"
                        :key="limitation"
                    >
                        {{ limitation }}
                    </li>
                </ul>
            </details>
        </div>
    </details>
</template>
