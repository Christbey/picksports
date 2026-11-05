<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowUpRight } from 'lucide-vue-next';
import { Card } from '@/components/ui/card';
const sports = [
    { slug: 'mlb', label: 'MLB', detail: 'Games, F3/F5, markets' },
    { slug: 'nfl', label: 'NFL', detail: 'Matchups, injuries, futures' },
    { slug: 'nba', label: 'NBA', detail: 'Games, props, futures' },
    { slug: 'wnba', label: 'WNBA', detail: 'Games, injuries, props' },
    { slug: 'cfb', label: 'CFB', detail: 'Games, teams, futures' },
    { slug: 'cbb', label: 'CBB', detail: 'Games, teams, tournament' },
    { slug: 'wcbb', label: 'WCBB', detail: 'Games, teams, tournament' },
] as const;
</script>
<template>
    <section
        id="sports"
        aria-labelledby="sports-title"
        class="scroll-mt-36 border-t border-border py-14 sm:py-20 md:scroll-mt-24"
    >
        <div class="ps-container">
            <div
                class="flex flex-col justify-between gap-4 sm:flex-row sm:items-end"
            >
                <div>
                    <p class="ps-eyebrow">Find your field</p>
                    <h2
                        id="sports-title"
                        class="mt-3 text-3xl font-semibold tracking-tight sm:text-4xl"
                    >
                        One platform. Seven sports.
                    </h2>
                </div>
                <p class="max-w-sm text-sm leading-6 text-muted-foreground">
                    Choose your league for predictions, team context, injuries,
                    and available markets.
                </p>
            </div>
            <div
                class="mt-8 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-7"
            >
                <Link
                    v-for="sport in sports"
                    :key="sport.slug"
                    :href="`/${sport.slug}`"
                    class="group block h-full"
                    :aria-label="`Explore ${sport.label}`"
                >
                    <Card
                        class="h-full gap-5 px-4 py-5 transition-colors group-hover:border-primary/60 group-hover:bg-accent/30"
                    >
                        <div class="flex items-center justify-between">
                            <h3 class="text-xl font-semibold tracking-tight">
                                {{ sport.label }}
                            </h3>
                            <ArrowUpRight
                                aria-hidden="true"
                                class="size-4 shrink-0 text-muted-foreground transition-colors group-hover:text-primary"
                            />
                        </div>
                        <p class="text-xs leading-5 text-muted-foreground">
                            {{ sport.detail }}
                        </p>
                    </Card>
                </Link>
            </div>
        </div>
    </section>
</template>
