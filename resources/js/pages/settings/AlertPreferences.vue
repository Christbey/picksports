<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import RenderErrorBoundary from '@/components/RenderErrorBoundary.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { update } from '@/routes/alert-preferences';
import { type BreadcrumbItem } from '@/types';

interface Preference {
    enabled: boolean;
    sports: string[];
    notification_types: string[];
    enabled_template_ids: number[]; // legacy field, hidden from UI
    minimum_edge: number | string;
    time_window_start: string;
    time_window_end: string;
    digest_mode: string;
    digest_time: string | null;
    phone_number: string | null;
}

interface WebPushState {
    configured: boolean;
    publicKey: string | null;
    hasSubscription: boolean;
}

const props = defineProps<{
    preference: Preference | null;
    webPush: WebPushState;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Alert Preferences',
        href: '/settings/alert-preferences',
    },
];

const defaultPreference: Preference = {
    enabled: false,
    sports: ['nfl', 'nba', 'cbb'],
    notification_types: ['email'],
    enabled_template_ids: [],
    minimum_edge: 5.0,
    time_window_start: '09:00',
    time_window_end: '23:00',
    digest_mode: 'realtime',
    digest_time: null,
    phone_number: null,
};

const currentPreference = computed(() => props.preference || defaultPreference);

const availableSports = [
    { value: 'nfl', label: 'NFL' },
    { value: 'nba', label: 'NBA' },
    { value: 'cbb', label: 'NCAA Men\'s Basketball' },
    { value: 'wcbb', label: 'NCAA Women\'s Basketball' },
    { value: 'mlb', label: 'MLB' },
    { value: 'cfb', label: 'NCAA Football' },
    { value: 'wnba', label: 'WNBA' },
];

const availableNotificationTypes = [
    { value: 'email', label: 'Email' },
    { value: 'push', label: 'Push Notifications' },
    { value: 'sms', label: 'SMS (Coming Soon)', disabled: true },
];

function normalizeStringArray(value: unknown): string[] {
    if (!Array.isArray(value)) return [];
    return value
        .map((item) => String(item).trim().toLowerCase())
        .filter((item) => item.length > 0);
}

const selectedSports = ref<string[]>(normalizeStringArray(currentPreference.value.sports));
const selectedNotificationTypes = ref<string[]>(normalizeStringArray(currentPreference.value.notification_types));
const alertsEnabled = ref<boolean>(currentPreference.value.enabled);

const sportLabelMap = computed<Record<string, string>>(() =>
    Object.fromEntries(availableSports.map((sport) => [sport.value, sport.label])),
);
const notificationLabelMap = computed<Record<string, string>>(() =>
    Object.fromEntries(availableNotificationTypes.map((type) => [type.value, type.label.replace(' (Coming Soon)', '')])),
);
const selectedSportLabels = computed(() => selectedSports.value.map((sport) => sportLabelMap.value[sport] ?? sport.toUpperCase()));
const selectedNotificationLabels = computed(() =>
    selectedNotificationTypes.value.map((type) => notificationLabelMap.value[type] ?? type.toUpperCase()),
);
const autoDigestMode = computed(() => (selectedNotificationTypes.value.includes('push') ? 'realtime' : 'daily_summary'));
const digestModeLabel = computed(() => (autoDigestMode.value === 'daily_summary' ? 'Daily Summary' : 'Real-time'));

function onEnabledChange(checked: boolean | 'indeterminate') {
    alertsEnabled.value = checked === true;
}

function toggleSport(sport: string, checked: boolean | 'indeterminate') {
    const shouldEnable = checked === true;
    const index = selectedSports.value.indexOf(sport);
    if (shouldEnable && index === -1) {
        selectedSports.value.push(sport);
    }
    if (!shouldEnable && index !== -1) {
        selectedSports.value.splice(index, 1);
    }
}

function toggleNotificationType(type: string, checked: boolean | 'indeterminate') {
    const shouldEnable = checked === true;
    const index = selectedNotificationTypes.value.indexOf(type);
    if (shouldEnable && index === -1) {
        selectedNotificationTypes.value.push(type);
    }
    if (!shouldEnable && index !== -1) {
        selectedNotificationTypes.value.splice(index, 1);
    }
}

function isSportSelected(sport: string): boolean {
    return selectedSports.value.includes(sport);
}

function isNotificationTypeSelected(type: string): boolean {
    return selectedNotificationTypes.value.includes(type);
}

