<script setup lang="ts">
import { Label } from '@/components/ui/label';
import { Checkbox } from '@/components/ui/checkbox';
import InputError from '@/components/InputError.vue';
import { useAlertPreferencesContext } from '@/composables/useAlertPreferences';
const { alertsEnabled, onEnabledChange } = useAlertPreferencesContext();
defineProps<{ errors: Record<string, string> }>();
</script>
<template>
    <div
        class="rounded-xl border border-sidebar-border bg-white p-6 dark:bg-sidebar"
    >
        <div class="flex items-start gap-3">
            <input type="hidden" name="enabled" value="0" />
            <Checkbox
                id="enabled"
                :model-value="alertsEnabled"
                @update:model-value="onEnabledChange"
            />
            <input
                v-if="alertsEnabled"
                type="hidden"
                name="enabled"
                value="1"
            />
            <div class="grid gap-1.5 leading-none">
                <Label
                    for="enabled"
                    class="cursor-pointer text-sm leading-none font-medium peer-disabled:cursor-not-allowed peer-disabled:opacity-70"
                >
                    Enable Betting Alerts
                </Label>
                <p class="text-sm text-muted-foreground">
                    Receive notifications when we identify high-value betting
                    opportunities based on your preferences
                </p>
            </div>
        </div>
        <InputError class="mt-2" :message="errors.enabled" />
    </div>
</template>
