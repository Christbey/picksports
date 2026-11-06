import { ref, onMounted, onBeforeUnmount } from 'vue';
import { useApiV2Client } from '@/composables/useApiV2Client';
import {
    normalizePlayer,
    normalizePlayerProp,
    normalizeStatRow,
} from '@/lib/playerPagePresentation';
import type {
    Player,
    PlayerProp,
    PlayerStat,
    PlayerPageProps,
} from '@/types/player-page';

export function usePlayerPageData(props: PlayerPageProps) {
    const playerData = ref<Player | null>(null);
    const playerProps = ref<PlayerProp[]>([]);
    const gameLogs = ref<PlayerStat[]>([]);
    const leaderboardRows = ref<Record<string, unknown>[]>([]);
    const leaderboardRanks = ref<
        Record<string, { rank: number; total: number }>
    >({});
    const loading = ref(true);
    const error = ref<string | null>(null);
    const api = useApiV2Client();
    let controller = new AbortController();
    let supplementalController = new AbortController();
    const supplementalLoading = ref(false);
    const supplementalError = ref<string | null>(null);
    let supplementalScheduled = false;
    let disposed = false;
    const isActive = () => !disposed && !controller.signal.aborted;
    let supplementalIdleHandle: number | null = null;
    let supplementalTimeoutHandle: number | null = null;

    const loadSupplementalData = async () => {
        if (!isActive()) return;
        if (supplementalLoading.value) return;
        supplementalController.abort();
        const current = new AbortController();
        supplementalController = current;
        const active = () => !disposed && !current.signal.aborted;
        supplementalLoading.value = true;
        supplementalError.value = null;
        const requests: Promise<unknown>[] = [];

        if (props.config.showPlayerProps) {
            requests.push(
                api.players
                    .playerProps(props.config.sport, props.playerId, {
                        query: { per_page: 12 },
                        init: { signal: current.signal },
                    })
                    .then((response) => {
                        if (!active()) return;
                        if (!response)
                            throw new Error(
                                'Some player props or rankings could not be loaded.',
                            );
                        playerProps.value = (response?.data ?? []).map(
                            normalizePlayerProp,
                        );
                    }),
            );
        }

        if (props.config.leaderboardQuery !== null) {
            requests.push(
                api.leaderboards
                    .players(props.config.sport, {
                        query: {
                            ...(props.config.leaderboardQuery ?? {}),
                            focus_player_id: props.playerId,
                        },
                        init: { signal: current.signal },
                    })
                    .then((response) => {
                        if (!active()) return;
                        if (!response)
                            throw new Error(
                                'Some player props or rankings could not be loaded.',
                            );
                        leaderboardRows.value = response?.data ?? [];
                        const ranks = response?.meta?.focus_ranks;
                        leaderboardRanks.value =
                            ranks && typeof ranks === 'object'
                                ? (ranks as Record<
                                      string,
                                      { rank: number; total: number }
                                  >)
                                : {};
                    }),
            );
        }

        const results = await Promise.allSettled(requests);
        if (!active()) return;
        if (results.some((result) => result.status === 'rejected')) {
            supplementalError.value =
                'Some player props or rankings could not be loaded.';
        }
        supplementalLoading.value = false;
    };

    const scheduleSupplementalData = () => {
        if (!isActive() || supplementalScheduled) return;
        supplementalScheduled = true;
        if ('requestIdleCallback' in window) {
            supplementalIdleHandle = window.requestIdleCallback(
                () => void loadSupplementalData(),
                { timeout: 1_500 },
            );
            return;
        }

        supplementalTimeoutHandle = globalThis.setTimeout(
            () => void loadSupplementalData(),
            500,
        );
    };

    const loadPlayer = async () => {
        if (disposed || (loading.value && primaryStarted)) return;
        primaryStarted = true;
        controller.abort();
        const current = new AbortController();
        controller = current;
        const active = () => !disposed && !current.signal.aborted;
        loading.value = true;
        error.value = null;
        try {
            const results = await Promise.allSettled([
                api.players
                    .show(props.config.sport, props.playerId, {
                        init: { signal: current.signal },
                    })
                    .then((response) => {
                        if (!active()) return;
                        if (!response?.data)
                            throw new Error(
                                'Player information could not be loaded.',
                            );
                        playerData.value = normalizePlayer(
                            response?.data ?? null,
                        );
                    }),
                api.stats
                    .players(props.config.sport, {
                        query: {
                            ...(props.config.statsQuery ?? {}),
                            player_id: props.playerId,
                            per_page: 100,
                        },
                        init: { signal: current.signal },
                    })
                    .then((response) => {
                        if (!active()) return;
                        if (!response)
                            throw new Error(
                                'Player game log could not be loaded.',
                            );
                        gameLogs.value = (response?.data ?? []).map(
                            normalizeStatRow,
                        );
                    }),
            ]);
            const failure = results.find(
                (result) => result.status === 'rejected',
            );
            if (failure?.status === 'rejected') throw failure.reason;
        } catch (e) {
            if (!active()) return;
            error.value =
                e instanceof Error ? e.message : 'Failed to load player data';
        } finally {
            if (active()) {
                loading.value = false;
                scheduleSupplementalData();
            }
        }
    };
    let primaryStarted = false;
    onMounted(() => void loadPlayer());

    onBeforeUnmount(() => {
        disposed = true;
        controller.abort();
        supplementalController.abort();
        if (supplementalIdleHandle !== null && 'cancelIdleCallback' in window) {
            window.cancelIdleCallback(supplementalIdleHandle);
        }
        if (supplementalTimeoutHandle !== null) {
            globalThis.clearTimeout(supplementalTimeoutHandle);
        }
    });

    return {
        playerData,
        playerProps,
        gameLogs,
        leaderboardRows,
        leaderboardRanks,
        loading,
        error,
        loadPlayer,
        supplementalLoading,
        supplementalError,
        loadSupplementalData,
    };
}
