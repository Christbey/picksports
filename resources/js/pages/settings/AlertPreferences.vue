<script setup lang="ts">
import { Form, Head, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import RenderErrorBoundary from '@/components/RenderErrorBoundary.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { update } from '@/routes/alert-preferences';
import { type BreadcrumbItem } from '@/types';

interface Preference {
    enabled: boolean;
    sports: string[];
    notification_types: string[];
    enabled_template_ids: number[];
    minimum_edge: number;
    time_window_start: string;
    time_window_end: string;
    digest_mode: string;
    digest_time: string | null;
    phone_number: string | null;
}

interface AvailableTemplate {
    id: number;
    name: string;
    description: string | null;
}

interface AdminStats {
    total_users_with_alerts: number;
    total_preferences: number;
    users_by_sport: Record<string, number>;
}

const props = defineProps<{
    preference: Preference | null;
    availableTemplates: AvailableTemplate[];
    adminStats?: AdminStats;
}>();

const page = usePage();
const isAdmin = computed(() => page.props.auth.user?.is_admin);

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
const usersBySport = computed<Record<string, number>>(() => props.adminStats?.users_by_sport ?? {});
const mostPopularSport = computed(() => {
    const entries = Object.entries(usersBySport.value);
    if (entries.length === 0) return 'N/A';
    return entries.reduce((maxEntry, entry) => (entry[1] > maxEntry[1] ? entry : maxEntry))[0].toUpperCase();
});
const maxUsersBySport = computed(() => {
    const counts = Object.values(usersBySport.value);
    return counts.length ? Math.max(...counts) : 0;
});

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

const selectedSports = ref<string[]>(currentPreference.value.sports);
const selectedNotificationTypes = ref<string[]>(currentPreference.value.notification_types);
const selectedTemplateIds = ref<number[]>(currentPreference.value.enabled_template_ids);
const selectedDigestMode = ref<string>(currentPreference.value.digest_mode);

function toggleSport(sport: string) {
    const index = selectedSports.value.indexOf(sport);
    if (index === -1) {
        selectedSports.value.push(sport);
    } else {
        selectedSports.value.splice(index, 1);
    }
}

function toggleNotificationType(type: string) {
    const index = selectedNotificationTypes.value.indexOf(type);
    if (index === -1) {
        selectedNotificationTypes.value.push(type);
    } else {
        selectedNotificationTypes.value.splice(index, 1);
    }
}

function toggleTemplate(templateId: number) {
    const index = selectedTemplateIds.value.indexOf(templateId);
    if (index === -1) {
        selectedTemplateIds.value.push(templateId);
    } else {
        selectedTemplateIds.value.splice(index, 1);
    }
}

function isSportSelected(sport: string): boolean {
    return selectedSports.value.includes(sport);
}

function isNotificationTypeSelected(type: string): boolean {
    return selectedNotificationTypes.value.includes(type);
}

function isTemplateSelected(templateId: number): boolean {
    return selectedTemplateIds.value.includes(templateId);
}

const checkingAlerts = ref(false);
const lastCheckResult = ref<string | null>(null);

function checkAlerts(sport?: string) {
    checkingAlerts.value = true;
    lastCheckResult.value = null;

    const data = sport ? { sport } : {};

    router.post('/settings/alert-preferences/check-alerts', data, {
        preserveScroll: true,
        onSuccess: (response: any) => {
            const data = response.props.flash?.data || response.props?.data;
            if (data) {
                lastCheckResult.value = data.message;
            } else {
                lastCheckResult.value = 'Alert check completed';
            }
            setTimeout(() => {
                lastCheckResult.value = null;
            }, 5000);
        },
        onError: () => {
            lastCheckResult.value = 'Error checking alerts';
            setTimeout(() => {
                lastCheckResult.value = null;
            }, 5000);
        },
        onFinish: () => {
            checkingAlerts.value = false;
        },
    });
}
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
                            <Checkbox
                                name="enabled"
                                :default-checked="currentPreference.enabled"
                                id="enabled"
                            />
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

                    <!-- Sports Selection -->
                    <div class="space-y-3">
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
                            >
                                <Checkbox
                                    :name="`sports[${sport.value}]`"
                                    :value="sport.value"
                                    :id="`sport-${sport.value}`"
                                    :checked="isSportSelected(sport.value)"
                                    @update:checked="() => toggleSport(sport.value)"
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
                    <div class="space-y-3">
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
                                :class="{ 'opacity-50': type.disabled }"
                            >
                                <Checkbox
                                    :name="`notification_types[${type.value}]`"
                                    :value="type.value"
                                    :id="`notification-${type.value}`"
                                    :checked="isNotificationTypeSelected(type.value)"
                                    :disabled="type.disabled"
                                    @update:checked="() => toggleNotificationType(type.value)"
                                />
                                <Label
                                    :for="`notification-${type.value}`"
                                    class="text-sm font-medium cursor-pointer"
                                >
                                    {{ type.label }}
                                </Label>
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

                    <!-- Notification Templates -->
                    <div v-if="availableTemplates.length > 0" class="space-y-3">
                        <div>
                            <Label class="text-base">Notification Templates</Label>
                            <p class="text-sm text-muted-foreground">
                                Choose which types of alerts you want to receive. Leave all unchecked to receive all template types.
                            </p>
                        </div>

                        <div class="grid grid-cols-1 gap-3">
                            <div
                                v-for="template in availableTemplates"
                                :key="template.id"
                                class="flex items-start gap-3 rounded-lg border border-sidebar-border bg-white p-4 dark:bg-sidebar"
                            >
                                <Checkbox
                                    :name="`enabled_template_ids[${template.id}]`"
                                    :value="template.id"
                                    :id="`template-${template.id}`"
                                    :checked="isTemplateSelected(template.id)"
                                    @update:checked="() => toggleTemplate(template.id)"
                                />
                                <div class="flex-1">
                                    <Label
                                        :for="`template-${template.id}`"
                                        class="text-sm font-medium cursor-pointer"
                                    >
                                        {{ template.name.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase()) }}
                                    </Label>
                                    <p v-if="template.description" class="mt-0.5 text-xs text-muted-foreground">
                                        {{ template.description }}
                                    </p>
                                </div>
                            </div>
                        </div>

                        <input
                            v-for="templateId in selectedTemplateIds"
                            :key="templateId"
                            type="hidden"
                            name="enabled_template_ids[]"
                            :value="templateId"
                        />

                        <InputError class="mt-2" :message="errors.enabled_template_ids" />
                    </div>

                    <!-- Minimum Edge -->
                    <div class="space-y-3">
                        <div>
                            <Label for="minimum_edge" class="text-base">Minimum Expected Value (%)</Label>
                            <p class="text-sm text-muted-foreground">
                                Only receive alerts for bets with at least this much expected value
                            </p>
                        </div>

                        <Input
                            id="minimum_edge"
                            name="minimum_edge"
                            type="number"
                            min="0"
                            max="100"
                            step="0.1"
                            :default-value="currentPreference.minimum_edge"
                            class="max-w-xs"
                            placeholder="5.0"
                        />
                        <InputError class="mt-2" :message="errors.minimum_edge" />
                    </div>

                    <!-- Time Window -->
                    <div class="space-y-3">
                        <div>
                            <Label class="text-base">Notification Time Window</Label>
                            <p class="text-sm text-muted-foreground">
                                Set quiet hours when you don't want to receive alerts
                            </p>
                        </div>

                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 max-w-md">
                            <div class="grid gap-2">
                                <Label for="time_window_start">Start Time</Label>
                                <Input
                                    id="time_window_start"
                                    name="time_window_start"
                                    type="time"
                                    :default-value="currentPreference.time_window_start"
                                />
                                <InputError :message="errors.time_window_start" />
                            </div>

                            <div class="grid gap-2">
                                <Label for="time_window_end">End Time</Label>
                                <Input
                                    id="time_window_end"
                                    name="time_window_end"
                                    type="time"
                                    :default-value="currentPreference.time_window_end"
                                />
                                <InputError :message="errors.time_window_end" />
                            </div>
                        </div>
                    </div>

                    <!-- Digest Mode -->
                    <div class="space-y-3">
                        <div>
                            <Label for="digest_mode" class="text-base">Delivery Mode</Label>
                            <p class="text-sm text-muted-foreground">
                                Choose when to receive alerts
                            </p>
                        </div>

                        <div class="space-y-3">
                            <div class="flex items-center gap-3 rounded-lg border border-sidebar-border bg-white p-4 dark:bg-sidebar">
                                <input
                                    type="radio"
                                    name="digest_mode"
                                    value="realtime"
                                    id="digest-realtime"
                                    :checked="selectedDigestMode === 'realtime'"
                                    @change="selectedDigestMode = 'realtime'"
                                    class="size-4 border-input text-primary focus:ring-primary"
                                />
                                <Label for="digest-realtime" class="cursor-pointer">
                                    <div class="font-medium">Real-time</div>
                                    <div class="text-sm text-muted-foreground">
                                        Get notified immediately when high-value opportunities are found
                                    </div>
                                </Label>
                            </div>

                            <div class="flex items-center gap-3 rounded-lg border border-sidebar-border bg-white p-4 dark:bg-sidebar">
                                <input
                                    type="radio"
                                    name="digest_mode"
                                    value="daily_summary"
                                    id="digest-daily"
                                    :checked="selectedDigestMode === 'daily_summary'"
                                    @change="selectedDigestMode = 'daily_summary'"
                                    class="size-4 border-input text-primary focus:ring-primary"
                                />
                                <Label for="digest-daily" class="cursor-pointer">
                                    <div class="font-medium">Daily Summary</div>
                                    <div class="text-sm text-muted-foreground">
                                        Receive one consolidated email per day with the top betting opportunities
                                    </div>
                                </Label>
                            </div>
                        </div>

                        <InputError :message="errors.digest_mode" />

                        <!-- Digest Time Picker (only for daily_summary mode) -->
                        <div v-if="selectedDigestMode === 'daily_summary'" class="mt-4 grid gap-2 max-w-xs">
                            <Label for="digest_time">Digest Delivery Time</Label>
                            <Input
                                id="digest_time"
                                name="digest_time"
                                type="time"
                                :default-value="currentPreference.digest_time || '10:00'"
                            />
                            <p class="text-xs text-muted-foreground">
                                Receive your daily digest at this time (your local timezone)
                            </p>
                            <InputError :message="errors.digest_time" />
                        </div>
                    </div>

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

                <!-- Admin Section -->
                <div v-if="isAdmin && adminStats" class="space-y-6 mt-12">
                    <Separator />

                    <Heading
                        variant="small"
                        title="Admin Controls"
                        description="Manage alert system and view statistics"
                    />

                    <!-- Stats Cards -->
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <div class="rounded-xl border border-sidebar-border bg-white p-6 dark:bg-sidebar">
                            <div class="text-sm font-medium text-muted-foreground">Total Users with Alerts</div>
                            <div class="mt-2 text-3xl font-bold">{{ adminStats.total_users_with_alerts }}</div>
                            <div class="mt-1 text-xs text-muted-foreground">
                                out of {{ adminStats.total_preferences }} total preferences
                            </div>
                        </div>

                        <div class="rounded-xl border border-sidebar-border bg-white p-6 dark:bg-sidebar">
                            <div class="text-sm font-medium text-muted-foreground">Most Popular Sport</div>
                            <div class="mt-2 text-3xl font-bold">
                                {{ mostPopularSport }}
                            </div>
                            <div class="mt-1 text-xs text-muted-foreground">
                                {{ maxUsersBySport }} users subscribed
                            </div>
                        </div>

                        <div class="rounded-xl border border-sidebar-border bg-white p-6 dark:bg-sidebar">
                            <div class="text-sm font-medium text-muted-foreground">Sport Coverage</div>
                            <div class="mt-2 space-y-1">
                                <div v-for="(count, sport) in adminStats.users_by_sport" :key="sport" class="flex justify-between text-sm">
                                    <span class="font-medium">{{ sport.toUpperCase() }}</span>
                                    <span class="text-muted-foreground">{{ count }}</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Manual Alert Trigger -->
                    <div class="rounded-xl border border-sidebar-border bg-white p-6 dark:bg-sidebar">
                        <h3 class="text-sm font-semibold mb-4">Manual Alert Check</h3>
                        <p class="text-sm text-muted-foreground mb-4">
                            Manually trigger the alert system to check for betting opportunities across all sports or a specific sport.
                        </p>

                        <div class="flex flex-wrap gap-2">
                            <Button
                                @click="checkAlerts()"
                                :disabled="checkingAlerts"
                                variant="default"
                            >
                                {{ checkingAlerts ? 'Checking...' : 'Check All Sports' }}
                            </Button>

                            <Button
                                v-for="sport in availableSports"
                                :key="sport.value"
                                @click="checkAlerts(sport.value)"
                                :disabled="checkingAlerts"
                                variant="outline"
                            >
                                {{ sport.value.toUpperCase() }}
                            </Button>
                        </div>

                        <div v-if="lastCheckResult" class="mt-4 rounded-lg bg-green-50 p-3 dark:bg-green-900/20">
                            <p class="text-sm text-green-800 dark:text-green-200">
                                {{ lastCheckResult }}
                            </p>
                        </div>
                    </div>
                </div>
                </div>
            </RenderErrorBoundary>
        </SettingsLayout>
    </AppLayout>
</template>
