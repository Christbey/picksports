<script setup lang="ts">
import { onErrorCaptured, ref } from 'vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';

const capturedError = ref<string | null>(null);

onErrorCaptured((error) => {
    capturedError.value =
        error instanceof Error ? error.message : 'An unexpected rendering error occurred.';
    return false;
});
</script>

<template>
    <Alert v-if="capturedError" variant="destructive">
        <AlertTitle>Page Rendering Error</AlertTitle>
        <AlertDescription>
            {{ capturedError }}
        </AlertDescription>
    </Alert>

    <slot v-else />
</template>
