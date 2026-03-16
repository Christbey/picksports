<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { type BreadcrumbItem } from '@/types';

type Team = {
    id: number;
    name: string;
    abbreviation: string;
    mascot?: string | null;
};

type Mapping = {
    id: number;
    external_team_name?: string | null;
    external_team_abbreviation?: string | null;
    external_team_id?: number | null;
    espn_team_name?: string | null;
    suggested_espn_team_name?: string | null;
    odds_api_team_name?: string;
    espn_player_name?: string | null;
    espn_player_id?: number | null;
    odds_api_player_name?: string;
    suggested_espn_player_name?: string | null;
    suggested_player_id?: number | null;
    suggested_match_quality_score?: number | null;
    sport: string;
};

type Sport = {
    key: string;
    label: string;
};

type Provider = {
    key: string;
    label: string;
};

type Props = {
    mappings: {
        data: Mapping[];
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
    espnTeams: Team[];
    currentSport: string;
    currentFilter: string;
    currentProvider?: string;
    entityType?: 'team' | 'player';
    indexBase?: string;
    mutationBase?: string;
    queryParams?: Record<string, string>;
    pageTitle?: string;
    pageDescription?: string;
    externalSourceLabel?: string;
    emptyStateCommand?: string | null;
    stats: {
        total: number;
        mapped: number;
        unmapped: number;
    };
    sports: Sport[];
    providers?: Provider[];
};

const props = withDefaults(defineProps<Props>(), {
    entityType: 'team',
    currentProvider: '',
    indexBase: '',
    mutationBase: '',
    queryParams: () => ({}),
    pageTitle: '',
    pageDescription: '',
    externalSourceLabel: 'Odds API',
    emptyStateCommand: null,
    providers: () => [],
});

const isPlayer = computed(() => props.entityType === 'player');
const indexBase = computed(() => props.indexBase || (isPlayer.value ? '/settings/player-mappings' : '/settings/team-mappings'));
const mutationBase = computed(() => props.mutationBase || (isPlayer.value ? '/settings/player-mappings' : '/settings/team-mappings'));
const entityTitle = computed(() => isPlayer.value ? 'Player' : 'Team');
const resolvedPageTitle = computed(() => props.pageTitle || `${currentSportLabel.value} ${props.externalSourceLabel} ${entityTitle.value} Mappings`);
const resolvedPageDescription = computed(() =>
    props.pageDescription || `Manual matching view for ${entityTitle.value.toLowerCase()} names. ${props.stats.mapped} of ${props.stats.total} mapped (${mappingPercentage.value}%).`
);

const breadcrumbItems = computed<BreadcrumbItem[]>(() => [{
    title: resolvedPageTitle.value,
    href: buildUrl(indexBase.value, {
        ...props.queryParams,
        sport: props.currentSport,
        filter: props.currentFilter,
    }),
}]);

const searchQuery = ref('');
const editingMappingId = ref<number | null>(null);
const selectedEspnName = ref<string>('');
const isSyncing = ref(false);

const oddsName = (mapping: Mapping): string => mapping.odds_api_player_name ?? mapping.external_team_name ?? mapping.odds_api_team_name ?? '';
const espnName = (mapping: Mapping): string | null => mapping.espn_player_name ?? mapping.espn_team_name ?? null;
const suggestedTeamName = (mapping: Mapping): string | null => mapping.suggested_espn_team_name ?? null;
const suggestedPlayerName = (mapping: Mapping): string | null => mapping.suggested_espn_player_name ?? null;
const suggestedScore = (mapping: Mapping): number | null => mapping.suggested_match_quality_score ?? null;

const filteredMappings = computed(() => {
    if (!searchQuery.value) {
        return props.mappings.data;
    }
    const query = searchQuery.value.toLowerCase();
    return props.mappings.data.filter((m) =>
        oddsName(m).toLowerCase().includes(query) || (espnName(m)?.toLowerCase().includes(query) ?? false)
    );
});

const currentSportLabel = computed(() => props.sports.find((s) => s.key === props.currentSport)?.label ?? '');

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

const buildUrl = (base: string, params: Record<string, string | number | null | undefined> = {}) => {
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
    const payloadKey = isPlayer.value ? 'espn_player_name' : 'espn_team_name';
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
        }
    );
};

const removeMapping = (mappingId: number) => {
    router.delete(buildUrl(`${mutationBase.value}/${mappingId}`, props.queryParams), {
        preserveScroll: true,
    });
};

const acceptSuggestedMapping = (mapping: Mapping) => {
    if (!isPlayer.value && mapping.suggested_espn_team_name) {
        router.patch(
            buildUrl(`${mutationBase.value}/${mapping.id}`, props.queryParams),
            {
                espn_team_name: mapping.suggested_espn_team_name,
            },
            {
                preserveScroll: true,
            }
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
        }
    );
};

const changeProvider = (providerKey: string) => {
    const nextSport = providerKey === 'cfbd' ? 'americanfootball_ncaaf' : props.currentSport;

    router.visit(buildUrl(indexBase.value, {
        provider: providerKey,
        sport: nextSport,
        filter: props.currentFilter,
    }));
};

