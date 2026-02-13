<script setup lang="ts">
import Progress from '@/components/ui/progress/Progress.vue';

defineProps<{
    percentage: number;
    currentStep?: string | null;
}>();
</script>

<template>
    <div class="w-full space-y-2">
        <div class="flex items-center justify-between text-sm">
            <span class="font-medium">Onboarding Progress</span>
            <span class="text-muted-foreground">{{ percentage }}%</span>
        </div>
        <Progress :value="percentage" :max="100" />
        <p v-if="currentStep" class="text-xs text-muted-foreground">
            Current step: {{ currentStep }}
        </p>
    </div>
</template>
