<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { X, Sparkles } from 'lucide-vue-next';
import { ref, computed, onMounted } from 'vue';
import { Button } from '@/components/ui/button';
import { useSubscription } from '@/composables/useSubscription';
import { plans as subscriptionPlans } from '@/routes/subscription';

interface Props {
    variant?: 'gradient' | 'subtle' | 'minimal';
    dismissible?: boolean;
    storageKey?: string;
}

const props = withDefaults(defineProps<Props>(), {
    variant: 'gradient',
    dismissible: true,
    storageKey: 'subscription-banner-dismissed',
});

const { isPremium, getUpgradeMessage } = useSubscription();

const isDismissed = ref(false);

const message = computed(() => getUpgradeMessage());

const shouldShow = computed(() => {
    return !isPremium.value && !isDismissed.value && message.value.title;
});

const gradientClasses = computed(() => {
    switch (props.variant) {
        case 'gradient':
            return 'bg-gradient-to-r from-indigo-600 via-purple-600 to-pink-600';
        case 'subtle':
            return 'bg-gradient-to-r from-indigo-500/10 via-purple-500/10 to-pink-500/10 border border-indigo-500/20';
        case 'minimal':
            return 'bg-sidebar-accent/50 border border-sidebar-border';
        default:
            return 'bg-gradient-to-r from-indigo-600 via-purple-600 to-pink-600';
    }
});

const textClasses = computed(() => {
    switch (props.variant) {
        case 'gradient':
            return 'text-white';
        case 'subtle':
        case 'minimal':
            return 'text-foreground';
        default:
            return 'text-white';
    }
});

const buttonVariant = computed(() => {
    return props.variant === 'gradient' ? 'secondary' : 'default';
});

function dismiss() {
    isDismissed.value = true;
    if (props.dismissible) {
        sessionStorage.setItem(props.storageKey, 'true');
    }
}

onMounted(() => {
    if (props.dismissible) {
        isDismissed.value = sessionStorage.getItem(props.storageKey) === 'true';
    }
});
</script>

<template>
    <div
        v-if="shouldShow"
        :class="[
            'relative overflow-hidden rounded-xl p-4 md:p-6',
            gradientClasses,
        ]"
    >
        <div class="flex flex-col items-start justify-between gap-4 md:flex-row md:items-center">
            <div class="flex items-center gap-3">
                <div
                    :class="[
                        'flex h-10 w-10 shrink-0 items-center justify-center rounded-full',
                        variant === 'gradient' ? 'bg-white/20' : 'bg-indigo-500/20',
                    ]"
                >
                    <Sparkles
                        :class="[
                            'h-5 w-5',
                            variant === 'gradient' ? 'text-white' : 'text-indigo-500',
                        ]"
                    />
                </div>
                <div>
                    <h3 :class="['font-semibold', textClasses]">
                        {{ message.title }}
                    </h3>
                    <p
                        :class="[
                            'text-sm',
                            variant === 'gradient' ? 'text-white/80' : 'text-muted-foreground',
                        ]"
                    >
                        {{ message.description }}
                    </p>
                </div>
            </div>

            <div class="flex items-center gap-3">
                <Button :variant="buttonVariant" size="sm" as-child>
                    <Link :href="subscriptionPlans()">
                        View Plans
                    </Link>
                </Button>

                <button
                    v-if="dismissible"
                    type="button"
                    :class="[
                        'rounded-full p-1 transition-colors',
                        variant === 'gradient'
                            ? 'text-white/60 hover:bg-white/10 hover:text-white'
                            : 'text-muted-foreground hover:bg-muted hover:text-foreground',
                    ]"
                    @click="dismiss"
                >
                    <X class="h-4 w-4" />
                    <span class="sr-only">Dismiss</span>
                </button>
            </div>
        </div>
    </div>
</template>
