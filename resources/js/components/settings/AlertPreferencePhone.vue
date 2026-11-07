<script setup lang="ts">
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import InputError from '@/components/InputError.vue';
import { useAlertPreferencesContext } from '@/composables/useAlertPreferences';
const { currentPreference, needsPhoneNumber } = useAlertPreferencesContext();
defineProps<{ errors: Record<string, string> }>();
</script>
<template>
    <div class="space-y-3" :class="!needsPhoneNumber ? 'opacity-60' : ''">
        <div>
            <Label for="phone_number" class="text-base"
                >Phone Number (Optional)</Label
            >
            <p class="text-sm text-muted-foreground">
                Required for WhatsApp alerts. Use full international format
                (e.g. +15551234567).
            </p>
        </div>

        <Input
            id="phone_number"
            name="phone_number"
            type="tel"
            :default-value="currentPreference.phone_number || ''"
            class="max-w-xs"
            placeholder="+15550000000"
        />
        <InputError :message="errors.phone_number" />
    </div>
</template>
