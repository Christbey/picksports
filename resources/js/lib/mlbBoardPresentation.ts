import type { ApiV2Prediction, ApiV2Record } from '@/types';
import type { MlbDailyPick } from '@/types/mlb-daily-picks';
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

export function teamText(team: unknown): string {
    const payload = (team ?? {}) as ApiV2Record;

    return [
        payload.abbreviation,
        payload.short_display_name,
        payload.display_name,
        payload.location,
        payload.name,
    ]
        .filter((value) => typeof value === 'string' && value.length > 0)
        .join(' ');
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

export function formatDateLabel(date: string): string {
    if (!date) return 'Select date';

    const [year, month, day] = date.split('-').map(Number);
    const displayDate = new Date(year, month - 1, day);

    return displayDate.toLocaleDateString('en-US', {
        weekday: 'short',
        month: 'short',
        day: 'numeric',
    });
}

export function todayKey(): string {
    const now = new Date();

    return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
}

export function gameSortKey(prediction: ApiV2Prediction): string {
    const game = prediction.game;
    const date = String(game?.game_date ?? '');
    const datePart = date.includes('T')
        ? date.split('T')[0]
        : date.split(' ')[0];
    const timePart = String(game?.game_time ?? '23:59:59');

    return `${datePart} ${timePart} ${game?.short_name ?? ''}`;
}
