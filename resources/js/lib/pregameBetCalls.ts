type Row = Record<string, unknown>;
const record = (value: unknown): Row =>
    value && typeof value === 'object' ? (value as Row) : {};
const number = (value: unknown): number | null =>
    value !== null &&
    value !== undefined &&
    value !== '' &&
    Number.isFinite(Number(value))
        ? Number(value)
        : null;

/** Display model directions. Bet approval is deliberately not a display gate. */
export function pregameBetCalls(
    prediction: unknown,
    home: string,
    away: string,
) {
    const data = record(prediction);
    const context = record(data.model_bet_context);
    const best = record(record(data.value_signal).best);
    const assessment = record(record(data.value_signal).spread_assessment);
    const probability = number(
        data.home_win_probability ?? data.win_probability,
    );
    const modelLine = number(context.model_home_spread);
    const margin =
        modelLine !== null ? -modelLine : number(assessment.model_home_margin);
    const marketLine = number(
        context.home_spread ??
            best.market_home_line ??
            assessment.market_home_line,
    );
    const projectedTotal = number(data.predicted_total);
    const marketTotal = number(context.total);
    const calls = {
        winner: 'Model unavailable',
        spread: 'Line unavailable',
        total: 'Line unavailable',
    };
    const direction = probability !== null ? probability - 0.5 : margin;
    if (direction !== null)
        calls.winner =
            direction === 0
                ? 'Even matchup'
                : `Bet ${direction > 0 ? home : away} to win`;
    if (margin !== null && marketLine !== null) {
        const edge = margin + marketLine;
        const team = edge > 0 ? home : away;
        const line = edge > 0 ? marketLine : -marketLine;
        calls.spread =
            Math.abs(edge) < 0.0001
                ? 'Model projects a push'
                : `Bet ${team} ${line > 0 ? '+' : ''}${line} to cover`;
    } else if (
        best.type === 'spread' &&
        ['home', 'away'].includes(String(best.side)) &&
        number(best.market_line) !== null
    ) {
        const line = number(best.market_line)!;
        calls.spread = `Bet ${best.side === 'home' ? home : away} ${line > 0 ? '+' : ''}${line} to cover`;
    }
    if (projectedTotal !== null && marketTotal !== null)
        calls.total =
            Math.abs(projectedTotal - marketTotal) < 0.0001
                ? 'Model projects a push'
                : `Bet ${projectedTotal > marketTotal ? 'Over' : 'Under'} ${marketTotal}`;
    else if (
        best.type === 'total' &&
        ['over', 'under'].includes(String(best.side)) &&
        number(best.market_line) !== null
    )
        calls.total = `Bet ${best.side === 'over' ? 'Over' : 'Under'} ${number(best.market_line)}`;
    return calls;
}
