import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import type { AppPageProps, SubscriptionTier } from '@/types';

export function useSubscription() {
    const page = usePage<AppPageProps>();

    const subscription = computed(() => page.props.subscription);
    const tier = computed(() => subscription.value?.tier ?? 'free');
    const tierName = computed(() => subscription.value?.tier_name ?? 'Free');
    const isSubscribed = computed(() => subscription.value?.is_subscribed ?? false);
    const features = computed(() => subscription.value?.features ?? {});

    const isFree = computed(() => tier.value === 'free');
    const isBasic = computed(() => tier.value === 'basic');
    const isPro = computed(() => tier.value === 'pro');
    const isPremium = computed(() => tier.value === 'premium');

    const hasFeature = (feature: string): boolean => {
        return features.value?.[feature] ?? false;
    };

    const canAccessTier = (requiredTier: SubscriptionTier): boolean => {
        const tierOrder: SubscriptionTier[] = ['free', 'basic', 'pro', 'premium'];
        return tierOrder.indexOf(tier.value) >= tierOrder.indexOf(requiredTier);
    };

    const getUpgradeMessage = (): { title: string; description: string } => {
        switch (tier.value) {
            case 'free':
                return {
                    title: 'Unlock Unlimited Predictions',
                    description: 'Upgrade to get more predictions, advanced analytics, and access to all sports.',
                };
            case 'basic':
                return {
                    title: 'Go Pro for More Power',
                    description: 'Upgrade to Pro for unlimited predictions, API access, and advanced analytics.',
                };
            case 'pro':
                return {
                    title: 'Experience Premium',
                    description: 'Upgrade to Premium for priority support and exclusive features.',
                };
            default:
                return {
                    title: '',
                    description: '',
                };
        }
    };

    const getNextTier = (): SubscriptionTier | null => {
        switch (tier.value) {
            case 'free':
                return 'basic';
            case 'basic':
                return 'pro';
            case 'pro':
                return 'premium';
            default:
                return null;
        }
    };

    return {
        subscription,
        tier,
        tierName,
        isSubscribed,
        features,
        isFree,
        isBasic,
        isPro,
        isPremium,
        hasFeature,
        canAccessTier,
        getUpgradeMessage,
        getNextTier,
    };
}
