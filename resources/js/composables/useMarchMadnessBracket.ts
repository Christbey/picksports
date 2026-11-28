import { usePage } from '@inertiajs/vue3';
import { computed, onMounted, onScopeDispose, reactive, ref, watch } from 'vue';
import { useAppearance } from '@/composables/useAppearance';
import { useApiV2Client } from '@/composables/useApiV2Client';
import { useBracketTournament } from '@/composables/useBracketTournament';
import { useBracketProgress } from '@/composables/useBracketProgress';
import {
    storageKey,
    roundLabels,
    bracketLockAtIso,
    accentByRegion,
} from '@/lib/bracketConfig';
import type {
    TournamentRegion,
    SavedBracket,
    Group,
    LeaderboardEntry,
    ApiResource,
    ApiMutationError,
} from '@/types/bracket';

const apiStatus = (error: unknown): number | null =>
    typeof error === 'object' && error !== null && 'status' in error
        ? Number((error as ApiMutationError).status)
        : null;

const apiLockAt = (error: unknown): string | null => {
    if (typeof error !== 'object' || error === null || !('data' in error)) {
        return null;
    }

    const lockAt = (error as ApiMutationError).data?.lock_at;

    return typeof lockAt === 'string' ? lockAt : null;
};

export function useMarchMadnessBracket(props: {
    regions?: TournamentRegion[];
}) {
    const { resolvedAppearance, updateAppearance } = useAppearance();
    const api = useApiV2Client();
    const page = usePage();
    const picks = ref<Record<string, string>>({});
    const currentBracket = ref<SavedBracket | null>(null);
    const brackets = ref<SavedBracket[]>([]);
    const activeBracketPublicId = ref<string | null>(null);
    const availableGroups = ref<Group[]>([]);
    const bracketNameDraft = ref('');
    const bracketGroupIdDraft = ref<string>('');
    const leaderboard = ref<LeaderboardEntry[]>([]);
    const saveState = ref<'idle' | 'saving' | 'saved' | 'error'>('idle');
    const metaSaveState = ref<'idle' | 'saving' | 'saved' | 'error'>('idle');
    let metaSaveTimer: number | undefined;
    let alive = true;
    let contextGeneration = 0;
    let contextController = new AbortController();
    let groupRequest = 0;
    let bracketRequest = 0;
    let leaderboardRequest = 0;
    let metadataRequest = 0;
    let picksRequest = 0;
    let appliedPicks = '{}';
    let pickSaveQueue = Promise.resolve();
    onScopeDispose(() => {
        alive = false;
        contextController.abort();
        contextGeneration += 1;
        if (metaSaveTimer !== undefined) window.clearTimeout(metaSaveTimer);
    });
    const resetConfirmOpen = ref(false);
    const storageLoaded = ref(false);
    const serverLoaded = ref(false);
    const accountReadErrors = reactive<{
        brackets: string | null;
        groups: string | null;
        leaderboard: string | null;
    }>({ brackets: null, groups: null, leaderboard: null });
    const accountReadErrorMessages = computed(() =>
        Object.values(accountReadErrors).filter(
            (message): message is string => message !== null,
        ),
    );
    const accountLoading = ref(false);
    let accountLoadingToken: string | null = null;

    const tournament = useBracketTournament(
        props,
        picks,
        currentBracket,
        () => isBracketLocked.value,
    );
    const {
        allTournamentGames,
        resolveSlotParticipant,
        selectedParticipant,
        standaloneRounds,
        allBracketMatchups,
    } = tournament;
    const progress = useBracketProgress(tournament);
    const currentSeason = computed(() => {
        const seasons = allTournamentGames.value
            .map((game) => game.gameDate?.slice(0, 4))
            .filter((season): season is string => Boolean(season));

        return seasons.length
            ? Math.max(...seasons.map((season) => Number(season)))
            : new Date().getFullYear();
    });
    const isAuthenticated = computed(() => Boolean(page.props.auth?.user));
    const currentUserId = computed(() =>
        Number(page.props.auth?.user?.id ?? 0),
    );
    const contextKey = computed(
        () => `${currentUserId.value}:${currentSeason.value}`,
    );
    const localStorageKey = computed(() => `${storageKey}:${contextKey.value}`);
    const captureContext = () => `${contextGeneration}:${contextKey.value}`;
    const contextIsCurrent = (token: string) =>
        alive && token === captureContext();
    const bracketLockDate = computed(() => new Date(bracketLockAtIso));
    const isBracketLocked = computed(() => {
        if (
            currentBracket.value?.is_locked ||
            currentBracket.value?.can_edit === false
        )
            return true;
        if (currentBracket.value?.lock_at) {
            return new Date() >= new Date(currentBracket.value.lock_at);
        }

        return new Date() >= bracketLockDate.value;
    });
    const totalGames = computed(() => allBracketMatchups.value.length);
    const pickedGames = computed(() => Object.keys(picks.value).length);
    const completionPct = computed(() =>
        totalGames.value === 0
            ? 0
            : Math.round((pickedGames.value / totalGames.value) * 100),
    );
    const champion = computed(
        () =>
            selectedParticipant(standaloneRounds.value.championship)?.name ??
            null,
    );
    const activeBracketName = computed(
        () => currentBracket.value?.name?.trim() || 'Untitled bracket',
    );
    const nextBracketName = computed(
        () => `Bracket ${brackets.value.length + 1}`,
    );
    const selectedGroupName = computed(() => {
        const groupId = Number(bracketGroupIdDraft.value);
        if (!groupId) return 'No group';

        return (
            availableGroups.value.find((group) => group.id === groupId)?.name ??
            'No group'
        );
    });
    const requiresGroupAssignment = computed(() =>
        availableGroups.value.some(
            (group) => group.owner_id !== currentUserId.value,
        ),
    );
    const maskedLeaderboardUser = (userName: string | null) => {
        const trimmed = userName?.trim();

        if (!trimmed) {
            return 'UNK';
        }

        return trimmed.replace(/\s+/g, '').slice(0, 3).toUpperCase();
    };
    const saveStateLabel = computed(() => {
        if (isBracketLocked.value) return 'Locked Mar 19, 2026 at 11:00 AM CT';
        if (!isAuthenticated.value) return 'Saved on this device';
        if (accountReadErrors.brackets)
            return 'Account sync unavailable; picks saved on this device';
        if (saveState.value === 'saving') return 'Saving to account';
        if (saveState.value === 'saved') return 'Saved to account';
        if (saveState.value === 'error') return 'Save failed';
        return 'Account sync ready';
    });
    const picksAutosaveLabel = computed(() => {
        if (isBracketLocked.value) return 'Bracket locked';
        if (!isAuthenticated.value) return 'Picks autosave on this device';
        if (saveState.value === 'saving')
            return 'Picks autosaving to your account';
        if (saveState.value === 'saved')
            return 'Picks autosaved to your account';
        if (saveState.value === 'error') return 'Picks failed to autosave';
        return 'Picks autosave to your account';
    });
    const picksAutosaveToneClass = computed(() => {
        if (saveState.value === 'saved') return 'text-emerald-200/80';
        if (saveState.value === 'saving') return 'text-foreground/80';
        if (saveState.value === 'error') return 'text-rose-200/80';
        return 'text-muted-foreground';
    });
    const metaSaveLabel = computed(() => {
        if (isBracketLocked.value) return 'Locked';
        if (metaSaveState.value === 'saving') return 'Saving...';
        if (metaSaveState.value === 'saved') return 'Saved';
        if (metaSaveState.value === 'error') return 'Retry Save';
        return 'Save Details';
    });
    const metaSaveToneClass = computed(() => {
        if (metaSaveState.value === 'saved') return 'text-emerald-200/80';
        if (metaSaveState.value === 'saving') return 'text-foreground/80';
        if (metaSaveState.value === 'error') return 'text-rose-200/80';
        return 'text-muted-foreground';
    });
    const appearanceToggleLabel = computed(() =>
        resolvedAppearance.value === 'dark' ? 'Light mode' : 'Dark mode',
    );

    const toggleAppearance = () => {
        updateAppearance(
            resolvedAppearance.value === 'dark' ? 'light' : 'dark',
        );
    };

    const resetBracket = () => {
        if (isBracketLocked.value) return;
        picks.value = {};
    };

    const requestResetBracket = () => {
        if (isBracketLocked.value) return;
        resetConfirmOpen.value = true;
    };

    const confirmResetBracket = () => {
        resetBracket();
        resetConfirmOpen.value = false;
    };

    const upsertBracketInList = (bracket: SavedBracket) => {
        const next = [...brackets.value];
        const index = next.findIndex(
            (entry) => entry.public_id === bracket.public_id,
        );

        if (index === -1) {
            next.unshift(bracket);
        } else {
            next[index] = bracket;
        }

        brackets.value = next.sort(
            (left, right) =>
                new Date(right.updated_at ?? 0).getTime() -
                new Date(left.updated_at ?? 0).getTime(),
        );
    };

    const activateBracket = (
        bracket: SavedBracket | null,
        syncPicks = true,
    ) => {
        currentBracket.value = bracket;
        activeBracketPublicId.value = bracket?.public_id ?? null;
        bracketNameDraft.value = bracket?.name ?? '';
        bracketGroupIdDraft.value = bracket?.group_id
            ? String(bracket.group_id)
            : requiresGroupAssignment.value && availableGroups.value.length
              ? String(availableGroups.value[0].id)
              : '';

        if (!syncPicks) {
            return;
        }

        if (bracket?.picks) {
            applyStoredPicks(bracket.picks);
            return;
        }

        if (bracket) {
            picks.value = {};
        }
    };

    const applyStoredPicks = (stored: Record<string, string>) => {
        const validMatchupIds = new Set(
            allBracketMatchups.value.map((matchup) => matchup.id),
        );
        const validPicks = Object.fromEntries(
            Object.entries(stored).filter(([matchupId]) =>
                validMatchupIds.has(matchupId),
            ),
        );
        appliedPicks = JSON.stringify(validPicks);
        picks.value = validPicks;
    };

    const readLocalPicks = (allowGuestImport = false) => {
        let key = localStorageKey.value;
        let stored = window.localStorage.getItem(key);
        if (!stored && allowGuestImport && isAuthenticated.value) {
            key = `${storageKey}:0:${currentSeason.value}`;
            stored = window.localStorage.getItem(key);
        }
        if (!stored) {
            storageLoaded.value = true;
            return;
        }

        try {
            applyStoredPicks(JSON.parse(stored) as Record<string, string>);
        } catch {
            window.localStorage.removeItem(key);
        } finally {
            storageLoaded.value = true;
        }
    };

    const createBracket = async (
        initialPicks: Record<string, string> = {},
        syncPicks = true,
    ) => {
        if (!isAuthenticated.value || isBracketLocked.value) {
            return null;
        }

        const token = captureContext();
        const activeAtStart = activeBracketPublicId.value;
        const resolvedGroupId = bracketGroupIdDraft.value
            ? Number(bracketGroupIdDraft.value)
            : requiresGroupAssignment.value && availableGroups.value.length
              ? availableGroups.value[0].id
              : null;

        const response = await api.cbbBrackets.store<ApiResource<SavedBracket>>(
            {
                season: currentSeason.value,
                name: bracketNameDraft.value.trim() || nextBracketName.value,
                group_id: resolvedGroupId,
                picks: initialPicks,
            },
            { init: { signal: contextController.signal } },
        );

        const bracket = response?.data;
        if (!bracket || !contextIsCurrent(token)) {
            return null;
        }

        upsertBracketInList(bracket);
        if (activeBracketPublicId.value === activeAtStart)
            activateBracket(bracket, syncPicks);

        return bracket;
    };

    const loadGroups = async () => {
        if (!isAuthenticated.value) return;
        const token = captureContext();
        const request = ++groupRequest;
        accountReadErrors.groups = null;
        try {
            const response = await api.groups.index<ApiResource<Group[]>>({
                init: { signal: contextController.signal },
                query: {
                    type: 'bracket_pool',
                    sport: 'cbb',
                    season: currentSeason.value,
                },
            });
            if (!contextIsCurrent(token) || request !== groupRequest) return;
            if (!response || !Array.isArray(response.data))
                throw new Error('Missing group response');
            const groups = response.data;
            availableGroups.value = groups;
            if (
                !bracketGroupIdDraft.value &&
                groups.length > 0 &&
                groups.some((group) => group.owner_id !== currentUserId.value)
            )
                bracketGroupIdDraft.value = String(groups[0].id);
        } catch {
            if (contextIsCurrent(token) && request === groupRequest)
                accountReadErrors.groups =
                    'Bracket groups could not be loaded.';
        }
    };

    const saveBracketMeta = async () => {
        if (
            !isAuthenticated.value ||
            !currentBracket.value ||
            isBracketLocked.value
        ) {
            return;
        }

        if (metaSaveTimer !== undefined) window.clearTimeout(metaSaveTimer);
        const token = captureContext();
        const sourceId = currentBracket.value.public_id;
        const request = ++metadataRequest;
        metaSaveState.value = 'saving';

        try {
            const resolvedGroupId = bracketGroupIdDraft.value
                ? Number(bracketGroupIdDraft.value)
                : requiresGroupAssignment.value && availableGroups.value.length
                  ? availableGroups.value[0].id
                  : null;

            const response = await api.cbbBrackets.update<
                ApiResource<SavedBracket>
            >(
                sourceId,
                {
                    name: bracketNameDraft.value.trim() || null,
                    group_id: resolvedGroupId,
                },
                { init: { signal: contextController.signal } },
            );

            if (!contextIsCurrent(token) || request !== metadataRequest) return;
            const savedBracket = response?.data;
            if (!savedBracket) {
                metaSaveState.value = 'error';
                return;
            }

            upsertBracketInList(savedBracket);
            if (activeBracketPublicId.value !== sourceId) return;
            activateBracket(savedBracket, false);
            metaSaveState.value = 'saved';

            metaSaveTimer = window.setTimeout(() => {
                if (metaSaveState.value === 'saved') {
                    metaSaveState.value = 'idle';
                }
            }, 2000);
        } catch {
            if (
                contextIsCurrent(token) &&
                request === metadataRequest &&
                activeBracketPublicId.value === sourceId
            )
                metaSaveState.value = 'error';
        }
    };

    const switchBracket = (publicId: string) => {
        const bracket =
            brackets.value.find((entry) => entry.public_id === publicId) ??
            null;
        metaSaveState.value = 'idle';
        saveState.value = 'idle';
        activateBracket(bracket);
    };

    const loadServerBrackets = async () => {
        if (!isAuthenticated.value) {
            serverLoaded.value = true;
            return;
        }
        const token = captureContext();
        const request = ++bracketRequest;
        serverLoaded.value = false;
        accountReadErrors.brackets = null;
        try {
            const response = await api.cbbBrackets.index<
                ApiResource<SavedBracket[]>
            >({
                query: { season: currentSeason.value },
                init: { signal: contextController.signal },
            });
            if (!contextIsCurrent(token) || request !== bracketRequest) return;
            if (!response || !Array.isArray(response.data))
                throw new Error('Missing bracket response');
            const serverBrackets = response.data;
            brackets.value = serverBrackets;
            if (serverBrackets.length > 0) {
                activateBracket(
                    serverBrackets.find(
                        (entry) =>
                            entry.public_id === activeBracketPublicId.value,
                    ) ?? serverBrackets[0],
                );
            } else if (
                !isBracketLocked.value &&
                Object.keys(picks.value).length > 0
            ) {
                await createBracket(picks.value);
            } else {
                activateBracket(null);
            }
            if (contextIsCurrent(token) && request === bracketRequest)
                serverLoaded.value = true;
        } catch {
            if (contextIsCurrent(token) && request === bracketRequest)
                accountReadErrors.brackets =
                    'Saved brackets could not be loaded. Your local draft is retained.';
        }
    };

    const loadLeaderboard = async () => {
        if (!isAuthenticated.value) return;
        const token = captureContext();
        const groupId = currentBracket.value?.group_id ?? null;
        const request = ++leaderboardRequest;
        leaderboard.value = [];
        accountReadErrors.leaderboard = null;
        try {
            const response = await api.cbbBrackets.leaderboard<
                ApiResource<LeaderboardEntry[]>
            >({
                init: { signal: contextController.signal },
                query: {
                    season: currentSeason.value,
                    limit: 10,
                    group_id: groupId ?? undefined,
                },
            });
            if (
                !contextIsCurrent(token) ||
                request !== leaderboardRequest ||
                (currentBracket.value?.group_id ?? null) !== groupId
            )
                return;
            if (!response || !Array.isArray(response.data))
                throw new Error('Missing leaderboard response');
            leaderboard.value = response.data;
        } catch {
            if (
                contextIsCurrent(token) &&
                request === leaderboardRequest &&
                (currentBracket.value?.group_id ?? null) === groupId
            )
                accountReadErrors.leaderboard =
                    'Bracket standings could not be loaded.';
        }
    };

    const reloadAccountData = async () => {
        const token = captureContext();
        if (accountLoading.value && accountLoadingToken === token) return;
        accountLoadingToken = token;
        accountLoading.value = true;
        try {
            await Promise.all([
                loadGroups(),
                loadServerBrackets(),
                loadLeaderboard(),
            ]);
        } finally {
            if (contextIsCurrent(token)) accountLoading.value = false;
        }
    };

    onMounted(() => {
        readLocalPicks(true);
        void reloadAccountData();
    });

    watch(
        contextKey,
        (_key, previousKey) => {
            contextController.abort();
            contextController = new AbortController();
            contextGeneration += 1;
            metadataRequest += 1;
            picksRequest += 1;
            if (metaSaveTimer !== undefined) window.clearTimeout(metaSaveTimer);
            currentBracket.value = null;
            activeBracketPublicId.value = null;
            brackets.value = [];
            availableGroups.value = [];
            leaderboard.value = [];
            bracketNameDraft.value = '';
            bracketGroupIdDraft.value = '';
            saveState.value = 'idle';
            metaSaveState.value = 'idle';
            serverLoaded.value = false;
            accountReadErrors.brackets = null;
            accountReadErrors.groups = null;
            accountReadErrors.leaderboard = null;
            appliedPicks = '{}';
            picks.value = {};
            readLocalPicks(previousKey?.startsWith('0:') ?? false);
            void reloadAccountData();
        },
        { flush: 'sync' },
    );

    watch(
        () => currentBracket.value?.group_id,
        () => {
            void loadLeaderboard();
        },
    );

    watch(
        picks,
        (value) => {
            if (typeof window === 'undefined') return;
            window.localStorage.setItem(
                localStorageKey.value,
                JSON.stringify(value),
            );
        },
        { deep: true },
    );

    watch(
        picks,
        (value, _oldValue, onCleanup) => {
            if (
                !isAuthenticated.value ||
                !storageLoaded.value ||
                !serverLoaded.value ||
                isBracketLocked.value
            ) {
                return;
            }

            if (
                JSON.stringify(value) === appliedPicks &&
                saveState.value !== 'saving'
            )
                return;
            const token = captureContext();
            const sourceId = activeBracketPublicId.value;
            const request = ++picksRequest;
            const savedPicks = { ...value };
            saveState.value = 'saving';

            const timeoutId = window.setTimeout(async () => {
                const save = async () => {
                    if (
                        !contextIsCurrent(token) ||
                        request !== picksRequest ||
                        activeBracketPublicId.value !== sourceId
                    )
                        return;
                    try {
                        let savedBracket: SavedBracket;

                        if (sourceId) {
                            const response = await api.cbbBrackets.update<
                                ApiResource<SavedBracket>
                            >(
                                sourceId,
                                {
                                    picks: savedPicks,
                                },
                                { init: { signal: contextController.signal } },
                            );

                            if (!response?.data) {
                                saveState.value = 'error';
                                return;
                            }

                            savedBracket = response.data;
                        } else {
                            const createdBracket = await createBracket(
                                savedPicks,
                                false,
                            );

                            if (!createdBracket) {
                                return;
                            }

                            savedBracket = createdBracket;
                            if (
                                contextIsCurrent(token) &&
                                request !== picksRequest &&
                                activeBracketPublicId.value ===
                                    createdBracket.public_id
                            ) {
                                // A newer edit made while creating the draft must save against its new ID.
                                picks.value = { ...picks.value };
                            }
                        }

                        if (
                            !contextIsCurrent(token) ||
                            request !== picksRequest
                        )
                            return;
                        upsertBracketInList(savedBracket);
                        if (
                            sourceId !== null &&
                            activeBracketPublicId.value !== sourceId
                        )
                            return;
                        appliedPicks = JSON.stringify(savedPicks);
                        saveState.value = 'saved';
                        activateBracket(savedBracket, false);
                    } catch (error: unknown) {
                        if (
                            !contextIsCurrent(token) ||
                            request !== picksRequest ||
                            activeBracketPublicId.value !== sourceId
                        )
                            return;
                        if (apiStatus(error) === 423) {
                            saveState.value = 'idle';
                            currentBracket.value = currentBracket.value
                                ? {
                                      ...currentBracket.value,
                                      is_locked: true,
                                      can_edit: false,
                                      lock_at:
                                          apiLockAt(error) ??
                                          currentBracket.value.lock_at ??
                                          bracketLockAtIso,
                                  }
                                : null;
                        } else {
                            saveState.value = 'error';
                        }
                    }
                };
                const queued = pickSaveQueue.then(save, save);
                pickSaveQueue = queued.catch(() => undefined);
                await queued;
            }, 500);

            onCleanup(() => window.clearTimeout(timeoutId));
        },
        { deep: true },
    );

    watch(
        allBracketMatchups,
        (matchups) => {
            const validSelections = new Map(
                matchups.map((matchup) => [
                    matchup.id,
                    new Set(
                        matchup.participants
                            .map((slot) => resolveSlotParticipant(slot)?.id)
                            .filter((participantId): participantId is string =>
                                Boolean(participantId),
                            ),
                    ),
                ]),
            );

            const filteredEntries = Object.entries(picks.value).filter(
                ([matchupId, participantId]) =>
                    validSelections.get(matchupId)?.has(participantId),
            );

            if (filteredEntries.length !== Object.keys(picks.value).length) {
                picks.value = Object.fromEntries(filteredEntries);
            }
        },
        { deep: true, immediate: true },
    );

    return reactive({
        ...tournament,
        ...progress,
        accountReadErrors,
        accountReadErrorMessages,
        accountLoading,
        reloadAccountData,
        roundLabels,
        accentByRegion,
        picks,
        currentBracket,
        brackets,
        activeBracketPublicId,
        availableGroups,
        bracketNameDraft,
        bracketGroupIdDraft,
        leaderboard,
        metaSaveState,
        resetConfirmOpen,
        currentSeason,
        isAuthenticated,
        isBracketLocked,
        totalGames,
        pickedGames,
        completionPct,
        champion,
        activeBracketName,
        selectedGroupName,
        requiresGroupAssignment,
        maskedLeaderboardUser,
        saveStateLabel,
        picksAutosaveLabel,
        picksAutosaveToneClass,
        metaSaveLabel,
        metaSaveToneClass,
        appearanceToggleLabel,
        toggleAppearance,
        requestResetBracket,
        confirmResetBracket,
        createBracket,
        saveBracketMeta,
        switchBracket,
    });
}
