<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { provide } from 'vue';
import Heading from '@/components/Heading.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import {
    useAdminSettings,
    adminSettingsKey,
} from '@/composables/useAdminSettings';
import type { AdminSettingsProps } from '@/types/admin-settings';
import type { BreadcrumbItem } from '@/types';
import AdminBracketGroups from '@/components/admin/AdminBracketGroups.vue';
import AdminFoundingUsers from '@/components/admin/AdminFoundingUsers.vue';
import AdminSettingsNavigation from '@/components/admin/AdminSettingsNavigation.vue';
const props = defineProps<AdminSettingsProps>();
provide(adminSettingsKey, useAdminSettings(props));
const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Admin settings', href: '/settings/admin' },
];
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Admin settings" />

        <h1 class="sr-only">Admin Settings</h1>

        <SettingsLayout>
            <div class="flex flex-col space-y-6">
                <Heading
                    variant="small"
                    title="Admin Settings"
                    description="Use this panel to access all admin configuration areas"
                />

                <AdminBracketGroups />

                <AdminFoundingUsers />

                <AdminSettingsNavigation />
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
