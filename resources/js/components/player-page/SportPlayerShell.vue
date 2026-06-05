<script setup lang="ts">
import type { UrlMethodPair } from '@inertiajs/core';
import { Head, Link } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { useApiV2Client } from '@/composables/useApiV2Client';
import AppLayout from '@/layouts/AppLayout.vue';
import type { ApiV2Query, ApiV2SportSlug, BreadcrumbItem } from '@/types';

type HrefLike = string | UrlMethodPair;

interface Player {
    id: number;
    team_id: number;
    first_name: string | null;
    last_name: string | null;
    full_name?: string | null;
    name: string;
    jersey_number: string | null;
    position: string | null;
    height: string | null;
    weight: string | null;
    headshot_url: string | null;
    active_injuries_count?: number;
    active_injuries?: Array<{
        id: number;
        status: string | null;
        detail: string | null;
        type: string | null;
        return_date: string | null;
        source_updated_at?: string | null;
    }>;
    team: {
        id: number;
        name: string;
        display_name: string;
        abbreviation: string;
    } | null;
}

interface PlayerProp {
    id: number;
    market: string;
    line: number;
    over_price: number;
    under_price: number;
    game: {
        id: number;
        home_team: string;
        away_team: string;
    };
}

interface PlayerStat {
    id: number;
    [key: string]: number | string | null | undefined | PlayerStat['game'];
    game: {
        id: number;
        game_date: string | null;
        home_team_id: number;
        away_team_id: number;
        home_team: { abbreviation: string; name: string } | null;
        away_team: { abbreviation: string; name: string } | null;
    } | null;
}

interface SummaryCard {
    label: string;
    value: (stats: PlayerStat[]) => string;
    rankKey?: string;
}

interface GameLogColumn {
    label: string;
    value: (stat: PlayerStat) => string | number;
    className?: string;
}

interface SportPlayerShellConfig {
    sport: ApiV2SportSlug;
    sportLabel: string;
    predictionsHref: string;
    teamLink?: (id: number) => HrefLike;
    gameLink?: (id: number) => HrefLike;
    showPlayerProps?: boolean;
    statsQuery?: ApiV2Query;
    leaderboardQuery?: ApiV2Query;
    summaryCards?: SummaryCard[];
    gameLogColumns?: GameLogColumn[];
}

const props = defineProps<{
    config: SportPlayerShellConfig;
    playerId: number;
}>();

const playerData = ref<Player | null>(null);
const playerProps = ref<PlayerProp[]>([]);
const gameLogs = ref<PlayerStat[]>([]);
const leaderboardRows = ref<Record<string, unknown>[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);
const api = useApiV2Client();

const breadcrumbs = computed<BreadcrumbItem[]>(() => {
    const items: BreadcrumbItem[] = [
        { title: props.config.sportLabel, href: props.config.predictionsHref },
    ];
    if (playerData.value?.team?.id != null && props.config.teamLink) {
        items.push({
            title: playerData.value.team.name,
            href: String(props.config.teamLink(playerData.value.team.id)),
        });
    }
    items.push({ title: playerData.value?.name ?? 'Player', href: '#' });
    return items;
});

const formatDate = (dateString: string | null): string => {
    if (!dateString) return '-';
    return new Date(dateString).toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
    });
};

const formatOdds = (odds: number): string =>
    odds > 0 ? `+${odds}` : odds.toString();

const toNumber = (value: unknown): number => {
    const numeric = Number(value);
    return Number.isFinite(numeric) ? numeric : 0;
};

const teamName = (
    team:
        | {
              abbreviation?: string | null;
              name?: string | null;
              display_name?: string | null;
          }
        | string
        | null
        | undefined,
): string => {
    if (!team) return 'TBD';
    if (typeof team === 'string') return team;

    return team.abbreviation || team.name || team.display_name || 'TBD';
};

