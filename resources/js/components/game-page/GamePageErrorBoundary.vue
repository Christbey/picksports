<script setup lang="ts">
import { onErrorCaptured, ref } from 'vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';

const capturedError = ref<string | null>(null);

onErrorCaptured((error) => {
    capturedError.value =
        error instanceof Error
            ? error.message
            : 'An unexpected rendering error occurred.';
    return false;
});
</script>

<template>
    <Alert v-if="capturedError !== null" variant="destructive">
        <AlertTitle>Page Rendering Error</AlertTitle>
        <AlertDescription>
            {{ capturedError || 'An unexpected rendering error occurred.' }}
        </AlertDescription>
    </Alert>

    <slot v-else />
</template>
