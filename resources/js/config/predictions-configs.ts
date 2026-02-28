import type { SportPredictionsConfig } from '@/types';

export const nbaPredictionsConfig: SportPredictionsConfig = {
    sport: 'nba',
    title: 'NBA Predictions',
    subtitle: 'Predictions based on Elo ratings and advanced metrics',
    useEasternTime: false,
    showGameTime: false,
    confidenceIsDecimal: true,
    confidenceDecimals: 1,
};

export const cbbPredictionsConfig: SportPredictionsConfig = {
    sport: 'cbb',
    title: 'CBB Predictions',
    subtitle: 'Predictions based on Elo ratings and advanced metrics',
    useEasternTime: true,
    showGameTime: true,
    confidenceIsDecimal: false,
    confidenceDecimals: 0,
};

export const mlbPredictionsConfig: SportPredictionsConfig = {
    sport: 'mlb',
    title: 'MLB Predictions',
    subtitle: 'Predictions based on team and pitcher Elo ratings',
    useEasternTime: false,
    showGameTime: true,
    confidenceIsDecimal: true,
    confidenceDecimals: 0,
};

export const wcbbPredictionsConfig: SportPredictionsConfig = {
    sport: 'wcbb',
    title: 'WCBB Predictions',
    subtitle: 'Predictions based on Elo ratings and advanced metrics',
    useEasternTime: false,
    showGameTime: true,
    confidenceIsDecimal: false,
    confidenceDecimals: 0,
};

export const wnbaPredictionsConfig: SportPredictionsConfig = {
    sport: 'wnba',
    title: 'WNBA Predictions',
    subtitle: 'Predictions based on Elo ratings and team efficiency metrics',
    useEasternTime: false,
    showGameTime: true,
    confidenceIsDecimal: false,
    confidenceDecimals: 1,
    filterMode: 'none',
};

export const cfbPredictionsConfig: SportPredictionsConfig = {
    sport: 'cfb',
    title: 'CFB Predictions',
    subtitle: 'Predictions based on Elo ratings and FPI metrics',
    useEasternTime: true,
    showGameTime: true,
    confidenceIsDecimal: false,
    confidenceDecimals: 1,
    filterMode: 'seasonWeek',
    seasonWeekConfig: {
        regularSeasonWeeks: 15,
        postseasonOptions: [
            { value: '1', label: 'Bowl Games' },
            { value: '2', label: 'Playoffs' },
            { value: '3', label: 'Championship' },
        ],
    },
};

export const nflPredictionsConfig: SportPredictionsConfig = {
    sport: 'nfl',
    title: 'NFL Predictions',
    subtitle: 'Predictions based on Elo ratings and advanced metrics',
    useEasternTime: false,
    showGameTime: false,
    confidenceIsDecimal: true,
    confidenceDecimals: 1,
    filterMode: 'seasonWeek',
    seasonWeekConfig: {
        regularSeasonWeeks: 18,
        postseasonOptions: [
            { value: '1', label: 'Wild Card' },
            { value: '2', label: 'Divisional' },
            { value: '3', label: 'Conference Championship' },
            { value: '5', label: 'Super Bowl' },
        ],
    },
    cardVariant: 'nfl',
};
