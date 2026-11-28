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
    record_updated_at?: string | null;
    record_updated_at_raw?: string | null;
    record_clock_status?: 'unverified_storage_clock';
    source_clock_status?: 'observation_unavailable';
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
    const unknown = 'Provider observation freshness is unavailable.';
    if (!snapshot.projection && NFL_LIVE_STATUSES.has(snapshot.game.status))
        return `Projection unavailable: valid game state and a pregame prediction are required. ${unknown}`;
    // Neither legacy local record text nor the old source_updated_at alias
    // qualifies an observation instant. Do not parse it or invent an age.
    void now;
    return unknown;
}