const webPushSupported = ref(false);
const webPushPermission = ref<NotificationPermission>('default');
const webPushHasSubscription = ref<boolean>(props.webPush?.hasSubscription ?? false);
const webPushBusy = ref(false);
const webPushMessage = ref<string | null>(null);
const webPushError = ref<string | null>(null);
const iosStandalone = ref(false);

function getCsrfToken(): string {
    const el = document.querySelector('meta[name="csrf-token"]');
    return el?.getAttribute('content') ?? '';
}

function urlBase64ToUint8Array(base64String: string): Uint8Array {
    const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const rawData = window.atob(base64);
    const outputArray = new Uint8Array(rawData.length);

    for (let i = 0; i < rawData.length; ++i) {
        outputArray[i] = rawData.charCodeAt(i);
    }

    return outputArray;
}

async function enableWebPush() {
    webPushBusy.value = true;
    webPushError.value = null;
    webPushMessage.value = null;

    try {
        if (!webPushSupported.value) {
            throw new Error('This browser does not support push notifications.');
        }

        if (!props.webPush.configured || !props.webPush.publicKey) {
            throw new Error('Push notifications are not configured on the server yet.');
        }

        const registration = await navigator.serviceWorker.register('/sw.js');
        const permission = await Notification.requestPermission();
        webPushPermission.value = permission;

        if (permission !== 'granted') {
            throw new Error('Notification permission was not granted.');
        }

        let subscription = await registration.pushManager.getSubscription();
        if (!subscription) {
            subscription = await registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: urlBase64ToUint8Array(props.webPush.publicKey),
            });
        }

        const response = await fetch('/settings/web-push/subscriptions', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': getCsrfToken(),
                'Accept': 'application/json',
            },
            body: JSON.stringify(subscription.toJSON()),
        });

        if (!response.ok) {
            throw new Error('Could not save push subscription on the server.');
        }

        webPushHasSubscription.value = true;
        webPushMessage.value = 'Push notifications are enabled for this device.';

        if (!selectedNotificationTypes.value.includes('push')) {
            selectedNotificationTypes.value.push('push');
        }
    } catch (error) {
        webPushError.value = error instanceof Error ? error.message : 'Failed to enable push notifications.';
    } finally {
        webPushBusy.value = false;
    }
}

async function disableWebPush() {
    webPushBusy.value = true;
    webPushError.value = null;
    webPushMessage.value = null;

    try {
        if ('serviceWorker' in navigator) {
            const registration = await navigator.serviceWorker.ready;
            const subscription = await registration.pushManager.getSubscription();

            if (subscription) {
                await subscription.unsubscribe();
                await fetch('/settings/web-push/subscriptions', {
                    method: 'DELETE',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': getCsrfToken(),
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ endpoint: subscription.endpoint }),
                });
            }
        }

        webPushHasSubscription.value = false;
        webPushMessage.value = 'Push notifications were disabled for this device.';
    } catch (error) {
        webPushError.value = error instanceof Error ? error.message : 'Failed to disable push notifications.';
    } finally {
        webPushBusy.value = false;
    }
}

async function sendTestPush() {
    webPushBusy.value = true;
    webPushError.value = null;
    webPushMessage.value = null;

    try {
        const response = await fetch('/settings/web-push/test', {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': getCsrfToken(),
                'Accept': 'application/json',
            },
        });

        const data = await response.json();

        if (!response.ok || !data.ok) {
            throw new Error(data.message ?? 'Failed to send test push notification.');
        }

        webPushMessage.value = data.message ?? 'Test push sent.';
    } catch (error) {
        webPushError.value = error instanceof Error ? error.message : 'Failed to send test notification.';
    } finally {
        webPushBusy.value = false;
    }
}

