import type {
    Recommendation,
    StatRow,
    PredictionExport,
    FuturesExport,
    TournamentExport,
} from '@/types/admin-exports';
export const getConfidenceColor = (confidence: number) => {
    if (confidence >= 88) return 'bg-green-500';
    if (confidence >= 76) return 'bg-emerald-500';
    if (confidence >= 64) return 'bg-yellow-500';
    return 'bg-gray-500';
};

export const getSignalBand = (confidence: number) => {
    if (confidence >= 97) return 'Outlier';
    if (confidence >= 88) return 'Very Strong';
    if (confidence >= 76) return 'Strong';
    if (confidence >= 64) return 'Lean';
    return 'Low';
};

export const formatOdds = (odds: number) =>
    odds > 0 ? `+${odds}` : odds.toString();

export const getInitials = (name: string) =>
    name
        .split(' ')
        .filter(Boolean)
        .map((part) => part[0])
        .join('')
        .toUpperCase();

export const recommendationHitCount = (
    recommendation: 'Over' | 'Under',
    values: { hits: number; games: number } | null,
) => {
    if (!values) {
        return 'N/A';
    }

    const hits =
        recommendation === 'Under' ? values.games - values.hits : values.hits;

    return `${hits}/${values.games}`;
};

export const shortMarket = (market: string) =>
    market.replace(/_/g, ' ').replace(/\b\w/g, (m) => m.toUpperCase());

export const sanitizeFilename = (value: string) =>
    value
        .replace(/[^a-z0-9_-]+/gi, '-')
        .replace(/-+/g, '-')
        .replace(/(^-|-$)/g, '');

export const formatStatNumber = (value: number | null | undefined) => {
    if (value === null || value === undefined || Number.isNaN(value)) {
        return 'N/A';
    }

    return Number(value).toFixed(1);
};

export function buildRelativeSignalMap(
    items: Array<{ id: number; value: number }>,
): Record<number, number> {
    const out: Record<number, number> = {};
    if (items.length === 0) return out;
    if (items.length === 1) {
        out[items[0].id] = Math.round(items[0].value);
        return out;
    }

    const values = items.map((item) => Number(item.value) || 0);
    const min = Math.min(...values);
    const max = Math.max(...values);
    const range = max - min;
    const mean = values.reduce((sum, value) => sum + value, 0) / values.length;
    const variance =
        values.reduce((sum, value) => sum + (value - mean) ** 2, 0) /
        values.length;
    const stdDev = Math.sqrt(Math.max(variance, 0.0001));
    const ranked = [...items].sort((a, b) => a.value - b.value);
    const rankMap = ranked.reduce<Record<number, number>>((acc, item, idx) => {
        acc[item.id] = idx;
        return acc;
    }, {});
    const denominator = Math.max(items.length - 1, 1);

    items.forEach((item) => {
        const rankPct = (rankMap[item.id] ?? 0) / denominator;
        const zScore = (item.value - mean) / stdDev;

        if (range < 6) {
            let score = 58 + rankPct * 34;
            if (zScore >= 1.2) score += 4;
            if (zScore >= 1.8) score += 4;
            out[item.id] = Math.round(Math.max(45, Math.min(99, score)));
            return;
        }

        const normalized = (item.value - min) / range;
        let score = 48 + normalized * 40 + rankPct * 8;
        if (zScore >= 1.2) score += 5;
        if (zScore >= 1.8) score += 5;
        out[item.id] = Math.round(Math.max(40, Math.min(99, score)));
    });
    return out;
}

export function buildStatRows(rec: Recommendation): StatRow[] {
    return [
        {
            key: 'season',
            label: 'SEASON AVG',
            value: formatStatNumber(rec.stats?.season_avg),
        },
        {
            key: 'last10',
            label: 'LAST 10',
            value: formatStatNumber(rec.stats?.recent_avg),
        },
        {
            key: 'last5',
            label: 'LAST 5',
            value: formatStatNumber(rec.stats?.last5_avg),
        },
        {
            key: 'opponent',
            label: 'VS OPPONENT',
            value: formatStatNumber(rec.stats?.vs_opponent_avg),
        },
        {
            key: 'hit5',
            label: `HIT ${rec.recommendation.toUpperCase()} (L5)`,
            value: recommendationHitCount(
                rec.recommendation,
                rec.stats?.times_covered_last5 ?? null,
            ),
        },
        {
            key: 'hitSeason',
            label: `HIT ${rec.recommendation.toUpperCase()} (SEASON)`,
            value: recommendationHitCount(
                rec.recommendation,
                rec.stats?.times_covered_season ?? null,
            ),
        },
        {
            key: 'consistency',
            label: 'CONSISTENCY',
            value: rec.stats?.consistency
                ? `${rec.stats.consistency.level} (±${formatStatNumber(rec.stats.consistency.std_dev)})`
                : 'N/A',
        },
        {
            key: 'edge',
            label: 'EDGE VS LINE',
            value: `${(rec.edge ?? 0) > 0 ? '+' : ''}${formatStatNumber(rec.edge)}`,
        },
        {
            key: 'model',
            label: 'MODEL VS MARKET',
            value:
                rec.model_over_probability !== null &&
                rec.model_over_probability !== undefined &&
                rec.market_over_probability !== null &&
                rec.market_over_probability !== undefined
                    ? `${rec.model_over_probability.toFixed(1)}% vs ${rec.market_over_probability.toFixed(1)}%`
                    : 'N/A',
        },
    ];
}

