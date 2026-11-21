/** API canonical markets use home handicaps; dashboard and legacy values use home margins. */
export function predictionHomeMargin(
    prediction: unknown,
    usesHomeMargin = false,
): number | null {
    if (!prediction || typeof prediction !== 'object') return null;
    const value = prediction as Record<string, unknown>;
    // Board adapters can omit canonical phase/revision metadata. This field is
    // explicitly a home handicap in both API and dashboard payloads.
    const context = value.model_bet_context;
    const modelLine =
        context && typeof context === 'object'
            ? (context as Record<string, unknown>).model_home_spread
            : null;
    if (
        (typeof modelLine === 'number' ||
            (typeof modelLine === 'string' && modelLine.trim())) &&
        Number.isFinite(Number(modelLine))
    ) {
        return Number(modelLine) === 0 ? 0 : -Number(modelLine);
    }
    const spread = value.predicted_spread;
    if (typeof spread !== 'number' && typeof spread !== 'string') return null;
    if (typeof spread === 'string' && !spread.trim()) return null;
    const parsed = Number(spread);
    if (!Number.isFinite(parsed)) return null;
    if (parsed === 0) return 0;
    const canonicalMarket =
        !usesHomeMargin &&
        value.phase === 'pregame' &&
        typeof value.revision === 'number' &&
        typeof value.game !== 'string';
    return canonicalMarket ? -parsed : parsed;
}