const normalizePlayer = (
    player: Record<string, unknown> | null,
): Player | null => {
    if (!player) return null;

    const fullName =
        (player.full_name as string | null | undefined) ||
        (player.display_name as string | null | undefined) ||
        (player.name as string | null | undefined) ||
        null;
    const name = fullName || 'Player';

    return {
        ...(player as unknown as Player),
        name,
        full_name: fullName,
        first_name: (player.first_name as string | null | undefined) ?? null,
        last_name: (player.last_name as string | null | undefined) ?? null,
    };
};

const normalizeStatRow = (row: Record<string, unknown>): PlayerStat => {
    const stats =
        row.stats && typeof row.stats === 'object'
            ? (row.stats as Record<string, unknown>)
            : {};

    return {
        ...stats,
        ...row,
        game: (row.game as PlayerStat['game']) ?? null,
    } as PlayerStat;
};

const normalizePlayerProp = (prop: Record<string, unknown>): PlayerProp => {
    const game = (
        prop.game && typeof prop.game === 'object'
            ? (prop.game as Record<string, unknown>)
            : {}
    ) as {
        id?: number;
        home_team?: string | Record<string, unknown> | null;
        away_team?: string | Record<string, unknown> | null;
    };

    return {
        ...(prop as unknown as PlayerProp),
        line: toNumber(prop.line),
        over_price: toNumber(prop.over_price),
        under_price: toNumber(prop.under_price),
        game: {
            id: Number(game.id ?? prop.game_id ?? 0),
            home_team: teamName(
                game.home_team as Parameters<typeof teamName>[0],
            ),
            away_team: teamName(
                game.away_team as Parameters<typeof teamName>[0],
            ),
        },
    };
};

const defaultSummaryCards: SummaryCard[] = [
    {
        label: 'PPG',
        value: (stats) =>
            (
                stats.reduce((acc, s) => acc + toNumber(s.points), 0) /
                stats.length
            ).toFixed(1),
        rankKey: 'points_per_game',
    },
    {
        label: 'RPG',
        value: (stats) =>
            (
                stats.reduce(
                    (acc, s) => acc + toNumber(s.rebounds ?? s.rebounds_total),
                    0,
                ) / stats.length
            ).toFixed(1),
        rankKey: 'rebounds_per_game',
    },
    {
        label: 'APG',
        value: (stats) =>
            (
                stats.reduce((acc, s) => acc + toNumber(s.assists), 0) /
                stats.length
            ).toFixed(1),
        rankKey: 'assists_per_game',
    },
    {
        label: 'SPG',
        value: (stats) =>
            (
                stats.reduce((acc, s) => acc + toNumber(s.steals), 0) /
                stats.length
            ).toFixed(1),
        rankKey: 'steals_per_game',
    },
    {
        label: 'BPG',
        value: (stats) =>
            (
                stats.reduce((acc, s) => acc + toNumber(s.blocks), 0) /
                stats.length
            ).toFixed(1),
        rankKey: 'blocks_per_game',
    },
    {
        label: 'FG%',
        value: (stats) => {
            const fgm = stats.reduce(
                (acc, s) => acc + toNumber(s.field_goals_made),
                0,
            );
            const fga = stats.reduce(
                (acc, s) => acc + toNumber(s.field_goals_attempted),
                0,
            );
            return fga > 0 ? ((fgm / fga) * 100).toFixed(1) : '-';
        },
        rankKey: 'field_goal_percentage',
    },
    {
        label: '3P%',
        value: (stats) => {
            const tpm = stats.reduce(
                (acc, s) => acc + toNumber(s.three_point_made),
                0,
            );
            const tpa = stats.reduce(
                (acc, s) => acc + toNumber(s.three_point_attempted),
                0,
            );
            return tpa > 0 ? ((tpm / tpa) * 100).toFixed(1) : '-';
        },
        rankKey: 'three_point_percentage',
    },
    {
        label: 'FT%',
        value: (stats) => {
            const ftm = stats.reduce(
                (acc, s) => acc + toNumber(s.free_throws_made),
                0,
            );
            const fta = stats.reduce(
                (acc, s) => acc + toNumber(s.free_throws_attempted),
                0,
            );
            return fta > 0 ? ((ftm / fta) * 100).toFixed(1) : '-';
        },
        rankKey: 'free_throw_percentage',
    },
    {
        label: 'MPG',
        value: (stats) =>
            (
                stats.reduce((acc, s) => acc + toNumber(s.minutes_played), 0) /
                stats.length
            ).toFixed(1),
        rankKey: 'minutes_per_game',
    },
];

