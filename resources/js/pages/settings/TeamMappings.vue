<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { provide } from 'vue';
import Heading from '@/components/Heading.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import type { TeamMappingsProps } from '@/types/team-mappings';
import {
    useTeamMappings,
    teamMappingsKey,
} from '@/composables/useTeamMappings';
import TeamMappingSync from '@/components/settings/TeamMappingSync.vue';
import TeamMappingProviders from '@/components/settings/TeamMappingProviders.vue';
import TeamMappingSummary from '@/components/settings/TeamMappingSummary.vue';
import TeamMappingSports from '@/components/settings/TeamMappingSports.vue';
import TeamMappingFilters from '@/components/settings/TeamMappingFilters.vue';
import TeamMappingSearch from '@/components/settings/TeamMappingSearch.vue';
import TeamMappingResults from '@/components/settings/TeamMappingResults.vue';
import TeamMappingPagination from '@/components/settings/TeamMappingPagination.vue';
const props = withDefaults(defineProps<TeamMappingsProps>(), {
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

const state = useTeamMappings(props);
provide(teamMappingsKey, state);
const {
    entityTitle,
    resolvedPageTitle,
    resolvedPageDescription,
    breadcrumbItems,
} = state;
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

                <TeamMappingSync />

                <TeamMappingProviders />

                <TeamMappingSummary />

                <TeamMappingSports />

                <TeamMappingFilters />

                <TeamMappingSearch />

                <TeamMappingResults />

                <TeamMappingPagination />
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
