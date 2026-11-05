export interface Preference {
    enabled: boolean;
    sports: string[];
    notification_types: string[];
    enabled_template_ids: number[]; // managed by server-side template-selection flow
    minimum_edge: number | string;
    time_window_start: string;
    time_window_end: string;
    digest_mode: string;
    digest_time: string | null;
    daily_digest_subscribed: boolean;
    phone_number: string | null;
}

export interface WebPushState {
    configured: boolean;
    publicKey: string | null;
    hasSubscription: boolean;
}

export interface AlertPreferencesProps {
    preference: Preference | null;
    webPush: WebPushState;
}
