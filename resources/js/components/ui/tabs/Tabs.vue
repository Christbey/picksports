<script setup lang="ts">
import {
    TabsRoot,
    useForwardPropsEmits,
    type TabsRootEmits,
    type TabsRootProps,
} from 'reka-ui';
const props = withDefaults(defineProps<TabsRootProps<string>>(), {
    defaultValue: '',
    activationMode: 'manual',
});
const emit = defineEmits<TabsRootEmits<string>>();
const forwarded = useForwardPropsEmits(props, emit);
</script>
<template>
    <TabsRoot v-bind="forwarded" class="w-full"><slot /></TabsRoot>
</template>
