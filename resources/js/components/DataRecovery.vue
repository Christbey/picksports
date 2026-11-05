<script setup lang="ts">
import { Button } from '@/components/ui/button';
defineProps<{ message: string; busy?: boolean; stale?: boolean }>();
defineEmits<{ retry: [] }>();
</script>

<template>
    <div
        :role="stale ? 'status' : 'alert'"
        class="flex flex-wrap items-center justify-between gap-3 rounded-lg border p-4 text-sm"
        :class="
            stale
                ? 'border-amber-500/40 text-amber-700 dark:text-amber-300'
                : 'border-destructive/40 text-destructive'
        "
    >
        <p class="min-w-0 flex-1">
            {{ message
            }}<span v-if="stale"> Showing the previous results.</span>
        </p>
        <Button variant="outline" :disabled="busy" @click="$emit('retry')">{{
            busy ? 'Retrying…' : 'Try again'
        }}</Button>
    </div>
</template>
