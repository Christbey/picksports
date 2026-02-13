<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { Check, Zap } from 'lucide-vue-next';
import { useSubscription } from '@/composables/useSubscription';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { plans as subscriptionPlans } from '@/routes/subscription';

interface Props {
    title?: string;
    description?: string;
    features?: string[];
    ctaText?: string;
    variant?: 'default' | 'compact';
}

const props = withDefaults(defineProps<Props>(), {
    title: '',
    description: '',
    features: () => [],
    ctaText: 'Upgrade Now',
    variant: 'default',
});

const { isPremium, getUpgradeMessage, tierName } = useSubscription();

const message = computed(() => getUpgradeMessage());

const displayTitle = computed(() => props.title || message.value.title);
const displayDescription = computed(() => props.description || message.value.description);

const defaultFeatures = computed(() => {
    if (props.features.length > 0) {
        return props.features;
    }
    return [
        'Unlimited predictions',
        'Advanced analytics',
        'All sports access',
    ];
});

const shouldShow = computed(() => {
    return !isPremium.value && displayTitle.value;
});
</script>

<template>
    <Card v-if="shouldShow" class="border-dashed border-indigo-500/30 bg-gradient-to-br from-indigo-50/50 to-purple-50/50 dark:from-indigo-950/20 dark:to-purple-950/20">
        <CardHeader :class="variant === 'compact' ? 'pb-2' : ''">
            <div class="flex items-center gap-2">
                <div class="flex h-8 w-8 items-center justify-center rounded-full bg-indigo-500/10">
                    <Zap class="h-4 w-4 text-indigo-500" />
                </div>
                <div>
                    <CardTitle class="text-base">{{ displayTitle }}</CardTitle>
                    <p class="text-xs text-muted-foreground">
                        Current plan: {{ tierName }}
                    </p>
                </div>
            </div>
        </CardHeader>

        <CardContent :class="variant === 'compact' ? 'pt-0' : ''">
            <p v-if="variant !== 'compact'" class="mb-4 text-sm text-muted-foreground">
                {{ displayDescription }}
            </p>

            <ul class="mb-4 space-y-2">
                <li
                    v-for="feature in defaultFeatures"
                    :key="feature"
                    class="flex items-center gap-2 text-sm"
                >
                    <Check class="h-4 w-4 text-green-500" />
                    <span>{{ feature }}</span>
                </li>
            </ul>

            <Button class="w-full" size="sm" as-child>
                <Link :href="subscriptionPlans()">
                    {{ ctaText }}
                </Link>
            </Button>
        </CardContent>
    </Card>
</template>
