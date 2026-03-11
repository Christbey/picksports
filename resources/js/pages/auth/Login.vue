<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { signInWithPasskey } from '@/composables/usePasskeys';
import AuthBase from '@/layouts/AuthLayout.vue';
import {
    clearPendingAnalyticsEvent,
    pushAnalyticsEvent,
    setPendingAnalyticsEvent,
} from '@/lib/analytics';
import { register } from '@/routes';
import { store } from '@/routes/login';
import { request } from '@/routes/password';

defineProps<{
    status?: string;
    canResetPassword: boolean;
    canRegister: boolean;
    oauthError?: string;
    oauthProviders: Array<{ key: string; label: string; href: string }>;
}>();

const email = ref('');
const passkeyError = ref<string | null>(null);
const passkeyProcessing = ref(false);

function trackPasswordLoginStart(): void {
    pushAnalyticsEvent('login_start', { login_method: 'password' });
    setPendingAnalyticsEvent('login_complete', { login_method: 'password' });
}

async function handlePasskeySignIn(): Promise<void> {
    passkeyError.value = null;
    passkeyProcessing.value = true;
    pushAnalyticsEvent('login_start', { login_method: 'passkey' });
    setPendingAnalyticsEvent('login_complete', { login_method: 'passkey' });

    try {
        await signInWithPasskey(email.value);
    } catch (error) {
        clearPendingAnalyticsEvent();
        passkeyError.value = error instanceof Error ? error.message : 'Passkey sign-in failed.';
    } finally {
        passkeyProcessing.value = false;
    }
}
</script>

<template>
    <AuthBase
        title="Log in to your account"
        description="Enter your email and password below to log in"
    >
        <Head title="Log in" />

        <div
            v-if="status"
            class="mb-4 text-center text-sm font-medium text-green-600"
        >
            {{ status }}
        </div>

        <div
            v-if="oauthError"
            class="mb-4 text-center text-sm font-medium text-red-600"
        >
            {{ oauthError }}
        </div>

        <Form
            v-bind="store.form()"
            :reset-on-success="['password']"
            @submit="trackPasswordLoginStart"
            @error="clearPendingAnalyticsEvent"
            v-slot="{ errors, processing }"
            class="flex flex-col gap-6"
        >
            <div class="grid gap-6">
                <div class="grid gap-2">
                    <Label for="email">Email address</Label>
                    <Input
                        id="email"
                        type="email"
                        name="email"
                        v-model="email"
                        required
                        autofocus
                        :tabindex="1"
                        autocomplete="email"
                        placeholder="email@example.com"
                    />
                    <InputError :message="errors.email" />
                </div>

                <div class="grid gap-2">
                    <div class="flex items-center justify-between">
                        <Label for="password">Password</Label>
                        <TextLink
                            v-if="canResetPassword"
                            :href="request()"
                            class="text-sm"
                            :tabindex="5"
                        >
                            Forgot password?
                        </TextLink>
                    </div>
                    <Input
                        id="password"
                        type="password"
                        name="password"
                        required
                        :tabindex="2"
                        autocomplete="current-password"
                        placeholder="Password"
                    />
                    <InputError :message="errors.password" />
                </div>

                <div class="flex items-center justify-between">
                    <Label for="remember" class="flex items-center space-x-3">
                        <Checkbox id="remember" name="remember" :tabindex="3" />
                        <span>Remember me</span>
                    </Label>
                </div>

                <Button
                    type="submit"
                    class="mt-4 w-full"
                    :tabindex="4"
                    :disabled="processing"
                    data-test="login-button"
                >
                    <Spinner v-if="processing" />
                    Log in
                </Button>

                <Button
                    type="button"
                    variant="outline"
                    class="w-full"
                    :disabled="passkeyProcessing"
                    @click="handlePasskeySignIn"
                >
                    <Spinner v-if="passkeyProcessing" />
                    Sign in with passkey
                </Button>

                <a
                    v-for="provider in oauthProviders"
                    :key="provider.key"
                    :href="provider.href"
                    class="inline-flex h-10 w-full items-center justify-center rounded-md border border-input bg-background px-4 py-2 text-sm font-medium shadow-sm transition-colors hover:bg-accent hover:text-accent-foreground"
                >
                    Continue with {{ provider.label }}
                </a>

                <p v-if="passkeyError" class="text-sm text-red-600">{{ passkeyError }}</p>
            </div>

            <div
                class="text-center text-sm text-muted-foreground"
                v-if="canRegister"
            >
                Don't have an account?
                <TextLink :href="register()" :tabindex="5">Sign up</TextLink>
            </div>
        </Form>
    </AuthBase>
</template>
