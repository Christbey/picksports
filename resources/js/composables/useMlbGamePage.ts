import { finiteValue as toOptionalNumber } from '@/lib/gameOutcome';
import { recentGamesBefore, observedStart } from '@/lib/recentGameEvidence';
import {
    normalizePredictionConfidence,
    normalizeNarrativeContent,
    normalizeModernBettingPlan,
} from '@/lib/predictionNormalization';
import { normalizeDepthChartContext } from '@/lib/depthChartContext';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useApiV2Client } from '@/composables/useApiV2Client';
import { formatDateLong, useGameStatus } from '@/composables/useFormatters';
import {
    getRecentForm,
    parseBroadcastNetworks,
    parseLinescores,
} from '@/composables/useGameDataUtils';
import { useTeamTrends } from '@/composables/useTeamTrends';
import { predictionHomeMargin } from '@/lib/predictionSpread';
import type {
    BasketballHistoricalPrediction,
    ApiV2Team,
    ApiV2TeamMetric,
    MlbPageGame,
    MlbPagePrediction,
    MlbPageTeam,
    MlbTeamMetricsData,
    TeamTrendData,
} from '@/types';

interface MlbMatchupTeam extends MlbPageTeam {
    logo: string | null;
    display_name: string;
}

interface MlbGamePageBootstrap {
    game: MlbPageGame & {
        home_team?: ApiV2Team | null;
        away_team?: ApiV2Team | null;
    };
    prediction: unknown;
    historical_prediction?: BasketballHistoricalPrediction | null;
    recent_games: {
        home: MlbPageGame[];
        away: MlbPageGame[];
    };
    metrics: {
        home: ApiV2TeamMetric | null;
        away: ApiV2TeamMetric | null;
    };
    depth_charts_available: boolean;
}

interface MlbMatchupTrendPayload {
    home: TeamTrendData | null;
    away: TeamTrendData | null;
}

const fallbackGame = (gameId: number): MlbPageGame => ({
    id: gameId,
    status: 'STATUS_SCHEDULED',
    game_date: null,
    away_score: null,
    home_score: null,
    home_team_id: 0,
    away_team_id: 0,
    home_linescores: null,
    away_linescores: null,
    inning: null,
    inning_half: null,
    venue_name: null,
    venue_city: null,
    venue_state: null,
    broadcast_networks: null,
    season: 0,
    season_type: '',
    game_time: null,
});

const objectValue = <T>(value: unknown): T | null =>
    value && typeof value === 'object' && !Array.isArray(value)
        ? (value as T)
        : null;

const normalizeMlbTeamMetrics = (
    rawMetrics: ApiV2TeamMetric,
): MlbTeamMetricsData => {
    const number = (key: string): number | null =>
        toOptionalNumber(rawMetrics[key]);

    return {
        team_id: number('team_id'),
        season: rawMetrics.season ?? null,
        season_type: rawMetrics.season_type ?? null,
        wins: number('wins'),
        losses: number('losses'),
        games_played: number('games_played'),
        record_label:
            typeof rawMetrics.record_label === 'string'
                ? rawMetrics.record_label
                : null,
        offensive_rating: number('offensive_rating'),
        pitching_rating: number('pitching_rating'),
        defensive_rating: number('defensive_rating'),
        runs_per_game: number('runs_per_game'),
        runs_allowed_per_game: number('runs_allowed_per_game'),
        run_differential_per_game: number('run_differential_per_game'),
        ops: number('ops'),
        team_era: number('team_era'),
        whip: number('whip'),
        recent_form_rating: number('recent_form_rating'),
        injury_adjusted_team_rating: number('injury_adjusted_team_rating'),
        strength_of_schedule: number('strength_of_schedule'),
        rest_travel_fatigue: number('rest_travel_fatigue'),
        calculation_date:
            typeof rawMetrics.calculation_date === 'string'
                ? rawMetrics.calculation_date
                : null,
    };
};

