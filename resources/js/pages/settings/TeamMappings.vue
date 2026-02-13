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
};

type Mapping = {
    id: number;
    espn_team_name: string | null;
    odds_api_team_name: string;
    sport: string;
};

type Sport = {
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
    sports: Sport[];
};

const props = defineProps<Props>();

const breadcrumbItems: BreadcrumbItem[] = [
    {
        title: 'Team Mappings',
        href: '/settings/team-mappings',
    },
];

const searchQuery = ref('');
const editingMappingId = ref<number | null>(null);
const selectedEspnTeam = ref<string>('');

const filteredMappings = computed(() => {
    if (!searchQuery.value) {
        return props.mappings.data;
    }
    const query = searchQuery.value.toLowerCase();
    return props.mappings.data.filter(
        (m) =>
            m.odds_api_team_name.toLowerCase().includes(query) ||
            m.espn_team_name?.toLowerCase().includes(query)
    );
});

const unmappedCount = computed(() => {
    return props.mappings.data.filter((m) => !m.espn_team_name).length;
});

const currentSportLabel = computed(() => {
    return props.sports.find((s) => s.key === props.currentSport)?.label ?? '';
});

const startEdit = (mapping: Mapping) => {
    editingMappingId.value = mapping.id;
    selectedEspnTeam.value = mapping.espn_team_name || '';
};

const cancelEdit = () => {
    editingMappingId.value = null;
    selectedEspnTeam.value = '';
};

const saveMapping = (mappingId: number) => {
    router.patch(
        `/settings/team-mappings/${mappingId}`,
        {
            espn_team_name: selectedEspnTeam.value || null,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                editingMappingId.value = null;
                selectedEspnTeam.value = '';
            },
        }
    );
};

const removeMapping = (mappingId: number) => {
    router.delete(`/settings/team-mappings/${mappingId}`, {
        preserveScroll: true,
    });
};

const changeSport = (sportKey: string) => {
    router.visit(`/settings/team-mappings?sport=${sportKey}`);
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head title="Team Mappings" />

        <h1 class="sr-only">Team Mappings</h1>

        <SettingsLayout>
            <div class="flex flex-col space-y-6">
                <Heading
                    variant="small"
                    :title="`${currentSportLabel} Odds API Team Mappings`"
                    :description="`Map ESPN team names to Odds API team names for accurate odds matching. ${unmappedCount} unmapped teams.`"
                />

                <div class="flex flex-wrap gap-2">
                    <Button
                        v-for="sport in sports"
                        :key="sport.key"
                        :variant="
                            sport.key === currentSport ? 'default' : 'outline'
                        "
                        size="sm"
                        @click="changeSport(sport.key)"
                    >
                        {{ sport.label }}
                    </Button>
                </div>

                <div class="grid gap-2">
                    <Label for="search">Search</Label>
                    <Input
                        id="search"
                        v-model="searchQuery"
                        placeholder="Search by team name..."
                        class="w-full"
                    />
                </div>

                <div class="space-y-2">
                    <div
                        v-for="mapping in filteredMappings"
                        :key="mapping.id"
                        class="rounded-lg border p-4"
                        :class="{
                            'bg-muted': !mapping.espn_team_name,
                        }"
                    >
                        <div class="flex items-start justify-between gap-4">
                            <div class="flex-1 space-y-2">
                                <div class="font-medium">
                                    {{ mapping.odds_api_team_name }}
                                </div>
                                <div
                                    v-if="editingMappingId === mapping.id"
                                    class="space-y-2"
                                >
                                    <Label :for="`espn-${mapping.id}`"
                                        >ESPN Team</Label
                                    >
                                    <select
                                        :id="`espn-${mapping.id}`"
                                        v-model="selectedEspnTeam"
                                        class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm transition-colors placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50"
                                    >
                                        <option value="">
                                            -- Select ESPN Team --
                                        </option>
                                        <option
                                            v-for="team in espnTeams"
                                            :key="team.id"
                                            :value="team.name"
                                        >
                                            {{ team.name }} ({{
                                                team.abbreviation
                                            }})
                                        </option>
                                    </select>
                                    <div class="flex gap-2">
                                        <Button
                                            size="sm"
                                            @click="saveMapping(mapping.id)"
                                        >
                                            Save
                                        </Button>
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            @click="cancelEdit"
                                        >
                                            Cancel
                                        </Button>
                                    </div>
                                </div>
                                <div
                                    v-else
                                    class="text-sm text-muted-foreground"
                                >
                                    <span v-if="mapping.espn_team_name"
                                        >→ {{ mapping.espn_team_name }}</span
                                    >
                                    <span v-else class="italic"
                                        >Not mapped</span
                                    >
                                </div>
                            </div>
                            <div
                                v-if="editingMappingId !== mapping.id"
                                class="flex gap-2"
                            >
                                <Button
                                    size="sm"
                                    variant="outline"
                                    @click="startEdit(mapping)"
                                >
                                    {{ mapping.espn_team_name ? 'Edit' : 'Map' }}
                                </Button>
                                <Button
                                    v-if="mapping.espn_team_name"
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

                <div
                    v-if="mappings.last_page > 1"
                    class="flex justify-between items-center"
                >
                    <div class="text-sm text-muted-foreground">
                        Page {{ mappings.current_page }} of
                        {{ mappings.last_page }}
                    </div>
                    <div class="flex gap-2">
                        <Button
                            :disabled="mappings.current_page === 1"
                            variant="outline"
                            size="sm"
                            @click="
                                router.visit(
                                    `/settings/team-mappings?sport=${currentSport}&page=${mappings.current_page - 1}`
                                )
                            "
                        >
                            Previous
                        </Button>
                        <Button
                            :disabled="
                                mappings.current_page === mappings.last_page
                            "
                            variant="outline"
                            size="sm"
                            @click="
                                router.visit(
                                    `/settings/team-mappings?sport=${currentSport}&page=${mappings.current_page + 1}`
                                )
                            "
                        >
                            Next
                        </Button>
                    </div>
                </div>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
