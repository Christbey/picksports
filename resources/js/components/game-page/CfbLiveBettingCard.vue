<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { fetchJson } from '@/composables/useApiClient';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

type Forecast = {
    spread: number | null;
    total: number | null;
    home_win_probability: number | null;
};
type Quote = {
    bookmaker: string;
    line: number;
    over_price: number | null;
    under_price: number | null;
    fresh: boolean;
    lean: string | null;
    difference: number | null;
    result?: string | null;
};
type Prop = {
    player_id: number;
    player: string;
    market: string;
    pregame_line: number;
    pregame_mean: number | null;
    actual: number | null;
    projected: number | null;
    status: string;
    quotes: Quote[];
};
type Market = {
    bookmaker: string;
    market: string;
    fresh: boolean;
    difference: number | null;
    lean: string | null;
    outcomes: { name: string; point?: number; price: number }[];
};
type Snapshot = {
    id: number;
    observed_at: string;
    status: string;
    stale: boolean;
    pregame: Forecast;
    projection: Forecast | null;
    state: {
        period: number;
        clock: string;
        home_score: number;
        away_score: number;
    };
    markets: Market[];
    props: Prop[];
};
type Payload = {
    data: Snapshot | null;
    history: Snapshot[];
    meta: { game_status: string; snapshot_count: number };
};
const props = defineProps<{ gameId: number }>();
const payload = ref<Payload | null>(null);
const failed = ref(false);
const loading = ref(false);
const snapshot = computed(() => payload.value?.data);
let timer: ReturnType<typeof setInterval> | undefined;
const controller = new AbortController();
const refresh = async () => {
    if (loading.value) return;
    loading.value = true;
    try {
        const response = await fetchJson<Payload>(
            `/api/v2/sports/cfb/games/${props.gameId}/live-betting`,
            { signal: controller.signal },
        );
        if (!response) throw new Error('No live response');
        payload.value = response;
        failed.value = false;
    } catch {
        if (!controller.signal.aborted) failed.value = true;
    } finally {
        loading.value = false;
    }
};
onMounted(() => {
    void refresh();
    timer = setInterval(() => {
        if (!document.hidden) void refresh();
    }, 30000);
});
onBeforeUnmount(() => {
    controller.abort();
    if (timer) clearInterval(timer);
});
const number = (value: number | null | undefined) =>
    value == null ? '—' : value.toFixed(1);
const percent = (value: number | null | undefined) =>
    value == null ? '—' : `${(value * 100).toFixed(1)}%`;
const time = (value: string) =>
    new Date(value).toLocaleTimeString([], {
        hour: 'numeric',
        minute: '2-digit',
        second: '2-digit',
    });
const marketLabel = (value: string) =>
    ({
        player_pass_yds: 'Passing yards',
        player_rush_yds: 'Rushing yards',
        player_reception_yds: 'Receiving yards',
        h2h: 'Moneyline',
        spreads: 'Spread',
        totals: 'Total',
    })[value] ?? value;
const outcomes = (market: Market) =>
    market.outcomes
        .map(
            (o) =>
                `${o.name}${o.point == null ? '' : ` ${o.point > 0 ? '+' : ''}${o.point}`} (${o.price > 0 ? '+' : ''}${o.price})`,
        )
        .join(' / ');
</script>

