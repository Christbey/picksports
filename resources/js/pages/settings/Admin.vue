<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { type BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Admin settings',
        href: '/settings/admin',
    },
];

const adminAreas = [
    {
        title: 'Subscriptions',
        description: 'Manage user plans and billing access.',
        href: '/admin/subscriptions',
    },
    {
        title: 'Tiers',
        description: 'Configure subscription tiers and feature flags.',
        href: '/admin/tiers',
    },
    {
        title: 'Permissions',
        description: 'Control role capabilities and overrides.',
        href: '/admin/permissions',
    },
    {
        title: 'Notification Templates',
        description: 'Edit alert template content and defaults.',
        href: '/admin/notification-templates',
    },
    {
        title: 'Healthchecks',
        description: 'Monitor heartbeat and data validation status.',
        href: '/admin/healthchecks',
    },
    {
        title: 'Team Mappings',
        description: 'Resolve odds provider and internal team mapping gaps.',
        href: '/settings/team-mappings',
    },
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

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div
                        v-for="area in adminAreas"
                        :key="area.href"
                        class="rounded-xl border border-sidebar-border bg-white p-5 dark:bg-sidebar"
                    >
                        <h3 class="text-sm font-semibold">{{ area.title }}</h3>
                        <p class="mt-2 text-sm text-muted-foreground">{{ area.description }}</p>
                        <Button as-child variant="outline" class="mt-4">
                            <Link :href="area.href">Open</Link>
                        </Button>
                    </div>
                </div>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
