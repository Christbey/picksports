import {
    candidateRecommendation,
    getPredictionRecommendation,
} from '@/lib/predictionRecommendation';
import {
    labelizeMlbCode,
    safeMlbPickStatus,
    tierFromScore,
} from '@/lib/mlbRecommendationLabels';
import type {
    ApiV2Prediction,
    ApiV2Record,
    MlbPeriodInsight,
    MlbPeriodInsightTeam,
} from '@/types';
import type {
    MlbDailyPick,
    MlbPeriodModelContext,
    MlbSignalGroup,
} from '@/types/mlb-daily-picks';

const BETTOR_SIGNAL_LABELS: Record<string, string> = {
    acceptable_model_margin: 'Model margin is inside the playable range',
    blend_probability_strong: 'Blended probability clears the signal screen',
    bullpen_quality_home_edge: 'Bullpen quality supports the home side',
    historical_matchup_context: 'Historical matchup context supports the read',
    home_field_support: 'Home-field context supports the pick',
    model_market_agrees: 'Model and market point to the same side',
    model_projection: 'Model projection supports this angle',
    model_total_over_market: 'Model projects more runs than the market total',
    model_total_under_market: 'Model projects fewer runs than the market total',
    moneyline_bet_filter: 'Moneyline side passed the pick screen',
    moneyline_pick_filter: 'Moneyline side passed the pick screen',
    park_support: 'Ballpark run environment supports the angle',
    positive_no_vig_edge: 'Positive edge after removing sportsbook vig',
    probable_pitchers_confirmed: 'Probable starters are confirmed',
    selective_bet_filter_applied: 'Passed the selective pick screen',
    selective_pick_filter_applied: 'Passed the selective pick screen',
    weather_supports_over: 'Weather leans toward more runs',
    weather_supports_under: 'Weather leans toward fewer runs',
};

const BETTOR_RISK_LABELS: Record<string, string> = {
    away_pick_underperforming_split:
        'Away-side picks have underperformed this split',
    low_confidence_bucket: 'Model confidence is still modest',
    missing_market_context: 'Market context is incomplete',
    missing_odds_timestamp: 'Odds timestamp is missing',
    moneyline_price_missing: 'Moneyline price is missing',
    pitcher_uncertainty: 'Starting pitcher situation is uncertain',
    pitcher_uncertainty_risk: 'Starting pitcher situation is uncertain',
    stale_odds: 'Odds may be stale',
    total_model_over_bias: 'Totals model has shown over-bias',
    unconfirmed_pitcher: 'Starting pitcher is not confirmed',
};

export function numberValue(value: unknown): number | null {
    if (value === null || value === undefined || value === '') return null;

    const numeric = Number(value);

    return Number.isFinite(numeric) ? numeric : null;
}

export function teamAbbreviation(team: unknown): string {
    const payload = (team ?? {}) as ApiV2Record;

    return String(
        payload.abbreviation ??
            payload.short_display_name ??
            payload.display_name ??
            '',
    );
}

export function matchupLabel(prediction: ApiV2Prediction | null): string {
    const game = prediction?.game;
    if (!game) return 'MLB Matchup';

    return `${teamAbbreviation(game.away_team)} @ ${teamAbbreviation(game.home_team)}`;
}

export function formatPercent(value?: number | null): string {
    if (value == null) return '-';

    return `${(value * 100).toFixed(1)}%`;
}

export function formatSignedPercent(value?: number | null): string {
    if (value == null) return '-';

    const percent = value * 100;

    return `${percent > 0 ? '+' : ''}${percent.toFixed(1)}%`;
}

export function formatNumber(value?: number | null): string {
    if (value == null) return '-';

    return Number(value).toFixed(2);
}

export function formatOdds(value?: number | null): string {
    if (value == null) return '-';

    return value > 0 ? `+${value}` : `${value}`;
}

export function isFinal(prediction: ApiV2Prediction | null): boolean {
    return String(prediction?.status ?? prediction?.game?.status ?? '')
        .toLowerCase()
        .includes('final');
}

export function modelResult(
    prediction: ApiV2Prediction | null,
): 'win' | 'loss' | 'ungraded' | null {
    if (!isFinal(prediction)) return null;

    if (prediction?.winner_correct === true) return 'win';
    if (prediction?.winner_correct === false) return 'loss';

    return 'ungraded';
}

export function modelResultLabel(prediction: ApiV2Prediction | null): string {
    const result = modelResult(prediction);

    if (result === 'win') return 'Projection won';
    if (result === 'loss') return 'Projection lost';
    if (result === 'ungraded') return 'Ungraded';

    return 'Pending';
}