const changeSport = (sportKey: string) => {
    router.visit(buildUrl(indexBase.value, {
        ...props.queryParams,
        sport: sportKey,
        filter: props.currentFilter,
    }));
};

const changeFilter = (filter: string) => {
    router.visit(buildUrl(indexBase.value, {
        ...props.queryParams,
        sport: props.currentSport,
        filter,
    }));
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
        }
    );
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head :title="resolvedPageTitle" />

        <h1 class="sr-only">{{ entityTitle }} Mappings</h1>

        <SettingsLayout>
            <div class="flex flex-col space-y-6">
                <Heading
                    variant="small"
                    :title="resolvedPageTitle"
                    :description="resolvedPageDescription"
                />

                <div v-if="!isPlayer && currentProvider === 'odds'" class="flex justify-end">
                    <Button :disabled="isSyncing" @click="syncOddsApiMappings">
                        {{ isSyncing ? 'Syncing...' : 'Sync Odds API Teams' }}
                    </Button>
                </div>

                <div v-if="!isPlayer && providers.length > 1">
                    <div class="text-sm font-medium mb-2">Provider</div>
                    <div class="flex flex-wrap gap-2">
                        <Button
                            v-for="provider in providers"
                            :key="provider.key"
                            :variant="provider.key === currentProvider ? 'default' : 'outline'"
                            size="sm"
                            @click="changeProvider(provider.key)"
                        >
                            {{ provider.label }}
                        </Button>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                    <div class="rounded-lg border p-4">
                        <div class="text-2xl font-bold">{{ stats.total }}</div>
                        <div class="text-sm text-muted-foreground">Total {{ entityTitle }}s</div>
                    </div>
                    <div class="rounded-lg border p-4 bg-green-50 dark:bg-green-950">
                        <div class="text-2xl font-bold text-green-700 dark:text-green-300">{{ stats.mapped }}</div>
                        <div class="text-sm text-green-600 dark:text-green-400">Mapped ({{ mappingPercentage }}%)</div>
                    </div>
                    <div class="rounded-lg border p-4 bg-yellow-50 dark:bg-yellow-950">
                        <div class="text-2xl font-bold text-yellow-700 dark:text-yellow-300">{{ stats.unmapped }}</div>
                        <div class="text-sm text-yellow-600 dark:text-yellow-400">Unmapped</div>
                    </div>
                    <div class="rounded-lg border p-4">
                        <div class="text-2xl font-bold">{{ mappings.total }}</div>
                        <div class="text-sm text-muted-foreground">Showing</div>
                    </div>
                </div>

                <div>
                    <div class="text-sm font-medium mb-2">Sport</div>
                    <div class="flex flex-wrap gap-2">
                        <Button
                            v-for="sport in sports"
                            :key="sport.key"
                            :variant="sport.key === currentSport ? 'default' : 'outline'"
                            size="sm"
                            @click="changeSport(sport.key)"
                        >
                            {{ sport.label }}
                        </Button>
                    </div>
                </div>

                <div>
                    <div class="text-sm font-medium mb-2">Filter</div>
                    <div class="flex flex-wrap gap-2">
                        <Button :variant="currentFilter === 'all' ? 'default' : 'outline'" size="sm" @click="changeFilter('all')">
                            All ({{ stats.total }})
                        </Button>
                        <Button :variant="currentFilter === 'mapped' ? 'default' : 'outline'" size="sm" @click="changeFilter('mapped')">
                            Mapped ({{ stats.mapped }})
                        </Button>
                        <Button :variant="currentFilter === 'unmapped' ? 'default' : 'outline'" size="sm" @click="changeFilter('unmapped')">
                            Unmapped ({{ stats.unmapped }})
                        </Button>
                    </div>
                </div>

                <div class="grid gap-2">
                    <Label for="search">Search</Label>
                    <Input id="search" v-model="searchQuery" :placeholder="`Search by ${entityTitle.toLowerCase()} name...`" class="w-full" />
                </div>

                <div v-if="stats.total === 0" class="rounded-lg border border-dashed p-8 text-center">
                    <div class="text-lg font-medium mb-2">No {{ entityTitle.toLowerCase() }} mappings found</div>
                    <div class="text-sm text-muted-foreground mb-4" v-if="!isPlayer">
                        Run the populate command to fetch teams from {{ externalSourceLabel }}
                    </div>
                    <div class="text-sm text-muted-foreground mb-4" v-else>
                        Unmatched player names are captured automatically during sync and analysis.
                    </div>
                    <code v-if="!isPlayer && emptyStateCommand" class="text-sm bg-muted px-3 py-1 rounded">
                        {{ emptyStateCommand }}
                    </code>
                </div>

                <div v-else class="space-y-2">
                    <div
                        v-for="mapping in filteredMappings"
                        :key="mapping.id"
                        class="rounded-lg border p-4"
                        :class="{
                            'bg-green-50 dark:bg-green-950/20 border-green-200 dark:border-green-900': espnName(mapping),
                            'bg-yellow-50 dark:bg-yellow-950/20 border-yellow-200 dark:border-yellow-900': !espnName(mapping),
                        }"
                    >
                        <div class="flex items-start justify-between gap-4">
                            <div class="flex-1 space-y-2">
                                <div class="flex items-center gap-2">
                                    <div class="font-medium">{{ oddsName(mapping) }}</div>
                                    <span
                                        v-if="espnName(mapping)"
                                        class="inline-flex items-center rounded-full px-2 py-1 text-xs font-medium bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300"
                                    >
                                        Mapped
                                    </span>
                                    <span
                                        v-else
                                        class="inline-flex items-center rounded-full px-2 py-1 text-xs font-medium bg-yellow-100 text-yellow-700 dark:bg-yellow-900 dark:text-yellow-300"
                                    >
                                        Unmapped
                                    </span>
                                </div>
                                <div v-if="editingMappingId === mapping.id" class="space-y-2">
                                    <Label :for="`espn-${mapping.id}`">ESPN {{ entityTitle }}</Label>
                                    <select
                                        v-if="!isPlayer"
                                        :id="`espn-${mapping.id}`"
                                        v-model="selectedEspnName"
                                        class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm transition-colors placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50"
                                    >
                                        <option value="">-- Select ESPN Team --</option>
                                        <option v-for="team in espnTeams" :key="team.id" :value="team.name">
                                            {{ team.name }} ({{ team.abbreviation }})
                                        </option>
                                    </select>
                                    <Input
                                        v-else
                                        :id="`espn-${mapping.id}`"
                                        v-model="selectedEspnName"
                                        placeholder="Exact ESPN player name"
                                    />
                                    <div class="flex gap-2">
                                        <Button size="sm" @click="saveMapping(mapping.id)">Save</Button>
                                        <Button size="sm" variant="outline" @click="cancelEdit">Cancel</Button>
                                    </div>
                                </div>
                                <div
                                    v-else
                                    class="space-y-1 text-sm"
                                    :class="{
                                        'text-green-700 dark:text-green-300': espnName(mapping),
                                        'text-muted-foreground': !espnName(mapping),
                                    }"
                                >
                                    <span v-if="espnName(mapping)">→ ESPN: {{ espnName(mapping) }}</span>
                                    <span v-else class="italic">No ESPN {{ entityTitle.toLowerCase() }} mapped</span>
                                    <div v-if="!isPlayer && !espnName(mapping) && suggestedTeamName(mapping)" class="text-amber-700 dark:text-amber-300">
                                        Suggested: {{ suggestedTeamName(mapping) }}
                                        <span v-if="suggestedScore(mapping) !== null">({{ suggestedScore(mapping) }}%)</span>
                                    </div>
                                    <div v-if="isPlayer && !espnName(mapping) && suggestedPlayerName(mapping)" class="text-amber-700 dark:text-amber-300">
                                        Suggested: {{ suggestedPlayerName(mapping) }}
                                        <span v-if="suggestedScore(mapping) !== null">({{ suggestedScore(mapping) }}%)</span>
                                    </div>
                                </div>
                            </div>
                            <div v-if="editingMappingId !== mapping.id" class="flex gap-2">
                                <Button
                                    v-if="!isPlayer && !espnName(mapping) && suggestedTeamName(mapping)"
                                    size="sm"
                                    @click="acceptSuggestedMapping(mapping)"
                                >
                                    Accept Suggestion
                                </Button>
                                <Button
                                    v-if="isPlayer && !espnName(mapping) && suggestedPlayerName(mapping)"
                                    size="sm"
                                    @click="acceptSuggestedMapping(mapping)"
                                >
                                    Accept Suggestion
                                </Button>
                                <Button size="sm" variant="outline" @click="startEdit(mapping)">
                                    {{ espnName(mapping) ? 'Edit' : 'Map' }}
                                </Button>
                                <Button
                                    v-if="espnName(mapping)"
                                    size="sm"
                                    variant="destructive"
                                    @click="removeMapping(mapping.id)"
                                >
                                    Remove
                                </Button>
                            </div>
                        </div>
                    </div>
                </div>

                <div v-if="mappings.last_page > 1" class="flex justify-between items-center">
                    <div class="text-sm text-muted-foreground">Page {{ mappings.current_page }} of {{ mappings.last_page }}</div>
                    <div class="flex gap-2">
                        <Button
                            :disabled="mappings.current_page === 1"
                            variant="outline"
                            size="sm"
                            @click="router.visit(buildUrl(indexBase, { ...queryParams, sport: currentSport, filter: currentFilter, page: mappings.current_page - 1 }))"
                        >
                            Previous
                        </Button>
                        <Button
                            :disabled="mappings.current_page === mappings.last_page"
                            variant="outline"
                            size="sm"
                            @click="router.visit(buildUrl(indexBase, { ...queryParams, sport: currentSport, filter: currentFilter, page: mappings.current_page + 1 }))"
                        >
                            Next
                        </Button>
                    </div>
                </div>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
