<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { provide } from 'vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import type { BreadcrumbItem } from '@/types';
import type { AdminExportProps } from '@/types/admin-exports';
import {
    useAdminExports,
    adminExportsKey,
} from '@/composables/useAdminExports';
import AdminExportRecommendations from '@/components/admin/AdminExportRecommendations.vue';
import AdminExportPredictions from '@/components/admin/AdminExportPredictions.vue';
import AdminExportFutures from '@/components/admin/AdminExportFutures.vue';
import AdminExportTournament from '@/components/admin/AdminExportTournament.vue';
import AdminExportFilters from '@/components/admin/AdminExportFilters.vue';
const props = defineProps<AdminExportProps>();
const context = useAdminExports(props);
provide(adminExportsKey, context);
const { activeTab: currentTab, setTab, activeTabMeta } = context;
const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Settings', href: '/settings/profile' },
    { title: 'Exports', href: '/settings/prop-exports' },
];
</script>

<template>
    <Head title="Admin Exports" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <SettingsLayout :full-width="true">
            <div
                class="flex h-full flex-1 flex-col gap-6 overflow-x-auto rounded-xl p-4"
            >
                <div
                    class="rounded-xl border border-sidebar-border bg-background p-5 dark:bg-sidebar"
                >
                    <h1 class="text-2xl font-bold">Admin Exports</h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ activeTabMeta.description }}
                    </p>
                    <div class="mt-4 grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                        <Button
                            :variant="
                                currentTab === 'props' ? 'default' : 'outline'
                            "
                            class="h-auto justify-start px-3 py-2 text-left"
                            @click="setTab('props')"
                        >
                            <span class="font-semibold">Props</span>
                        </Button>
                        <Button
                            :variant="
                                currentTab === 'predictions'
                                    ? 'default'
                                    : 'outline'
                            "
                            class="h-auto justify-start px-3 py-2 text-left"
                            @click="setTab('predictions')"
                        >
                            <span class="font-semibold">Predictions</span>
                        </Button>
                        <Button
                            :variant="
                                currentTab === 'futures' ? 'default' : 'outline'
                            "
                            class="h-auto justify-start px-3 py-2 text-left"
                            @click="setTab('futures')"
                        >
                            <span class="font-semibold">Futures</span>
                        </Button>
                        <Button
                            :variant="
                                currentTab === 'tournament'
                                    ? 'default'
                                    : 'outline'
                            "
                            class="h-auto justify-start px-3 py-2 text-left"
                            @click="setTab('tournament')"
                        >
                            <span class="font-semibold">Tournament</span>
                        </Button>
                    </div>
                </div>

                <AdminExportFilters />

                <AdminExportRecommendations v-if="currentTab === 'props'" />

                <AdminExportPredictions v-if="currentTab === 'predictions'" />

                <AdminExportFutures v-if="currentTab === 'futures'" />

                <AdminExportTournament v-if="currentTab === 'tournament'" />
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
