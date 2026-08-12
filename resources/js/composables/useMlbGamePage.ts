import { computed, onMounted, ref } from 'vue';
import { useApiV2Client } from '@/composables/useApiV2Client';
import { formatDateLong, useGameStatus } from '@/composables/useFormatters';
import {
    getRecentForm,
    parseBroadcastNetworks,
    parseLinescores,
} from '@/composables/useGameDataUtils';
import { useTeamTrends } from '@/composables/useTeamTrends';
import type {
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

const toNumber = (value: unknown): number => {
    const parsed = Number(value);
    return Number.isFinite(parsed) ? parsed : 0;
};

const toOptionalNumber = (value: unknown): number | null => {
    if (value === null || value === undefined || value === '') return null;
    const parsed = Number(value);
    return Number.isFinite(parsed) ? parsed : null;
};

const stringList = (value: unknown): string[] =>
    Array.isArray(value)
        ? value.map((item) => String(item)).filter((item) => item.length > 0)
        : [];

const objectValue = <T>(value: unknown): T | null =>
    value && typeof value === 'object' && !Array.isArray(value)
        ? (value as T)
        : null;

const gameStartIso = (game: MlbPageGame): string | null => {
    if (!game.game_date) return null;
    if (!game.game_time) return game.game_date;

    const date = game.game_date.slice(0, 10);
    const offset = game.game_date.match(/(Z|[+-]\d{2}:\d{2})$/)?.[1] ?? '';

    return `${date}T${game.game_time}${offset}`;
};

const gameStartTimestamp = (game: MlbPageGame): number | null => {
    const value = gameStartIso(game);
    if (!value) return null;

    const timestamp = Date.parse(value);
    return Number.isFinite(timestamp) ? timestamp : null;
};

const recentGamesBefore = (
    games: MlbPageGame[],
    currentGame: MlbPageGame,
): MlbPageGame[] => {
    const currentStart = gameStartTimestamp(currentGame);

    return games
        .filter((game) => {
            if (
                game.status !== 'STATUS_FINAL' ||
                game.id === currentGame.id ||
                game.season !== currentGame.season
            ) {
                return false;
            }

            const start = gameStartTimestamp(game);
            return currentStart === null || start === null
                ? (game.game_date ?? '') <= (currentGame.game_date ?? '')
                : start < currentStart;
        })
        .sort(
            (a, b) =>
                (gameStartTimestamp(b) ?? 0) - (gameStartTimestamp(a) ?? 0),
        )
        .slice(0, 5);
};

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

const normalizeDepthChartContext = (
    rawContext: unknown,
): MlbPagePrediction['depth_chart_context'] => {
    if (!rawContext || typeof rawContext !== 'object') return null;

    const source = rawContext as Record<string, unknown>;
    const type =
        source.type === 'injury_weighting' || source.type === 'starter_fallback'
            ? source.type
            : null;

    if (!type) return null;

    return {
        type,
        applied:
            typeof source.applied === 'boolean' ? source.applied : undefined,
        home_out_weighted: toOptionalNumber(source.home_out_weighted),
        away_out_weighted: toOptionalNumber(source.away_out_weighted),
        home_questionable_weighted: toOptionalNumber(
            source.home_questionable_weighted,
        ),
        away_questionable_weighted: toOptionalNumber(
            source.away_questionable_weighted,
        ),
        spread_adjustment: toOptionalNumber(source.spread_adjustment),
        total_adjustment: toOptionalNumber(source.total_adjustment),
        win_probability_adjustment: toOptionalNumber(
            source.win_probability_adjustment,
        ),
        injury_model_source:
            typeof source.injury_model_source === 'string'
                ? source.injury_model_source
                : null,
        injury_spread_model_source:
            typeof source.injury_spread_model_source === 'string'
                ? source.injury_spread_model_source
                : null,
        injury_total_model_source:
            typeof source.injury_total_model_source === 'string'
                ? source.injury_total_model_source
                : null,
        home_pitcher_source:
            typeof source.home_pitcher_source === 'string'
                ? source.home_pitcher_source
                : null,
        away_pitcher_source:
            typeof source.away_pitcher_source === 'string'
                ? source.away_pitcher_source
                : null,
        home_depth_chart_fallback_used:
            typeof source.home_depth_chart_fallback_used === 'boolean'
                ? source.home_depth_chart_fallback_used
                : undefined,
        away_depth_chart_fallback_used:
            typeof source.away_depth_chart_fallback_used === 'boolean'
                ? source.away_depth_chart_fallback_used
                : undefined,
        probable_pitcher_injury_applied:
            typeof source.probable_pitcher_injury_applied === 'boolean'
                ? source.probable_pitcher_injury_applied
                : undefined,
    };
};

const normalizeMlbPrediction = (
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
        record.home_win_probability === undefined ||
        record.away_win_probability === undefined
    ) {
        return null;
    }

    const confidenceScore = toOptionalNumber(record.confidence_score);
    const confidenceLevel =
        typeof record.confidence_level === 'string'
            ? record.confidence_level
            : confidenceScore === null
              ? 'unavailable'
              : confidenceScore >= 75
                ? 'high'
                : confidenceScore >= 60
                  ? 'medium'
                  : 'low';
    const rawConfidenceContext = record.confidence_context;
    const confidenceContext =
        rawConfidenceContext && typeof rawConfidenceContext === 'object'
            ? {
                  label:
                      typeof (rawConfidenceContext as Record<string, unknown>)
                          .label === 'string'
                          ? ((rawConfidenceContext as Record<string, unknown>)
                                .label as string)
                          : null,
                  tier:
                      typeof (rawConfidenceContext as Record<string, unknown>)
                          .tier === 'string'
                          ? ((rawConfidenceContext as Record<string, unknown>)
                                .tier as string)
                          : null,
                  model_level:
                      typeof (rawConfidenceContext as Record<string, unknown>)
                          .model_level === 'string'
                          ? ((rawConfidenceContext as Record<string, unknown>)
                                .model_level as string)
                          : typeof (
                                  rawConfidenceContext as Record<
                                      string,
                                      unknown
                                  >
                              ).raw_level === 'string'
                            ? ((rawConfidenceContext as Record<string, unknown>)
                                  .raw_level as string)
                            : null,
                  reason_codes: Array.isArray(
                      (rawConfidenceContext as Record<string, unknown>)
                          .reason_codes,
                  )
                      ? (
                            (rawConfidenceContext as Record<string, unknown>)
                                .reason_codes as unknown[]
                        )
                            .map((item) => String(item))
                            .filter((item) => item.length > 0)
                      : [],
                  sample_games: toOptionalNumber(
                      (rawConfidenceContext as Record<string, unknown>)
                          .sample_games,
                  ),
              }
            : null;

    const rawNarrative = record.narrative;
    const narrative =
        rawNarrative && typeof rawNarrative === 'object'
            ? {
                  summary:
                      typeof (rawNarrative as Record<string, unknown>)
                          .summary === 'string'
                          ? ((rawNarrative as Record<string, unknown>)
                                .summary as string)
                          : '',
                  key_points: Array.isArray(
                      (rawNarrative as Record<string, unknown>).key_points,
                  )
                      ? (
                            (rawNarrative as Record<string, unknown>)
                                .key_points as unknown[]
                        )
                            .map((point) => String(point))
                            .filter((point) => point.length > 0)
                      : [],
                  risk_note:
                      typeof (rawNarrative as Record<string, unknown>)
                          .risk_note === 'string'
                          ? ((rawNarrative as Record<string, unknown>)
                                .risk_note as string)
                          : '',
                  generated_by:
                      typeof (rawNarrative as Record<string, unknown>)
                          .generated_by === 'string'
                          ? ((rawNarrative as Record<string, unknown>)
                                .generated_by as string)
                          : '',
                  social_caption:
                      typeof (rawNarrative as Record<string, unknown>)
                          .social_caption === 'string'
                          ? ((rawNarrative as Record<string, unknown>)
                                .social_caption as string)
                          : null,
                  betting_plan: (() => {
                      const plan = (rawNarrative as Record<string, unknown>)
                          .betting_plan;
                      if (!plan || typeof plan !== 'object') return null;

                      const betPick =
                          typeof (plan as Record<string, unknown>).bet_pick ===
                          'string'
                              ? ((plan as Record<string, unknown>)
                                    .bet_pick as string)
                              : '';
                      const reasoning =
                          typeof (plan as Record<string, unknown>).reasoning ===
                          'string'
                              ? ((plan as Record<string, unknown>)
                                    .reasoning as string)
                              : '';

                      return betPick !== '' && reasoning !== ''
                          ? {
                                bet_pick: betPick,
                                reasoning,
                                classification:
                                    typeof (plan as Record<string, unknown>)
                                        .classification === 'string'
                                        ? ((plan as Record<string, unknown>)
                                              .classification as string)
                                        : null,
                                for_bet: stringList(
                                    (plan as Record<string, unknown>).for_bet,
                                ),
                                against_bet: stringList(
                                    (plan as Record<string, unknown>)
                                        .against_bet,
                                ),
                                pass_reasons: stringList(
                                    (plan as Record<string, unknown>)
                                        .pass_reasons,
                                ),
                                reason_codes: stringList(
                                    (plan as Record<string, unknown>)
                                        .reason_codes,
                                ),
                            }
                          : null;
                  })(),
              }
            : null;

    return {
        home_win_probability: toNumber(record.home_win_probability),
        away_win_probability: toNumber(record.away_win_probability),
        predicted_spread: toNumber(record.predicted_spread),
        predicted_total: toNumber(record.predicted_total),
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
    const currentGame = ref<MlbPageGame>(fallbackGame(gameId));
    const homeTeam = ref<MlbPageTeam | null>(null);
    const awayTeam = ref<MlbPageTeam | null>(null);
    const prediction = ref<MlbPagePrediction | null>(null);
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
        homeTeam.value
            ? getRecentForm(homeRecentGames.value, homeTeam.value.id)
            : '',
    );
    const awayRecentForm = computed(() =>
        awayTeam.value
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
                { query: { games: 'season' } },
            );
            homeTrends.value = response?.data?.home ?? null;
            awayTrends.value = response?.data?.away ?? null;
            trendsLoaded.value = true;
        } catch {
            homeTrends.value = null;
            awayTrends.value = null;
        } finally {
            trendsLoading.value = false;
        }
    };

    const load = async () => {
        try {
            loading.value = true;
            error.value = null;

            const response = await api.games.page<MlbGamePageBootstrap>(
                'mlb',
                gameId,
            );
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
            error.value = e instanceof Error ? e.message : 'An error occurred';
        } finally {
            loading.value = false;
        }
    };

    onMounted(load);

    return {
        game: currentGame,
        homeTeam,
        awayTeam,
        prediction,
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
