<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { Lock } from 'lucide-vue-next';
import { useSubscription } from '@/composables/useSubscription';
import { Button } from '@/components/ui/button';
import { plans as subscriptionPlans } from '@/routes/subscription';
import type { SubscriptionTier } from '@/types';

interface Props {
    requiredTier: Exclude<SubscriptionTier, 'free'>;
    featureName?: string;
    blur?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    featureName: 'this feature',
    blur: true,
});

const { canAccessTier, tierName } = useSubscription();

const hasAccess = computed(() => canAccessTier(props.requiredTier));

const tierNames: Record<SubscriptionTier, string> = {
    free: 'Free',
    basic: 'Basic',
    pro: 'Pro',
    premium: 'Premium',
};

const requiredTierName = computed(() => tierNames[props.requiredTier]);
</script>

<template>
    <div class="relative">
        <!-- Content (blurred if locked) -->
        <div
            :class="[
                'transition-all duration-200',
                !hasAccess && blur ? 'pointer-events-none select-none blur-sm' : '',
            ]"
        >
            <slot />
        </div>

        <!-- Lock overlay -->
        <div
            v-if="!hasAccess"
            class="absolute inset-0 flex items-center justify-center bg-background/60 backdrop-blur-[2px]"
        >
            <slot name="locked">
                <div class="flex flex-col items-center gap-3 rounded-lg bg-card p-6 text-center shadow-lg">
                    <div class="flex h-12 w-12 items-center justify-center rounded-full bg-muted">
                        <Lock class="h-6 w-6 text-muted-foreground" />
                    </div>
                    <div>
                        <p class="font-medium">
                            {{ requiredTierName }} Required
                        </p>
                        <p class="mt-1 text-sm text-muted-foreground">
                            Upgrade from {{ tierName }} to access {{ featureName }}.
                        </p>
                    </div>
                    <Button size="sm" as-child>
                        <Link :href="subscriptionPlans()">
                            Upgrade Now
                        </Link>
                    </Button>
                </div>
            </slot>
        </div>
    </div>
</template>
