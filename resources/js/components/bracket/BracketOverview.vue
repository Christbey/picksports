<script setup lang="ts">
import { useBracketBuilderContext } from '@/composables/useBracketBuilderContext';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
const bracket = useBracketBuilderContext();
</script>

<template>
    <section
        class="mx-auto max-w-[1500px] px-4 pt-6 pb-8 sm:px-6 lg:px-8 lg:pt-10"
    >
        <div class="space-y-6">
            <div class="ui-surface rounded-[2rem] p-4 sm:p-5">
                <div
                    class="grid gap-5 xl:grid-cols-[minmax(0,0.9fr)_minmax(0,1.1fr)] xl:items-start"
                >
                    <div class="min-w-0 space-y-3">
                        <p
                            class="text-xs tracking-[0.24em] text-muted-foreground uppercase"
                        >
                            Active bracket
                        </p>
                        <p
                            class="mt-2 truncate text-xl font-semibold text-foreground sm:text-2xl"
                        >
                            {{
                                bracket.isAuthenticated
                                    ? bracket.activeBracketName
                                    : 'Local bracket'
                            }}
                        </p>
                        <div class="flex flex-wrap gap-2">
                            <span
                                class="inline-flex rounded-full border border-border/70 bg-card/60 px-3 py-1 text-[11px] tracking-[0.18em] text-muted-foreground uppercase"
                            >
                                Season {{ bracket.currentSeason }}
                            </span>
                            <span
                                v-if="
                                    bracket.isAuthenticated &&
                                    bracket.selectedGroupName !== 'No group'
                                "
                                class="inline-flex rounded-full border border-border/70 bg-card/60 px-3 py-1 text-[11px] tracking-[0.18em] text-muted-foreground uppercase"
                            >
                                {{ bracket.selectedGroupName }}
                            </span>
                            <span
                                class="inline-flex rounded-full border border-border/70 bg-card/60 px-3 py-1 text-[11px] tracking-[0.18em] uppercase"
                                :class="
                                    bracket.isBracketLocked
                                        ? 'text-destructive'
                                        : 'text-muted-foreground'
                                "
                            >
                                {{
                                    bracket.isBracketLocked
                                        ? 'Locked'
                                        : 'Editable'
                                }}
                            </span>
                            <span
                                v-if="bracket.isAuthenticated"
                                class="inline-flex rounded-full border border-border/70 bg-card/60 px-3 py-1 text-[11px] tracking-[0.18em] text-muted-foreground uppercase"
                            >
                                {{ bracket.brackets.length }} saved
                            </span>
                            <span
                                v-else
                                class="inline-flex rounded-full border border-border/70 bg-card/60 px-3 py-1 text-[11px] tracking-[0.18em] text-muted-foreground uppercase"
                            >
                                Local only
                            </span>
                        </div>
                    </div>

                    <div class="space-y-3 xl:min-w-0">
                        <div
                            v-if="bracket.isAuthenticated"
                            class="grid gap-3 md:grid-cols-[minmax(0,1fr)_auto_auto]"
                        >
                            <div class="space-y-1.5">
                                <label
                                    class="text-[11px] font-medium tracking-[0.18em] text-muted-foreground uppercase"
                                    >Switch Bracket</label
                                >
                                <select
                                    :value="bracket.activeBracketPublicId ?? ''"
                                    class="ui-select rounded-2xl bg-card/70 text-foreground"
                                    @change="
                                        bracket.switchBracket(
                                            ($event.target as HTMLSelectElement)
                                                .value,
                                        )
                                    "
                                >
                                    <option
                                        v-if="!bracket.brackets.length"
                                        value=""
                                    >
                                        No saved brackets
                                    </option>
                                    <option
                                        v-for="bracket in bracket.brackets"
                                        :key="bracket.public_id"
                                        :value="bracket.public_id"
                                    >
                                        {{ bracket.name || 'Untitled bracket' }}
                                    </option>
                                </select>
                            </div>
                            <Button
                                size="sm"
                                variant="ghost"
                                class="self-end border border-border/70 bg-card/60 text-foreground hover:bg-accent/70 disabled:cursor-not-allowed disabled:opacity-45"
                                :disabled="bracket.isBracketLocked"
                                @click="bracket.createBracket()"
                            >
                                New Bracket
                            </Button>
                            <Button
                                size="sm"
                                variant="ghost"
                                class="self-end border border-border/70 bg-card/60 text-foreground hover:bg-accent/70 disabled:cursor-not-allowed disabled:opacity-45"
                                :disabled="bracket.isBracketLocked"
                                @click="bracket.requestResetBracket"
                            >
                                Reset Picks
                            </Button>
                        </div>

                        <div
                            v-if="
                                bracket.isAuthenticated &&
                                bracket.currentBracket
                            "
                            class="grid gap-3 md:grid-cols-[minmax(0,1fr)_220px_auto]"
                        >
                            <div class="space-y-1.5">
                                <label
                                    class="text-[11px] font-medium tracking-[0.18em] text-muted-foreground uppercase"
                                    >Bracket Name</label
                                >
                                <Input
                                    v-model="bracket.bracketNameDraft"
                                    placeholder="Bracket name"
                                    class="border-border/70 bg-card/70 text-foreground placeholder:text-muted-foreground"
                                    :disabled="
                                        !bracket.currentBracket ||
                                        bracket.isBracketLocked
                                    "
                                />
                            </div>
                            <div class="space-y-1.5">
                                <label
                                    class="text-[11px] font-medium tracking-[0.18em] text-muted-foreground uppercase"
                                    >Group</label
                                >
                                <select
                                    v-model="bracket.bracketGroupIdDraft"
                                    class="ui-select rounded-2xl bg-card/70 text-foreground"
                                    :disabled="
                                        !bracket.currentBracket ||
                                        bracket.isBracketLocked
                                    "
                                >
                                    <option
                                        v-if="!bracket.requiresGroupAssignment"
                                        value=""
                                    >
                                        No group
                                    </option>
                                    <option
                                        v-for="group in bracket.availableGroups"
                                        :key="group.public_id"
                                        :value="String(group.id)"
                                    >
                                        {{ group.name }}
                                    </option>
                                </select>
                            </div>
                            <Button
                                size="sm"
                                variant="ghost"
                                class="self-end border border-border/70 bg-card/60 text-foreground hover:bg-accent/70 disabled:cursor-not-allowed disabled:opacity-45"
                                :disabled="
                                    !bracket.currentBracket ||
                                    bracket.isBracketLocked ||
                                    bracket.metaSaveState === 'saving'
                                "
                                @click="bracket.saveBracketMeta"
                            >
                                {{ bracket.metaSaveLabel }}
                            </Button>
                        </div>
                    </div>
                </div>

                <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    <div
                        class="rounded-2xl border border-border/70 bg-card/55 px-4 py-3"
                    >
                        <p
                            class="text-[11px] tracking-[0.2em] text-muted-foreground uppercase"
                        >
                            Progress
                        </p>
                        <div class="mt-2 flex items-center gap-3">
                            <p class="text-xl font-semibold text-foreground">
                                {{ bracket.completionPct }}%
                            </p>
                            <div class="h-2 flex-1 rounded-full bg-accent/70">
                                <div
                                    class="h-full rounded-full bg-primary/80 transition-[width]"
                                    :style="{
                                        width: `${bracket.completionPct}%`,
                                    }"
                                />
                            </div>
                        </div>
                        <p
                            class="mt-2 text-[11px] tracking-[0.16em] text-muted-foreground uppercase"
                        >
                            {{ bracket.pickedGames }}/{{ bracket.totalGames }}
                            picked
                        </p>
                    </div>

                    <div
                        class="rounded-2xl border border-border/70 bg-card/55 px-4 py-3"
                    >
                        <p
                            class="text-[11px] tracking-[0.2em] text-muted-foreground uppercase"
                        >
                            Champion
                        </p>
                        <p class="mt-2 text-xl font-semibold text-foreground">
                            {{ bracket.champion ?? 'TBD' }}
                        </p>
                        <p
                            class="mt-2 text-[11px] tracking-[0.16em] text-muted-foreground uppercase"
                        >
                            {{
                                bracket.isBracketLocked
                                    ? 'Bracket locked'
                                    : 'Still editable'
                            }}
                        </p>
                    </div>

                    <div
                        v-if="bracket.isAuthenticated && bracket.currentBracket"
                        class="rounded-2xl border border-border/70 bg-card/55 px-4 py-3"
                    >
                        <p
                            class="text-[11px] tracking-[0.2em] text-muted-foreground uppercase"
                        >
                            Score
                        </p>
                        <p class="mt-2 text-xl font-semibold text-foreground">
                            {{ bracket.currentBracket.points_earned }} pts
                        </p>
                        <p
                            class="mt-2 text-[11px] tracking-[0.16em] text-muted-foreground uppercase"
                        >
                            {{ bracket.currentBracket.correct_picks }} correct ·
                            {{ bracket.currentBracket.incorrect_picks }} missed
                        </p>
                    </div>
                </div>

                <div
                    class="mt-3 rounded-2xl border border-border/70 bg-card/45 px-4 py-3"
                >
                    <div
                        v-if="bracket.isAuthenticated && bracket.currentBracket"
                        class="flex flex-col gap-2 text-xs md:flex-row md:items-center md:justify-between"
                    >
                        <div
                            class="flex flex-col gap-1 sm:flex-row sm:items-center sm:gap-4"
                        >
                            <p
                                class="tracking-[0.18em] uppercase"
                                :class="bracket.picksAutosaveToneClass"
                            >
                                {{ bracket.picksAutosaveLabel }}
                            </p>
                            <p
                                class="tracking-[0.18em] uppercase"
                                :class="bracket.metaSaveToneClass"
                            >
                                Details:
                                {{
                                    bracket.metaSaveState === 'idle'
                                        ? 'manual save'
                                        : bracket.metaSaveLabel.toLowerCase()
                                }}
                            </p>
                        </div>
                        <a href="#bracket-board" class="inline-flex">
                            <Button
                                size="sm"
                                class="bg-primary text-primary-foreground hover:opacity-90"
                            >
                                Jump to Bracket
                            </Button>
                        </a>
                    </div>
                    <p
                        v-else
                        class="text-xs tracking-[0.18em] text-muted-foreground uppercase"
                    >
                        {{ bracket.saveStateLabel }}
                    </p>
                </div>
            </div>

            <div class="ui-surface rounded-[2rem] p-4 sm:p-5">
                <div
                    class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between"
                >
                    <div>
                        <p
                            class="text-xs tracking-[0.24em] text-muted-foreground uppercase"
                        >
                            How It Works
                        </p>
                        <h2
                            class="mt-2 text-xl font-semibold text-foreground sm:text-2xl"
                        >
                            Make picks round by round
                        </h2>
                    </div>
                </div>

                <div class="mt-4 grid gap-3 lg:grid-cols-3">
                    <div
                        class="rounded-2xl border border-border/70 bg-card/55 px-4 py-3"
                    >
                        <p
                            class="text-[11px] tracking-[0.18em] text-muted-foreground uppercase"
                        >
                            1. Start Here
                        </p>
                        <p class="mt-2 text-sm leading-6 text-foreground">
                            Pick winners on each region board. First Four
                            winners feed into the main bracket automatically.
                        </p>
                    </div>
                    <div
                        class="rounded-2xl border border-border/70 bg-card/55 px-4 py-3"
                    >
                        <p
                            class="text-[11px] tracking-[0.18em] text-muted-foreground uppercase"
                        >
                            2. Saving
                        </p>
                        <p class="mt-2 text-sm leading-6 text-foreground">
                            Picks autosave as you go. Bracket name and group
                            changes use
                            <span class="font-semibold">Save Details</span>.
                        </p>
                    </div>
                    <div
                        class="rounded-2xl border border-border/70 bg-card/55 px-4 py-3"
                    >
                        <p
                            class="text-[11px] tracking-[0.18em] text-muted-foreground uppercase"
                        >
                            3. Scoring
                        </p>
                        <p class="mt-2 text-sm leading-6 text-foreground">
                            Correct picks earn more points each round. Final
                            scores update your grade and leaderboard rank
                            automatically.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </section>
</template>
