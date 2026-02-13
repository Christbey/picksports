<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
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
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Admin settings" />

        <h1 class="sr-only">Admin Settings</h1>

        <SettingsLayout>
            <div class="flex flex-col space-y-6">
                <Heading
                    variant="small"
                    title="Admin"
                    description="Manage administrative features and settings"
                />

                <div class="space-y-6">
                    <!-- Subscription Management -->
                    <div class="rounded-xl border border-sidebar-border bg-white p-6 dark:bg-sidebar">
                        <h3 class="mb-4 text-sm font-semibold">Subscription Management</h3>

                        <div class="space-y-3">
                            <p class="text-sm text-muted-foreground">
                                View and manage user subscriptions, sync subscription data with Stripe.
                            </p>

                            <Button
                                as-child
                                class="w-full"
                                variant="default"
                            >
                                <a href="/admin/subscriptions">
                                    View All Subscriptions
                                </a>
                            </Button>
                        </div>
                    </div>

                    <!-- Tier Management -->
                    <div class="rounded-xl border border-sidebar-border bg-white p-6 dark:bg-sidebar">
                        <h3 class="mb-4 text-sm font-semibold">Tier Management</h3>

                        <div class="space-y-3">
                            <p class="text-sm text-muted-foreground">
                                Create, edit, and manage subscription tiers and their features.
                            </p>

                            <Button
                                as-child
                                class="w-full"
                                variant="default"
                            >
                                <a href="/admin/tiers">
                                    Manage Tiers
                                </a>
                            </Button>
                        </div>
                    </div>

                    <!-- Permission Management -->
                    <div class="rounded-xl border border-sidebar-border bg-white p-6 dark:bg-sidebar">
                        <h3 class="mb-4 text-sm font-semibold">Permission Management</h3>

                        <div class="space-y-3">
                            <p class="text-sm text-muted-foreground">
                                View and manage roles, permissions, and user access control.
                            </p>

                            <Button
                                as-child
                                class="w-full"
                                variant="default"
                            >
                                <a href="/admin/permissions">
                                    Manage Permissions
                                </a>
                            </Button>
                        </div>
                    </div>

                    <!-- Health Checks -->
                    <div class="rounded-xl border border-sidebar-border bg-white p-6 dark:bg-sidebar">
                        <h3 class="mb-4 text-sm font-semibold">Health Checks</h3>

                        <div class="space-y-3">
                            <p class="text-sm text-muted-foreground">
                                Monitor system health and data sync status across all sports.
                            </p>

                            <Button
                                as-child
                                class="w-full"
                                variant="default"
                            >
                                <a href="/admin/healthchecks">
                                    View Health Checks
                                </a>
                            </Button>
                        </div>
                    </div>
                </div>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
