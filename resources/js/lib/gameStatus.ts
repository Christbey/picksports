/** Matches the two completed-result statuses admitted by the server GameData contract. */
export const FINAL_GAME_STATUSES = new Set([
    'STATUS_FINAL',
    'STATUS_FULL_TIME',
]);
export const isFinalGameStatus = (status: unknown): boolean =>
    typeof status === 'string' && FINAL_GAME_STATUSES.has(status.toUpperCase());