const summaryCards = computed(
    () => props.config.summaryCards ?? defaultSummaryCards,
);

const rankForMetric = (
    metricKey: string,
): { rank: number; total: number } | null => {
    if (leaderboardRows.value.length === 0 || !playerData.value) return null;

    const eligibleRows = leaderboardRows.value
        .filter((row) => toNumber(row[metricKey]) > 0)
        .sort((a, b) => toNumber(b[metricKey]) - toNumber(a[metricKey]));

    if (eligibleRows.length === 0) return null;

    const rankIndex = eligibleRows.findIndex(
        (row) => toNumber(row.player_id) === playerData.value?.id,
    );

    if (rankIndex === -1) return null;

    return { rank: rankIndex + 1, total: eligibleRows.length };
};

const seasonSummaryValues = computed(() => {
    if (gameLogs.value.length === 0) return null;

    return summaryCards.value.map((card) => ({
        label: card.label,
        value: card.value(gameLogs.value),
        rank: card.rankKey ? rankForMetric(card.rankKey) : null,
    }));
});

const defaultGameLogColumns: GameLogColumn[] = [
    {
        label: 'MIN',
        value: (stat) => toNumber(stat.minutes_played) || '-',
        className: 'py-2 pr-2 text-right',
    },
    {
        label: 'PTS',
        value: (stat) => toNumber(stat.points) || '-',
        className: 'py-2 pr-2 text-right font-medium',
    },
    {
        label: 'REB',
        value: (stat) => toNumber(stat.rebounds ?? stat.rebounds_total) || '-',
        className: 'py-2 pr-2 text-right',
    },
    {
        label: 'AST',
        value: (stat) => toNumber(stat.assists) || '-',
        className: 'py-2 pr-2 text-right',
    },
    {
        label: 'STL',
        value: (stat) => toNumber(stat.steals) || '-',
        className: 'py-2 pr-2 text-right',
    },
    {
        label: 'BLK',
        value: (stat) => toNumber(stat.blocks) || '-',
        className: 'py-2 pr-2 text-right',
    },
    {
        label: 'FG',
        value: (stat) =>
            `${toNumber(stat.field_goals_made)}-${toNumber(stat.field_goals_attempted)}`,
        className: 'py-2 pr-2 text-right whitespace-nowrap',
    },
    {
        label: '3PT',
        value: (stat) =>
            `${toNumber(stat.three_point_made)}-${toNumber(stat.three_point_attempted)}`,
        className: 'py-2 pr-2 text-right whitespace-nowrap',
    },
    {
        label: 'FT',
        value: (stat) =>
            `${toNumber(stat.free_throws_made)}-${toNumber(stat.free_throws_attempted)}`,
        className: 'py-2 pr-2 text-right whitespace-nowrap',
    },
    {
        label: 'TO',
        value: (stat) => toNumber(stat.turnovers) || '-',
        className: 'py-2 text-right',
    },
];

const gameLogColumns = computed(
    () => props.config.gameLogColumns ?? defaultGameLogColumns,
);

const getOpponent = (
    stat: PlayerStat,
): { label: string; name: string } | null => {
    if (!stat.game || !playerData.value) return null;
    const isHome = stat.game.home_team_id === playerData.value.team_id;
    const opp = isHome ? stat.game.away_team : stat.game.home_team;
    return {
        label: isHome ? 'vs' : '@',
        name: opp?.abbreviation || opp?.name || '???',
    };
};

