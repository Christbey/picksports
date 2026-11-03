/** API canonical markets use home handicaps; dashboard and legacy values use home margins. */
export function predictionHomeMargin(
    prediction: unknown,
    usesHomeMargin = false,
): number | null {
    if (!prediction || typeof prediction !== 'object') return null;
    const value = prediction as Record<string, unknown>;
    const spread = value.predicted_spread;
    if (spread === null || spread === undefined || spread === '') return null;
    const parsed = Number(spread);
    if (!Number.isFinite(parsed)) return null;
    const canonicalMarket =
        !usesHomeMargin &&
        value.phase === 'pregame' &&
        typeof value.revision === 'number' &&
        typeof value.game !== 'string';
    return canonicalMarket ? -parsed : parsed;
}
