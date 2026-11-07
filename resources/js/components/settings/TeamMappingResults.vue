<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTeamMappingsContext } from '@/composables/useTeamMappings';
const {
    isPlayer,
    entityTitle,
    editingMappingId,
    selectedEspnName,
    oddsName,
    espnName,
    suggestedTeamName,
    suggestedPlayerName,
    suggestedScore,
    filteredMappings,
    startEdit,
    cancelEdit,
    saveMapping,
    removeMapping,
    acceptSuggestedMapping,
    espnTeams,
    externalSourceLabel,
    emptyStateCommand,
    stats,
} = useTeamMappingsContext();
</script>
<template>
    <div
        v-if="stats.total === 0"
        class="rounded-lg border border-dashed p-8 text-center"
    >
        <div class="mb-2 text-lg font-medium">
            No {{ entityTitle.toLowerCase() }} mappings found
        </div>
        <div class="mb-4 text-sm text-muted-foreground" v-if="!isPlayer">
            Run the populate command to fetch teams from
            {{ externalSourceLabel }}
        </div>
        <div class="mb-4 text-sm text-muted-foreground" v-else>
            Unmatched player names are captured automatically during sync and
            analysis.
        </div>
        <code
            v-if="!isPlayer && emptyStateCommand"
            class="rounded bg-muted px-3 py-1 text-sm"
        >
            {{ emptyStateCommand }}
        </code>
    </div>

    <div v-else class="space-y-2">
        <div
            v-for="mapping in filteredMappings"
            :key="mapping.id"
            class="rounded-lg border p-4"
            :class="{
                'border-green-200 bg-green-50 dark:border-green-900 dark:bg-green-950/20':
                    espnName(mapping),
                'border-yellow-200 bg-yellow-50 dark:border-yellow-900 dark:bg-yellow-950/20':
                    !espnName(mapping),
            }"
        >
            <div class="flex items-start justify-between gap-4">
                <div class="flex-1 space-y-2">
                    <div class="flex items-center gap-2">
                        <div class="font-medium">
                            {{ oddsName(mapping) }}
                        </div>
                        <span
                            v-if="espnName(mapping)"
                            class="inline-flex items-center rounded-full bg-green-100 px-2 py-1 text-xs font-medium text-green-700 dark:bg-green-900 dark:text-green-300"
                        >
                            Mapped
                        </span>
                        <span
                            v-else
                            class="inline-flex items-center rounded-full bg-yellow-100 px-2 py-1 text-xs font-medium text-yellow-700 dark:bg-yellow-900 dark:text-yellow-300"
                        >
                            Unmapped
                        </span>
                    </div>
                    <div
                        v-if="editingMappingId === mapping.id"
                        class="space-y-2"
                    >
                        <Label :for="`espn-${mapping.id}`"
                            >ESPN {{ entityTitle }}</Label
                        >
                        <select
                            v-if="!isPlayer"
                            :id="`espn-${mapping.id}`"
                            v-model="selectedEspnName"
                            class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm transition-colors placeholder:text-muted-foreground focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            <option value="">-- Select ESPN Team --</option>
                            <option
                                v-for="team in espnTeams"
                                :key="team.id"
                                :value="team.name"
                            >
                                {{ team.name }} ({{ team.abbreviation }})
                            </option>
                        </select>
                        <Input
                            v-else
                            :id="`espn-${mapping.id}`"
                            v-model="selectedEspnName"
                            placeholder="Exact ESPN player name"
                        />
                        <div class="flex gap-2">
                            <Button size="sm" @click="saveMapping(mapping.id)"
                                >Save</Button
                            >
                            <Button
                                size="sm"
                                variant="outline"
                                @click="cancelEdit"
                                >Cancel</Button
                            >
                        </div>
                    </div>
                    <div
                        v-else
                        class="space-y-1 text-sm"
                        :class="{
                            'text-green-700 dark:text-green-300':
                                espnName(mapping),
                            'text-muted-foreground': !espnName(mapping),
                        }"
                    >
                        <span v-if="espnName(mapping)"
                            >→ ESPN: {{ espnName(mapping) }}</span
                        >
                        <span v-else class="italic"
                            >No ESPN
                            {{ entityTitle.toLowerCase() }}
                            mapped</span
                        >
                        <div
                            v-if="
                                !isPlayer &&
                                !espnName(mapping) &&
                                suggestedTeamName(mapping)
                            "
                            class="text-amber-700 dark:text-amber-300"
                        >
                            Suggested:
                            {{ suggestedTeamName(mapping) }}
                            <span v-if="suggestedScore(mapping) !== null"
                                >({{ suggestedScore(mapping) }}%)</span
                            >
                        </div>
                        <div
                            v-if="
                                isPlayer &&
                                !espnName(mapping) &&
                                suggestedPlayerName(mapping)
                            "
                            class="text-amber-700 dark:text-amber-300"
                        >
                            Suggested:
                            {{ suggestedPlayerName(mapping) }}
                            <span v-if="suggestedScore(mapping) !== null"
                                >({{ suggestedScore(mapping) }}%)</span
                            >
                        </div>
                    </div>
                </div>
                <div v-if="editingMappingId !== mapping.id" class="flex gap-2">
                    <Button
                        v-if="
                            !isPlayer &&
                            !espnName(mapping) &&
                            suggestedTeamName(mapping)
                        "
                        size="sm"
                        @click="acceptSuggestedMapping(mapping)"
                    >
                        Accept Suggestion
                    </Button>
                    <Button
                        v-if="
                            isPlayer &&
                            !espnName(mapping) &&
                            suggestedPlayerName(mapping)
                        "
                        size="sm"
                        @click="acceptSuggestedMapping(mapping)"
                    >
                        Accept Suggestion
                    </Button>
                    <Button
                        size="sm"
                        variant="outline"
                        @click="startEdit(mapping)"
                    >
                        {{ espnName(mapping) ? 'Edit' : 'Map' }}
                    </Button>
                    <Button
                        v-if="espnName(mapping)"
                        size="sm"
                        variant="destructive"
                        @click="removeMapping(mapping.id)"
                    >
                        Remove
                    </Button>
                </div>
            </div>
        </div>
    </div>
</template>
