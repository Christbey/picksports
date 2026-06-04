import type { ApiV2Stat } from '@/types';

type FlatStatRow = Record<string, unknown> & {
    id: number;
    game_id?: number | string | null;
    team_id?: number | string | null;
    player_id?: number | string | null;
    stat_type?: string | null;
    team_type?: string | null;
    team?: ApiV2Stat['team'];
    player?: ApiV2Stat['player'];
    game?: ApiV2Stat['game'];
};

const toNumberId = (value: ApiV2Stat['id']): number => {
    const parsed = Number(value);

    return Number.isFinite(parsed) ? parsed : 0;
};

export const flattenApiV2Stat = (row: ApiV2Stat): FlatStatRow => ({
    ...(row.stats ?? {}),
    id: toNumberId(row.id),
    game_id: row.game_id,
    team_id: row.team_id,
    player_id: row.player_id,
    stat_type: row.stat_type,
    team_type: row.team_type,
    team: row.team,
    player: row.player,
    game: row.game,
});

export const flattenApiV2Stats = (rows: ApiV2Stat[] = []): FlatStatRow[] =>
    rows.map((row) => flattenApiV2Stat(row));