onMounted(() => {
    webPushSupported.value =
        'serviceWorker' in navigator &&
        'PushManager' in window &&
        'Notification' in window;

    if ('Notification' in window) {
        webPushPermission.value = Notification.permission;
    }

    iosStandalone.value =
        window.matchMedia('(display-mode: standalone)').matches ||
        (window.navigator as Navigator & { standalone?: boolean }).standalone === true;
});
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Alert Preferences" />

        <h1 class="sr-only">Alert Preferences</h1>

        <SettingsLayout>
            <RenderErrorBoundary title="Alert Preferences Render Error">
                <div class="flex flex-col space-y-6">
                <Heading
                    variant="small"
                    title="Alert Preferences"
                    description="Get notified when we find high-value betting opportunities"
                />

                <Form
                    v-bind="update.form()"
                    class="space-y-8"
                    v-slot="{ errors, processing, recentlySuccessful }"
                >
                    <!-- Enable Alerts Toggle -->
                    <div class="rounded-xl border border-sidebar-border bg-white p-6 dark:bg-sidebar">
                        <div class="flex items-start gap-3">
                            <input type="hidden" name="enabled" value="0" />
                            <Checkbox
                                id="enabled"
                                :model-value="alertsEnabled"
                                @update:model-value="onEnabledChange"
                            />
                            <input v-if="alertsEnabled" type="hidden" name="enabled" value="1" />
                            <div class="grid gap-1.5 leading-none">
                                <Label
                                    for="enabled"
                                    class="text-sm font-medium leading-none peer-disabled:cursor-not-allowed peer-disabled:opacity-70 cursor-pointer"
                                >
                                    Enable Betting Alerts
                                </Label>
                                <p class="text-sm text-muted-foreground">
                                    Receive notifications when we identify high-value betting opportunities based on your preferences
                                </p>
                            </div>
                        </div>
                        <InputError class="mt-2" :message="errors.enabled" />
                    </div>

                    <div class="rounded-xl border border-sidebar-border bg-sidebar-accent/40 p-4">
                        <p class="text-sm font-medium">Current Setup</p>
                        <div class="mt-2 flex flex-wrap gap-2 text-xs">
                            <span class="rounded bg-white px-2 py-1 dark:bg-sidebar">Alerts: {{ alertsEnabled ? 'On' : 'Off' }}</span>
                            <span class="rounded bg-white px-2 py-1 dark:bg-sidebar">Sports: {{ selectedSportLabels.length }}</span>
                            <span class="rounded bg-white px-2 py-1 dark:bg-sidebar">Channels: {{ selectedNotificationLabels.join(', ') || 'None' }}</span>
                            <span class="rounded bg-white px-2 py-1 dark:bg-sidebar">Delivery: {{ digestModeLabel }}</span>
                        </div>
                    </div>

                    <!-- Sports Selection -->
                    <div class="space-y-3 rounded-xl border border-sidebar-border bg-white p-6 dark:bg-sidebar">
                        <div>
                            <Label class="text-base">Sports</Label>
                            <p class="text-sm text-muted-foreground">
                                Select which sports you want to receive alerts for
                            </p>
                        </div>

                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div
                                v-for="sport in availableSports"
                                :key="sport.value"
                                class="flex items-center gap-3 rounded-lg border border-sidebar-border bg-white p-4 dark:bg-sidebar"
                                :class="isSportSelected(sport.value) ? 'border-primary bg-primary/5 dark:bg-primary/10' : ''"
                            >
                                <Checkbox
                                    :name="`sports[${sport.value}]`"
                                    :value="sport.value"
                                    :id="`sport-${sport.value}`"
                                    :model-value="isSportSelected(sport.value)"
                                    @update:model-value="(checked) => toggleSport(sport.value, checked)"
                                />
                                <Label
                                    :for="`sport-${sport.value}`"
                                    class="text-sm font-medium cursor-pointer"
                                >
                                    {{ sport.label }}
                                </Label>
                            </div>
                        </div>

                        <input
                            v-for="sport in selectedSports"
                            :key="sport"
                            type="hidden"
                            name="sports[]"
                            :value="sport"
                        />

                        <InputError class="mt-2" :message="errors.sports" />
                    </div>

                    <!-- Notification Types -->
                    <div class="space-y-3 rounded-xl border border-sidebar-border bg-white p-6 dark:bg-sidebar">
                        <div>
                            <Label class="text-base">Notification Methods</Label>
                            <p class="text-sm text-muted-foreground">
                                Choose how you'd like to receive alerts
                            </p>
                        </div>

                        <div class="grid grid-cols-1 gap-3">
                            <div
                                v-for="type in availableNotificationTypes"
                                :key="type.value"
                                class="flex items-center gap-3 rounded-lg border border-sidebar-border bg-white p-4 dark:bg-sidebar"
                                :class="[
                                    type.disabled ? 'opacity-50' : '',
                                    isNotificationTypeSelected(type.value) ? 'border-primary bg-primary/5 dark:bg-primary/10' : '',
                                ]"
                            >
                                <Checkbox
                                    :name="`notification_types[${type.value}]`"
                                    :value="type.value"
                                    :id="`notification-${type.value}`"
                                    :model-value="isNotificationTypeSelected(type.value)"
                                    :disabled="type.disabled"
                                    @update:model-value="(checked) => toggleNotificationType(type.value, checked)"
                                />
                                <Label
                                    :for="`notification-${type.value}`"
                                    class="text-sm font-medium cursor-pointer"
                                >
                                    {{ type.label }}
                                </Label>
                                <span v-if="type.disabled" class="ml-auto rounded bg-sidebar-accent px-2 py-1 text-xs text-muted-foreground">
                                    Soon
                                </span>
                            </div>
                        </div>

                        <input
                            v-for="type in selectedNotificationTypes"
                            :key="type"
                            type="hidden"
                            name="notification_types[]"
                            :value="type"
                        />

                        <InputError class="mt-2" :message="errors.notification_types" />
                    </div>

                    <!-- Web Push Setup -->
                    <div class="space-y-3 rounded-xl border border-sidebar-border bg-white p-6 dark:bg-sidebar">
                        <div>
                            <Label class="text-base">Web Push Setup (iOS Home Screen)</Label>
                            <p class="text-sm text-muted-foreground">
                                Enable browser push for this device. On iPhone/iPad, install this app to the Home Screen first.
                            </p>
                        </div>

                        <div class="space-y-1 text-sm text-muted-foreground">
                            <p>
                                Browser support:
                                <span class="font-medium text-foreground">
                                    {{ webPushSupported ? 'Available' : 'Not available on this browser' }}
                                </span>
                            </p>
                            <p>
                                Permission:
                                <span class="font-medium text-foreground">{{ webPushPermission }}</span>
                            </p>
                            <p>
                                Installed mode:
                                <span class="font-medium text-foreground">
                                    {{ iosStandalone ? 'Home Screen app' : 'Browser tab' }}
                                </span>
                            </p>
                            <p v-if="!iosStandalone" class="text-amber-600 dark:text-amber-400">
                                iOS push only works for the installed Home Screen app, not Safari tabs.
                            </p>
                            <p>
                                Device subscription:
                                <span class="font-medium text-foreground">
                                    {{ webPushHasSubscription ? 'Active' : 'Not active' }}
                                </span>
                            </p>
                        </div>

                        <div class="flex flex-wrap gap-2">
                            <Button
                                type="button"
                                :disabled="webPushBusy || !webPushSupported || !props.webPush.configured"
                                @click="enableWebPush"
                            >
                                Enable Push On This Device
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                :disabled="webPushBusy || !webPushHasSubscription"
                                @click="sendTestPush"
                            >
                                Send Test Push
                            </Button>
                            <Button
                                type="button"
                                variant="ghost"
                                :disabled="webPushBusy || !webPushHasSubscription"
                                @click="disableWebPush"
                            >
                                Disable Push
                            </Button>
                        </div>

                        <p v-if="!props.webPush.configured" class="text-sm text-amber-600 dark:text-amber-400">
                            Push notifications are not configured on the server yet. Add VAPID keys in environment settings.
                        </p>
                        <p v-if="webPushMessage" class="text-sm text-emerald-600 dark:text-emerald-400">
                            {{ webPushMessage }}
                        </p>
                        <p v-if="webPushError" class="text-sm text-red-600 dark:text-red-400">
                            {{ webPushError }}
                        </p>
                    </div>

                    <input type="hidden" name="minimum_edge" :value="String(currentPreference.minimum_edge ?? '0')" />

                    <input type="hidden" name="time_window_start" :value="currentPreference.time_window_start" />
                    <input type="hidden" name="time_window_end" :value="currentPreference.time_window_end" />
                    <input type="hidden" name="digest_mode" :value="autoDigestMode" />
                    <input type="hidden" name="digest_time" :value="currentPreference.digest_time || '10:00'" />

                    <!-- Phone Number (for future SMS) -->
                    <div class="space-y-3 opacity-50">
                        <div>
                            <Label for="phone_number" class="text-base">Phone Number (Optional)</Label>
                            <p class="text-sm text-muted-foreground">
                                For SMS notifications (coming soon)
                            </p>
                        </div>

                        <Input
                            id="phone_number"
                            name="phone_number"
                            type="tel"
                            :default-value="currentPreference.phone_number || ''"
                            class="max-w-xs"
                            placeholder="+1 (555) 000-0000"
                            disabled
                        />
                        <InputError :message="errors.phone_number" />
                    </div>

                    <!-- Submit Button -->
                    <div class="flex items-center gap-4">
                        <Button
                            :disabled="processing"
                            data-test="save-alert-preferences-button"
                        >
                            Save Preferences
                        </Button>

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
            </RenderErrorBoundary>
        </SettingsLayout>
    </AppLayout>
</template>
