<script setup lang="ts">
import type { CoverRecord } from '@/lib/playerPropTypes';

defineProps<{
    records: { label: string; record?: CoverRecord | null }[];
}>();
</script>

<template>
    <dl class="grid grid-cols-4 divide-x rounded-lg border py-3">
        <div v-for="item in records" :key="item.label" class="px-2 text-center">
            <dt class="text-xs text-muted-foreground">{{ item.label }}</dt>
            <dd class="mt-1 text-sm font-semibold tabular-nums">
                {{
                    item.record && item.record.games > 0
                        ? `${item.record.wins}/${item.record.games}`
                        : '—'
                }}
                <span
                    v-if="item.record?.pushes"
                    class="block text-[10px] font-normal text-muted-foreground"
                >
                    {{ item.record.pushes }} push{{
                        item.record.pushes === 1 ? '' : 'es'
                    }}
                </span>
            </dd>
        </div>
    </dl>
</template>
