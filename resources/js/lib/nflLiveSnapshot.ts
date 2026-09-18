export const NFL_LIVE_STATUSES = new Set([
    'STATUS_IN_PROGRESS',
    'STATUS_HALFTIME',
    'STATUS_END_PERIOD',
]);

export interface NflLiveSnapshot {
    game: {
        id: number;
        status: string;
        home_score: number | null;
        away_score: number | null;
        period: number | null;
        game_clock: string | null;
    };
    projection: {
        live_win_probability: number;
        live_predicted_spread: number;
        live_predicted_total: number;
        live_seconds_remaining: number;
    } | null;
    source_updated_at: string | null;
    generated_at: string;
    warning: string;
}

export function liveSnapshotWarning(
    snapshot: NflLiveSnapshot | null,
    now: number,
    failed: boolean,
): string | null {
    if (failed)
        return 'Refresh failed — showing the last received snapshot, which may be stale.';
    if (!snapshot) return 'Waiting for a consistent game-state snapshot.';
    const timestamp = Date.parse(snapshot.source_updated_at ?? '');
    if (!Number.isFinite(timestamp) || timestamp > now + 30000)
        return 'Source update time unavailable or invalid; freshness cannot be verified.';
    if (now - timestamp > 180000)
        return 'Stale game data — the source has not updated in over 3 minutes.';
    if (!snapshot.projection && NFL_LIVE_STATUSES.has(snapshot.game.status))
        return 'Projection unavailable: valid game state and a pregame prediction are required.';
    return null;
}