export function resultBadgeClass(
    result: 'win' | 'loss' | 'push' | 'ungraded' | string | null,
): string {
    if (result === 'win') {
        return 'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300';
    }

    if (result === 'loss') {
        return 'border-red-500/30 bg-red-500/10 text-red-700 dark:text-red-300';
    }

    if (result === 'push') {
        return 'border-slate-500/30 bg-slate-500/10 text-slate-700 dark:text-slate-300';
    }

    return 'border-muted bg-muted/40 text-muted-foreground';
}

export function finalScoreLabel(prediction: ApiV2Prediction | null): string {
    if (!isFinal(prediction)) return 'Not Final';

    const awayScore = numberValue(prediction?.game?.away_score);
    const homeScore = numberValue(prediction?.game?.home_score);

    if (awayScore == null || homeScore == null) return 'Final';

    return `${teamAbbreviation(prediction?.game?.away_team)} ${awayScore} - ${teamAbbreviation(prediction?.game?.home_team)} ${homeScore}`;
}

type TotalPickContext = {
    side: string | null;
    line: number | null;
    result: string | null;
    actualTotal: number | null;
};

export function totalPickContext(
    prediction: ApiV2Prediction | null,
    candidate?: MlbDailyPick | null,
): TotalPickContext {
    const nested = (prediction?.total_result ?? {}) as ApiV2Record;
    const isTotalCandidate = candidate?.market_type === 'total';
    const side = String(
        prediction?.total_pick_side ??
            nested.side ??
            (isTotalCandidate ? candidate?.side : '') ??
            '',
    )
        .trim()
        .toLowerCase();
    const line =
        numberValue(prediction?.total_pick_line) ??
        numberValue(nested.line) ??
        (isTotalCandidate ? numberValue(candidate?.line) : null);
    const actualTotal =
        numberValue(prediction?.actual_total) ??
        numberValue(nested.actual_total);
    let result = String(prediction?.total_pick_result ?? nested.result ?? '')
        .trim()
        .toLowerCase();

    if (
        !result &&
        isFinal(prediction) &&
        isTotalCandidate &&
        side &&
        line !== null &&
        actualTotal !== null
    ) {
        const margin = actualTotal - line;
        if (Math.abs(margin) < 0.0001) {
            result = 'push';
        } else if (side === 'over') {
            result = margin > 0 ? 'win' : 'loss';
        } else if (side === 'under') {
            result = margin < 0 ? 'win' : 'loss';
        }
    }

    return {
        side: side || null,
        line,
        result: result || null,
        actualTotal,
    };
}

export function totalResultLabel(
    prediction: ApiV2Prediction | null,
    candidate?: MlbDailyPick | null,
): string {
    const total = totalPickContext(prediction, candidate);

    if (!total.side || !total.result) return 'Not Available';

    return `${labelizeMlbCode(total.side)} ${labelizeMlbCode(total.result)}`;
}

export function totalPickResult(
    prediction: ApiV2Prediction | null,
    candidate?: MlbDailyPick | null,
): string | null {
    if (!isFinal(prediction)) return null;

    return totalPickContext(prediction, candidate).result;
}

export function hasPositiveResult(
    prediction: ApiV2Prediction | null,
    candidate?: MlbDailyPick | null,
): boolean {
    return (
        modelResult(prediction) === 'win' ||
        totalPickResult(prediction, candidate) === 'win' ||
        candidate?.result_status === 'win'
    );
}

export function hasNegativeResult(
    prediction: ApiV2Prediction | null,
    candidate?: MlbDailyPick | null,
): boolean {
    return (
        modelResult(prediction) === 'loss' ||
        totalPickResult(prediction, candidate) === 'loss' ||
        candidate?.result_status === 'loss'
    );
}

export function candidateStatusLabel(candidate?: MlbDailyPick | null): string {
    if (!candidate) return 'No public pick';

    if (candidate.is_tracking_only || !candidate.is_public) {
        return `${tierFromScore(candidate.score)} tracking signal`;
    }

    return safeMlbPickStatus(candidate);
}

export function candidatePanelClass(
    prediction: ApiV2Prediction | null,
    candidate?: MlbDailyPick | null,
): string {
    if (hasPositiveResult(prediction, candidate)) {
        return 'border-emerald-500/30 bg-emerald-500/[0.04]';
    }

    if (hasNegativeResult(prediction, candidate)) {
        return 'border-red-500/30 bg-red-500/[0.04]';
    }

    return 'border-border bg-card';
}

