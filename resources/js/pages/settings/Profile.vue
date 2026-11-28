<script setup lang="ts">
import type { AuthenticatedAppPageProps } from '@/types';
import { Form, Head, Link, usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';
import DeleteUser from '@/components/DeleteUser.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    deletePasskey,
    listPasskeys,
    passkeyNameOrFallback,
    registerPasskey,
    toPasskeyLabelInput,
    type PasskeySummary,
} from '@/composables/usePasskeys';
import { signalCurrentUserDetails } from '@/composables/useWebAuthnSignal';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { type BreadcrumbItem } from '@/types';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import { edit } from '@/routes/profile';
import { send } from '@/routes/verification';

type Props = {
    mustVerifyEmail: boolean;
    status?: string;
};

defineProps<Props>();

const breadcrumbItems: BreadcrumbItem[] = [
    {
        title: 'Profile settings',
        href: edit().url,
    },
];

const page = usePage<AuthenticatedAppPageProps>();
const user = computed(() => page.props.auth.user);
const passkeys = ref<PasskeySummary[]>([]);
const loadingPasskeys = ref(false);
let passkeyLoadGeneration = 0;
const passkeyListState = ref<'unloaded' | 'ready' | 'error'>('unloaded');
const passkeyMessage = ref<string | null>(null);
const passkeyError = ref<string | null>(null);
const passkeyProcessing = ref(false);

async function handleProfileUpdateSuccess() {
    try {
        await signalCurrentUserDetails(
            String(user.value.id),
            String(user.value.email),
        );
    } catch {
        // Signal API is best-effort and should never block profile updates.
    }
}

async function loadPasskeys() {
    const generation = ++passkeyLoadGeneration;
    loadingPasskeys.value = true;
    passkeyListState.value = 'unloaded';
    passkeyError.value = null;

    try {
        const loaded = await listPasskeys();
        if (generation !== passkeyLoadGeneration) return;
        passkeys.value = loaded;
        passkeyListState.value = 'ready';
    } catch (error) {
        if (generation !== passkeyLoadGeneration) return;
        passkeyListState.value = 'error';
        passkeyError.value =
            error instanceof Error ? error.message : 'Failed to load passkeys.';
    } finally {
        if (generation === passkeyLoadGeneration) loadingPasskeys.value = false;
    }
}

async function handleAddPasskey(): Promise<void> {
    passkeyError.value = null;
    passkeyMessage.value = null;
    passkeyProcessing.value = true;

    try {
        const labelInput = window.prompt(
            'Passkey label (optional)',
            'My device',
        );
        const name = labelInput ? toPasskeyLabelInput(labelInput) : null;
        await registerPasskey(name);
        await loadPasskeys();
        passkeyMessage.value = 'Passkey added successfully.';
    } catch (error) {
        passkeyError.value =
            error instanceof Error ? error.message : 'Failed to add passkey.';
    } finally {
        passkeyProcessing.value = false;
    }
}

async function handleDeletePasskey(id: number): Promise<void> {
    passkeyError.value = null;
    passkeyMessage.value = null;
    passkeyProcessing.value = true;

    try {
        await deletePasskey(id);
        await loadPasskeys();
        passkeyMessage.value = 'Passkey removed.';
    } catch (error) {
        passkeyError.value =
            error instanceof Error
                ? error.message
                : 'Failed to remove passkey.';
    } finally {
        passkeyProcessing.value = false;
    }
}

