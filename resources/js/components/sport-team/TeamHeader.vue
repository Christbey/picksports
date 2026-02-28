<script setup lang="ts">
import type { TeamPageConfig } from '@/types';

defineProps<{
    config: TeamPageConfig;
    teamData: any;
    headerInfoItems: Array<{ label: string; value: string }>;
}>();
</script>

<template>
    <div class="flex items-start gap-4">
        <img
            v-if="config.teamLogo(teamData)"
            :src="config.teamLogo(teamData)!"
            :alt="config.teamDisplayName(teamData)"
            class="w-20 h-20 object-contain"
        />
        <div class="flex-1">
            <h1 class="text-3xl font-bold">{{ config.teamDisplayName(teamData) }}</h1>
            <p class="text-muted-foreground">{{ config.teamSubtitle(teamData) }}</p>
            <div v-if="headerInfoItems.length > 0" class="mt-2 flex items-center gap-4">
                <div v-for="item in headerInfoItems" :key="item.label" class="text-sm font-medium">
                    {{ item.label }}: {{ item.value }}
                </div>
            </div>
        </div>
    </div>
</template>
