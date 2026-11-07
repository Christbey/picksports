import { router } from '@inertiajs/vue3';
import { computed, ref, toRefs, inject, type InjectionKey } from 'vue';
import type { BreadcrumbItem } from '@/types';
import type { Mapping, TeamMappingsProps } from '@/types/team-mappings';

export function useTeamMappings(props: Readonly<Required<TeamMappingsProps>>) {
    const isPlayer = computed(() => props.entityType === 'player');
    const indexBase = computed(
        () =>
            props.indexBase ||
            (isPlayer.value
                ? '/settings/player-mappings'
                : '/settings/team-mappings'),
    );
    const mutationBase = computed(
        () =>
            props.mutationBase ||
            (isPlayer.value
                ? '/settings/player-mappings'
                : '/settings/team-mappings'),
    );
    const entityTitle = computed(() => (isPlayer.value ? 'Player' : 'Team'));
    const resolvedPageTitle = computed(
        () =>
            props.pageTitle ||
            `${currentSportLabel.value} ${props.externalSourceLabel} ${entityTitle.value} Mappings`,
    );
    const resolvedPageDescription = computed(
        () =>
            props.pageDescription ||
            `Manual matching view for ${entityTitle.value.toLowerCase()} names. ${props.stats.mapped} of ${props.stats.total} mapped (${mappingPercentage.value}%).`,
    );

    const breadcrumbItems = computed<BreadcrumbItem[]>(() => [
        {
            title: resolvedPageTitle.value,
            href: buildUrl(indexBase.value, {
                ...props.queryParams,
                sport: props.currentSport,
                filter: props.currentFilter,
            }),
        },
    ]);

    const searchQuery = ref('');
    const editingMappingId = ref<number | null>(null);
    const selectedEspnName = ref<string>('');
    const isSyncing = ref(false);

    const oddsName = (mapping: Mapping): string =>
        mapping.odds_api_player_name ??
        mapping.external_team_name ??
        mapping.odds_api_team_name ??
        '';
    const espnName = (mapping: Mapping): string | null =>
        mapping.espn_player_name ?? mapping.espn_team_name ?? null;
    const suggestedTeamName = (mapping: Mapping): string | null =>
        mapping.suggested_espn_team_name ?? null;
    const suggestedPlayerName = (mapping: Mapping): string | null =>
        mapping.suggested_espn_player_name ?? null;
    const suggestedScore = (mapping: Mapping): number | null =>
        mapping.suggested_match_quality_score ?? null;

    const filteredMappings = computed(() => {
        if (!searchQuery.value) {
            return props.mappings.data;
        }
        const query = searchQuery.value.toLowerCase();
        return props.mappings.data.filter(
            (m) =>
                oddsName(m).toLowerCase().includes(query) ||
                (espnName(m)?.toLowerCase().includes(query) ?? false),
        );
    });

    const currentSportLabel = computed(
        () =>
            props.sports.find((s) => s.key === props.currentSport)?.label ?? '',
    );

    const mappingPercentage = computed(() => {
        if (props.stats.total === 0) return 0;
        return Math.round((props.stats.mapped / props.stats.total) * 100);
    });

    const startEdit = (mapping: Mapping) => {
        editingMappingId.value = mapping.id;
        selectedEspnName.value = espnName(mapping) || '';
    };

    const cancelEdit = () => {
        editingMappingId.value = null;
        selectedEspnName.value = '';
    };

    const buildUrl = (
        base: string,
        params: Record<string, string | number | null | undefined> = {},
    ) => {
        const search = new URLSearchParams();

        Object.entries(params).forEach(([key, value]) => {
            if (value === null || value === undefined || value === '') {
                return;
            }

            search.set(key, String(value));
        });

        const query = search.toString();

        return query ? `${base}?${query}` : base;
    };

    const saveMapping = (mappingId: number) => {
        const payloadKey = isPlayer.value
            ? 'espn_player_name'
            : 'espn_team_name';
        const payload = isPlayer.value
            ? {
                  espn_player_name: selectedEspnName.value || null,
                  espn_player_id: null,
              }
            : {
                  [payloadKey]: selectedEspnName.value || null,
              };

        router.patch(
            buildUrl(`${mutationBase.value}/${mappingId}`, props.queryParams),
            payload,
            {
                preserveScroll: true,
                onSuccess: () => {
                    editingMappingId.value = null;
                    selectedEspnName.value = '';
                },
            },
        );
    };

    const removeMapping = (mappingId: number) => {
        router.delete(
            buildUrl(`${mutationBase.value}/${mappingId}`, props.queryParams),
            {
                preserveScroll: true,
            },
        );
    };

    const acceptSuggestedMapping = (mapping: Mapping) => {
        if (!isPlayer.value && mapping.suggested_espn_team_name) {
            router.patch(
                buildUrl(
                    `${mutationBase.value}/${mapping.id}`,
                    props.queryParams,
                ),
                {
                    espn_team_name: mapping.suggested_espn_team_name,
                },
                {
                    preserveScroll: true,
                },
            );

            return;
        }

        if (!isPlayer.value || !mapping.suggested_espn_player_name) {
            return;
        }

        router.patch(
            buildUrl(`${mutationBase.value}/${mapping.id}`, props.queryParams),
            {
                espn_player_name: mapping.suggested_espn_player_name,
                espn_player_id: mapping.suggested_player_id ?? null,
            },
            {
                preserveScroll: true,
            },
        );
    };

    const changeProvider = (providerKey: string) => {
        const nextSport =
            providerKey === 'cfbd'
                ? 'americanfootball_ncaaf'
                : props.currentSport;

        router.visit(
            buildUrl(indexBase.value, {
                provider: providerKey,
                sport: nextSport,
                filter: props.currentFilter,
            }),
        );
    };

    const changeSport = (sportKey: string) => {
        router.visit(
            buildUrl(indexBase.value, {
                ...props.queryParams,
                sport: sportKey,
                filter: props.currentFilter,
            }),
        );
    };

    const changeFilter = (filter: string) => {
        router.visit(
            buildUrl(indexBase.value, {
                ...props.queryParams,
                sport: props.currentSport,
                filter,
            }),
        );
    };

    const syncOddsApiMappings = () => {
        isSyncing.value = true;

        router.post(
            buildUrl(`${indexBase.value}/sync`, props.queryParams),
            {},
            {
                preserveScroll: true,
                onFinish: () => {
                    isSyncing.value = false;
                },
            },
        );
    };

    const changePage = (page: number) =>
        router.visit(
            buildUrl(indexBase.value, {
                ...props.queryParams,
                sport: props.currentSport,
                filter: props.currentFilter,
                page,
            }),
        );

    return {
        ...toRefs(props),
        changePage,
        isPlayer,
        indexBase,
        mutationBase,
        entityTitle,
        resolvedPageTitle,
        resolvedPageDescription,
        breadcrumbItems,
        searchQuery,
        editingMappingId,
        selectedEspnName,
        isSyncing,
        oddsName,
        espnName,
        suggestedTeamName,
        suggestedPlayerName,
        suggestedScore,
        filteredMappings,
        currentSportLabel,
        mappingPercentage,
        startEdit,
        cancelEdit,
        buildUrl,
        saveMapping,
        removeMapping,
        acceptSuggestedMapping,
        changeProvider,
        changeSport,
        changeFilter,
        syncOddsApiMappings,
    };
}
export const teamMappingsKey: InjectionKey<ReturnType<typeof useTeamMappings>> =
    Symbol('TeamMappings');
export function useTeamMappingsContext() {
    const state = inject(teamMappingsKey);
    if (!state) throw new Error('TeamMappings provider is missing.');
    return state;
}
