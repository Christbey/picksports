<script setup lang="ts">
import SportPlayerShell from '@/components/player-page/SportPlayerShell.vue';
import { nbaPlayerPageConfig } from '@/config/player-page-configs';

defineProps<{ playerId: number }>();
</script>

<template>
    <SportPlayerShell :config="nbaPlayerPageConfig" :player-id="playerId">
        <template #afterHeader="{ player }">
            <div class="rounded-2xl border bg-white/95 p-4 shadow-sm dark:border-zinc-800 dark:bg-zinc-900/90">
                <h3 class="text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Injury Status</h3>
                <ul v-if="player.active_injuries && player.active_injuries.length > 0" class="mt-3 space-y-2">
                    <li
                        v-for="injury in player.active_injuries"
                        :key="injury.id"
                        class="rounded border border-zinc-200 p-3 dark:border-zinc-700"
                    >
                        <p class="text-sm font-medium text-zinc-800 dark:text-zinc-100">
                            {{ injury.status || 'Injury' }}
                        </p>
                        <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">
                            {{ injury.detail || injury.type || 'No detail provided' }}
                        </p>
                        <p v-if="injury.return_date" class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                            Expected return: {{ injury.return_date }}
                        </p>
                    </li>
                </ul>
                <p v-else class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">No active injuries listed.</p>
            </div>
        </template>
    </SportPlayerShell>
</template>
