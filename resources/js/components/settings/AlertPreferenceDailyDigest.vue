<script setup lang="ts">
import { Label } from '@/components/ui/label';
import { Checkbox } from '@/components/ui/checkbox';
import InputError from '@/components/InputError.vue';
import { useAlertPreferencesContext } from '@/composables/useAlertPreferences';
const { dailyDigestSubscribed, onDailyDigestSubscribedChange } =
    useAlertPreferencesContext();
defineProps<{ errors: Record<string, string> }>();
</script>
<template>
    <div
        class="rounded-xl border border-sidebar-border bg-white p-6 dark:bg-sidebar"
    >
        <div class="flex items-start gap-3">
            <input type="hidden" name="daily_digest_subscribed" value="0" />
            <Checkbox
                id="daily_digest_subscribed"
                :model-value="dailyDigestSubscribed"
                @update:model-value="onDailyDigestSubscribedChange"
            />
            <input
                v-if="dailyDigestSubscribed"
                type="hidden"
                name="daily_digest_subscribed"
                value="1"
            />
            <div class="grid gap-1.5 leading-none">
                <Label
                    for="daily_digest_subscribed"
                    class="cursor-pointer text-sm leading-none font-medium peer-disabled:cursor-not-allowed peer-disabled:opacity-70"
                >
                    Receive Daily Digest Emails
                </Label>
                <p class="text-sm text-muted-foreground">
                    Daily digests are on by default. Turn this off to
                    unsubscribe from the 10:00 AM digest email.
                </p>
            </div>
        </div>
        <InputError class="mt-2" :message="errors.daily_digest_subscribed" />
    </div>
</template>
