import { computed, onMounted, ref } from 'vue';
import { fetchJson } from '@/composables/useApiClient';
import { formatDateLong, useGameStatus } from '@/composables/useFormatters';
import {
    getRecentForm,
    parseBroadcastNetworks,
    parseLinescores,
} from '@/composables/useGameDataUtils';
import { useTeamTrends } from '@/composables/useTeamTrends';
import type {
    MlbPageGame,
    MlbPagePrediction,
    MlbPageTeam,
    TeamTrendData,
} from '@/types';

interface MlbMatchupTeam extends MlbPageTeam {
    logo: string | null;
    display_name: string;
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
        confidence_score: confidenceScore,
        narrative,
    };
};

export function useMlbGamePage(gameId: number) {
    const currentGame = ref<MlbPageGame>(fallbackGame(gameId));
    const homeTeam = ref<MlbPageTeam | null>(null);
    const awayTeam = ref<MlbPageTeam | null>(null);
    const prediction = ref<MlbPagePrediction | null>(null);
    const homeRecentGames = ref<MlbPageGame[]>([]);
    const awayRecentGames = ref<MlbPageGame[]>([]);
    const homeTrends = ref<TeamTrendData | null>(null);
    const awayTrends = ref<TeamTrendData | null>(null);
    const trendsLoading = ref(false);
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
        const sampleSize =
            homeTrends.value?.sample_size ||
            awayTrends.value?.sample_size ||
            20;
        return `Based on current season form (${sampleSize} games before this matchup)`;
    });
    const homeMatchupTeam = computed<MlbMatchupTeam | null>(() =>
        homeTeam.value
            ? {
                  ...homeTeam.value,
                  logo: homeTeam.value.logo_url,
                  display_name:
                      `${homeTeam.value.location} ${homeTeam.value.name}`.trim(),
              }
            : null,
    );
    const awayMatchupTeam = computed<MlbMatchupTeam | null>(() =>
        awayTeam.value
            ? {
                  ...awayTeam.value,
                  logo: awayTeam.value.logo_url,
                  display_name:
                      `${awayTeam.value.location} ${awayTeam.value.name}`.trim(),
              }
            : null,
    );

    const {
        allTrendCategories,
        isLockedCategory,
        getRequiredTier,
        formatTierName,
        formatTrendCategoryName: formatCategoryName,
    } = useTeamTrends(homeTrends, awayTrends);

    const load = async () => {
        try {
            loading.value = true;
            error.value = null;

            const [gameData, predictionData] = await Promise.all([
                fetchJson<{ data: MlbPageGame }>(`/api/v1/mlb/games/${gameId}`),
                fetchJson<{ data: MlbPagePrediction }>(
                    `/api/v1/mlb/games/${gameId}/prediction`,
                ),
            ]);

            if (gameData?.data) {
                currentGame.value = gameData.data;
            }

            const [homeTeamData, awayTeamData] = await Promise.all([
                fetchJson<{ data: MlbPageTeam }>(
                    `/api/v1/mlb/teams/${currentGame.value.home_team_id}`,
                ),
                fetchJson<{ data: MlbPageTeam }>(
                    `/api/v1/mlb/teams/${currentGame.value.away_team_id}`,
                ),
            ]);

            if (homeTeamData?.data) {
                homeTeam.value = homeTeamData.data;
            }

            if (awayTeamData?.data) {
                awayTeam.value = awayTeamData.data;
            }

            if (predictionData?.data) {
                prediction.value = normalizeMlbPrediction(predictionData.data);
            }

            const teamRequests: Promise<void>[] = [];

            if (homeTeam.value?.id) {
                teamRequests.push(
                    fetchJson<{ data: MlbPageGame[] }>(
                        `/api/v1/mlb/teams/${homeTeam.value.id}/games`,
                    ).then((gamesData) => {
                        if (!gamesData?.data) return;
                        homeRecentGames.value = gamesData.data
                            .filter(
                                (g) =>
                                    g.status === 'STATUS_FINAL' &&
                                    g.id !== currentGame.value.id,
                            )
                            .slice(0, 5);
                    }),
                );
            }

            if (awayTeam.value?.id) {
                teamRequests.push(
                    fetchJson<{ data: MlbPageGame[] }>(
                        `/api/v1/mlb/teams/${awayTeam.value.id}/games`,
                    ).then((gamesData) => {
                        if (!gamesData?.data) return;
                        awayRecentGames.value = gamesData.data
                            .filter(
                                (g) =>
                                    g.status === 'STATUS_FINAL' &&
                                    g.id !== currentGame.value.id,
                            )
                            .slice(0, 5);
                    }),
                );
            }

            if (homeTeam.value?.id || awayTeam.value?.id) {
                trendsLoading.value = true;
                const beforeDate = currentGame.value.game_date || '';
                const trendQuery = new URLSearchParams({
                    games: 'season',
                    season: String(currentGame.value.season),
                    before_date: beforeDate,
                });

                if (currentGame.value.season_type) {
                    trendQuery.set(
                        'season_type',
                        String(currentGame.value.season_type),
                    );
                }

                if (homeTeam.value?.id) {
                    teamRequests.push(
                        fetchJson<TeamTrendData>(
                            `/api/v1/mlb/teams/${homeTeam.value.id}/trends?${trendQuery.toString()}`,
                        )
                            .then((data) => {
                                homeTrends.value = data;
                            })
                            .catch(() => {
                                homeTrends.value = null;
                            }),
                    );
                }

                if (awayTeam.value?.id) {
                    teamRequests.push(
                        fetchJson<TeamTrendData>(
                            `/api/v1/mlb/teams/${awayTeam.value.id}/trends?${trendQuery.toString()}`,
                        )
                            .then((data) => {
                                awayTrends.value = data;
                            })
                            .catch(() => {
                                awayTrends.value = null;
                            }),
                    );
                }
            }

            if (teamRequests.length > 0) {
                await Promise.all(teamRequests);
            }
        } catch (e) {
            error.value = e instanceof Error ? e.message : 'An error occurred';
        } finally {
            loading.value = false;
            trendsLoading.value = false;
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
        allTrendCategories,
        isLockedCategory,
        getRequiredTier,
        formatTierName,
        formatCategoryName,
    };
}
