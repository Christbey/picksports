import { ref, onMounted, type Ref } from 'vue';
import webPushRoutes from '@/routes/web-push';
import type { AlertPreferencesProps } from '@/types/alert-preferences';
export function useWebPushPreferences(
    props: Readonly<AlertPreferencesProps>,
    selectedNotificationTypes: Ref<string[]>,
) {
    const webPushSupported = ref(false);
    const webPushPermission = ref<NotificationPermission>('default');
    const webPushHasSubscription = ref<boolean>(
        props.webPush?.hasSubscription ?? false,
    );
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
        const base64 = (base64String + padding)
            .replace(/-/g, '+')
            .replace(/_/g, '/');
        const rawData = window.atob(base64);
        const outputArray = new Uint8Array(rawData.length);

        for (let i = 0; i < rawData.length; ++i) {
            outputArray[i] = rawData.charCodeAt(i);
        }

        return outputArray;
    }

    function toArrayBuffer(bytes: Uint8Array): ArrayBuffer {
        const copy = new Uint8Array(bytes.byteLength);
        copy.set(bytes);
        return copy.buffer;
    }

    async function enableWebPush() {
        webPushBusy.value = true;
        webPushError.value = null;
        webPushMessage.value = null;

        try {
            if (!webPushSupported.value) {
                throw new Error(
                    'This browser does not support push notifications.',
                );
            }

            if (!props.webPush.configured || !props.webPush.publicKey) {
                throw new Error(
                    'Push notifications are not configured on the server yet.',
                );
            }

            const registration =
                await navigator.serviceWorker.register('/sw.js');
            const permission = await Notification.requestPermission();
            webPushPermission.value = permission;

            if (permission !== 'granted') {
                throw new Error('Notification permission was not granted.');
            }

            let subscription = await registration.pushManager.getSubscription();
            if (!subscription) {
                subscription = await registration.pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey: toArrayBuffer(
                        urlBase64ToUint8Array(props.webPush.publicKey),
                    ),
                });
            }

            const response = await fetch(
                webPushRoutes.subscriptions.store.url(),
                {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': getCsrfToken(),
                        Accept: 'application/json',
                    },
                    body: JSON.stringify(subscription.toJSON()),
                },
            );

            if (!response.ok) {
                throw new Error(
                    'Could not save push subscription on the server.',
                );
            }

            webPushHasSubscription.value = true;
            webPushMessage.value =
                'Push notifications are enabled for this device.';

            if (!selectedNotificationTypes.value.includes('push')) {
                selectedNotificationTypes.value.push('push');
            }
        } catch (error) {
            webPushError.value =
                error instanceof Error
                    ? error.message
                    : 'Failed to enable push notifications.';
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
                const subscription =
                    await registration.pushManager.getSubscription();

                if (subscription) {
                    await subscription.unsubscribe();
                    await fetch(webPushRoutes.subscriptions.destroy.url(), {
                        method: 'DELETE',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': getCsrfToken(),
                            Accept: 'application/json',
                        },
                        body: JSON.stringify({
                            endpoint: subscription.endpoint,
                        }),
                    });
                }
            }

            webPushHasSubscription.value = false;
            webPushMessage.value =
                'Push notifications were disabled for this device.';
        } catch (error) {
            webPushError.value =
                error instanceof Error
                    ? error.message
                    : 'Failed to disable push notifications.';
        } finally {
            webPushBusy.value = false;
        }
    }

    async function sendTestPush() {
        webPushBusy.value = true;
        webPushError.value = null;
        webPushMessage.value = null;

        try {
            const response = await fetch(webPushRoutes.test.url(), {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': getCsrfToken(),
                    Accept: 'application/json',
                },
            });

            const data = await response.json();

            if (!response.ok || !data.ok) {
                throw new Error(
                    data.message ?? 'Failed to send test push notification.',
                );
            }

            webPushMessage.value = data.message ?? 'Test push sent.';
        } catch (error) {
            webPushError.value =
                error instanceof Error
                    ? error.message
                    : 'Failed to send test notification.';
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
            (window.navigator as Navigator & { standalone?: boolean })
                .standalone === true;
    });

    return {
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
    };
}