export function candidateModeLabel(candidate?: MlbDailyPick | null): string {
    if (!candidate) return 'No pick';

    return candidate.is_tracking_only || !candidate.is_public
        ? 'Tracking only'
        : 'Official pick';
}

export function candidateModeDescription(
    candidate?: MlbDailyPick | null,
): string {
    if (!candidate) return 'No candidate was selected for this matchup.';

    return candidate.is_tracking_only || !candidate.is_public
        ? 'Use this for model review only. It is not an official betting recommendation.'
        : 'This pick passed the current public recommendation checks.';
}

export function summaryBadgeClass(
    prediction: ApiV2Prediction | null,
    candidate?: MlbDailyPick | null,
): string {
    if (hasPositiveResult(prediction, candidate)) {
        return 'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300';
    }

    if (hasNegativeResult(prediction, candidate)) {
        return 'border-red-500/30 bg-red-500/10 text-red-700 dark:text-red-300';
    }

    return 'border-slate-500/25 bg-slate-500/10 text-slate-700 dark:text-slate-300';
}

export function totalPickLabel(
    prediction: ApiV2Prediction | null,
    candidate?: MlbDailyPick | null,
): string {
    const total = totalPickContext(prediction, candidate);

    if (!total.side || total.line === null) return '-';

    return `${labelizeMlbCode(total.side)} ${total.line.toFixed(1)}`;
}

export function candidateSummary(
    prediction: ApiV2Prediction | null,
    candidate?: MlbDailyPick | null,
): string {
    if (!candidate) {
        return 'No tracked pick is attached to this matchup yet.';
    }

    const watchouts = watchoutLabels(prediction, candidate);
    const caution =
        watchouts.length > 0
            ? ` Main caution: ${watchouts[0].toLowerCase()}.`
            : ' No major caution flags were returned.';

    return `${candidateModeDescription(candidate)} Our read is ${candidate.label}.${caution}`;
}

export function formatDateTime(value?: string | null): string {
    if (!value) return '-';

    return new Intl.DateTimeFormat(undefined, {
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    }).format(new Date(value));
}

export function formatTime(
    value?: string | null,
    time?: string | null,
): string {
    if (!value) return 'Time pending';

    const date = gameDateTime(value, time);

    return new Intl.DateTimeFormat(undefined, {
        weekday: 'short',
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    }).format(date);
}

export function gameDateTime(value: string, time?: string | null): Date {
    if (time && /^\d{1,2}:\d{2}/.test(time)) {
        const datePart = value.includes('T')
            ? value.split('T')[0]
            : value.split(' ')[0];

        return new Date(`${datePart}T${time}`);
    }

    return new Date(value);
}

export function rawReasonCodes(
    prediction: ApiV2Prediction | null,
    candidate?: MlbDailyPick | null,
): string[] {
    return (
        candidate?.reason_codes ??
        candidateRecommendation(prediction)?.reason_codes ??
        getPredictionRecommendation(prediction)?.reason_codes ?? [
            'model_projection',
        ]
    );
}

export function rawRiskCodes(
    prediction: ApiV2Prediction | null,
    candidate?: MlbDailyPick | null,
): string[] {
    return (
        candidate?.risk_flags ??
        candidateRecommendation(prediction)?.risk_flags ??
        []
    );
}

export function isWatchoutCode(code: string): boolean {
    const normalized = code.toLowerCase();

    return [
        'bias',
        'missing',
        'risk',
        'stale',
        'uncertain',
        'uncertainty',
        'unconfirmed',
        'underperforming',
        'low_confidence',
    ].some((needle) => normalized.includes(needle));
}

export function friendlyCodeLabel(
    code: string,
    type: 'signal' | 'risk',
): string {
    const normalized = code.toLowerCase();

    return (
        (type === 'risk'
            ? BETTOR_RISK_LABELS[normalized]
            : BETTOR_SIGNAL_LABELS[normalized]) ??
        BETTOR_SIGNAL_LABELS[normalized] ??
        BETTOR_RISK_LABELS[normalized] ??
        labelizeMlbCode(code)
            .replace(/\bFilter\b/g, 'screen')
            .replace(/\bNo Vig\b/g, 'no-vig')
            .replace(/\bPick\b/g, 'pick')
    );
}

export function uniqueLabels(
    codes: string[],
    type: 'signal' | 'risk',
): string[] {
    return Array.from(
        new Set(
            codes.filter(Boolean).map((code) => friendlyCodeLabel(code, type)),
        ),
    );
}

export function signalDriverLabels(
    prediction: ApiV2Prediction | null,
    candidate?: MlbDailyPick | null,
): string[] {
    const labels = uniqueLabels(
        rawReasonCodes(prediction, candidate).filter(
            (code) => !isWatchoutCode(code),
        ),
        'signal',
    );

    return labels.length > 0
        ? labels
        : ['Model projection supports this angle'];
}

