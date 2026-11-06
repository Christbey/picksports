<script setup lang="ts">
import { computed } from 'vue';
import {
    TabsTrigger,
    injectTabsRootContext,
    useForwardProps,
    type TabsTriggerProps,
} from 'reka-ui';
const props = defineProps<TabsTriggerProps>();
const forwarded = useForwardProps(props);
const context = injectTabsRootContext();
const isActive = computed(() => context.modelValue.value === props.value);
</script>
<template>
    <TabsTrigger
        v-bind="forwarded"
        :class="[
            'inline-flex min-h-11 items-center justify-center rounded-xl px-3.5 py-1.5 text-sm font-semibold tracking-tight whitespace-nowrap ring-offset-background transition-all duration-200 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none disabled:pointer-events-none disabled:opacity-50',
            isActive
                ? 'bg-background text-foreground shadow-sm'
                : 'text-muted-foreground hover:bg-background/65 hover:text-foreground',
        ]"
        ><slot
    /></TabsTrigger>
</template>
