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
const showEmailLogin = ref(false);

function isGoogleProvider(label: string): boolean {
    return label.trim().toLowerCase() === 'google';
}

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
        description="Use a passkey or Google for the fastest sign-in."
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
            <div class="grid gap-5">
                <div class="grid gap-3 rounded-2xl border border-border/70 bg-card/40 p-4">
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-muted-foreground">Recommended</p>

                    <Button
                        type="button"
                        class="h-12 w-full justify-between rounded-xl border border-border/70 bg-card/80 px-4 text-foreground shadow-sm transition-colors hover:bg-accent/70"
                        :disabled="passkeyProcessing"
                        @click="handlePasskeySignIn"
                    >
                        <span class="flex items-center gap-3">
                            <span class="inline-flex size-8 items-center justify-center rounded-lg bg-primary/12 text-primary">
                                <svg viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M12 11a4 4 0 1 0-4-4" />
                                    <path d="M8 11v4" />
                                    <path d="M8 15h3" />
                                    <path d="M11 15v2a2 2 0 0 0 2 2" />
                                    <path d="M13 19h1a2 2 0 0 0 2-2v-1" />
                                    <path d="M16 16h1a2 2 0 0 0 2-2" />
                                    <path d="M19 14a2 2 0 0 0 2-2v-1" />
                                </svg>
                            </span>
                            <span class="text-sm font-semibold">Sign in with passkey</span>
                        </span>
                        <Spinner v-if="passkeyProcessing" />
                        <span v-else class="text-xs uppercase tracking-[0.16em] text-muted-foreground">Fastest</span>
                    </Button>

                    <a
                        v-for="provider in oauthProviders"
                        :key="provider.key"
                        :href="provider.href"
                        class="inline-flex h-12 w-full items-center justify-between rounded-xl border px-4 py-2 text-sm font-medium shadow-sm transition-colors"
                        :class="isGoogleProvider(provider.label)
                            ? 'border-border/70 bg-background text-foreground hover:bg-accent/70'
                            : 'border-primary/20 bg-primary text-primary-foreground hover:opacity-90'"
                    >
                        <span class="flex items-center gap-3">
                            <span
                                v-if="isGoogleProvider(provider.label)"
                                class="inline-flex size-8 items-center justify-center rounded-lg bg-white shadow-sm"
                            >
                                <svg viewBox="0 0 24 24" class="size-4" aria-hidden="true">
                                    <path fill="#EA4335" d="M12 10.2v3.9h5.5c-.2 1.3-1.5 3.9-5.5 3.9-3.3 0-6-2.8-6-6.2s2.7-6.2 6-6.2c1.9 0 3.1.8 3.8 1.5l2.6-2.5C16.7 2.9 14.6 2 12 2 6.9 2 2.8 6.5 2.8 12s4.1 10 9.2 10c5.3 0 8.9-3.8 8.9-9.2 0-.6-.1-1.1-.2-1.6H12Z" />
                                    <path fill="#34A853" d="M3.8 7.3l3.2 2.4C7.8 7.4 9.7 5.8 12 5.8c1.9 0 3.1.8 3.8 1.5l2.6-2.5C16.7 2.9 14.6 2 12 2 8 2 4.5 4.3 3 7.7Z" />
                                    <path fill="#FBBC05" d="M12 22c2.5 0 4.6-.8 6.1-2.3l-2.8-2.3c-.8.6-1.8 1-3.3 1-2.8 0-5.1-1.9-5.9-4.4l-3.3 2.5C4.4 19.7 7.9 22 12 22Z" />
                                    <path fill="#4285F4" d="M3 7.7A10.3 10.3 0 0 0 2 12c0 1.5.3 2.9.9 4.3l3.3-2.5A6.4 6.4 0 0 1 5.9 12c0-.6.1-1.3.3-1.8L3 7.7Z" />
                                </svg>
                            </span>
                            <span
                                v-else
                                class="inline-flex size-8 items-center justify-center rounded-lg bg-primary-foreground/15 text-current"
                            >
                                <svg viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <circle cx="12" cy="12" r="3" />
                                    <path d="M19 12h2" />
                                    <path d="M3 12h2" />
                                    <path d="M12 3v2" />
                                    <path d="M12 19v2" />
                                </svg>
                            </span>
                            <span class="text-sm font-semibold">Continue with {{ provider.label }}</span>
                        </span>
                        <span class="text-xs uppercase tracking-[0.16em]" :class="isGoogleProvider(provider.label) ? 'text-muted-foreground' : 'text-primary-foreground/80'">
                            {{ isGoogleProvider(provider.label) ? 'Popular' : 'SSO' }}
                        </span>
                    </a>

                    <p v-if="passkeyError" class="text-sm text-red-600">{{ passkeyError }}</p>
                </div>

                <div class="flex justify-center">
                    <Button
                        type="button"
                        variant="ghost"
                        class="border border-border/70 bg-card/50 text-foreground hover:bg-accent/70"
                        @click="showEmailLogin = !showEmailLogin"
                    >
                        {{ showEmailLogin ? 'Hide email login' : 'Use email instead' }}
                    </Button>
                </div>

                <div v-if="showEmailLogin" class="rounded-2xl border border-border/70 bg-card/50 p-4">
                    <div class="mb-4">
                        <p class="text-sm font-semibold text-foreground">Email and password</p>
                        <p class="mt-1 text-sm text-muted-foreground">Use your account credentials if you do not want to sign in with a passkey or Google.</p>
                    </div>

                    <div class="grid gap-5">
                        <div class="grid gap-2">
                            <Label for="email">Email address</Label>
                            <Input
                                id="email"
                                type="email"
                                name="email"
                                v-model="email"
                                required
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
                            class="w-full"
                            :tabindex="4"
                            :disabled="processing"
                            data-test="login-button"
                        >
                            <Spinner v-if="processing" />
                            Log in
                        </Button>
                    </div>
                </div>
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
