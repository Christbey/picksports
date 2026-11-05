import type { Player, PlayerProp, PlayerStat } from '@/types/player-page';

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
