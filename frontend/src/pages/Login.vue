<script setup lang="ts">
import { ref } from 'vue';
import { useRoute } from 'vue-router';
import { ApiError, mutateJson } from '@/composables/useApiClient';
import { loadSession } from '../context';
import { router } from '../router';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
const route = useRoute();
const email = ref('');
const password = ref('');
const code = ref('');
const challenge = ref(false);
const recovery = ref(false);
const busy = ref(false);
const error = ref('');
async function submit() {
    if (busy.value) return;
    busy.value = true;
    error.value = '';
    try {
        const response = await mutateJson<{ two_factor?: boolean }>(
            challenge.value ? '/two-factor-challenge' : '/login',
            'POST',
            challenge.value
                ? { [recovery.value ? 'recovery_code' : 'code']: code.value }
                : { email: email.value, password: password.value },
        );
        password.value = '';
        if (response?.two_factor) {
            challenge.value = true;
            return;
        }
        await loadSession();
        const next =
            typeof route.query.next === 'string' ? route.query.next : '';
        await router.replace(
            next.startsWith('/') &&
                !next.startsWith('//') &&
                !next.includes('\\')
                ? next
                : '/nfl/predictions',
        );
    } catch (cause) {
        error.value =
            cause instanceof ApiError
                ? cause.message
                : 'Unable to connect. Please try again.';
    } finally {
        busy.value = false;
    }
}
</script>
<template>
    <main
        class="flex min-h-screen items-center justify-center bg-background p-4 text-foreground"
    >
        <form
            class="w-full max-w-sm space-y-5 rounded-xl border bg-card p-6"
            @submit.prevent="submit"
        >
            <h1 class="text-xl font-semibold">
                {{
                    challenge ? 'Verify your identity' : 'Sign in to PickSports'
                }}
            </h1>
            <p v-if="error" role="alert" class="text-sm text-destructive">
                {{ error }}
            </p>
            <template v-if="!challenge">
                <div class="space-y-2">
                    <Label for="email">Email</Label
                    ><Input
                        id="email"
                        v-model="email"
                        type="email"
                        autocomplete="username"
                        required
                    />
                </div>
                <div class="space-y-2">
                    <Label for="password">Password</Label
                    ><Input
                        id="password"
                        v-model="password"
                        type="password"
                        autocomplete="current-password"
                        required
                    />
                </div>
            </template>
            <template v-else>
                <div class="space-y-2">
                    <Label for="code">{{
                        recovery ? 'Recovery code' : 'Authentication code'
                    }}</Label
                    ><Input
                        id="code"
                        v-model="code"
                        autocomplete="one-time-code"
                        required
                    />
                </div>
                <button
                    class="text-sm underline"
                    type="button"
                    @click="
                        recovery = !recovery;
                        code = '';
                    "
                >
                    {{
                        recovery
                            ? 'Use an authentication code'
                            : 'Use a recovery code'
                    }}
                </button>
            </template>
            <Button class="w-full" :disabled="busy" type="submit">{{
                busy ? 'Signing in…' : 'Continue'
            }}</Button>
        </form>
    </main>
</template>
