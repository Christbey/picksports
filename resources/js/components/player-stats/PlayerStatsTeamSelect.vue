<script setup lang="ts">
import { Check, ChevronsUpDown, X } from 'lucide-vue-next';
import {
    ComboboxAnchor,
    ComboboxContent,
    ComboboxEmpty,
    ComboboxInput,
    ComboboxItem,
    ComboboxItemIndicator,
    ComboboxPortal,
    ComboboxRoot,
    ComboboxTrigger,
    ComboboxViewport,
} from 'reka-ui';

const selectedTeam = defineModel<string>({ required: true });
const props = defineProps<{
    options: Array<{ value: string; label: string; search: string }>;
    disabled?: boolean;
}>();
const displayTeam = (value: unknown) =>
    props.options.find((team) => team.value === value)?.label ?? '';
</script>

<template>
    <div class="min-w-0 space-y-2 sm:w-72">
        <label for="player-stats-team" class="ui-kicker">Team</label>
        <ComboboxRoot
            :model-value="selectedTeam || 'all'"
            @update:model-value="
                selectedTeam = $event === 'all' ? '' : String($event)
            "
            :disabled="disabled"
            open-on-focus
            open-on-click
        >
            <ComboboxAnchor
                class="flex h-10 items-center rounded-md border border-input bg-background focus-within:ring-2 focus-within:ring-ring focus-within:ring-offset-2"
            >
                <ComboboxInput
                    id="player-stats-team"
                    :display-value="displayTeam"
                    placeholder="All teams — type to search"
                    class="h-full w-full min-w-0 bg-transparent px-3 text-sm outline-none placeholder:text-muted-foreground disabled:opacity-50"
                />
                <button
                    v-if="selectedTeam"
                    type="button"
                    aria-label="Clear team filter"
                    class="shrink-0 rounded p-2 text-muted-foreground hover:text-foreground focus-visible:outline-2 focus-visible:outline-ring"
                    @click="selectedTeam = ''"
                >
                    <X class="size-4" />
                </button>
                <ComboboxTrigger
                    aria-label="Choose a team"
                    class="shrink-0 rounded p-2 text-muted-foreground focus-visible:outline-2 focus-visible:outline-ring"
                >
                    <ChevronsUpDown class="size-4" />
                </ComboboxTrigger>
            </ComboboxAnchor>
            <ComboboxPortal>
                <ComboboxContent
                    position="popper"
                    :side-offset="4"
                    class="z-50 w-[var(--reka-combobox-trigger-width)] min-w-64 overflow-hidden rounded-md border bg-popover text-popover-foreground shadow-md"
                >
                    <ComboboxViewport class="max-h-72 overflow-y-auto p-1">
                        <ComboboxItem
                            value="all"
                            text-value="All teams"
                            class="relative flex cursor-pointer items-center rounded-sm py-2 pr-3 pl-8 text-sm outline-none data-[highlighted]:bg-accent data-[highlighted]:text-accent-foreground"
                        >
                            <ComboboxItemIndicator class="absolute left-2"
                                ><Check class="size-4"
                            /></ComboboxItemIndicator>
                            All teams
                        </ComboboxItem>
                        <ComboboxItem
                            v-for="team in options"
                            :key="team.value"
                            :value="team.value"
                            :text-value="team.search"
                            class="relative flex cursor-pointer items-center rounded-sm py-2 pr-3 pl-8 text-sm outline-none data-[highlighted]:bg-accent data-[highlighted]:text-accent-foreground"
                        >
                            <ComboboxItemIndicator class="absolute left-2"
                                ><Check class="size-4"
                            /></ComboboxItemIndicator>
                            {{ team.label }}
                        </ComboboxItem>
                        <ComboboxEmpty class="p-3 text-sm text-muted-foreground"
                            >No teams found.</ComboboxEmpty
                        >
                    </ComboboxViewport>
                </ComboboxContent>
            </ComboboxPortal>
        </ComboboxRoot>
    </div>
</template>
