import { computed, ref, inject, type InjectionKey } from 'vue';
import type { BreadcrumbItem } from '@/types';
import type {
    Preference,
    AlertPreferencesProps,
} from '@/types/alert-preferences';
import { useWebPushPreferences } from '@/composables/useWebPushPreferences';
export function useAlertPreferences(props: Readonly<AlertPreferencesProps>) {
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
        daily_digest_subscribed: true,
        phone_number: null,
    };

    const currentPreference = computed(
        () => props.preference || defaultPreference,
    );

    const availableNotificationTypes = [
        { value: 'email', label: 'Email' },
        { value: 'push', label: 'Push Notifications' },
        { value: 'whatsapp', label: 'WhatsApp' },
        { value: 'sms', label: 'SMS (Coming Soon)', disabled: true },
    ];

    function normalizeStringArray(value: unknown): string[] {
        if (!Array.isArray(value)) return [];
        return value
            .map((item) => String(item).trim().toLowerCase())
            .filter((item) => item.length > 0);
    }

    const selectedNotificationTypes = ref<string[]>(
        normalizeStringArray(currentPreference.value.notification_types),
    );
    const alertsEnabled = ref<boolean>(currentPreference.value.enabled);
    const dailyDigestSubscribed = ref<boolean>(
        currentPreference.value.daily_digest_subscribed ?? true,
    );

    const notificationLabelMap = computed<Record<string, string>>(() =>
        Object.fromEntries(
            availableNotificationTypes.map((type) => [
                type.value,
                type.label.replace(' (Coming Soon)', ''),
            ]),
        ),
    );
    const selectedNotificationLabels = computed(() =>
        selectedNotificationTypes.value.map(
            (type) => notificationLabelMap.value[type] ?? type.toUpperCase(),
        ),
    );
    const autoDigestMode = computed(() =>
        selectedNotificationTypes.value.includes('push')
            ? 'realtime'
            : 'daily_summary',
    );
    const digestModeLabel = computed(() =>
        autoDigestMode.value === 'daily_summary'
            ? 'Daily Summary'
            : 'Real-time',
    );
    const needsPhoneNumber = computed(
        () =>
            selectedNotificationTypes.value.includes('whatsapp') ||
            selectedNotificationTypes.value.includes('sms'),
    );

    function onEnabledChange(checked: boolean | 'indeterminate') {
        alertsEnabled.value = checked === true;
    }

    function onDailyDigestSubscribedChange(checked: boolean | 'indeterminate') {
        dailyDigestSubscribed.value = checked === true;
    }

    function toggleNotificationType(
        type: string,
        checked: boolean | 'indeterminate',
    ) {
        const shouldEnable = checked === true;
        const index = selectedNotificationTypes.value.indexOf(type);
        if (shouldEnable && index === -1) {
            selectedNotificationTypes.value.push(type);
        }
        if (!shouldEnable && index !== -1) {
            selectedNotificationTypes.value.splice(index, 1);
        }
    }

    function isNotificationTypeSelected(type: string): boolean {
        return selectedNotificationTypes.value.includes(type);
    }

    return {
        props,
        breadcrumbs,
        currentPreference,
        availableNotificationTypes,
        selectedNotificationTypes,
        alertsEnabled,
        dailyDigestSubscribed,
        selectedNotificationLabels,
        autoDigestMode,
        digestModeLabel,
        needsPhoneNumber,
        onEnabledChange,
        onDailyDigestSubscribedChange,
        toggleNotificationType,
        isNotificationTypeSelected,
        ...useWebPushPreferences(props, selectedNotificationTypes),
    };
}
export const alertPreferencesKey: InjectionKey<
    ReturnType<typeof useAlertPreferences>
> = Symbol('AlertPreferences');
export function useAlertPreferencesContext() {
    const state = inject(alertPreferencesKey);
    if (!state) throw new Error('AlertPreferences provider is missing.');
    return state;
}
