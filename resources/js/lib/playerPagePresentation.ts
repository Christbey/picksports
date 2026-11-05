import type {
    Player,
    PlayerProp,
    PlayerStat,
    SummaryCard,
    GameLogColumn,
} from '@/types/player-page';

export const toNumber = (value: unknown): number => {
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

export const normalizePlayer = (
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

export const normalizeStatRow = (row: Record<string, unknown>): PlayerStat => {
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

export const normalizePlayerProp = (
    prop: Record<string, unknown>,
): PlayerProp => {
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

export const formatDate = (dateString: string | null): string => {
    if (!dateString) return '-';
    return new Date(dateString).toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
    });
};

export const formatOdds = (odds: number): string =>
    odds > 0 ? `+${odds}` : odds.toString();

export const defaultSummaryCards: SummaryCard[] = [
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

export const defaultGameLogColumns: GameLogColumn[] = [
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

export const getPlayerOpponent = (
    stat: PlayerStat,
    player: Player | null,
): { label: string; name: string } | null => {
    if (!stat.game || !player) return null;
    const isHome = stat.game.home_team_id === player.team_id;
    const opp = isHome ? stat.game.away_team : stat.game.home_team;
    return {
        label: isHome ? 'vs' : '@',
        name: opp?.abbreviation || opp?.name || '???',
    };
};
