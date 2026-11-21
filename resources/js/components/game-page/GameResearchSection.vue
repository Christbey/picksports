<script setup lang="ts">
import { ref } from 'vue';
import GameResearchPanel from '@/components/game-page/GameResearchPanel.vue';
import { useViewerAccess } from '@/composables/useViewerAccess';

defineProps<{ sport: string; gameId: number }>();
const { isAdmin } = useViewerAccess();
const opened = ref(false);
function onToggle(event: Event) {
    if ((event.target as HTMLDetailsElement).open) opened.value = true;
}
</script>

<template>
    <details
        v-if="isAdmin"
        class="min-w-0 rounded-xl border bg-card p-4 text-card-foreground sm:p-6"
        @toggle="onToggle"
    >
        <summary class="cursor-pointer text-lg font-semibold">
            Matchup research
        </summary>
        <GameResearchPanel v-if="opened" :sport="sport" :game-id="gameId" />
    </details>
</template>
