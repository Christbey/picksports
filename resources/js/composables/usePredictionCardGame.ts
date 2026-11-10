import { computed, ref, watch } from 'vue';
import { livePeriodLabel } from '@/lib/liveGamePresentation';
import {
    isPredictionListItem,
    normalizePredictionLiveState,
} from '@/composables/usePredictionLiveData';
import { getCfbPostseasonLabel } from '@/lib/cfbPostseason';
import { liveForecastMessage } from '@/lib/predictionBoardLive';
import type { PredictionCardProps } from '@/types/prediction-card';

function compactTeamLogoUrl(source: string | null): string | null {
    if (!source) return source;

    const match =
        /^https:\/\/a\.espncdn\.com(\/i\/teamlogos\/[a-z0-9_-]+\/500\/[a-z0-9_-]+\.png)$/.exec(
            source,
        );
    if (!match) return source;

    // The compact card uses 20–24 CSS pixels. 80px covers 3x displays.
    return `https://a.espncdn.com/combiner/i?img=${encodeURIComponent(match[1])}&w=80&h=80`;
}

export function usePredictionCardGame(props: PredictionCardProps) {
    const predictionType = computed(() =>
        isPredictionListItem(props.prediction) ? props.prediction : null,
    );
    const dashboardType = computed(() =>
        isPredictionListItem(props.prediction) ? null : props.prediction,
    );
    function useLogo(original: () => string | null) {
        const candidate = computed(() =>
            props.compact ? compactTeamLogoUrl(original()) : original(),
        );
        const failedVariant = ref<string | null>(null);
        const failedOriginal = ref<string | null>(null);
        const url = () =>
            candidate.value === failedVariant.value
                ? original()
                : candidate.value;

        return {
            url,
            show: () => Boolean(url()) && url() !== failedOriginal.value,
            reset() {
                failedVariant.value = null;
                failedOriginal.value = null;
            },
            handleError(event?: Event) {
                const source = url();
                const attempted = (
                    event?.currentTarget as HTMLImageElement | null
                )?.getAttribute('src');
                // Both the summary and expanded card can report the same failure.
                if (attempted != null && attempted !== source) return;

                if (source !== original()) failedVariant.value = source;
                else failedOriginal.value = source;
            },
        };
    }

    const awayTeamLogo = useLogo(originalAwayLogo);
    const homeTeamLogo = useLogo(originalHomeLogo);
    watch(
        [
            () => props.sport,
            () => props.prediction.id,
            () => predictionType.value?.game.id ?? dashboardType.value?.game_id,
            originalAwayLogo,
            originalHomeLogo,
        ],
        () => {
            awayTeamLogo.reset();
            homeTeamLogo.reset();
        },
    );
    const normalizedLiveState = computed(() =>
        normalizePredictionLiveState(props.prediction),
    );

    function awayTeamLabel(): string {
        if (dashboardType.value) return dashboardType.value.away_team;

        const team = predictionType.value?.game.away_team;
        return team?.abbreviation ?? team?.school ?? team?.name ?? 'Away';
    }

    function homeTeamLabel(): string {
        if (dashboardType.value) return dashboardType.value.home_team;

        const team = predictionType.value?.game.home_team;
        return team?.abbreviation ?? team?.school ?? team?.name ?? 'Home';
    }

    function originalAwayLogo(): string | null {
        if (dashboardType.value) return dashboardType.value.away_logo;
        return predictionType.value?.game.away_team?.logo ?? null;
    }

    function originalHomeLogo(): string | null {
        if (dashboardType.value) return dashboardType.value.home_logo;
        return predictionType.value?.game.home_team?.logo ?? null;
    }

    function isLive(): boolean {
        return normalizedLiveState.value.isLive;
    }

    function gameBalls(): number | null {
        return normalizedLiveState.value.balls;
    }

    function gameStrikes(): number | null {
        return normalizedLiveState.value.strikes;
    }

    function gameOuts(): number | null {
        return normalizedLiveState.value.outs;
    }

    function isFinal(): boolean {
        return normalizedLiveState.value.isFinal;
    }

    function awayScore(): number | null {
        return normalizedLiveState.value.awayScore;
    }

    function homeScore(): number | null {
        return normalizedLiveState.value.homeScore;
    }

    function showGameScore(): boolean {
        return (
            (isLive() || isFinal()) &&
            awayScore() !== null &&
            homeScore() !== null
        );
    }

    function teamScoreClass(team: 'away' | 'home'): string {
        if (!showGameScore()) {
            return '';
        }

        const away = awayScore();
        const home = homeScore();
        if (away === null || home === null || away === home) {
            return 'text-foreground';
        }

        const isWinning =
            (team === 'away' && away > home) ||
            (team === 'home' && home > away);

        if (isFinal()) {
            return isWinning
                ? 'text-emerald-700 dark:text-emerald-300'
                : 'text-muted-foreground';
        }

        return isWinning
            ? 'text-sky-700 dark:text-sky-300'
            : 'text-muted-foreground';
    }

    function weekLabel(): string | null {
        const normalizedSport = (props.sport ?? '').toLowerCase();
        if (!['nfl', 'cfb'].includes(normalizedSport)) {
            return null;
        }

        const week = predictionType.value?.game.week;
        const postseasonRound = predictionType.value?.game.postseason_round;
        const seasonType = predictionType.value?.game.season_type;

        if (week === null || week === undefined || !seasonType) {
            return null;
        }

        const normalizedSeasonType = String(seasonType);

        if (
            normalizedSeasonType === 'Regular Season' ||
            normalizedSeasonType === '2'
        ) {
            return `Week ${week}`;
        }

        if (normalizedSport === 'nfl') {
            const playoffRounds: Record<number, string> = {
                1: 'Wild Card',
                2: 'Divisional',
                3: 'Conference Championship',
                5: 'Super Bowl',
            };

            return playoffRounds[week] ?? `Postseason Week ${week}`;
        }

        if (normalizedSport === 'cfb') {
            return (
                getCfbPostseasonLabel(postseasonRound, week) ??
                `Postseason Week ${week}`
            );
        }

        return `Postseason Week ${week}`;
    }

    function gameDateTimeLabel(): string | null {
        if (dashboardType.value?.game_time) {
            const date = new Date(dashboardType.value.game_time);

            if (!Number.isNaN(date.getTime())) {
                return date.toLocaleString('en-US', {
                    month: 'short',
                    day: 'numeric',
                    hour: 'numeric',
                    minute: '2-digit',
                });
            }

            return dashboardType.value.game_time;
        }

        const game = predictionType.value?.game;
        if (!game?.game_date) {
            return null;
        }

        const rawDateTime = game.game_time
            ? `${game.game_date}T${game.game_time}`
            : `${game.game_date}T00:00:00`;
        const date = new Date(rawDateTime);
        if (Number.isNaN(date.getTime())) {
            return game.game_time || game.game_date;
        }

        return date.toLocaleString('en-US', {
            month: 'short',
            day: 'numeric',
            hour: game.game_time ? 'numeric' : undefined,
            minute: game.game_time ? '2-digit' : undefined,
        });
    }

    function predictionFreshnessLabel(): string | null {
        const updatedAt = props.prediction.updated_at;
        if (!updatedAt) {
            return null;
        }

        const date = new Date(updatedAt);
        if (Number.isNaN(date.getTime())) {
            return null;
        }

        return `Updated ${date.toLocaleString('en-US', {
            month: 'short',
            day: 'numeric',
            hour: 'numeric',
            minute: '2-digit',
        })}`;
    }

    function statusBadgeLabel(): string {
        const status = String(
            dashboardType.value?.status ??
                predictionType.value?.game.status ??
                '',
        ).toLowerCase();

        if (isFinal()) return 'Final';
        if (isLive()) return 'Live';
        if (status.includes('postponed')) return 'Postponed';

        return 'Pregame';
    }

    function statusBadgeClass(): string {
        if (isLive()) {
            return 'rounded-full border border-sky-500/25 bg-sky-500/10 px-2.5 py-1 text-xs font-semibold text-sky-700 dark:text-sky-300';
        }

        return dashboardChipClass();
    }

    function preGameWinProbability(): number {
        return props.prediction.win_probability ?? 0;
    }

    function liveWinProbability(): number | null {
        return normalizedLiveState.value.liveWinProbability;
    }

    function normalizedProbability(value: number | null): number | null {
        if (value === null || !Number.isFinite(value)) return null;

        const decimal = value > 1 ? value / 100 : value;

        return Math.max(0, Math.min(1, decimal));
    }

    function ordinal(value: number): string {
        const mod100 = value % 100;
        if (mod100 >= 11 && mod100 <= 13) return `${value}th`;

        const suffix =
            value % 10 === 1
                ? 'st'
                : value % 10 === 2
                  ? 'nd'
                  : value % 10 === 3
                    ? 'rd'
                    : 'th';

        return `${value}${suffix}`;
    }

    function liveGameStateLabel(): string {
        if (isMlbPrediction()) {
            const rawState = normalizedLiveState.value.inningState?.trim();
            const inning = normalizedLiveState.value.inning;

            if (rawState && /\d/.test(rawState)) return rawState;

            if (inning !== null) {
                const half = rawState
                    ? rawState.charAt(0).toUpperCase() + rawState.slice(1)
                    : 'Inning';

                return `${half} ${ordinal(inning)}`;
            }

            return rawState || 'Live inning';
        }

        return livePeriodLabel(
            props.sport ?? dashboardType.value?.sport ?? '',
            normalizedLiveState.value.period,
            normalizedLiveState.value.gameClock,
            normalizedLiveState.value.status,
        );
    }

    function liveScoreContextLabel(): string {
        const away = awayScore();
        const home = homeScore();

        if (away === null || home === null) return 'Score updating';
        if (away === home) return `Tied at ${home}`;

        const leader = home > away ? homeTeamLabel() : awayTeamLabel();

        return `${leader} leads by ${Math.abs(home - away)}`;
    }

    function liveGameStateMeta(): string {
        if (!isMlbPrediction()) return liveScoreContextLabel();

        const parts = [];
        if (gameOuts() !== null) {
            parts.push(`${gameOuts()} ${gameOuts() === 1 ? 'out' : 'outs'}`);
        }
        if (gameBalls() !== null && gameStrikes() !== null) {
            parts.push(`Count ${gameBalls()}-${gameStrikes()}`);
        }

        return parts.join(' · ') || liveScoreContextLabel();
    }

    function liveHomeProbability(): number | null {
        return normalizedProbability(liveWinProbability());
    }

    function liveWinLeaderLabel(): string | null {
        const probability = liveHomeProbability();
        if (probability === null) return null;

        return probability >= 0.5 ? homeTeamLabel() : awayTeamLabel();
    }

    function liveWinOutlookValue(): string {
        const probability = liveHomeProbability();
        const leader = liveWinLeaderLabel();

        if (probability === null || !leader)
            return isPredictionListItem(props.prediction) &&
                props.prediction.live_status === 'unavailable'
                ? 'Forecast unavailable'
                : 'Model updating';

        return `${leader} ${(Math.max(probability, 1 - probability) * 100).toFixed(1)}%`;
    }

    function liveWinMovement(): number | null {
        const live = liveHomeProbability();
        const pregame = normalizedProbability(preGameWinProbability());

        return live === null || pregame === null
            ? null
            : (live - pregame) * 100;
    }

    function liveWinOutlookMeta(): string {
        const status = liveForecastStatus();
        if (status) return status;
        const movement = liveWinMovement();
        if (movement === null) return 'Live probability pending';
        if (Math.abs(movement) < 1) return 'Holding near pregame';

        const beneficiary = movement > 0 ? homeTeamLabel() : awayTeamLabel();

        return `${beneficiary} +${Math.abs(movement).toFixed(1)} pts from pregame`;
    }

    function liveTotalOutlookValue(): string {
        const projected = normalizedLiveState.value.livePredictedTotal;
        const unit = isMlbPrediction() ? 'runs' : 'points';

        if (projected !== null) return `${projected.toFixed(1)} ${unit}`;

        const away = awayScore();
        const home = homeScore();

        return away !== null && home !== null
            ? `${away + home} ${unit} scored`
            : 'Model updating';
    }

    const liveLeaderProbability = computed(() => {
        const probability = liveHomeProbability();
        return probability === null
            ? null
            : Math.max(probability, 1 - probability) * 100;
    });

    const liveWinnerColor = computed(() => {
        const probability = liveHomeProbability();
        if (probability === null || probability === 0.5) return null;
        const home = probability > 0.5;
        const color = dashboardType.value
            ? home
                ? dashboardType.value.home_color
                : dashboardType.value.away_color
            : home
              ? predictionType.value?.game.home_team.color
              : predictionType.value?.game.away_team.color;
        const hex = color?.trim().replace(/^#/, '');
        return hex && /^(?:[0-9a-f]{3}|[0-9a-f]{6})$/i.test(hex)
            ? `#${hex}`
            : null;
    });

    const liveTotalDirection = computed(() => {
        const live = normalizedLiveState.value.livePredictedTotal;
        const pregame = props.prediction.predicted_total;
        if (
            live === null ||
            !Number.isFinite(live) ||
            typeof pregame !== 'number' ||
            !Number.isFinite(pregame) ||
            liveForecastStatus()
        )
            return null;
        const movement = live - pregame;
        return Math.abs(movement) < 0.5
            ? 'steady'
            : movement > 0
              ? 'up'
              : 'down';
    });

    function liveTotalOutlookMeta(): string {
        const status = liveForecastStatus();
        if (status) return status;
        const live = normalizedLiveState.value.livePredictedTotal;
        const pregame = normalizedLiveState.value.preGamePredictedTotal;

        if (live === null || !Number.isFinite(pregame)) {
            return 'Updated total pending';
        }

        const movement = live - pregame;
        if (Math.abs(movement) < 0.5) return 'Near pregame projection';

        return `${formatSignedNumber(movement)} vs pregame total`;
    }

    function liveForecastStatus(): string | null {
        return isPredictionListItem(props.prediction)
            ? liveForecastMessage(props.prediction)
            : null;
    }

    function isMlbPrediction(): boolean {
        return (props.sport ?? '').toLowerCase() === 'mlb';
    }

    function isNflPrediction(): boolean {
        return (props.sport ?? '').toLowerCase() === 'nfl';
    }

    function isCfbPrediction(): boolean {
        return (props.sport ?? '').toLowerCase() === 'cfb';
    }

    function dashboardChipClass(): string {
        return 'rounded-full border bg-background/75 px-2.5 py-1 text-xs font-semibold text-muted-foreground';
    }
    function formatSignedNumber(value: number, decimals = 1): string {
        return `${value > 0 ? '+' : ''}${value.toFixed(decimals)}`;
    }
    return {
        formatSignedNumber,
        dashboardChipClass,
        awayTeamLabel,
        homeTeamLabel,
        awayLogo: awayTeamLogo.url,
        homeLogo: homeTeamLogo.url,
        isLive,
        gameOuts,
        isFinal,
        awayScore,
        homeScore,
        showGameScore,
        teamScoreClass,
        showAwayLogo: awayTeamLogo.show,
        showHomeLogo: homeTeamLogo.show,
        handleAwayLogoError: awayTeamLogo.handleError,
        handleHomeLogoError: homeTeamLogo.handleError,
        weekLabel,
        gameDateTimeLabel,
        predictionFreshnessLabel,
        statusBadgeLabel,
        statusBadgeClass,
        preGameWinProbability,
        liveGameStateLabel,
        liveGameStateMeta,
        liveWinLeaderLabel,
        liveWinOutlookValue,
        liveWinOutlookMeta,
        liveTotalOutlookValue,
        liveLeaderProbability,
        liveWinnerColor,
        liveTotalDirection,
        liveTotalOutlookMeta,
        isMlbPrediction,
        isNflPrediction,
        isCfbPrediction,
        predictionType,
        dashboardType,
    };
}