onMounted(async () => {
    try {
        const requests: Promise<void>[] = [
            api.players
                .show(props.config.sport, props.playerId)
                .then((response) => {
                    playerData.value = normalizePlayer(
                        response?.data ?? null,
                    );
                }),
            api.stats
                .players(props.config.sport, {
                    query: {
                        ...(props.config.statsQuery ?? {}),
                        player_id: props.playerId,
                        per_page: 100,
                    },
                })
                .then((response) => {
                    gameLogs.value = (response?.data ?? []).map(
                        normalizeStatRow,
                    );
                }),
        ];

        if (props.config.showPlayerProps) {
            requests.push(
                api.players
                    .playerProps(props.config.sport, props.playerId)
                    .then((response) => {
                        playerProps.value = (response?.data ?? []).map(
                            normalizePlayerProp,
                        );
                    }),
            );
        }

        if (props.config.leaderboardQuery !== null) {
            requests.push(
                api.leaderboards
                    .players(props.config.sport, {
                        query: props.config.leaderboardQuery ?? {},
                    })
                    .then((response) => {
                        leaderboardRows.value = response?.data ?? [];
                    }),
            );
        }

        await Promise.all(requests);
    } catch (e) {
        error.value =
            e instanceof Error ? e.message : 'Failed to load player data';
    } finally {
        loading.value = false;
    }
});
</script>

