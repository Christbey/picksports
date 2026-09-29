<script setup lang="ts">
import { onErrorCaptured, ref } from 'vue';
import { RouterView } from 'vue-router';
import { router } from './router';
const error = ref('');
router.onError(() => {
    error.value =
        'The page could not load. Check your connection and try again.';
});
onErrorCaptured(() => {
    error.value = 'The page could not load. Please try again.';
    return false;
});
const retry = () => window.location.reload();
</script>
<template>
    <main v-if="error" class="mx-auto max-w-xl space-y-4 p-6">
        <p role="alert">{{ error }}</p>
        <button type="button" class="min-h-11 underline" @click="retry">
            Try again
        </button>
    </main>
    <RouterView v-else v-slot="{ Component, route }"
        ><component :is="Component" :key="route.path"
    /></RouterView>
</template>
