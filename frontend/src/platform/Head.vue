<script setup lang="ts">
import { watchEffect } from 'vue';
const props = defineProps<{ title?: string }>();
watchEffect(() => {
    if (typeof document !== 'undefined')
        document.title = props.title
            ? `${props.title} · PickSports`
            : 'PickSports';
});
</script>
<template>
    <Teleport to="head"><slot /></Teleport>
</template>
