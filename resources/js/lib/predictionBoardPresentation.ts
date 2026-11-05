import { predictionBoardLiveFields } from '@/lib/predictionBoardLive';
import { pregameBetCalls } from '@/lib/pregameBetCalls';
import { isBetRecommendation } from '@/lib/predictionRecommendation';
import type {
    ApiV2Prediction,
    ApiV2TeamSummary,
    PredictionListGameTeam,
    PredictionListItem,
    SportPredictionsConfig,
} from '@/types';

const numberValue = (value: unknown): number | undefined => {
    if (value === null || value === undefined || value === '') {
        return undefined;
    }

    const numeric = Number(value);

    return Number.isFinite(numeric) ? numeric : undefined;
};

const teamPayload = (
    team: ApiV2TeamSummary | null | undefined,
): PredictionListGameTeam => ({
    abbreviation: team?.abbreviation ?? team?.short_display_name ?? '',
    school: team?.location ?? undefined,
    mascot: team?.name ?? undefined,
    location: team?.location ?? undefined,
    name: team?.display_name ?? team?.name ?? undefined,
    logo: team?.logo_url ?? undefined,
});

export const mapV2Prediction = (
    prediction: ApiV2Prediction,
): PredictionListItem => {
    const projection = prediction.projection ?? {};
    const game = prediction.game;
    const homeWinProbability = numberValue(projection.home_win_probability);

    return {
        id: prediction.id,
        comparison_forecasts: prediction.comparison_forecasts ?? null,
        ...predictionBoardLiveFields(prediction),
        game_id: numberValue(prediction.game_id),
        predicted_spread: numberValue(projection.predicted_spread),
        predicted_total: numberValue(projection.predicted_total),
        win_probability: homeWinProbability,
        home_win_probability: homeWinProbability,
        away_win_probability: numberValue(projection.away_win_probability),
        confidence_score: numberValue(projection.confidence_score),
        actual_spread: numberValue(prediction.actual_spread),
        actual_total: numberValue(prediction.actual_total),
        spread_error: numberValue(prediction.spread_error),
        total_error: numberValue(prediction.total_error),
        winner_correct:
            typeof prediction.winner_correct === 'boolean'
                ? prediction.winner_correct
                : undefined,
        graded_at:
            typeof prediction.graded_at === 'string'
                ? prediction.graded_at
                : undefined,
        created_at: prediction.created_at,
        updated_at: prediction.updated_at,
        model_bet_context: prediction.model_bet_context,
        weather_forecast: prediction.weather_forecast,
        value_signal:
            prediction.value_signal &&
            typeof prediction.value_signal === 'object'
                ? (prediction.value_signal as unknown as PredictionListItem['value_signal'])
                : null,
        cfb_signal_context:
            prediction.cfb_signal_context &&
            typeof prediction.cfb_signal_context === 'object'
                ? prediction.cfb_signal_context
                : null,
        recommendation: prediction.recommendation ?? null,
        game: {
            id: Number(game?.id ?? prediction.game_id ?? 0),
            game_date: game?.game_date ?? '',
            game_time: game?.game_time ?? undefined,
            status: prediction.status ?? game?.status ?? '',
            home_score: numberValue(game?.home_score),
            away_score: numberValue(game?.away_score),
            period: numberValue(game?.period),
            clock: typeof game?.clock === 'string' ? game.clock : undefined,
            inning: numberValue(game?.inning) ?? null,
            inning_half:
                typeof game?.inning_half === 'string' ? game.inning_half : null,
            balls: numberValue(game?.balls) ?? null,
            strikes: numberValue(game?.strikes) ?? null,
            outs: numberValue(game?.outs) ?? null,
            week: numberValue(game?.week),
            season_type:
                game?.season_type === null || game?.season_type === undefined
                    ? undefined
                    : String(game.season_type),
            home_team: teamPayload(game?.home_team),
            away_team: teamPayload(game?.away_team),
        },
    };
};

export const isBoardRecommendedBet = (
    prediction: PredictionListItem,
    sport: SportPredictionsConfig['sport'],
): boolean => {
    if (prediction.model_bet_context != null) {
        return Object.values(pregameBetCalls(prediction, 'Home', 'Away')).some(
            (call) => call.startsWith('Bet '),
        );
    }

    if (sport === 'mlb') {
        return isBetRecommendation(prediction);
    }

    if (prediction.betting_value_summary?.has_playable_value === true) {
        return true;
    }

    if (prediction.value_signal?.has_playable_value === true) {
        return true;
    }

    if (
        sport !== 'nfl' &&
        prediction.betting_value?.some(
            (recommendation) => Number(recommendation.edge) > 0,
        )
    ) {
        return true;
    }

    const analysisClassification =
        prediction.prediction_analysis?.bet_classification
            ?.toLowerCase()
            .trim() ?? '';
    if (
        [
            'bet',
            'bettable_edge',
            'playable',
            'strong_play',
            'recommended',
        ].includes(analysisClassification)
    ) {
        return true;
    }

    const aiClassification = prediction.ai_analysis?.bet_classification
        ?.toLowerCase()
        .trim();

    return aiClassification === 'bet';
};
