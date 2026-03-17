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
import passkeyMarkGray from '@/assets/auth/passkey-mark-gray.svg';
import passkeyMarkWhite from '@/assets/auth/passkey-mark-white.svg';
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

                    <button
                        type="button"
                        class="passkey-material-button"
                        :disabled="passkeyProcessing"
                        @click="handlePasskeySignIn"
                    >
                        <span class="passkey-material-button-state"></span>
                        <span class="passkey-material-button-content-wrapper">
                            <span class="passkey-material-button-icon" aria-hidden="true">
                                <img :src="passkeyMarkGray" alt="" class="passkey-mark passkey-mark-light">
                                <img :src="passkeyMarkWhite" alt="" class="passkey-mark passkey-mark-dark">
                            </span>
                            <span class="passkey-material-button-contents">Sign in with passkey</span>
                            <span class="passkey-material-button-meta">
                                <Spinner v-if="passkeyProcessing" class="size-4" />
                                <span v-else>Fastest</span>
                            </span>
                        </span>
                    </button>

                    <a
                        v-for="provider in oauthProviders"
                        :key="provider.key"
                        :href="provider.href"
                        :class="isGoogleProvider(provider.label) ? 'gsi-material-button' : 'provider-material-button'"
                    >
                        <template v-if="isGoogleProvider(provider.label)">
                            <span class="gsi-material-button-state"></span>
                            <span class="gsi-material-button-content-wrapper">
                                <span class="gsi-material-button-icon">
                                    <svg version="1.1" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48" style="display: block;" aria-hidden="true">
                                        <path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"></path>
                                        <path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"></path>
                                        <path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"></path>
                                        <path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"></path>
                                        <path fill="none" d="M0 0h48v48H0z"></path>
                                    </svg>
                                </span>
                                <span class="gsi-material-button-contents">Sign in with Google</span>
                                <span class="gsi-material-button-meta">Popular</span>
                            </span>
                        </template>
                        <template v-else>
                            <span class="provider-material-button-state"></span>
                            <span class="provider-material-button-content-wrapper">
                                <span class="provider-material-button-icon" aria-hidden="true">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <circle cx="12" cy="12" r="3" />
                                        <path d="M19 12h2" />
                                        <path d="M3 12h2" />
                                        <path d="M12 3v2" />
                                        <path d="M12 19v2" />
                                    </svg>
                                </span>
                                <span class="provider-material-button-contents">Continue with {{ provider.label }}</span>
                                <span class="provider-material-button-meta">SSO</span>
                            </span>
                        </template>
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

<style scoped>
.gsi-material-button,
.passkey-material-button,
.provider-material-button {
    position: relative;
    display: inline-flex;
    min-height: 48px;
    width: 100%;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    border-radius: 14px;
    text-decoration: none;
    transition: box-shadow 150ms ease, border-color 150ms ease, background-color 150ms ease, transform 150ms ease;
}

.gsi-material-button {
    border: 1px solid rgb(218 220 224);
    background: rgb(255 255 255);
    box-shadow: 0 1px 2px 0 rgb(60 64 67 / 0.3), 0 1px 3px 1px rgb(60 64 67 / 0.15);
}

.gsi-material-button:hover {
    background: rgb(248 249 250);
    box-shadow: 0 2px 6px 2px rgb(60 64 67 / 0.15), 0 1px 2px 0 rgb(60 64 67 / 0.3);
}

.dark .gsi-material-button {
    border-color: rgb(63 63 70);
    background: rgb(24 24 27);
}

.dark .gsi-material-button:hover {
    background: rgb(39 39 42);
}

.gsi-material-button-state,
.passkey-material-button-state,
.provider-material-button-state {
    position: absolute;
    inset: 0;
}

.gsi-material-button-content-wrapper,
.passkey-material-button-content-wrapper,
.provider-material-button-content-wrapper {
    position: relative;
    z-index: 1;
    display: flex;
    width: 100%;
    align-items: center;
    gap: 12px;
    padding: 0 16px;
}

.gsi-material-button-icon,
.passkey-material-button-icon,
.provider-material-button-icon {
    display: inline-flex;
    height: 20px;
    width: 20px;
    flex-shrink: 0;
    align-items: center;
    justify-content: center;
}

.gsi-material-button-contents,
.passkey-material-button-contents,
.provider-material-button-contents {
    flex: 1;
    text-align: left;
    font-size: 14px;
    font-weight: 600;
    line-height: 20px;
}

.gsi-material-button-contents {
    color: rgb(60 64 67);
}

.dark .gsi-material-button-contents {
    color: rgb(244 244 245);
}

.gsi-material-button-meta,
.passkey-material-button-meta,
.provider-material-button-meta {
    flex-shrink: 0;
    font-size: 11px;
    font-weight: 600;
    letter-spacing: 0.12em;
    text-transform: uppercase;
}

.gsi-material-button-meta {
    color: rgb(95 99 104);
}

.dark .gsi-material-button-meta {
    color: rgb(161 161 170);
}

.passkey-material-button {
    border: 1px solid rgb(218 220 224);
    background: rgb(255 255 255);
    box-shadow: 0 1px 2px 0 rgb(60 64 67 / 0.3), 0 1px 3px 1px rgb(60 64 67 / 0.15);
}

.passkey-material-button:hover {
    background: rgb(248 249 250);
    box-shadow: 0 2px 6px 2px rgb(60 64 67 / 0.15), 0 1px 2px 0 rgb(60 64 67 / 0.3);
}

.passkey-material-button:disabled {
    cursor: not-allowed;
    opacity: 0.7;
    transform: none;
}

.dark .passkey-material-button {
    border-color: rgb(63 63 70);
    background: rgb(24 24 27);
}

.dark .passkey-material-button:hover {
    background: rgb(39 39 42);
}

.passkey-material-button-icon {
    height: 36px;
    width: 36px;
    border-radius: 0;
    background: transparent;
    overflow: hidden;
}

.dark .passkey-material-button-icon {
    background: transparent;
}

.passkey-material-button-contents {
    color: rgb(60 64 67);
}

.passkey-material-button-meta {
    color: rgb(95 99 104);
}

.dark .passkey-material-button-contents {
    color: rgb(244 244 245);
}

.dark .passkey-material-button-meta {
    color: rgb(161 161 170);
}

.passkey-mark {
    display: block;
    height: 32px;
    width: 32px;
}

.passkey-mark-dark {
    display: none;
}

.dark .passkey-mark-light {
    display: none;
}

.dark .passkey-mark-dark {
    display: block;
}

.provider-material-button {
    border: 1px solid hsl(var(--border) / 0.8);
    background: hsl(var(--card));
}

.provider-material-button:hover {
    background: hsl(var(--accent));
}

.provider-material-button-icon {
    height: 32px;
    width: 32px;
    border-radius: 10px;
    background: hsl(var(--primary) / 0.12);
    color: hsl(var(--primary));
}

.provider-material-button-contents {
    color: hsl(var(--foreground));
}

.provider-material-button-meta {
    color: hsl(var(--muted-foreground));
}
</style>
