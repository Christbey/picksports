import { computed, type Ref } from 'vue';
import {
    isMlbRegularSeasonType,
    isMlbSpringTrainingType,
} from '@/lib/mlbSeasonType';
import { isBoardRecommendedBet } from '@/lib/predictionBoardPresentation';
import type { PredictionListItem, SportPredictionsConfig } from '@/types';

type BoardViewState = {
    predictions: Ref<PredictionListItem[]>;
    filterMode: Readonly<
        Ref<NonNullable<SportPredictionsConfig['filterMode']>>
    >;
    searchQuery: Ref<string>;
    betViewMode: Ref<'recommended' | 'all'>;
    availableDates: Ref<string[]>;
    selectedDate: Ref<string>;
    seasonType: Ref<string>;
    week: Ref<string>;
    formatDateLabel: (date: string) => string;
};
export function usePredictionBoardView(
    props: { config: SportPredictionsConfig },
    {
        predictions,
        filterMode,
        searchQuery,
        betViewMode,
        availableDates,
        selectedDate,
        seasonType,
        week,
        formatDateLabel,
    }: BoardViewState,
) {
    const hasAppliedSeasonWeekFilters = computed(() => {
        if (filterMode.value !== 'seasonWeek') return false;
        return seasonType.value !== '' || week.value !== '';
    });

    const normalizedSearchQuery = computed(() =>
        searchQuery.value.trim().toLowerCase(),
    );

    const shouldShowAsRecommendedBet = (prediction: PredictionListItem) =>
        isBoardRecommendedBet(prediction, props.config.sport);

    const recommendedBetCount = computed(
        () => predictions.value.filter(shouldShowAsRecommendedBet).length,
    );

    const filteredPredictions = computed(() => {
        let visiblePredictions = predictions.value;

        if (betViewMode.value === 'recommended') {
            visiblePredictions = visiblePredictions.filter(
                shouldShowAsRecommendedBet,
            );
        }

        if (!normalizedSearchQuery.value) {
            return visiblePredictions;
        }

        return visiblePredictions.filter((prediction) => {
            const game = prediction.game;
            const haystack = [
                game?.home_team?.abbreviation,
                game?.home_team?.school,
                game?.home_team?.mascot,
                game?.home_team?.location,
                game?.home_team?.name,
                game?.away_team?.abbreviation,
                game?.away_team?.school,
                game?.away_team?.mascot,
                game?.away_team?.location,
                game?.away_team?.name,
            ]
                .filter((value): value is string => Boolean(value))
                .join(' ')
                .toLowerCase();

            return haystack.includes(normalizedSearchQuery.value);
        });
    });

    const showSpringTrainingBadge = computed(() => {
        if (props.config.sport !== 'mlb' || predictions.value.length === 0) {
            return false;
        }

        // Keep the badge visible until this MLB slate is regular season.
        const hasSpringTraining = predictions.value.some((prediction) =>
            isMlbSpringTrainingType(prediction.game?.season_type),
        );
        if (hasSpringTraining) {
            return true;
        }

        return predictions.value.some(
            (prediction) =>
                !isMlbRegularSeasonType(prediction.game?.season_type),
        );
    });

    const emptyStateTitle = computed(() => {
        if (betViewMode.value === 'recommended') {
            return 'No recommended bets match these filters';
        }

        if (normalizedSearchQuery.value) {
            return 'No predictions match this search';
        }

        if (filterMode.value === 'date' && availableDates.value.length === 0) {
            return 'No prediction dates available yet';
        }

        if (
            filterMode.value === 'seasonWeek' &&
            hasAppliedSeasonWeekFilters.value
        ) {
            return 'No predictions match these filters';
        }

        return 'No predictions available';
    });

    const emptyStateDescription = computed(() => {
        if (betViewMode.value === 'recommended') {
            return 'The model does not currently have a playable edge on this slate. Switch to All games to review every prediction.';
        }

        if (normalizedSearchQuery.value) {
            return `No games matched "${searchQuery.value}". Try team abbreviations, school names, or mascots.`;
        }

        if (filterMode.value === 'date' && availableDates.value.length === 0) {
            return 'We have not generated a slate for this sport yet. Run sync + prediction jobs, then refresh.';
        }

        if (filterMode.value === 'date' && selectedDate.value) {
            return `No games are available for ${formatDateLabel(selectedDate.value)}. Try another date.`;
        }

        if (
            filterMode.value === 'seasonWeek' &&
            hasAppliedSeasonWeekFilters.value
        ) {
            return 'Choose another week or return to the current week.';
        }

        return 'Check back after model runs complete.';
    });

    const showEmptyClearAction = computed(() => {
        if (betViewMode.value === 'recommended') return true;
        if (normalizedSearchQuery.value) return true;
        if (filterMode.value === 'seasonWeek')
            return hasAppliedSeasonWeekFilters.value;
        if (filterMode.value === 'date') return availableDates.value.length > 0;
        return false;
    });

    return {
        recommendedBetCount,
        filteredPredictions,
        showSpringTrainingBadge,
        emptyStateTitle,
        emptyStateDescription,
        showEmptyClearAction,
    };
}