<template>
    <Head :title="playerData?.name || `${config.sportLabel} Player`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-5 p-3 md:p-4">
            <div
                v-if="playerData"
                class="ui-surface flex items-start gap-4 p-4 md:p-5"
            >
                <img
                    v-if="playerData.headshot_url"
                    :src="playerData.headshot_url"
                    :alt="playerData.name"
                    class="h-24 w-24 rounded-xl object-cover"
                />
                <div
                    v-else
                    class="flex h-24 w-24 items-center justify-center rounded-xl bg-muted text-2xl font-bold text-muted-foreground"
                >
                    {{ playerData.first_name?.[0]
                    }}{{ playerData.last_name?.[0] }}
                </div>
                <div class="flex-1">
                    <h1 class="text-3xl font-semibold tracking-tight">
                        {{ playerData.name }}
                    </h1>
                    <div
                        class="mt-1 flex items-center gap-2 text-muted-foreground"
                    >
                        <span
                            v-if="playerData.jersey_number"
                            class="font-semibold"
                            >#{{ playerData.jersey_number }}</span
                        >
                        <span
                            v-if="
                                playerData.jersey_number && playerData.position
                            "
                            >·</span
                        >
                        <span v-if="playerData.position">{{
                            playerData.position
                        }}</span>
                    </div>
                    <div
                        v-if="playerData.team && playerData.team.id != null"
                        class="mt-1"
                    >
                        <Link
                            v-if="config.teamLink"
                            :href="config.teamLink(playerData.team.id)"
                            class="text-sm text-primary hover:underline"
                        >
                            {{ playerData.team.name }}
                        </Link>
                        <span v-else class="text-sm text-muted-foreground">{{
                            playerData.team.name
                        }}</span>
                    </div>
                    <div
                        v-if="playerData.height || playerData.weight"
                        class="mt-1 text-sm text-muted-foreground"
                    >
                        <span v-if="playerData.height">{{
                            playerData.height
                        }}</span>
                        <span v-if="playerData.height && playerData.weight">
                            ·
                        </span>
                        <span v-if="playerData.weight"
                            >{{ playerData.weight }} lbs</span
                        >
                    </div>
                </div>
            </div>
            <Skeleton v-else class="ui-surface h-32 w-full" />

            <slot v-if="playerData" name="afterHeader" :player="playerData" />

            <Card v-if="playerProps.length > 0" class="mb-4">
                <CardHeader>
                    <CardTitle>Upcoming Props</CardTitle>
                </CardHeader>
                <CardContent>
                    <div class="grid gap-3 md:grid-cols-2 lg:grid-cols-3">
                        <div
                            v-for="prop in playerProps"
                            :key="prop.id"
                            class="space-y-2 rounded-lg border p-4"
                        >
                            <div class="flex items-center justify-between">
                                <span class="text-sm font-semibold">{{
                                    prop.market
                                }}</span>
                                <span class="text-xs text-muted-foreground"
                                    >{{ prop.game.away_team }} @
                                    {{ prop.game.home_team }}</span
                                >
                            </div>
                            <div class="flex items-center justify-center py-2">
                                <span class="text-2xl font-bold">{{
                                    prop.line
                                }}</span>
                            </div>
                            <div class="grid grid-cols-2 gap-2 text-sm">
                                <div class="rounded bg-muted p-2 text-center">
                                    <div class="text-xs text-muted-foreground">
                                        Over
                                    </div>
                                    <div class="font-mono font-semibold">
                                        {{ formatOdds(prop.over_price) }}
                                    </div>
                                </div>
                                <div class="rounded bg-muted p-2 text-center">
                                    <div class="text-xs text-muted-foreground">
                                        Under
                                    </div>
                                    <div class="font-mono font-semibold">
                                        {{ formatOdds(prop.under_price) }}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <div v-if="loading" class="space-y-4">
                <Skeleton class="h-32 w-full" />
                <Skeleton class="h-64 w-full" />
            </div>

            <template v-else>
                <Card v-if="seasonSummaryValues">
                    <CardHeader>
                        <CardTitle class="tracking-tight"
                            >Season Averages ({{
                                gameLogs.length
                            }}
                            games)</CardTitle
                        >
                    </CardHeader>
                    <CardContent>
                        <div
                            class="grid grid-cols-3 gap-4 md:grid-cols-5 lg:grid-cols-9"
                        >
                            <div
                                v-for="card in seasonSummaryValues"
                                :key="card.label"
                                class="ui-surface-subtle p-3 text-center"
                            >
                                <div class="text-sm text-muted-foreground">
                                    {{ card.label }}
                                </div>
                                <div class="text-2xl font-bold">
                                    {{ card.value }}
                                </div>
                                <div
                                    v-if="card.rank"
                                    class="mt-1 text-xs text-muted-foreground"
                                >
                                    Rank {{ card.rank.rank }} /
                                    {{ card.rank.total }} eligible
                                </div>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Game Log</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div v-if="error" class="mb-3 text-sm text-destructive">
                            {{ error }}
                        </div>
                        <div
                            v-if="gameLogs.length > 0"
                            class="overflow-x-auto rounded-xl border border-border/70"
                        >
                            <table class="w-full text-sm">
                                <thead>
                                    <tr
                                        class="border-b bg-muted/35 text-left text-muted-foreground"
                                    >
                                        <th class="pr-4 pb-2 font-medium">
                                            Date
                                        </th>
                                        <th class="pr-4 pb-2 font-medium">
                                            OPP
                                        </th>
                                        <th
                                            v-for="column in gameLogColumns"
                                            :key="column.label"
                                            class="pr-2 pb-2 text-right font-medium"
                                        >
                                            {{ column.label }}
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="stat in gameLogs"
                                        :key="stat.id"
                                        class="border-b transition-colors last:border-b-0 hover:bg-muted/40"
                                    >
                                        <td class="py-2 pr-4">
                                            <Link
                                                v-if="
                                                    stat.game && config.gameLink
                                                "
                                                :href="
                                                    config.gameLink(
                                                        stat.game.id,
                                                    )
                                                "
                                                class="text-primary hover:underline"
                                            >
                                                {{
                                                    formatDate(
                                                        stat.game.game_date,
                                                    )
                                                }}
                                            </Link>
                                            <span v-else>{{
                                                formatDate(
                                                    stat.game?.game_date ??
                                                        null,
                                                )
                                            }}</span>
                                        </td>
                                        <td class="py-2 pr-4">
                                            <template v-if="getOpponent(stat)">
                                                <span
                                                    class="text-muted-foreground"
                                                    >{{
                                                        getOpponent(stat)!.label
                                                    }}</span
                                                >
                                                {{ getOpponent(stat)!.name }}
                                            </template>
                                            <span v-else>-</span>
                                        </td>
                                        <td
                                            v-for="column in gameLogColumns"
                                            :key="column.label"
                                            :class="
                                                column.className ??
                                                'py-2 pr-2 text-right'
                                            "
                                        >
                                            {{ column.value(stat) }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div
                            v-else
                            class="py-8 text-center text-muted-foreground"
                        >
                            <p>No game log data available.</p>
                        </div>
                    </CardContent>
                </Card>
            </template>
        </div>
    </AppLayout>
</template>
