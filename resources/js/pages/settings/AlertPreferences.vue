<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { provide } from 'vue';
import Heading from '@/components/Heading.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import type { AlertPreferencesProps } from '@/types/alert-preferences';
import RenderErrorBoundary from '@/components/RenderErrorBoundary.vue';
import { update } from '@/routes/alert-preferences';
import {
    useAlertPreferences,
    alertPreferencesKey,
} from '@/composables/useAlertPreferences';
import AlertPreferenceEnabled from '@/components/settings/AlertPreferenceEnabled.vue';
import AlertPreferenceSummary from '@/components/settings/AlertPreferenceSummary.vue';
import AlertPreferenceDailyDigest from '@/components/settings/AlertPreferenceDailyDigest.vue';
import AlertPreferenceChannels from '@/components/settings/AlertPreferenceChannels.vue';
import AlertPreferenceWebPush from '@/components/settings/AlertPreferenceWebPush.vue';
import AlertPreferencePhone from '@/components/settings/AlertPreferencePhone.vue';
import AlertPreferenceActions from '@/components/settings/AlertPreferenceActions.vue';
const props = defineProps<AlertPreferencesProps>();
const state = useAlertPreferences(props);
provide(alertPreferencesKey, state);
const { breadcrumbs, currentPreference, autoDigestMode } = state;
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
                        <AlertPreferenceEnabled :errors="errors" />

                        <AlertPreferenceSummary />

                        <!-- Notification Types -->
                        <AlertPreferenceDailyDigest :errors="errors" />

                        <AlertPreferenceChannels :errors="errors" />

                        <!-- Web Push Setup -->
                        <AlertPreferenceWebPush />

                        <input
                            type="hidden"
                            name="minimum_edge"
                            :value="
                                String(currentPreference.minimum_edge ?? '0')
                            "
                        />

                        <input
                            type="hidden"
                            name="time_window_start"
                            :value="currentPreference.time_window_start"
                        />
                        <input
                            type="hidden"
                            name="time_window_end"
                            :value="currentPreference.time_window_end"
                        />
                        <input
                            type="hidden"
                            name="digest_mode"
                            :value="autoDigestMode"
                        />
                        <input
                            type="hidden"
                            name="digest_time"
                            :value="currentPreference.digest_time || '10:00'"
                        />

                        <!-- Phone Number -->
                        <AlertPreferencePhone :errors="errors" />

                        <!-- Submit Button -->
                        <AlertPreferenceActions
                            :processing="processing"
                            :recentlySuccessful="recentlySuccessful"
                        />
                    </Form>
                </div>
            </RenderErrorBoundary>
        </SettingsLayout>
    </AppLayout>
</template>
