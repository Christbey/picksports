<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import AuthBase from '@/layouts/AuthLayout.vue';
import {
    clearPendingAnalyticsEvent,
    pushAnalyticsEvent,
    setPendingAnalyticsEvent,
} from '@/lib/analytics';
import { login, terms } from '@/routes';
import { store } from '@/routes/register';

defineProps<{
    oauthError?: string;
    oauthProviders: Array<{ key: string; label: string; href: string }>;
    access?: {
        token: string;
        token_field: 'invite_token' | 'join_token';
        email: string | null;
        group_name: string | null;
        mode: 'invite' | 'join_link';
    } | null;
}>();

function trackSignupStart(): void {
    pushAnalyticsEvent('sign_up_start', { sign_up_method: 'email' });
    setPendingAnalyticsEvent('sign_up_complete', { sign_up_method: 'email' });
}
</script>

<template>
    <AuthBase
        title="Create an account"
        description="Enter your details below to create your account"
    >
        <Head title="Register" />

        <div
            v-if="oauthError"
            class="mb-4 text-center text-sm font-medium text-red-600"
        >
            {{ oauthError }}
        </div>

        <Form
            v-bind="store.form()"
            :reset-on-success="['password', 'password_confirmation']"
            @submit="trackSignupStart"
            @error="clearPendingAnalyticsEvent"
            v-slot="{ errors, processing }"
            class="flex flex-col gap-6"
        >
            <input v-if="access?.token" type="hidden" :name="access.token_field" :value="access.token">
            <div class="grid gap-6">
                <div v-if="access" class="rounded-lg border border-border bg-muted/50 px-4 py-3 text-sm text-muted-foreground">
                    <template v-if="access.mode === 'invite'">
                        You were invited to join <span class="font-medium text-foreground">{{ access.group_name ?? 'a bracket group' }}</span>. Age verification is already handled for this invite.
                    </template>
                    <template v-else>
                        You are joining <span class="font-medium text-foreground">{{ access.group_name ?? 'a bracket group' }}</span> through a shared group link. Age verification is already handled for this link.
                    </template>
                </div>

                <div class="grid gap-2">
                    <Label for="name">Name</Label>
                    <Input
                        id="name"
                        type="text"
                        required
                        autofocus
                        :tabindex="1"
                        autocomplete="name"
                        name="name"
                        placeholder="Full name"
                    />
                    <InputError :message="errors.name" />
                </div>

                <div class="grid gap-2">
                    <Label for="email">Email address</Label>
                    <Input
                        id="email"
                        type="email"
                        required
                        :tabindex="2"
                        autocomplete="email"
                        name="email"
                        placeholder="email@example.com"
                        :default-value="access?.mode === 'invite' ? (access.email ?? undefined) : undefined"
                        :readonly="access?.mode === 'invite' && Boolean(access.email)"
                    />
                    <InputError :message="errors.email" />
                </div>

                <div class="grid gap-2">
                    <Label for="password">Password</Label>
                    <Input
                        id="password"
                        type="password"
                        required
                        :tabindex="3"
                        autocomplete="new-password"
                        name="password"
                        placeholder="Password"
                    />
                    <InputError :message="errors.password" />
                </div>

                <div class="grid gap-2">
                    <Label for="password_confirmation">Confirm password</Label>
                    <Input
                        id="password_confirmation"
                        type="password"
                        required
                        :tabindex="4"
                        autocomplete="new-password"
                        name="password_confirmation"
                        placeholder="Confirm password"
                    />
                    <InputError :message="errors.password_confirmation" />
                </div>

                <div v-if="!access" class="grid gap-2">
                    <div class="flex items-start space-x-2">
                        <input
                            id="age_verified"
                            type="checkbox"
                            name="age_verified"
                            value="1"
                            required
                            :tabindex="5"
                            class="mt-1 h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary"
                        />
                        <label for="age_verified" class="text-sm leading-5 text-muted-foreground">
                            I confirm that I am at least 18 years of age and have read and agree to the
                            <Link :href="terms()" target="_blank" class="text-primary hover:underline">Terms of Service</Link>.
                        </label>
                    </div>
                    <InputError :message="errors.age_verified" />
                </div>

                <Button
                    type="submit"
                    class="mt-2 w-full"
                    tabindex="6"
                    :disabled="processing"
                    data-test="register-user-button"
                >
                    <Spinner v-if="processing" />
                    Create account
                </Button>

                <a
                    v-for="provider in oauthProviders"
                    :key="provider.key"
                    :href="provider.href"
                    class="inline-flex h-10 w-full items-center justify-center rounded-md border border-input bg-background px-4 py-2 text-sm font-medium shadow-sm transition-colors hover:bg-accent hover:text-accent-foreground"
                >
                    Continue with {{ provider.label }}
                </a>
            </div>

            <div class="text-center text-sm text-muted-foreground">
                Already have an account?
                <TextLink
                    :href="login()"
                    class="underline underline-offset-4"
                    :tabindex="7"
                    >Log in</TextLink
                >
            </div>
        </Form>
    </AuthBase>
</template>
