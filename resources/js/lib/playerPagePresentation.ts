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

export const observedNumber = (value: unknown): number | null => {
    if (value == null || value === '' || typeof value === 'boolean')
        return null;
    if (typeof value !== 'number' && typeof value !== 'string') return null;
    const numeric = Number(value);
    return Number.isFinite(numeric) ? numeric : null;
};
export const displayObserved = (value: unknown): number | string =>
    observedNumber(value) ?? '—';

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
        line: observedNumber(prop.line),
        over_price: observedNumber(prop.over_price),
        under_price: observedNumber(prop.under_price),
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
    const calendar = dateString?.match(/^(\d{4}-\d{2}-\d{2})(?:T.*)?$/)?.[1];
    if (!calendar) return '—';
    const date = new Date(`${calendar}T12:00:00Z`);
    if (
        !Number.isFinite(date.getTime()) ||
        date.toISOString().slice(0, 10) !== calendar
    )
        return '—';
    return date.toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        timeZone: 'UTC',
    });
};

export const formatOdds = (odds: number | null): string =>
    odds != null && Number.isFinite(odds) && Math.abs(odds) >= 100
        ? odds > 0
            ? `+${odds}`
            : odds.toString()
        : 'Price unavailable';

export const averageObserved = (
    stats: PlayerStat[],
    field: string,
    digits = 1,
    alternate?: string,
): string => {
    const values = stats
        .map((row) =>
            observedNumber(row[field] ?? (alternate ? row[alternate] : null)),
        )
        .filter((value): value is number => value !== null);
    return values.length
        ? (
              values.reduce((sum, value) => sum + value, 0) / values.length
          ).toFixed(digits)
        : '—';
};
export const aggregateObserved = (
    stats: PlayerStat[],
    fields: string[],
    calculate: (totals: number[]) => number | null,
    digits: number,
): string => {
    const rows = stats
        .map((row) => fields.map((field) => observedNumber(row[field])))
        .filter((row): row is number[] => row.every((value) => value !== null));
    if (!rows.length) return '—';
    const result = calculate(
        fields.map((_, index) =>
            rows.reduce((sum, row) => sum + row[index], 0),
        ),
    );
    return result !== null && Number.isFinite(result)
        ? result.toFixed(digits)
        : '—';
};

export const defaultSummaryCards: SummaryCard[] = [
    {
        label: 'PPG',
        value: (stats) => averageObserved(stats, 'points'),
        rankKey: 'points_per_game',
    },
    {
        label: 'RPG',
        value: (stats) =>
            averageObserved(stats, 'rebounds', 1, 'rebounds_total'),
        rankKey: 'rebounds_per_game',
    },
    {
        label: 'APG',
        value: (stats) => averageObserved(stats, 'assists'),
        rankKey: 'assists_per_game',
    },
    {
        label: 'SPG',
        value: (stats) => averageObserved(stats, 'steals'),
        rankKey: 'steals_per_game',
    },
    {
        label: 'BPG',
        value: (stats) => averageObserved(stats, 'blocks'),
        rankKey: 'blocks_per_game',
    },
    {
        label: 'FG%',
        value: (stats) =>
            aggregateObserved(
                stats,
                ['field_goals_made', 'field_goals_attempted'],
                ([made, attempts]) =>
                    attempts > 0 ? (100 * made) / attempts : null,
                1,
            ),
        rankKey: 'field_goal_percentage',
    },
    {
        label: '3P%',
        value: (stats) =>
            aggregateObserved(
                stats,
                ['three_point_made', 'three_point_attempted'],
                ([made, attempts]) =>
                    attempts > 0 ? (100 * made) / attempts : null,
                1,
            ),
        rankKey: 'three_point_percentage',
    },
    {
        label: 'FT%',
        value: (stats) =>
            aggregateObserved(
                stats,
                ['free_throws_made', 'free_throws_attempted'],
                ([made, attempts]) =>
                    attempts > 0 ? (100 * made) / attempts : null,
                1,
            ),
        rankKey: 'free_throw_percentage',
    },
    {
        label: 'MPG',
        value: (stats) => averageObserved(stats, 'minutes_played'),
        rankKey: 'minutes_per_game',
    },
];

export const defaultGameLogColumns: GameLogColumn[] = [
    {
        label: 'MIN',
        value: (stat) => displayObserved(stat.minutes_played),
        className: 'py-2 pr-2 text-right',
    },
    {
        label: 'PTS',
        value: (stat) => displayObserved(stat.points),
        className: 'py-2 pr-2 text-right font-medium',
    },
    {
        label: 'REB',
        value: (stat) => displayObserved(stat.rebounds ?? stat.rebounds_total),
        className: 'py-2 pr-2 text-right',
    },
    {
        label: 'AST',
        value: (stat) => displayObserved(stat.assists),
        className: 'py-2 pr-2 text-right',
    },
    {
        label: 'STL',
        value: (stat) => displayObserved(stat.steals),
        className: 'py-2 pr-2 text-right',
    },
    {
        label: 'BLK',
        value: (stat) => displayObserved(stat.blocks),
        className: 'py-2 pr-2 text-right',
    },
    {
        label: 'FG',
        value: (stat) =>
            observedNumber(stat.field_goals_made) === null ||
            observedNumber(stat.field_goals_attempted) === null
                ? '—'
                : `${stat.field_goals_made}-${stat.field_goals_attempted}`,
        className: 'py-2 pr-2 text-right whitespace-nowrap',
    },
    {
        label: '3PT',
        value: (stat) =>
            observedNumber(stat.three_point_made) === null ||
            observedNumber(stat.three_point_attempted) === null
                ? '—'
                : `${stat.three_point_made}-${stat.three_point_attempted}`,
        className: 'py-2 pr-2 text-right whitespace-nowrap',
    },
    {
        label: 'FT',
        value: (stat) =>
            observedNumber(stat.free_throws_made) === null ||
            observedNumber(stat.free_throws_attempted) === null
                ? '—'
                : `${stat.free_throws_made}-${stat.free_throws_attempted}`,
        className: 'py-2 pr-2 text-right whitespace-nowrap',
    },
    {
        label: 'TO',
        value: (stat) => displayObserved(stat.turnovers),
        className: 'py-2 text-right',
    },
];

export const getPlayerOpponent = (
    stat: PlayerStat,
    player: Player | null,
): { label: string; name: string } | null => {
    if (!stat.game || !player) return null;
    const recordedTeam = observedNumber(stat.team_id);
    if (
        recordedTeam == null ||
        ![stat.game.home_team_id, stat.game.away_team_id].includes(recordedTeam)
    )
        return null;
    const isHome = stat.game.home_team_id === recordedTeam;
    const opp = isHome ? stat.game.away_team : stat.game.home_team;
    return {
        label: isHome ? 'vs' : '@',
        name: opp?.abbreviation || opp?.name || '???',
    };
};
