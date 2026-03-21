<script setup lang="ts">
withDefaults(
    defineProps<{
        id: string;
        label?: string;
        modelValue: string;
        options: number[];
        emptyLabel?: string;
        disabled?: boolean;
    }>(),
    {
        label: 'Season',
        emptyLabel: 'No seasons',
        disabled: false,
    },
);

const emit = defineEmits<{
    (e: 'update:modelValue', value: string): void;
}>();

const onChange = (event: Event) => {
    const target = event.target as HTMLSelectElement;
    emit('update:modelValue', target.value);
};
</script>

<template>
    <div class="min-w-[160px]">
        <label
            class="mb-1 block text-xs font-medium text-muted-foreground"
            :for="id"
        >
            {{ label }}
        </label>
        <select
            :id="id"
            :value="modelValue"
            :disabled="disabled || options.length === 0"
            class="mt-1 flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus:ring-2 focus:ring-ring focus:ring-offset-2 focus:outline-none disabled:cursor-not-allowed disabled:opacity-50"
            @change="onChange"
        >
            <option v-if="options.length === 0" value="">
                {{ emptyLabel }}
            </option>
            <option
                v-for="season in options"
                :key="season"
                :value="String(season)"
            >
                {{ season }}
            </option>
        </select>
    </div>
</template>
