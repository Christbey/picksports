import type { ApiV2Prediction, ApiV2Record } from '@/types';

export function record(value: unknown): ApiV2Record {
    return value && typeof value === 'object' ? (value as ApiV2Record) : {};
}
export function numberValue(value: unknown): number | null {
    if (value === null || value === undefined || value === '') return null;
    const n = Number(value);
    return Number.isFinite(n) ? n : null;
}
export function teamLabel(team: unknown): string {
    const t = record(team);
    return String(t.abbreviation ?? t.display_name ?? t.name ?? 'Pending');
}
export function signed(value: number): string {
    return `${value > 0 ? '+' : ''}${Number(value.toFixed(1))}`;
}

/** HOME probability determines the winner; market pick_side is a different concept. */
export function nflBoardPresentation(prediction: ApiV2Prediction) {
    const board = record(prediction.nfl_board);
    const forecast = record(board.forecast);
    const p = numberValue(
        forecast.win_probability ??
            prediction.home_win_probability ??
            prediction.win_probability,
    );
    const probability = p !== null && p >= 0 && p <= 1 ? p : null;
    const side =
        probability === null || probability === 0.5
            ? null
            : probability > 0.5
              ? 'home'
              : 'away';
    const game = prediction.game;
    const team = (s: 'home' | 'away') =>
        teamLabel(s === 'home' ? game?.home_team : game?.away_team);
    const margin = numberValue(
        forecast.predicted_spread ?? prediction.predicted_spread,
    );
    const total = numberValue(
        forecast.predicted_total ?? prediction.predicted_total,
    );
    const market = record(board.market);
    // Never infer a line's side from the old market_context.pick_side field.
    const homeLine = numberValue(market.home_spread);
    const marketTotal = numberValue(market.total);
    const edge =
        margin !== null && homeLine !== null ? margin + homeLine : null;
    const spreadSide =
        edge === null || Math.abs(edge) < 0.05
            ? null
            : edge > 0
              ? 'home'
              : 'away';
    const minimumSpreadEdge =
        numberValue(record(prediction.spread_assessment).minimum_edge_points) ??
        2;
    const smallSpreadEdge = edge !== null && Math.abs(edge) < minimumSpreadEdge;
    const research = record(board.research);
    const status = String(research.status ?? 'missing');
    const final = String(prediction.status ?? game?.status ?? '')
        .toLowerCase()
        .includes('final');
    return {
        winner: side
            ? team(side)
            : probability === 0.5
              ? 'Even matchup'
              : 'Unavailable',
        winnerProbability:
            side && probability !== null
                ? side === 'home'
                    ? probability
                    : 1 - probability
                : probability,
        winnerSide: side,
        homeProbability: probability,
        margin,
        marginLabel:
            margin === null
                ? 'Unavailable'
                : margin === 0
                  ? 'Even matchup'
                  : `${team(margin > 0 ? 'home' : 'away')} by ${Math.abs(margin).toFixed(1)}`,
        total,
        spreadLean:
            spreadSide && smallSpreadEdge
                ? 'Pass — small edge'
                : spreadSide && homeLine !== null
                  ? `${team(spreadSide)} ${signed(spreadSide === 'home' ? homeLine : -homeLine)}`
                  : homeLine === null
                    ? 'Line unavailable'
                    : margin === null
                      ? 'Forecast unavailable'
                      : 'No edge',
        spreadEdge: edge === null ? null : Math.abs(edge),
        marketSpread:
            homeLine === null
                ? 'Unavailable'
                : `${team('home')} ${signed(homeLine)}`,
        marketTotal,
        totalLean:
            total === null || marketTotal === null
                ? 'Unavailable'
                : Math.abs(total - marketTotal) < 0.05
                  ? 'No edge'
                  : `${total > marketTotal ? 'Over' : 'Under'} ${marketTotal}`,
        totalEdge:
            total === null || marketTotal === null
                ? null
                : Math.abs(total - marketTotal),
        researchStatus: status,
        researchLabel:
            {
                reviewed: 'Research checked',
                hold: 'Research hold',
                stale: 'Research outdated',
                evidence_expired: 'Evidence expired',
                prediction_changed: 'Prediction changed',
                refresh_blocked: 'Refresh blocked',
                reassessment_due: 'Reassessment needed',
                evidence_changed: 'Evidence needs assessment',
                archived: 'Archived research',
                missing: 'Research pending',
            }[status] ?? 'Research pending',
        researchReasons: Array.isArray(research.reasons)
            ? research.reasons.map(String)
            : [],
        researchDecision: String(research.decision ?? ''),
        researchAt:
            typeof research.assessed_at === 'string'
                ? research.assessed_at
                : null,
        forecastSource: String(board.forecast_source ?? 'Model forecast'),
        forecastAt:
            typeof board.forecast_at === 'string'
                ? board.forecast_at
                : prediction.updated_at,
        marketBook: String(market.bookmaker ?? ''),
        marketAt:
            typeof market.observed_at === 'string' ? market.observed_at : null,
        historicalMarket: market.historical === true,
        final,
        result: !final
            ? null
            : prediction.winner_correct === true
              ? 'Projection won'
              : prediction.winner_correct === false
                ? 'Projection lost'
                : 'Ungraded',
    };
}
export function kickoffLabel(prediction: ApiV2Prediction): string {
    const iso = prediction.game?.kickoff_at;
    if (typeof iso !== 'string') return 'Time pending';
    const date = new Date(iso);
    if (!Number.isFinite(date.getTime())) return 'Time pending';
    return new Intl.DateTimeFormat(undefined, {
        hour: 'numeric',
        minute: '2-digit',
        timeZoneName: 'short',
    }).format(date);
}
export function percent(value: number | null): string {
    return value === null ? '—' : `${(value * 100).toFixed(1)}%`;
}
