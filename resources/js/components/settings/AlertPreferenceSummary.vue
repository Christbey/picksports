<script setup lang="ts">
import { useAlertPreferencesContext } from '@/composables/useAlertPreferences';
const {
    alertsEnabled,
    dailyDigestSubscribed,
    selectedNotificationLabels,
    digestModeLabel,
} = useAlertPreferencesContext();
</script>
<template>
    <div
        class="rounded-xl border border-sidebar-border bg-sidebar-accent/40 p-4"
    >
        <p class="text-sm font-medium">Current Setup</p>
        <div class="mt-2 flex flex-wrap gap-2 text-xs">
            <span class="rounded bg-white px-2 py-1 dark:bg-sidebar"
                >Alerts: {{ alertsEnabled ? 'On' : 'Off' }}</span
            >
            <span class="rounded bg-white px-2 py-1 dark:bg-sidebar"
                >Channels:
                {{ selectedNotificationLabels.join(', ') || 'None' }}</span
            >
            <span class="rounded bg-white px-2 py-1 dark:bg-sidebar"
                >Delivery: {{ digestModeLabel }}</span
            >
            <span class="rounded bg-white px-2 py-1 dark:bg-sidebar"
                >Daily Digest:
                {{
                    dailyDigestSubscribed ? 'Subscribed' : 'Unsubscribed'
                }}</span
            >
        </div>
    </div>
</template>
