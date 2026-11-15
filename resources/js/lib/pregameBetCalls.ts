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
    dashboard = false,
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
        modelLine === null
            ? (number(assessment.model_home_margin) ??
              (dashboard ? number(data.predicted_spread) : null))
            : -modelLine;
    const marketLine = number(
        context.home_spread ??
            best.market_home_line ??
            assessment.market_home_line,
    );
    const projectedTotal = number(data.predicted_total);
    const marketTotal = number(context.total);
    const calls = {
        winner: 'Model unavailable',
        spread: dashboard ? '—' : 'Line unavailable',
        total: dashboard ? '—' : 'Line unavailable',
    };
    const bet = dashboard ? '' : 'Bet ';
    const direction = probability !== null ? probability - 0.5 : margin;
    if (direction !== null)
        calls.winner =
            direction === 0
                ? 'Even matchup'
                : `${bet}${direction > 0 ? home : away}${dashboard ? '' : ' to win'}`;
    const isOffSchedule =
        dashboard &&
        ['STATUS_POSTPONED', 'STATUS_CANCELED', 'STATUS_CANCELLED'].includes(
            String(data.status),
        );
    if (!isOffSchedule && margin !== null && marketLine !== null) {
        const edge = margin + marketLine;
        const team = edge > 0 ? home : away;
        const line = edge > 0 ? marketLine : -marketLine;
        calls.spread =
            Math.abs(edge) < 0.0001
                ? 'Model projects a push'
                : `${bet}${team} ${line > 0 ? '+' : ''}${line} to cover`;
    } else if (
        !isOffSchedule &&
        best.type === 'spread' &&
        ['home', 'away'].includes(String(best.side)) &&
        number(best.market_line) !== null
    ) {
        const line = number(best.market_line)!;
        calls.spread = `${bet}${best.side === 'home' ? home : away} ${line > 0 ? '+' : ''}${line} to cover`;
    } else if (dashboard && margin !== null) {
        calls.spread =
            margin === 0
                ? 'Projected tie'
                : `Projected ${margin > 0 ? home : away} by ${Math.abs(margin)}`;
    }
    if (!isOffSchedule && projectedTotal !== null && marketTotal !== null)
        calls.total =
            Math.abs(projectedTotal - marketTotal) < 0.0001
                ? 'Model projects a push'
                : `${bet}${projectedTotal > marketTotal ? 'Over' : 'Under'} ${marketTotal}`;
    else if (
        !isOffSchedule &&
        best.type === 'total' &&
        ['over', 'under'].includes(String(best.side)) &&
        number(best.market_line) !== null
    )
        calls.total = `${bet}${best.side === 'over' ? 'Over' : 'Under'} ${number(best.market_line)}`;
    else if (dashboard && projectedTotal !== null)
        calls.total = `Projected ${projectedTotal}`;
    return calls;
}
