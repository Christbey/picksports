<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Skeleton } from '@/components/ui/skeleton';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';

defineProps<{
    title: string;
    breadcrumbs: BreadcrumbItem[];
    loading: boolean;
    error?: string | null;
}>();
</script>

<template>
    <Head :title="title" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-4 p-4">
            <Alert v-if="error" variant="destructive">
                <AlertDescription>{{ error }}</AlertDescription>
            </Alert>

            <template v-if="loading">
                <slot name="loading">
                    <div class="space-y-4">
                        <Skeleton class="h-32 w-full" />
                        <Skeleton class="h-64 w-full" />
                        <Skeleton class="h-64 w-full" />
                    </div>
                </slot>
            </template>

            <slot v-else />
        </div>
    </AppLayout>
</template>
