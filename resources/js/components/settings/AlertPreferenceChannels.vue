<script setup lang="ts">
import { Label } from '@/components/ui/label';
import { Checkbox } from '@/components/ui/checkbox';
import InputError from '@/components/InputError.vue';
import { useAlertPreferencesContext } from '@/composables/useAlertPreferences';
const {
    availableNotificationTypes,
    selectedNotificationTypes,
    toggleNotificationType,
    isNotificationTypeSelected,
} = useAlertPreferencesContext();
defineProps<{ errors: Record<string, string> }>();
</script>
<template>
    <div
        class="space-y-3 rounded-xl border border-sidebar-border bg-white p-6 dark:bg-sidebar"
    >
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
                    isNotificationTypeSelected(type.value)
                        ? 'border-primary bg-primary/5 dark:bg-primary/10'
                        : '',
                ]"
            >
                <Checkbox
                    :name="`notification_types[${type.value}]`"
                    :value="type.value"
                    :id="`notification-${type.value}`"
                    :model-value="isNotificationTypeSelected(type.value)"
                    :disabled="type.disabled"
                    @update:model-value="
                        (checked) => toggleNotificationType(type.value, checked)
                    "
                />
                <Label
                    :for="`notification-${type.value}`"
                    class="cursor-pointer text-sm font-medium"
                >
                    {{ type.label }}
                </Label>
                <span
                    v-if="type.disabled"
                    class="ml-auto rounded bg-sidebar-accent px-2 py-1 text-xs text-muted-foreground"
                >
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
</template>