onMounted(async () => {
    await loadPasskeys();
});
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head title="Profile settings" />

        <h1 class="sr-only">Profile Settings</h1>

        <SettingsLayout>
            <div class="flex flex-col space-y-6">
                <Heading
                    variant="small"
                    title="Profile information"
                    description="Update your name and email address"
                />

                <Form
                    v-bind="ProfileController.update.form()"
                    class="space-y-6"
                    @success="handleProfileUpdateSuccess"
                    v-slot="{ errors, processing, recentlySuccessful }"
                >
                    <div class="grid gap-2">
                        <Label for="name">Name</Label>
                        <Input
                            id="name"
                            :aria-invalid="Boolean(errors.name)"
                            :aria-describedby="
                                errors.name ? 'name-error' : undefined
                            "
                            class="mt-1 block w-full"
                            name="name"
                            :default-value="user.name"
                            required
                            autocomplete="name"
                            placeholder="Full name"
                        />
                        <InputError
                            class="mt-2"
                            id="name-error"
                            :message="errors.name"
                        />
                    </div>

                    <div class="grid gap-2">
                        <Label for="email">Email address</Label>
                        <Input
                            id="email"
                            :aria-invalid="Boolean(errors.email)"
                            :aria-describedby="
                                errors.email ? 'email-error' : undefined
                            "
                            type="email"
                            class="mt-1 block w-full"
                            name="email"
                            :default-value="user.email"
                            required
                            autocomplete="username"
                            placeholder="Email address"
                        />
                        <InputError
                            class="mt-2"
                            id="email-error"
                            :message="errors.email"
                        />
                    </div>

                    <div v-if="mustVerifyEmail && !user.email_verified_at">
                        <p class="-mt-4 text-sm text-muted-foreground">
                            Your email address is unverified.
                            <Link
                                :href="send()"
                                as="button"
                                class="text-foreground underline decoration-neutral-300 underline-offset-4 transition-colors duration-300 ease-out hover:decoration-current! dark:decoration-neutral-500"
                            >
                                Click here to resend the verification email.
                            </Link>
                        </p>

                        <div
                            v-if="status === 'verification-link-sent'"
                            class="mt-2 text-sm font-medium text-green-600"
                        >
                            A new verification link has been sent to your email
                            address.
                        </div>
                    </div>

                    <div class="flex items-center gap-4">
                        <Button
                            :disabled="processing"
                            data-test="update-profile-button"
                            >Save</Button
                        >

                        <Transition
                            enter-active-class="transition ease-in-out"
                            enter-from-class="opacity-0"
                            leave-active-class="transition ease-in-out"
                            leave-to-class="opacity-0"
                        >
                            <p
                                v-show="recentlySuccessful"
                                class="text-sm text-neutral-600"
                            >
                                Saved.
                            </p>
                        </Transition>
                    </div>
                </Form>
            </div>

            <div class="flex flex-col space-y-6">
                <Heading
                    variant="small"
                    title="Passkeys"
                    description="Use a passkey to sign in without a password."
                />

                <div class="space-y-3">
                    <Button
                        type="button"
                        variant="outline"
                        :disabled="passkeyProcessing"
                        @click="handleAddPasskey"
                    >
                        Add passkey
                    </Button>

                    <p v-if="passkeyMessage" class="text-sm text-green-600">
                        {{ passkeyMessage }}
                    </p>
                    <p
                        v-if="passkeyError"
                        role="alert"
                        class="text-sm text-red-600"
                    >
                        {{ passkeyError }}
                    </p>

                    <Button
                        v-if="passkeyListState === 'error'"
                        type="button"
                        variant="outline"
                        :disabled="loadingPasskeys"
                        @click="loadPasskeys"
                    >
                        Retry loading passkeys
                    </Button>

                    <p
                        v-if="loadingPasskeys"
                        class="text-sm text-muted-foreground"
                    >
                        Loading passkeys...
                    </p>

                    <ul v-else class="space-y-2">
                        <li
                            v-for="passkey in passkeys"
                            :key="passkey.id"
                            class="flex items-center justify-between rounded-md border p-3"
                        >
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium">
                                    {{
                                        passkeyNameOrFallback(
                                            passkey.name,
                                            passkey.created_at,
                                        )
                                    }}
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    Last used:
                                    {{
                                        passkey.last_used_at
                                            ? new Date(
                                                  passkey.last_used_at,
                                              ).toLocaleString()
                                            : 'Never'
                                    }}
                                </p>
                            </div>

                            <Button
                                type="button"
                                variant="destructive"
                                size="sm"
                                :disabled="passkeyProcessing"
                                @click="handleDeletePasskey(passkey.id)"
                            >
                                Remove
                            </Button>
                        </li>
                        <li
                            v-if="
                                passkeyListState === 'ready' &&
                                passkeys.length === 0
                            "
                            class="text-sm text-muted-foreground"
                        >
                            No passkeys added yet.
                        </li>
                    </ul>
                </div>
            </div>

            <DeleteUser />
        </SettingsLayout>
    </AppLayout>
</template>
