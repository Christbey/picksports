<script setup lang="ts">
import { TabsContent, useForwardProps, type TabsContentProps } from 'reka-ui';
const props = defineProps<TabsContentProps>();
const forwarded = useForwardProps(props);
</script>
<template>
    <TabsContent
        v-bind="forwarded"
        class="mt-5 ring-offset-background focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none"
        ><slot
    /></TabsContent>
</template>
