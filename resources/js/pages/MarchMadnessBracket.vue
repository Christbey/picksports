<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { provide } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import BracketOverview from '@/components/bracket/BracketOverview.vue';
import BracketLeaderboard from '@/components/bracket/BracketLeaderboard.vue';
import BracketFinals from '@/components/bracket/BracketFinals.vue';
import BracketRegionBoard from '@/components/bracket/BracketRegionBoard.vue';
import BracketFirstFour from '@/components/bracket/BracketFirstFour.vue';
import { useMarchMadnessBracket } from '@/composables/useMarchMadnessBracket';
import { bracketBuilderKey } from '@/composables/useBracketBuilderContext';
import type { TournamentRegion } from '@/types/bracket';
const props = defineProps<{ regions?: TournamentRegion[] }>();
const bracket = useMarchMadnessBracket(props);
provide(bracketBuilderKey, bracket);
</script>

<template>
    <div class="min-h-screen overflow-x-hidden bg-background text-foreground">
        <Head title="March Madness Bracket Builder">
            <meta
                head-key="description"
                name="description"
                content="Build a March Madness bracket using real NCAA tournament games synced into PickSports."
            />
            <meta
                head-key="og:title"
                property="og:title"
                content="March Madness Bracket Builder"
            />
            <meta
                head-key="og:description"
                property="og:description"
                content="Interactive March Madness bracket builder powered by real tournament game data."
            />
        </Head>
        <nav
            class="sticky top-0 z-50 border-b border-border/60 bg-background/72 backdrop-blur-xl"
            style="padding-top: env(safe-area-inset-top)"
        >
            <div
                class="mx-auto flex max-w-[1500px] items-center justify-between gap-3 px-4 py-4 sm:px-6 lg:px-8"
            >
                <Link href="/" class="flex items-center gap-3">
                    <div
                        class="flex size-9 items-center justify-center rounded-xl bg-primary text-sm font-black text-primary-foreground"
                    >
                        PS
                    </div>
                    <div>
                        <p
                            class="text-sm font-semibold tracking-[0.24em] text-foreground/90 uppercase"
                        >
                            PickSports
                        </p>
                        <p class="text-xs text-muted-foreground">
                            Bracket Builder
                        </p>
                    </div>
                </Link>

                <Button
                    type="button"
                    variant="ghost"
                    class="border border-border/70 bg-card/60 text-foreground hover:bg-accent/70"
                    @click="bracket.toggleAppearance"
                >
                    {{ bracket.appearanceToggleLabel }}
                </Button>
            </div>
        </nav>

        <main
            class="relative"
            style="padding-bottom: env(safe-area-inset-bottom)"
        >
            <BracketOverview />

            <section
                id="bracket-board"
                class="mx-auto max-w-[1500px] px-4 pb-24 sm:px-6 lg:px-8"
            >
                <div
                    class="mb-6 flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between"
                >
                    <div>
                        <p
                            class="text-sm tracking-[0.28em] text-muted-foreground uppercase"
                        >
                            Bracket Board
                        </p>
                        <h2
                            class="mt-2 text-2xl font-semibold tracking-tight text-foreground sm:text-4xl"
                        >
                            Complete the full bracket here
                        </h2>
                    </div>
                </div>

                <div v-if="bracket.allBracketMatchups.length" class="space-y-8">
                    <BracketLeaderboard />

                    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                        <a
                            v-for="region in bracket.bracketRegions"
                            :key="`jump-${region.id}`"
                            :href="`#region-${region.id}`"
                            class="rounded-2xl border border-border/70 bg-card/65 px-4 py-3 backdrop-blur transition-colors hover:border-border hover:bg-accent/55"
                        >
                            <div
                                class="flex items-center justify-between gap-3"
                            >
                                <div>
                                    <p
                                        class="text-[11px] tracking-[0.2em] text-muted-foreground uppercase"
                                    >
                                        Region
                                    </p>
                                    <p
                                        class="text-base font-semibold text-foreground"
                                    >
                                        {{ region.name }}
                                    </p>
                                </div>
                                <div class="text-right">
                                    <p
                                        class="text-sm font-semibold text-foreground"
                                    >
                                        {{
                                            bracket.regionCompletion(region)
                                                .pct
                                        }}%
                                    </p>
                                    <p
                                        class="text-[11px] text-muted-foreground"
                                    >
                                        {{
                                            bracket.regionCompletion(region)
                                                .picked
                                        }}/{{
                                            bracket.regionCompletion(region)
                                                .total
                                        }}
                                    </p>
                                </div>
                            </div>
                        </a>
                    </div>

                    <BracketFinals />

                    <BracketRegionBoard
                        v-for="region in bracket.bracketRegions"
                        :key="region.id"
                        :region="region"
                    />

                    <BracketFirstFour />
                </div>

                <div
                    v-else
                    class="rounded-[2rem] border border-dashed border-border/70 bg-card/55 p-8 text-sm leading-6 text-muted-foreground"
                >
                    No NCAA tournament games are currently available for the
                    bracket board. Once the synced CBB games have tournament
                    metadata, this view will populate from the database
                    automatically.
                </div>
            </section>
        </main>

        <Dialog
            :open="bracket.resetConfirmOpen"
            @update:open="bracket.resetConfirmOpen = $event"
        >
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Reset this bracket?</DialogTitle>
                    <DialogDescription>
                        This clears every winner you have selected in the active
                        bracket. Your bracket name and group will stay the same.
                    </DialogDescription>
                </DialogHeader>

                <DialogFooter class="gap-2 sm:justify-end">
                    <Button
                        variant="ghost"
                        class="border border-border/70 bg-card/60 text-foreground hover:bg-accent/70"
                        @click="bracket.resetConfirmOpen = false"
                    >
                        Cancel
                    </Button>
                    <Button
                        variant="destructive"
                        @click="bracket.confirmResetBracket"
                    >
                        Reset Picks
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
