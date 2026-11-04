<script setup lang="ts">
import { provide, reactive } from 'vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import NflAllPropsBrowser from './NflAllPropsBrowser.vue';
import PlayerPropsFilters from './PlayerPropsFilters.vue';
import PlayerPropsRecommendations from './PlayerPropsRecommendations.vue';
import { usePlayerPropsBoard } from '@/composables/usePlayerPropsBoard';
import { playerPropsBoardKey } from '@/composables/usePlayerPropsBoardContext';
import type { PlayerPropsPageProps } from '@/types/player-props-board';
const props = defineProps<PlayerPropsPageProps>();
const board = reactive(usePlayerPropsBoard(props));
provide(playerPropsBoardKey, board);
</script>

<template>
    <AppLayout>
        <div class="mx-auto w-full max-w-7xl space-y-6 p-3 md:p-4">
            <div class="space-y-2">
                <p class="ui-kicker">Player Markets</p>
                <h1 class="text-3xl font-semibold tracking-tight">
                    {{ sportLabel }} Player Props
                </h1>
                <p class="text-muted-foreground">
                    {{
                        sport === 'NFL' && board.selectedView === 'all'
                            ? 'Every stored quote, including props without a recommendation.'
                            : sport === 'NFL'
                              ? 'Highest estimated hit chance first. Compare the history.'
                              : description
                    }}
                </p>
            </div>

            <div
                v-if="sport === 'NFL'"
                class="flex gap-2"
                aria-label="Prop view"
            >
                <Button
                    :variant="
                        board.selectedView === 'recommendations'
                            ? 'default'
                            : 'outline'
                    "
                    :aria-pressed="board.selectedView === 'recommendations'"
                    @click="board.switchView('recommendations')"
                    >Recommendations</Button
                >
                <Button
                    :variant="
                        board.selectedView === 'all' ? 'default' : 'outline'
                    "
                    :aria-pressed="board.selectedView === 'all'"
                    @click="board.switchView('all')"
                    >All props</Button
                >
            </div>
            <PlayerPropsFilters :sport="sport" />
            <PlayerPropsRecommendations :sport="sport" />

            <p
                v-if="board.selectedView === 'all' && board.error"
                role="alert"
                class="text-sm text-amber-700 dark:text-amber-300"
            >
                {{ board.error }}
            </p>
            <NflAllPropsBrowser
                v-if="board.selectedView === 'all'"
                :enabled="board.filtersReady"
                :date="board.selectedDate"
                :game="board.selectedGame"
                :market="board.selectedMarket"
            />
        </div>
    </AppLayout>
</template>
