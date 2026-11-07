<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { useAlertPreferencesContext } from '@/composables/useAlertPreferences';
const {
    props,
    webPushSupported,
    webPushPermission,
    webPushHasSubscription,
    webPushBusy,
    webPushMessage,
    webPushError,
    iosStandalone,
    enableWebPush,
    disableWebPush,
    sendTestPush,
} = useAlertPreferencesContext();
</script>
<template>
    <div
        class="space-y-3 rounded-xl border border-sidebar-border bg-white p-6 dark:bg-sidebar"
    >
        <div>
            <Label class="text-base">Web Push Setup (iOS Home Screen)</Label>
            <p class="text-sm text-muted-foreground">
                Enable browser push for this device. On iPhone/iPad, install
                this app to the Home Screen first.
            </p>
        </div>

        <div class="space-y-1 text-sm text-muted-foreground">
            <p>
                Browser support:
                <span class="font-medium text-foreground">
                    {{
                        webPushSupported
                            ? 'Available'
                            : 'Not available on this browser'
                    }}
                </span>
            </p>
            <p>
                Permission:
                <span class="font-medium text-foreground">{{
                    webPushPermission
                }}</span>
            </p>
            <p>
                Installed mode:
                <span class="font-medium text-foreground">
                    {{ iosStandalone ? 'Home Screen app' : 'Browser tab' }}
                </span>
            </p>
            <p v-if="!iosStandalone" class="text-amber-600 dark:text-amber-400">
                iOS push only works for the installed Home Screen app, not
                Safari tabs.
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
                :disabled="
                    webPushBusy ||
                    !webPushSupported ||
                    !props.webPush.configured
                "
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

        <p
            v-if="!props.webPush.configured"
            class="text-sm text-amber-600 dark:text-amber-400"
        >
            Push notifications are not configured on the server yet. Add VAPID
            keys in environment settings.
        </p>
        <p
            v-if="webPushMessage"
            class="text-sm text-emerald-600 dark:text-emerald-400"
        >
            {{ webPushMessage }}
        </p>
        <p v-if="webPushError" class="text-sm text-red-600 dark:text-red-400">
            {{ webPushError }}
        </p>
    </div>
</template>
