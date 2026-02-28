<script setup lang="ts">
import { onErrorCaptured, ref } from 'vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';

withDefaults(
    defineProps<{
        title?: string;
        fallbackMessage?: string;
    }>(),
    {
        title: 'Rendering Error',
        fallbackMessage: 'An unexpected rendering error occurred.',
    },
);

const capturedError = ref<string | null>(null);

onErrorCaptured((error) => {
    capturedError.value =
        error instanceof Error ? error.message : 'An unexpected rendering error occurred.';
    return false;
});
</script>

<template>
    <Alert v-if="capturedError" variant="destructive">
        <AlertTitle>{{ title }}</AlertTitle>
        <AlertDescription>
            {{ capturedError || fallbackMessage }}
        </AlertDescription>
    </Alert>

    <slot v-else />
</template>