export const normalizeMlbPrediction = (
    rawPrediction: unknown,
): MlbPagePrediction | null => {
    const source = Array.isArray(rawPrediction)
        ? rawPrediction[0]
        : rawPrediction;

    if (!source || typeof source !== 'object') {
        return null;
    }

    const record = source as Record<string, unknown>;
    if (
        ![
            'home_win_probability',
            'away_win_probability',
            'predicted_spread',
            'predicted_total',
        ].some((key) => key in record)
    )
        return null;

    const { confidenceScore, confidenceLevel, confidenceContext } =
        normalizePredictionConfidence(record);
    const rawNarrative = record.narrative;
    const content = normalizeNarrativeContent(rawNarrative);
    const narrativeSource = rawNarrative as Record<string, unknown> | null;
    const narrative = content
        ? {
              ...content,
              betting_plan: normalizeModernBettingPlan(
                  narrativeSource?.betting_plan,
              ),
          }
        : null;

    return {
        spread_convention: 'home_margin',
        home_win_probability: toOptionalNumber(record.home_win_probability),
        away_win_probability: toOptionalNumber(record.away_win_probability),
        predicted_spread: predictionHomeMargin(record),
        predicted_total: toOptionalNumber(record.predicted_total),
        confidence_level: confidenceLevel,
        confidence_context: confidenceContext,
        confidence_score: confidenceScore,
        recommendation: objectValue<MlbPagePrediction['recommendation']>(
            record.recommendation,
        ),
        public_recommendation: objectValue<
            MlbPagePrediction['public_recommendation']
        >(record.public_recommendation),
        value_signal: objectValue<MlbPagePrediction['value_signal']>(
            record.value_signal,
        ),
        market_aware_projection: objectValue<
            MlbPagePrediction['market_aware_projection']
        >(record.market_aware_projection),
        market_summary: objectValue<MlbPagePrediction['market_summary']>(
            record.market_summary,
        ),
        audit_context: objectValue<MlbPagePrediction['audit_context']>(
            record.audit_context,
        ),
        narrative,
        depth_chart_context: normalizeDepthChartContext(
            record.depth_chart_context,
            toOptionalNumber,
        ),
    };
};

const normalizeMlbTeam = (team: ApiV2Team): MlbPageTeam => ({
    id: Number(team.id),
    name: String(team.name ?? team.nickname ?? team.display_name ?? ''),
    location: String(team.location ?? ''),
    abbreviation: String(team.abbreviation ?? ''),
    logo_url: typeof team.logo_url === 'string' ? team.logo_url : null,
    league: String(team.league ?? ''),
    division: String(team.division ?? ''),
});

const mlbTeamDisplayName = (team: MlbPageTeam): string => {
    const location = team.location.trim();
    const name = team.name.trim();

    if (!location) return name;
    if (!name) return location;
    if (
        location.localeCompare(name, undefined, { sensitivity: 'accent' }) === 0
    ) {
        return name;
    }

    return `${location} ${name}`;
};

