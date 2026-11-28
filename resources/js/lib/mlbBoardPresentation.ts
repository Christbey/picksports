import type { ApiV2Prediction } from '@/types';
import type { MlbDailyPick } from '@/types/mlb-daily-picks';
import { teamText } from '@/lib/matchupBoardPresentation';
import { verifiedMlbStart } from '@/lib/mlbMatchupPresentation';
export {
    teamText,
    formatDateLabel,
    todayKey,
} from '@/lib/matchupBoardPresentation';
import type { MarketFilter } from '@/types/mlb-board';
import {
    candidateRecommendation,
    getPredictionRecommendation,
} from '@/lib/predictionRecommendation';
export function predictionMarketType(prediction: ApiV2Prediction): string {
    return String(
        candidateRecommendation(prediction)?.market_type ??
            getPredictionRecommendation(prediction)?.market_type ??
            prediction.market_aware_projection?.label ??
            '',
    ).toLowerCase();
}

export function marketMatches(
    prediction: ApiV2Prediction | null,
    candidate: MlbDailyPick | null,
    filter: MarketFilter,
): boolean {
    const marketType = (
        candidate?.market_type ??
        (prediction ? predictionMarketType(prediction) : '')
    )
        .toLowerCase()
        .replaceAll('-', '_')
        .replaceAll(' ', '_');

    if (filter === 'total') return marketType.includes('total');
    if (filter === 'first_inning') return marketType.includes('first_inning');
    if (filter === 'first_5')
        return marketType.includes('first_5') || marketType.includes('f5');
    if (filter === 'first_3')
        return marketType.includes('first_3') || marketType.includes('f3');
    if (filter === 'player_prop') return marketType.includes('prop');

    return marketType.includes(filter);
}

export function searchHaystack(
    prediction: ApiV2Prediction,
    candidate: MlbDailyPick | null,
): string {
    return [
        prediction.game?.short_name,
        teamText(prediction.game?.away_team),
        teamText(prediction.game?.home_team),
        candidate?.label,
        candidate?.side,
        candidate?.market_type,
    ]
        .filter(Boolean)
        .join(' ')
        .toLowerCase();
}

// MLB legacy split fields are venue walls; only admitted instants establish chronology.
export function gameSortKey(prediction: ApiV2Prediction): string {
    const start = verifiedMlbStart(prediction.game?.starts_at);
    return `${start?.toISOString() ?? '~'} ${prediction.game?.short_name ?? ''} ${prediction.game?.id ?? prediction.game_id ?? ''}`;
}
