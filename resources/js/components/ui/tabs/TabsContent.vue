<script setup lang="ts">
import { inject, computed, type Ref } from 'vue'

const props = defineProps<{
    value: string
}>()

const activeTab = inject<Ref<string>>('activeTab')

const isActive = computed(() => activeTab?.value === props.value)
</script>

<template>
    <div
        v-if="isActive"
        class="mt-4 ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
    >
        <slot />
    </div>
</template>
