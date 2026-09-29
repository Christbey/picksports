<script setup lang="ts">
import { ref } from 'vue';
import { RouterLink } from 'vue-router';
import { mutateJson } from '@/composables/useApiClient';
import { session } from './context';
import { router } from './router';
const error = ref('');
const busy = ref(false);
async function logout() {
    if (busy.value) return;
    busy.value = true;
    error.value = '';
    try {
        await mutateJson('/logout', 'POST');
        session.context = null;
        session.loaded = true;
        await router.replace('/login');
    } catch {
        error.value = 'Could not sign out. Please try again.';
    } finally {
        busy.value = false;
    }
}
</script>
<template>
    <div class="min-h-screen bg-background text-foreground">
        <header
            class="flex min-h-16 items-center justify-between gap-4 border-b px-4 sm:px-6"
        >
            <RouterLink to="/nfl/predictions" class="font-semibold"
                >PickSports</RouterLink
            >
            <nav
                aria-label="Main navigation"
                class="flex items-center gap-4 text-sm"
            >
                <RouterLink to="/nfl/predictions">NFL predictions</RouterLink>
                <button
                    v-if="session.context"
                    type="button"
                    :disabled="busy"
                    class="min-h-11"
                    @click="logout"
                >
                    Sign out
                </button>
            </nav>
        </header>
        <p v-if="error" role="alert" class="px-4 py-2 text-destructive">
            {{ error }}
        </p>
        <main class="mx-auto max-w-7xl p-3 sm:p-6"><slot /></main>
    </div>
</template>
