<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { provide } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import DataRecovery from '@/components/DataRecovery.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { useMyBets, myBetsKey } from '@/composables/useMyBets';
import MyBetsStatistics from '@/components/my-bets/MyBetsStatistics.vue';
import MyBetsActions from '@/components/my-bets/MyBetsActions.vue';
import MyBetsEntryForm from '@/components/my-bets/MyBetsEntryForm.vue';
import MyBetsHistory from '@/components/my-bets/MyBetsHistory.vue';
const state = useMyBets();
provide(myBetsKey, state);
const { breadcrumbs, error, loading, hasLoaded, fetchBets, mutationError } =
    state;
</script>
<template>
    <Head title="My Bets" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div
            class="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4"
        >
            <DataRecovery
                v-if="error"
                :message="error"
                :busy="loading"
                :stale="hasLoaded"
                @retry="fetchBets"
            />
            <Alert v-if="mutationError" variant="destructive"
                ><AlertDescription>{{ mutationError }}</AlertDescription></Alert
            >
            <!-- Statistics Cards -->
            <MyBetsStatistics />

            <!-- Actions Bar -->
            <MyBetsActions />

            <!-- Add Bet Form -->
            <MyBetsEntryForm />

            <!-- Bets List -->
            <MyBetsHistory />
        </div>
    </AppLayout>
</template>
