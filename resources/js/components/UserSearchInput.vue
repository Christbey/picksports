<script setup lang="ts">
import { computed, nextTick, ref, useId, watch } from 'vue';
import type { AdminUserSuggestion } from '@/composables/useAdminUserSearch';
defineOptions({ inheritAttrs: false });
const props = withDefaults(
    defineProps<{
        modelValue: string;
        suggestions: AdminUserSuggestion[];
        loading?: boolean;
        id?: string;
        label?: string;
        ariaLabel?: string;
        placeholder?: string;
        required?: boolean;
    }>(),
    { loading: false, required: false },
);
const emit = defineEmits<{
    'update:modelValue': [value: string];
    select: [user: AdminUserSuggestion];
}>();
const generatedId = useId();
const inputId = computed(() => props.id ?? `user-search-${generatedId}`);
const listId = computed(() => `${inputId.value}-suggestions`);
const activeIndex = ref(-1);
const dismissed = ref(false);
const open = computed(
    () => !dismissed.value && (props.loading || props.suggestions.length > 0),
);
const activeId = computed(() =>
    open.value && activeIndex.value >= 0 && props.suggestions[activeIndex.value]
        ? `${listId.value}-${activeIndex.value}`
        : undefined,
);
watch(
    () => props.modelValue,
    () => {
        activeIndex.value = -1;
        dismissed.value = false;
    },
);
watch(
    () => props.suggestions,
    () => {
        activeIndex.value = -1;
    },
);
watch(activeId, async (id) => {
    if (!id || typeof document === 'undefined') return;
    await nextTick();
    document.getElementById(id)?.scrollIntoView({ block: 'nearest' });
});
function close() {
    dismissed.value = true;
    activeIndex.value = -1;
}
function select(user: AdminUserSuggestion) {
    emit('select', user);
    close();
}
function onKeydown(event: KeyboardEvent) {
    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        if (!props.suggestions.length) return;
        event.preventDefault();
        dismissed.value = false;
        const count = props.suggestions.length;
        activeIndex.value =
            event.key === 'ArrowDown'
                ? (activeIndex.value + 1) % count
                : activeIndex.value < 0
                  ? count - 1
                  : (activeIndex.value - 1 + count) % count;
    } else if (event.key === 'Enter' && open.value && activeIndex.value >= 0) {
        const user = props.suggestions[activeIndex.value];
        if (user) {
            event.preventDefault();
            select(user);
        }
    } else if (event.key === 'Escape' && open.value) {
        event.preventDefault();
        event.stopPropagation();
        close();
    } else if (event.key === 'Tab') close();
}
</script>
<template>
    <div class="relative flex-1" @focusout="close">
        <label
            v-if="label"
            class="mb-1 block text-xs font-medium text-muted-foreground"
            :for="inputId"
            >{{ label }}</label
        >
        <input
            v-bind="$attrs"
            :id="inputId"
            :value="modelValue"
            type="text"
            :required="required"
            :placeholder="placeholder"
            autocomplete="off"
            role="combobox"
            aria-autocomplete="list"
            :aria-label="ariaLabel"
            :aria-expanded="open"
            :aria-controls="open ? listId : undefined"
            :aria-activedescendant="activeId"
            :aria-busy="loading"
            class="w-full rounded-lg border border-sidebar-border bg-white px-3 py-2 text-sm dark:bg-sidebar"
            @input="
                emit(
                    'update:modelValue',
                    ($event.target as HTMLInputElement).value,
                )
            "
            @keydown="onKeydown"
            @focus="dismissed = false"
        />
        <span class="sr-only" role="status" aria-live="polite">{{
            loading
                ? 'Searching...'
                : open
                  ? `${suggestions.length} suggestions available`
                  : ''
        }}</span>
        <div
            v-if="open"
            :id="listId"
            role="listbox"
            :aria-label="label ?? ariaLabel ?? 'User suggestions'"
            class="absolute z-10 mt-1 w-full overflow-hidden rounded-lg border border-sidebar-border bg-white shadow-lg dark:bg-sidebar"
        >
            <div
                v-if="loading"
                class="px-3 py-2 text-xs text-muted-foreground"
                aria-hidden="true"
            >
                Searching...
            </div>
            <button
                v-for="(user, index) in suggestions"
                :id="`${listId}-${index}`"
                :key="user.id"
                type="button"
                role="option"
                tabindex="-1"
                :aria-selected="activeIndex === index"
                class="block w-full border-t border-sidebar-border px-3 py-2 text-left text-sm first:border-t-0 hover:bg-sidebar-accent"
                :class="activeIndex === index ? 'bg-sidebar-accent' : ''"
                @pointerdown.prevent
                @click="select(user)"
            >
                <span class="font-medium">{{ user.name }}</span
                ><span class="ml-1 text-muted-foreground">{{
                    user.email
                }}</span>
            </button>
        </div>
    </div>
</template>
