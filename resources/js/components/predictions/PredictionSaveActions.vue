<script setup lang="ts">
import type { UnwrapNestedRefs } from 'vue';
import type { usePredictionTracking } from '@/composables/usePredictionTracking';
import SavePickDialog from '@/components/predictions/SavePickDialog.vue';
import { ChevronDown } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
defineProps<{
    tracking: UnwrapNestedRefs<ReturnType<typeof usePredictionTracking>>;
    final: boolean;
}>();
</script>
<template>
    <div class="contents">
        <div
            v-if="tracking.canSavePick && !final"
            class="mt-4 border-t border-border/70 pt-4"
        >
            <div class="flex items-center justify-between gap-2">
                <div>
                    <div
                        class="text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                    >
                        Track This Pick
                    </div>
                    <div
                        v-if="tracking.isLoadingTracking"
                        class="mt-1 text-xs text-muted-foreground"
                    >
                        Loading tracked picks...
                    </div>
                </div>
                <DropdownMenu>
                    <DropdownMenuTrigger :as-child="true">
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            class="gap-2"
                            @click.stop
                        >
                            Save Pick
                            <ChevronDown class="h-4 w-4" />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" class="w-56" @click.stop>
                        <DropdownMenuLabel>Choose a side</DropdownMenuLabel>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                            v-for="option in tracking.saveOptions()"
                            :key="`${option.betType}-${option.selectionSide}`"
                            @select.prevent="tracking.openSaveDialog(option)"
                        >
                            <div
                                class="flex w-full items-start justify-between gap-3"
                            >
                                <div class="min-w-0">
                                    <div
                                        class="truncate font-medium text-foreground"
                                    >
                                        {{ option.title }}
                                    </div>
                                    <div
                                        v-if="
                                            tracking.consensusForOption(option)
                                        "
                                        class="truncate text-xs text-muted-foreground"
                                    >
                                        {{
                                            tracking.consensusForOption(option)
                                                ?.summary
                                        }}
                                    </div>
                                </div>
                                <span
                                    v-if="tracking.trackedBetForOption(option)"
                                    class="shrink-0 rounded-full border border-emerald-500/25 bg-emerald-500/10 px-2 py-0.5 text-[11px] font-semibold text-emerald-700 dark:text-emerald-300"
                                >
                                    Tracked
                                </span>
                            </div>
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>
        </div>
        <SavePickDialog
            v-if="
                tracking.predictionId() !== null && tracking.predictionSport()
            "
            :open="tracking.isSaveDialogOpen"
            :prediction-id="tracking.predictionId()!"
            :prediction-sport="tracking.predictionSport()!"
            :option="tracking.activeSaveOption"
            :existing-bet="
                tracking.activeSaveOption
                    ? tracking.trackedBetForOption(tracking.activeSaveOption)
                    : null
            "
            :public-consensus="
                tracking.activeSaveOption
                    ? tracking.consensusForOption(tracking.activeSaveOption)
                    : null
            "
            @update:open="tracking.setSaveDialogOpen($event)"
            @saved="tracking.handleSaved"
        />
    </div>
</template>
