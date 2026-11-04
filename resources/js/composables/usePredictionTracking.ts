import { predictionHomeMargin } from '@/lib/predictionSpread';
import { computed, onScopeDispose, ref, watch, type Ref } from 'vue';
import { useApiV2Client } from '@/composables/useApiV2Client';
import type {
    PredictionCardProps,
    SavePickOption,
    TrackedBet,
    TrackingSummary,
    UserBetsTrackingResponse,
    PublicConsensus,
} from '@/types/prediction-card';
import type { usePredictionCardGame } from '@/composables/usePredictionCardGame';
export function usePredictionTracking(
    props: PredictionCardProps,
    game: ReturnType<typeof usePredictionCardGame>,
    expanded: Ref<boolean>,
) {
    const trackingCache = new Map<string, TrackingSummary>();
    const api = useApiV2Client();
    let latestRequest = 0;
    let controller: AbortController | null = null;
    onScopeDispose(() => {
        latestRequest++;
        controller?.abort();
    });
    const { awayTeamLabel, homeTeamLabel, predictionType, dashboardType } =
        game;
    const isSaveDialogOpen = ref(false);
    const activeSaveOption = ref<SavePickOption | null>(null);
    const trackingSummary = ref<TrackingSummary | null>(null);
    const isLoadingTracking = ref(false);
    const canSavePick = computed(
        () =>
            predictionType.value !== null &&
            predictionId() !== null &&
            predictionSport() !== null,
    );

    watch(
        [trackingKey, () => expanded.value, () => props.compact],
        ([key], previous) => {
            if (key !== previous?.[0]) {
                latestRequest++;
                controller?.abort();
                controller = null;
                trackingSummary.value = null;
                isLoadingTracking.value = false;
                isSaveDialogOpen.value = false;
                activeSaveOption.value = null;
            }
            if (!props.compact || expanded.value) void loadTrackingSummary();
        },
        { immediate: true },
    );

    function predictionSport(): string | null {
        const sport = (props.sport ?? '').toLowerCase();
        const supportedSports = [
            'nba',
            'wnba',
            'mlb',
            'nfl',
            'cbb',
            'wcbb',
            'cfb',
        ];

        return supportedSports.includes(sport) ? sport : null;
    }

    function predictionId(): number | string | null {
        if (predictionType.value) {
            return predictionType.value.id;
        }

        return dashboardType.value?.id ?? null;
    }

    function predictedSpreadValue(): number | null {
        const spread = predictionHomeMargin(
            props.prediction,
            predictionType.value === null,
        );

        return typeof spread === 'number' ? Number(spread.toFixed(1)) : null;
    }

    function predictedTotalValue(): number | null {
        const total = props.prediction.predicted_total;

        return typeof total === 'number' ? Number(total.toFixed(1)) : null;
    }

    function openSaveDialog(option: SavePickOption): void {
        activeSaveOption.value = option;
        isSaveDialogOpen.value = true;
    }

    function setSaveDialogOpen(value: boolean) {
        isSaveDialogOpen.value = value;
    }

    function trackingKey(): string | null {
        const id = predictionId();
        const type = predictionSport();

        if (id === null || type === null) {
            return null;
        }

        return `${type}:${id}`;
    }

    async function loadTrackingSummary(force = false): Promise<void> {
        const request = ++latestRequest;
        controller?.abort();
        controller = null;
        const key = trackingKey();
        isLoadingTracking.value = false;

        if (!key) {
            trackingSummary.value = null;
            return;
        }

        if (!force && trackingCache.has(key)) {
            trackingSummary.value = trackingCache.get(key) ?? null;
            return;
        }

        controller = new AbortController();
        const signal = controller.signal;
        isLoadingTracking.value = true;

        try {
            const response = await api.userBets.index<UserBetsTrackingResponse>(
                {
                    init: { signal },
                    query: {
                        prediction_id: predictionId(),
                        prediction_sport: predictionSport(),
                    },
                },
            );

            if (request !== latestRequest) return;
            trackingSummary.value = response?.tracking ?? null;
            if (trackingSummary.value) {
                trackingCache.set(key, trackingSummary.value);
            }
        } catch (error) {
            if (request !== latestRequest) return;
            console.error('Failed to load tracked picks:', error);
            trackingSummary.value = null;
        } finally {
            if (request === latestRequest) isLoadingTracking.value = false;
        }
    }

    function marketKeyForOption(
        option: SavePickOption,
    ): 'moneyline' | 'spread' | 'total' {
        return option.betType === 'total_over' ||
            option.betType === 'total_under'
            ? 'total'
            : option.betType;
    }

    function trackedBetForOption(option: SavePickOption): TrackedBet | null {
        return (
            trackingSummary.value?.user_bets.find(
                (bet) =>
                    bet.bet_type === option.betType &&
                    bet.selection_side === option.selectionSide,
            ) ?? null
        );
    }

    function consensusForOption(
        option: SavePickOption,
    ): PublicConsensus | null {
        const market =
            trackingSummary.value?.markets[marketKeyForOption(option)];

        if (!market || market.total_picks === 0) {
            return null;
        }

        const side = market.sides[option.selectionSide];

        if (!side || side.count === 0) {
            return {
                summary: `Public: no ${option.teamLabel} picks yet`,
                detail: `${market.total_picks} tracked ${marketKeyForOption(option)} pick${market.total_picks === 1 ? '' : 's'}`,
            };
        }

        const leader = market.leader;

        return {
            summary: `Public: ${side.percent}% on ${consensusSideLabel(option.selectionSide)}`,
            detail: leader
                ? `${consensusSideLabel(leader.side)} leads ${leader.percent}% of ${market.total_picks} picks`
                : `${market.total_picks} tracked pick${market.total_picks === 1 ? '' : 's'}`,
        };
    }

    function consensusSideLabel(side: SavePickOption['selectionSide']): string {
        if (side === 'home') {
            return homeTeamLabel();
        }

        if (side === 'away') {
            return awayTeamLabel();
        }

        return side === 'over' ? 'Over' : 'Under';
    }

    async function handleSaved(): Promise<void> {
        await loadTrackingSummary(true);
    }

    function saveOptions(): SavePickOption[] {
        const spread = predictedSpreadValue();
        const total = predictedTotalValue();

        return [
            {
                betType: 'moneyline',
                selectionSide: 'away',
                teamLabel: awayTeamLabel(),
                title: `${awayTeamLabel()} moneyline`,
                defaultLine: null,
            },
            {
                betType: 'moneyline',
                selectionSide: 'home',
                teamLabel: homeTeamLabel(),
                title: `${homeTeamLabel()} moneyline`,
                defaultLine: null,
            },
            {
                betType: 'spread',
                selectionSide: 'away',
                teamLabel: awayTeamLabel(),
                title: `${awayTeamLabel()} spread`,
                defaultLine: spread,
            },
            {
                betType: 'spread',
                selectionSide: 'home',
                teamLabel: homeTeamLabel(),
                title: `${homeTeamLabel()} spread`,
                defaultLine:
                    spread !== null ? Number((-spread).toFixed(1)) : null,
            },
            {
                betType: 'total_over',
                selectionSide: 'over',
                teamLabel: 'Over',
                title: 'Total over',
                defaultLine: total,
            },
            {
                betType: 'total_under',
                selectionSide: 'under',
                teamLabel: 'Under',
                title: 'Total under',
                defaultLine: total,
            },
        ];
    }

    return {
        setSaveDialogOpen,
        isSaveDialogOpen,
        activeSaveOption,
        isLoadingTracking,
        canSavePick,
        predictionSport,
        predictionId,
        openSaveDialog,
        trackedBetForOption,
        consensusForOption,
        handleSaved,
        saveOptions,
    };
}
