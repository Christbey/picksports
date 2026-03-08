<script setup lang="ts">
import type { HTMLAttributes } from "vue"
import { cn } from "@/lib/utils"

const props = defineProps<{
  class?: HTMLAttributes["class"]
}>()
</script>

<template>
  <div
    data-slot="card"
    :class="
      cn(
        'ui-surface text-card-foreground flex flex-col gap-5 py-5',
        props.class,
      )
    "
  >
    <slot />
  </div>
</template>