<template>
    <Card
        v-if="
            snapshot ||
            failed ||
            [
                'STATUS_IN_PROGRESS',
                'STATUS_HALFTIME',
                'STATUS_END_PERIOD',
            ].includes(payload?.meta.game_status ?? '')
        "
    >
        <CardHeader>
            <CardTitle>Pregame and live projections</CardTitle>
            <p class="text-sm text-muted-foreground">
                Pregame values stay fixed. Live updates are saved as separate
                snapshots.
            </p>
        </CardHeader>
        <CardContent class="space-y-5">
            <p v-if="failed" role="status" class="text-sm text-amber-600">
                Live refresh failed. Previously displayed data may be outdated.
            </p>
            <p
                v-if="!snapshot && !failed"
                class="text-sm text-muted-foreground"
            >
                Waiting for the first live snapshot.
            </p>
            <template v-if="snapshot">
                <p class="text-sm">
                    Captured {{ time(snapshot.observed_at) }} · Q{{
                        snapshot.state.period
                    }}
                    {{ snapshot.state.clock }} · Score
                    {{ snapshot.state.away_score }}–{{
                        snapshot.state.home_score
                    }}
                </p>
                <p v-if="snapshot.stale" class="text-sm text-amber-600">
                    Snapshot is stale. Live comparisons are withheld until the
                    next successful refresh.
                </p>
                <p
                    v-if="!['live', 'final'].includes(snapshot.status)"
                    class="text-sm text-amber-600"
                >
                    Live projection unavailable:
                    {{ snapshot.status.replaceAll('_', ' ') }}.
                </p>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead>
                            <tr class="border-b">
                                <th class="py-2">Measure</th>
                                <th>Pregame</th>
                                <th>
                                    {{
                                        snapshot.status === 'final'
                                            ? 'Final'
                                            : 'Live estimate'
                                    }}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td class="py-2">Home margin (home − away)</td>
                                <td>{{ number(snapshot.pregame.spread) }}</td>
                                <td>
                                    {{ number(snapshot.projection?.spread) }}
                                </td>
                            </tr>
                            <tr>
                                <td class="py-2">Total points</td>
                                <td>{{ number(snapshot.pregame.total) }}</td>
                                <td>
                                    {{ number(snapshot.projection?.total) }}
                                </td>
                            </tr>
                            <tr>
                                <td class="py-2">Home win probability</td>
                                <td>
                                    {{
                                        percent(
                                            snapshot.pregame
                                                .home_win_probability,
                                        )
                                    }}
                                </td>
                                <td>
                                    {{
                                        percent(
                                            snapshot.projection
                                                ?.home_win_probability,
                                        )
                                    }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div v-if="snapshot.status !== 'final'" class="space-y-2">
                    <p class="font-medium">Live sportsbook comparisons</p>
                    <p
                        v-if="!snapshot.markets.length"
                        class="text-sm text-muted-foreground"
                    >
                        No live game lines returned for this snapshot.
                    </p>
                    <div
                        v-for="(market, index) in snapshot.markets"
                        :key="index"
                        class="rounded border p-3 text-sm"
                    >
                        <p class="font-medium">
                            {{ market.bookmaker }} ·
                            {{ marketLabel(market.market) }}
                        </p>
                        <p>{{ outcomes(market) }}</p>
                        <p
                            v-if="!market.fresh || snapshot.stale || failed"
                            class="text-muted-foreground"
                        >
                            Comparison unavailable or stale.
                        </p>
                        <p v-else-if="market.difference !== null">
                            Model difference: {{ number(market.difference) }}
                            {{
                                market.market === 'h2h'
                                    ? 'percentage points'
                                    : 'points'
                            }}
                            · {{ market.lean ?? 'No lean' }}
                        </p>
                    </div>
                </div>
                <div v-if="snapshot.props.length" class="space-y-2">
                    <p class="font-medium">Player estimates</p>
                    <div
                        v-for="prop in snapshot.props"
                        :key="`${prop.player_id}:${prop.market}`"
                        class="rounded border p-3 text-sm"
                    >
                        <p class="font-medium">
                            {{ prop.player }} · {{ marketLabel(prop.market) }}
                        </p>
                        <p>
                            Pregame line {{ number(prop.pregame_line) }} · Prior
                            average {{ number(prop.pregame_mean) }} · Recorded
                            {{ number(prop.actual) }} · Estimated finish
                            {{ number(prop.projected) }}
                        </p>
                        <p
                            v-if="prop.projected === null"
                            class="text-muted-foreground"
                        >
                            {{ prop.status.replaceAll('_', ' ') }}
                        </p>
                        <p
                            v-if="!prop.quotes.length"
                            class="text-muted-foreground"
                        >
                            No live prop line available.
                        </p>
                        <p v-for="(quote, index) in prop.quotes" :key="index">
                            {{ quote.bookmaker }} {{ quote.line }} · O
                            {{ quote.over_price }} / U {{ quote.under_price }}
                            <span
                                v-if="
                                    quote.fresh &&
                                    !snapshot.stale &&
                                    !failed &&
                                    quote.lean
                                "
                                >· {{ quote.lean }} lean ({{
                                    number(quote.difference)
                                }}
                                yards)</span
                            ><span v-else>· No current comparison</span>
                        </p>
                    </div>
                </div>
                <details>
                    <summary class="cursor-pointer text-sm font-medium">
                        Saved live history ({{
                            payload?.meta.snapshot_count
                        }}
                        snapshots)
                    </summary>
                    <div class="mt-2 overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead>
                                <tr>
                                    <th>Captured</th>
                                    <th>State</th>
                                    <th>Home margin</th>
                                    <th>Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="row in payload?.history"
                                    :key="row.id"
                                >
                                    <td class="py-1">
                                        {{ time(row.observed_at) }}
                                    </td>
                                    <td>
                                        Q{{ row.state.period }}
                                        {{ row.state.clock }} ·
                                        {{ row.state.away_score }}–{{
                                            row.state.home_score
                                        }}
                                    </td>
                                    <td>
                                        {{ number(row.projection?.spread) }}
                                    </td>
                                    <td>{{ number(row.projection?.total) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </details>
                <p class="text-xs text-muted-foreground">
                    Experimental estimates, not calibrated betting edges. Player
                    estimates blend prior production with the current pace; they
                    do not account for depth-chart changes or teammate injury
                    redistribution. Overtime projections are withheld.
                </p>
            </template>
        </CardContent>
    </Card>
</template>
