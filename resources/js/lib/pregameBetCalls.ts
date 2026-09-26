type Row = Record<string, unknown>;
const record = (value: unknown): Row =>
    value && typeof value === 'object' ? (value as Row) : {};
const lineNumber = (value: unknown): number | null =>
    typeof value === 'number' && Number.isFinite(value) ? value : null;

/** Render approved public selections; never promote a model forecast or candidate. */
export function pregameBetCalls(
    prediction: unknown,
    home: string,
    away: string,
) {
    const data = record(prediction);
    const calls = { winner: 'No bet', spread: 'No bet', total: 'No bet' };
    const recommendation = record(data.recommendation);
    const publicPick = record(recommendation.public ?? recommendation);
    const promotion = record(recommendation.promotion);
    const apply = (pick: Row) => {
        const market = String(pick.market_type ?? pick.type ?? '');
        const side = pick.pick_side ?? pick.side;
        const team = side === 'home' ? home : side === 'away' ? away : null;
        const line = lineNumber(pick.market_line ?? pick.line);
        if (market === 'moneyline' && team) calls.winner = `Bet ${team} to win`;
        if (market === 'spread' && team && line !== null)
            calls.spread = `Bet ${team} ${line > 0 ? '+' : ''}${line} to cover`;
        if (
            market === 'total' &&
            (side === 'over' || side === 'under') &&
            line !== null
        )
            calls.total = `Bet ${side === 'over' ? 'Over' : 'Under'} ${line}`;
    };
    if (
        publicPick.is_bet === true &&
        publicPick.recommendation_type === 'bet' &&
        publicPick.prediction_phase !== 'live' &&
        publicPick.odds_fresh !== false
    ) {
        // Approval belongs to the public recommendation, never its candidate list.
        if (
            promotion.status !== 'blocked' &&
            !(
                Array.isArray(publicPick.block_reasons) &&
                publicPick.block_reasons.length
            )
        )
            apply(publicPick);
    }
    const signal = record(data.value_signal);
    if (
        signal.has_playable_value === true &&
        signal.decision_status !== 'blocked' &&
        signal.decision_status !== 'unavailable'
    )
        apply(record(signal.best));
    return calls;
}
