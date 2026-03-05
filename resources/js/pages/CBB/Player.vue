<script setup lang="ts">
import SportPlayerShell from '@/components/player-page/SportPlayerShell.vue';
import { cbbPlayerPageConfig } from '@/config/player-page-configs';

interface PlayerPagePlayer {
    id: number;
    team_id: number;
    first_name: string;
    last_name: string;
    full_name: string;
    name: string;
    jersey_number: string | null;
    position: string | null;
    height: string | null;
    weight: string | null;
    headshot_url: string | null;
    active_injuries_count?: number;
    active_injuries?: Array<{
        id: number;
        status: string | null;
        detail: string | null;
        type: string | null;
        return_date: string | null;
        source_updated_at: string | null;
    }>;
    team: {
        id: number;
        name: string;
        display_name: string;
        abbreviation: string;
    } | null;
}

interface PlayerPageProp {
    id: number;
    market: string;
    line: number;
    over_price: number;
    under_price: number;
    game: {
        id: number;
        home_team: string;
        away_team: string;
    };
}

defineProps<{
    player: PlayerPagePlayer;
    playerProps?: PlayerPageProp[];
}>();
</script>

<template>
    <SportPlayerShell :config="cbbPlayerPageConfig" :player="player" :player-props="playerProps">
        <template #afterHeader>
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
