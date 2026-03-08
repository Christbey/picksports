<script setup lang="ts">
import { inject, computed, type Ref } from 'vue'

const props = defineProps<{
    value: string
}>()

const activeTab = inject<Ref<string>>('activeTab')
const setActiveTab = inject<(value: string) => void>('setActiveTab')

const isActive = computed(() => activeTab?.value === props.value)
</script>

<template>
    <button
        type="button"
        @click="setActiveTab?.(value)"
        :class="[
            'inline-flex min-h-11 items-center justify-center whitespace-nowrap rounded-xl px-3.5 py-1.5 text-sm font-semibold tracking-tight ring-offset-background transition-all duration-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50',
            isActive
                ? 'bg-background text-foreground shadow-sm'
                : 'text-muted-foreground hover:bg-background/65 hover:text-foreground'
        ]"
    >
        <slot />
    </button>
</template>
