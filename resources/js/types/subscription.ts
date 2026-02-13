export type SubscriptionTier = 'free' | 'basic' | 'pro' | 'premium';

export interface SubscriptionFeatures {
    predictions_per_day: number | null;
    historical_days: number;
    sports: string[];
    export_data: boolean;
    api_access: boolean;
    advanced_analytics: boolean;
    email_alerts: boolean;
    priority_support: boolean;
    [key: string]: unknown;
}

export interface SubscriptionInfo {
    tier: SubscriptionTier;
    tier_name: string;
    is_subscribed: boolean;
    features: SubscriptionFeatures;
}
