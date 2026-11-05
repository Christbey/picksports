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
    onScopeDispose(() => {
        if (metaSaveTimer !== undefined) window.clearTimeout(metaSaveTimer);
    });
    const resetConfirmOpen = ref(false);
    const storageLoaded = ref(false);
    const serverLoaded = ref(false);

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
    const bracketLockDate = computed(() => new Date(bracketLockAtIso));
    const isBracketLocked = computed(() => {
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
        picks.value = Object.fromEntries(
            Object.entries(stored).filter(([matchupId]) =>
                validMatchupIds.has(matchupId),
            ),
        );
    };

    const readLocalPicks = () => {
        const stored = window.localStorage.getItem(storageKey);
        if (!stored) {
            storageLoaded.value = true;
            return;
        }

        try {
            applyStoredPicks(JSON.parse(stored) as Record<string, string>);
        } catch {
            window.localStorage.removeItem(storageKey);
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
        );

        const bracket = response?.data;
        if (!bracket) {
            return null;
        }

        upsertBracketInList(bracket);
        activateBracket(bracket, syncPicks);

        return bracket;
    };

    const loadGroups = async () => {
        if (!isAuthenticated.value) {
            return;
        }

        const response = await api.groups.index<ApiResource<Group[]>>({
            query: {
                type: 'bracket_pool',
                sport: 'cbb',
                season: currentSeason.value,
            },
        });

        const groups = response?.data ?? [];
        availableGroups.value = groups;

        if (
            !bracketGroupIdDraft.value &&
            groups.length > 0 &&
            groups.some(
                (group: Group) => group.owner_id !== currentUserId.value,
            )
        ) {
            bracketGroupIdDraft.value = String(groups[0].id);
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
        metaSaveState.value = 'saving';

        try {
            const resolvedGroupId = bracketGroupIdDraft.value
                ? Number(bracketGroupIdDraft.value)
                : requiresGroupAssignment.value && availableGroups.value.length
                  ? availableGroups.value[0].id
                  : null;

            const response = await api.cbbBrackets.update<
                ApiResource<SavedBracket>
            >(currentBracket.value.public_id, {
                name: bracketNameDraft.value.trim() || null,
                group_id: resolvedGroupId,
            });

            const savedBracket = response?.data;
            if (!savedBracket) {
                metaSaveState.value = 'error';
                return;
            }

            upsertBracketInList(savedBracket);
            activateBracket(savedBracket, false);
            metaSaveState.value = 'saved';

            metaSaveTimer = window.setTimeout(() => {
                if (metaSaveState.value === 'saved') {
                    metaSaveState.value = 'idle';
                }
            }, 2000);
        } catch {
            metaSaveState.value = 'error';
        }
    };

    const switchBracket = (publicId: string) => {
        const bracket =
            brackets.value.find((entry) => entry.public_id === publicId) ??
            null;
        activateBracket(bracket);
    };

    const loadServerBrackets = async () => {
        if (!isAuthenticated.value) {
            serverLoaded.value = true;
            return;
        }

        try {
            const response = await api.cbbBrackets.index<
                ApiResource<SavedBracket[]>
            >({
                query: { season: currentSeason.value },
            });

            const serverBrackets = response?.data ?? [];
            brackets.value = serverBrackets;

            if (serverBrackets.length > 0) {
                const activeBracket =
                    serverBrackets.find(
                        (entry) =>
                            entry.public_id === activeBracketPublicId.value,
                    ) ?? serverBrackets[0];
                activateBracket(activeBracket);
            } else if (
                !isBracketLocked.value &&
                Object.keys(picks.value).length > 0
            ) {
                await createBracket(picks.value);
            } else {
                activateBracket(null);
            }
        } finally {
            serverLoaded.value = true;
        }
    };

    const loadLeaderboard = async () => {
        if (!isAuthenticated.value) {
            return;
        }

        const response = await api.cbbBrackets.leaderboard<
            ApiResource<LeaderboardEntry[]>
        >({
            query: {
                season: currentSeason.value,
                limit: 10,
                group_id: currentBracket.value?.group_id ?? undefined,
            },
        });

        leaderboard.value = response?.data ?? [];
    };

    onMounted(() => {
        readLocalPicks();
        void loadGroups();
        void loadServerBrackets();
        void loadLeaderboard();
    });

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
            window.localStorage.setItem(storageKey, JSON.stringify(value));
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

            saveState.value = 'saving';

            const timeoutId = window.setTimeout(async () => {
                try {
                    let savedBracket: SavedBracket;

                    if (activeBracketPublicId.value) {
                        const response = await api.cbbBrackets.update<
                            ApiResource<SavedBracket>
                        >(activeBracketPublicId.value, {
                            picks: value,
                        });

                        if (!response?.data) {
                            saveState.value = 'error';
                            return;
                        }

                        savedBracket = response.data;
                    } else {
                        const createdBracket = await createBracket(
                            value,
                            false,
                        );

                        if (!createdBracket) {
                            return;
                        }

                        savedBracket = createdBracket;
                    }

                    saveState.value = 'saved';
                    upsertBracketInList(savedBracket);
                    activateBracket(savedBracket, false);
                } catch (error: unknown) {
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