export function useMlbGamePage(gameId: number) {
    const api = useApiV2Client();
    const requestController = new AbortController();
    onBeforeUnmount(() => requestController.abort());
    const currentGame = ref<MlbPageGame>(fallbackGame(gameId));
    const homeTeam = ref<MlbPageTeam | null>(null);
    const awayTeam = ref<MlbPageTeam | null>(null);
    const prediction = ref<MlbPagePrediction | null>(null);
    const historicalPrediction = ref<BasketballHistoricalPrediction | null>(
        null,
    );
    const homeRecentGames = ref<MlbPageGame[]>([]);
    const awayRecentGames = ref<MlbPageGame[]>([]);
    const homeMetrics = ref<MlbTeamMetricsData | null>(null);
    const awayMetrics = ref<MlbTeamMetricsData | null>(null);
    const depthChartsAvailable = ref(false);
    const homeTrends = ref<TeamTrendData | null>(null);
    const awayTrends = ref<TeamTrendData | null>(null);
    const trendsLoading = ref(false);
    const trendsLoaded = ref(false);
    const loading = ref(true);
    const error = ref<string | null>(null);

    const gameStatus = useGameStatus(() => currentGame.value.status);
    const formatDate = (dateString: string | null): string =>
        formatDateLong(dateString);
    const broadcastNetworks = computed(() =>
        parseBroadcastNetworks(currentGame.value.broadcast_networks),
    );
    const homeLinescores = computed(() =>
        parseLinescores(currentGame.value.home_linescores),
    );
    const awayLinescores = computed(() =>
        parseLinescores(currentGame.value.away_linescores),
    );
    const homeRecentForm = computed(() =>
        observedStart(currentGame.value.starts_at) === null
            ? 'Recent form unavailable: kickoff timing unverified'
            : homeTeam.value
              ? getRecentForm(homeRecentGames.value, homeTeam.value.id)
              : '',
    );
    const awayRecentForm = computed(() =>
        observedStart(currentGame.value.starts_at) === null
            ? 'Recent form unavailable: kickoff timing unverified'
            : awayTeam.value
              ? getRecentForm(awayRecentGames.value, awayTeam.value.id)
              : '',
    );
    const trendsSubtitle = computed(() => {
        const awaySample = awayTrends.value?.sample_size ?? 0;
        const homeSample = homeTrends.value?.sample_size ?? 0;
        const awayName = awayTeam.value?.abbreviation || 'Away';
        const homeName = homeTeam.value?.abbreviation || 'Home';

        return `Based on current season form (${awayName} ${awaySample} / ${homeName} ${homeSample} games before this matchup)`;
    });
    const homeMatchupTeam = computed<MlbMatchupTeam | null>(() =>
        homeTeam.value
            ? {
                  ...homeTeam.value,
                  logo: homeTeam.value.logo_url,
                  display_name: mlbTeamDisplayName(homeTeam.value),
              }
            : null,
    );
    const awayMatchupTeam = computed<MlbMatchupTeam | null>(() =>
        awayTeam.value
            ? {
                  ...awayTeam.value,
                  logo: awayTeam.value.logo_url,
                  display_name: mlbTeamDisplayName(awayTeam.value),
              }
            : null,
    );

    const {
        topMatchupEdges,
        allTrendCategories,
        isLockedCategory,
        getRequiredTier,
        formatTierName,
        formatTrendCategoryName: formatCategoryName,
    } = useTeamTrends(homeTrends, awayTrends);

    const loadTrends = async () => {
        if (trendsLoading.value || trendsLoaded.value) {
            return;
        }

        trendsLoading.value = true;

        try {
            const response = await api.games.trends<MlbMatchupTrendPayload>(
                'mlb',
                gameId,
                {
                    query: { games: 'season' },
                    init: { signal: requestController.signal },
                },
            );
            if (requestController.signal.aborted) return;
            homeTrends.value = response?.data?.home ?? null;
            awayTrends.value = response?.data?.away ?? null;
            trendsLoaded.value = true;
        } catch {
            if (requestController.signal.aborted) return;
            homeTrends.value = null;
            awayTrends.value = null;
        } finally {
            if (!requestController.signal.aborted) trendsLoading.value = false;
        }
    };

    const load = async () => {
        try {
            loading.value = true;
            error.value = null;

            const response = await api.games.page<MlbGamePageBootstrap>(
                'mlb',
                gameId,
                { init: { signal: requestController.signal } },
            );
            if (requestController.signal.aborted) return;
            const payload = response?.data;
            if (!payload?.game) {
                throw new Error('Game page data is unavailable');
            }

            currentGame.value = payload.game;
            homeTeam.value = payload.game.home_team
                ? normalizeMlbTeam(payload.game.home_team)
                : null;
            awayTeam.value = payload.game.away_team
                ? normalizeMlbTeam(payload.game.away_team)
                : null;
            prediction.value = normalizeMlbPrediction(payload.prediction);
            const archive = payload.historical_prediction;
            historicalPrediction.value =
                !prediction.value &&
                archive &&
                ['legacy_archive', 'legacy_history'].includes(archive.source)
                    ? archive
                    : null;
            homeRecentGames.value = recentGamesBefore(
                payload.recent_games.home ?? [],
                currentGame.value,
            );
            awayRecentGames.value = recentGamesBefore(
                payload.recent_games.away ?? [],
                currentGame.value,
            );
            homeMetrics.value = payload.metrics.home
                ? normalizeMlbTeamMetrics(payload.metrics.home)
                : null;
            awayMetrics.value = payload.metrics.away
                ? normalizeMlbTeamMetrics(payload.metrics.away)
                : null;
            depthChartsAvailable.value = Boolean(
                payload.depth_charts_available,
            );

            loading.value = false;
        } catch (e) {
            if (requestController.signal.aborted) return;
            error.value = e instanceof Error ? e.message : 'An error occurred';
        } finally {
            if (!requestController.signal.aborted) loading.value = false;
        }
    };

    onMounted(load);

    return {
        game: currentGame,
        homeTeam,
        awayTeam,
        prediction,
        historicalPrediction,
        homeTrends,
        awayTrends,
        homeMetrics,
        awayMetrics,
        depthChartsAvailable,
        trendsLoading,
        loading,
        error,
        gameStatus,
        formatDate,
        broadcastNetworks,
        homeLinescores,
        awayLinescores,
        homeRecentGames,
        awayRecentGames,
        homeRecentForm,
        awayRecentForm,
        trendsSubtitle,
        homeMatchupTeam,
        awayMatchupTeam,
        topMatchupEdges,
        allTrendCategories,
        isLockedCategory,
        getRequiredTier,
        formatTierName,
        formatCategoryName,
        loadTrends,
    };
}
