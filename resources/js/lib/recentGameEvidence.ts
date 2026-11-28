import { isFinalGameStatus } from './gameStatus';

type ObservedGame = {
    id: number;
    starts_at?: string | null;
    status?: string | null;
    season?: string | number | null;
    season_type?: string | number | null;
    home_team_id?: number;
    away_team_id?: number;
};
export function observedStart(value: string | null | undefined): number | null {
    if (!value || !/[T ]\d{2}:\d{2}.*(?:Z|[+-]\d{2}:?\d{2})$/.test(value))
        return null;
    const timestamp = Date.parse(value);
    return Number.isFinite(timestamp) ? timestamp : null;
}
/** Relative ordering needs actual offset-bearing starts, never guessed venue/app wall time. */
export function recentGamesBefore<T extends ObservedGame>(
    games: T[],
    target: ObservedGame,
    teamId?: number,
): T[] {
    const cutoff = observedStart(target.starts_at);
    if (
        cutoff === null ||
        target.season === null ||
        target.season === undefined
    )
        return [];
    return games
        .filter((game) => {
            const start = observedStart(game.starts_at);
            return (
                isFinalGameStatus(game.status) &&
                game.id !== target.id &&
                start !== null &&
                start < cutoff &&
                String(game.season) === String(target.season) &&
                (target.season_type === null ||
                    target.season_type === undefined ||
                    String(game.season_type) === String(target.season_type)) &&
                (teamId === undefined ||
                    [game.home_team_id, game.away_team_id].includes(teamId))
            );
        })
        .sort(
            (a, b) => observedStart(b.starts_at)! - observedStart(a.starts_at)!,
        )
        .slice(0, 5);
}
export function recentGameQuery(target: ObservedGame) {
    if (observedStart(target.starts_at) === null || target.season == null)
        return null;
    return {
        per_page: 5,
        completed_only: true,
        season: Number(target.season),
        ...(target.season_type == null
            ? {}
            : { season_type: String(target.season_type) }),
        before_game_at: target.starts_at!,
        exclude_game_id: target.id,
    };
}