export const normalizePercent = (value: number | null | undefined) => {
    const numeric = Number(value ?? 0);
    if (Number.isNaN(numeric)) {
        return 0;
    }

    return numeric <= 1 ? numeric * 100 : numeric;
};

export function buildPredictionRows(prediction: PredictionExport): StatRow[] {
    return [
        {
            key: 'win-prob',
            label: 'WIN PROBABILITY',
            value: `${formatStatNumber(prediction.win_probability)}%`,
        },
        {
            key: 'pred-spread',
            label: 'PREDICTED SPREAD',
            value: formatStatNumber(prediction.predicted_spread),
        },
        {
            key: 'pred-total',
            label: 'PREDICTED TOTAL',
            value: formatStatNumber(prediction.predicted_total),
        },
        {
            key: 'home-elo',
            label: 'HOME ELO',
            value: formatStatNumber(prediction.home_elo),
        },
        {
            key: 'away-elo',
            label: 'AWAY ELO',
            value: formatStatNumber(prediction.away_elo),
        },
        {
            key: 'pick-side',
            label: 'MODEL PICK SIDE',
            value: prediction.pick_side,
        },
        {
            key: 'pick-team',
            label: 'MODEL PICK TEAM',
            value: prediction.pick_team || 'N/A',
        },
        {
            key: 'confidence',
            label: 'CONFIDENCE',
            value: `${formatStatNumber(normalizePercent(prediction.confidence))}%`,
        },
        {
            key: 'matchup',
            label: 'MATCHUP',
            value: `${prediction.away_team} @ ${prediction.home_team}`,
        },
    ];
}

export function buildFuturesRows(row: FuturesExport): StatRow[] {
    return [
        { key: 'season', label: 'SEASON', value: `${row.season}` },
        {
            key: 'seed',
            label: 'PROJECTED SEED',
            value: row.projected_seed ? `${row.projected_seed}` : 'N/A',
        },
        {
            key: 'make',
            label: 'PLAYOFF MAKE',
            value: `${formatStatNumber(row.playoff_make_probability)}%`,
        },
        {
            key: 'title',
            label: 'TITLE ODDS',
            value: `${formatStatNumber(row.champion_probability)}%`,
        },
        {
            key: 'group',
            label: 'CONFERENCE / LEAGUE',
            value: row.conference_or_league ?? 'N/A',
        },
        {
            key: 'conf-finals',
            label: 'CONF FINALS',
            value:
                row.conference_finals_probability !== null
                    ? `${formatStatNumber(row.conference_finals_probability)}%`
                    : 'N/A',
        },
        {
            key: 'finals',
            label: 'FINALS / WS',
            value: `${formatStatNumber(row.nba_finals_probability ?? row.world_series_probability)}%`,
        },
        {
            key: 'lcs',
            label: 'LCS ODDS',
            value:
                row.league_championship_probability !== null
                    ? `${formatStatNumber(row.league_championship_probability)}%`
                    : 'N/A',
        },
        { key: 'team', label: 'TEAM', value: row.team },
    ];
}

export function buildTournamentRows(row: TournamentExport): StatRow[] {
    return [
        { key: 'season', label: 'SEASON', value: `${row.season}` },
        {
            key: 'seed',
            label: 'PROJECTED SEED',
            value: row.projected_seed ? `${row.projected_seed}` : 'N/A',
        },
        {
            key: 'make',
            label: 'TOURNAMENT MAKE',
            value: `${formatStatNumber(row.tournament_make_probability)}%`,
        },
        {
            key: 'title',
            label: 'TITLE ODDS',
            value: `${formatStatNumber(row.champion_probability)}%`,
        },
        {
            key: 'auto',
            label: 'AUTO BID',
            value: `${formatStatNumber(row.auto_bid_probability)}%`,
        },
        {
            key: 'at-large',
            label: 'AT LARGE',
            value: `${formatStatNumber(row.at_large_probability)}%`,
        },
        {
            key: 'first-four',
            label: 'FIRST FOUR',
            value: `${formatStatNumber(row.first_four_probability)}%`,
        },
        {
            key: 'bid-thief',
            label: 'BID THIEF',
            value: `${formatStatNumber(row.bid_thief_probability)}%`,
        },
        {
            key: 'conference',
            label: 'CONFERENCE',
            value: row.conference ?? 'N/A',
        },
    ];
}