export function watchoutLabels(
    prediction: ApiV2Prediction | null,
    candidate?: MlbDailyPick | null,
): string[] {
    return uniqueLabels(
        [
            ...rawReasonCodes(prediction, candidate).filter(isWatchoutCode),
            ...rawRiskCodes(prediction, candidate),
        ],
        'risk',
    );
}

export function signalGroups(
    candidate?: MlbDailyPick | null,
): MlbSignalGroup[] {
    return candidate?.signal_layer?.signal_groups ?? [];
}

export function periodModels(
    prediction: ApiV2Prediction | null,
    candidate?: MlbDailyPick | null,
): MlbPeriodModelContext[] {
    return candidate?.period_models?.length
        ? candidate.period_models
        : (prediction?.period_models ?? []);
}

export function periodInsights(
    prediction: ApiV2Prediction | null,
): MlbPeriodInsight[] {
    return prediction?.period_insights ?? [];
}

export function periodStateLabel(state: string): string {
    const labels: Record<string, string> = {
        insight_only_no_market: 'Insight only',
        no_bet_missing_quote: 'No quote',
        shadow_model_available: 'Shadow model',
        priced_candidate: 'Priced candidate',
        market_available_pending_candidate: 'Market pending',
    };

    return labels[state] ?? labelizeMlbCode(state);
}

export function periodStateClass(state: string): string {
    if (state === 'priced_candidate') {
        return 'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300';
    }
    if (state === 'shadow_model_available') {
        return 'border-sky-500/30 bg-sky-500/10 text-sky-700 dark:text-sky-300';
    }
    if (state === 'no_bet_missing_quote') {
        return 'border-amber-500/30 bg-amber-500/10 text-amber-700 dark:text-amber-300';
    }

    return 'border-muted bg-muted/40 text-muted-foreground';
}

export function periodRecord(team: MlbPeriodInsightTeam): string {
    const record = team.record;

    return `${record.wins}-${record.losses}-${record.ties}`;
}

export function periodLeanLabel(insight: MlbPeriodInsight): string {
    if (insight.lean.side === 'neutral') return 'No clear Elo lean';

    return `${insight.lean.team_abbreviation ?? insight.lean.side} Elo lean`;
}

export function signedNumber(value?: number | null, digits = 2): string {
    if (value == null) return '-';

    return `${value > 0 ? '+' : ''}${value.toFixed(digits)}`;
}

export function periodSignalLabel(code: string): string {
    const labels: Record<string, string> = {
        home_period_elo_edge: 'Home period Elo edge',
        away_period_elo_edge: 'Away period Elo edge',
        home_recent_period_form_edge: 'Home recent-form edge',
        away_recent_period_form_edge: 'Away recent-form edge',
        home_starter_edge: 'Home starter edge',
        away_starter_edge: 'Away starter edge',
    };

    return labels[code] ?? labelizeMlbCode(code);
}

export function periodRiskLabel(code: string): string {
    const labels: Record<string, string> = {
        limited_period_sample: 'Limited period sample',
        pregame_pitcher_context_missing: 'Pregame pitcher ratings unavailable',
        period_market_quote_missing: 'No F3/F5 market quote',
        short_period_variance: 'Higher short-window variance',
    };

    return labels[code] ?? labelizeMlbCode(code);
}

export function modelSourceLabel(candidate?: MlbDailyPick | null): string {
    return candidate?.model_source === 'promoted_period_model'
        ? 'Promoted period model'
        : candidate?.model_source === 'elo_heuristic'
          ? 'Elo heuristic fallback'
          : 'Rules model';
}

export function periodScore(
    prediction: ApiV2Prediction | null,
    innings: number,
): string {
    const away = prediction?.game?.away_linescores?.slice(0, innings) ?? [];
    const home = prediction?.game?.home_linescores?.slice(0, innings) ?? [];
    if (away.length < innings || home.length < innings) return '-';

    const sum = (values: Array<number | string | null>): number =>
        values.reduce<number>(
            (total, value) => total + (numberValue(value) ?? 0),
            0,
        );

    return `${teamAbbreviation(prediction?.game?.away_team)} ${sum(away)} - ${teamAbbreviation(prediction?.game?.home_team)} ${sum(home)}`;
}

export function compactId(value?: string | null): string {
    if (!value) return '-';

    return value.length > 18
        ? `${value.slice(0, 8)}...${value.slice(-6)}`
        : value;
}
