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
    const controller = new AbortController();
    let disposed = false;
    const isActive = () => !disposed && !controller.signal.aborted;
    let supplementalIdleHandle: number | null = null;
    let supplementalTimeoutHandle: number | null = null;

    const loadSupplementalData = async () => {
        if (!isActive()) return;
        const requests: Promise<unknown>[] = [];

        if (props.config.showPlayerProps) {
            requests.push(
                api.players
                    .playerProps(props.config.sport, props.playerId, {
                        query: { per_page: 12 },
                        init: { signal: controller.signal },
                    })
                    .then((response) => {
                        if (!isActive()) return;
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
                        init: { signal: controller.signal },
                    })
                    .then((response) => {
                        if (!isActive()) return;
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

        await Promise.allSettled(requests);
    };

    const scheduleSupplementalData = () => {
        if (!isActive()) return;
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

    onMounted(async () => {
        try {
            await Promise.all([
                api.players
                    .show(props.config.sport, props.playerId, {
                        init: { signal: controller.signal },
                    })
                    .then((response) => {
                        if (!isActive()) return;
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
                        init: { signal: controller.signal },
                    })
                    .then((response) => {
                        if (!isActive()) return;
                        gameLogs.value = (response?.data ?? []).map(
                            normalizeStatRow,
                        );
                    }),
            ]);
        } catch (e) {
            if (!isActive()) return;
            error.value =
                e instanceof Error ? e.message : 'Failed to load player data';
        } finally {
            if (isActive()) {
                loading.value = false;
                scheduleSupplementalData();
            }
        }
    });

    onBeforeUnmount(() => {
        disposed = true;
        controller.abort();
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
    };
}
