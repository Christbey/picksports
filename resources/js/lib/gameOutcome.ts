import type { RecentGameListItem } from '@/types';

export const finiteValue = (value: unknown): number | null => {
    if (typeof value !== 'number' && typeof value !== 'string') return null;
    if (typeof value === 'string' && value.trim() === '') return null;
    const result = Number(value);
    return Number.isFinite(result) ? result : null;
};

export const gameOutcome = (
    game: RecentGameListItem,
    teamId: number,
): 'W' | 'L' | 'T' | null => {
    if (game.status && game.status !== 'STATUS_FINAL') return null;
    if (![game.home_team_id, game.away_team_id].includes(teamId)) return null;
    const home = finiteValue(game.home_score);
    const away = finiteValue(game.away_score);
    if (home === null || away === null || home < 0 || away < 0) return null;
    if (home === away) return 'T';
    return (game.home_team_id === teamId ? home > away : away > home)
        ? 'W'
        : 'L';
};

export const recentRecord = (
    games: RecentGameListItem[],
    teamId: number,
): string => {
    const outcomes = games.map((game) => gameOutcome(game, teamId));
    const count = (value: 'W' | 'L' | 'T' | null) =>
        outcomes.filter((outcome) => outcome === value).length;
    return `${count('W')}-${count('L')}-${count('T')} W–L–T${count(null) ? ` · ${count(null)} ungraded` : ''}`;
};
