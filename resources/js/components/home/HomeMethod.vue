<script setup lang="ts">
import {
    BarChart3,
    CircleDollarSign,
    ShieldCheck,
    Scale,
} from 'lucide-vue-next';
const steps = [
    {
        label: 'Forecast',
        detail: 'A pregame probability with model lineage.',
        icon: BarChart3,
    },
    {
        label: 'Price',
        detail: 'The model is compared with the available market.',
        icon: CircleDollarSign,
    },
    {
        label: 'Risk',
        detail: 'Availability, uncertainty, and data quality are checked.',
        icon: ShieldCheck,
    },
    {
        label: 'Decision',
        detail: 'Bet or no bet, with the reason recorded.',
        icon: Scale,
    },
] as const;
</script>
<template>
    <section
        id="method"
        aria-labelledby="method-title"
        class="scroll-mt-36 border-y border-border bg-secondary/50 py-14 sm:py-20 md:scroll-mt-24"
    >
        <div class="ps-container">
            <div class="grid gap-5 lg:grid-cols-2 lg:items-end">
                <div>
                    <p class="ps-eyebrow">The process behind the pick</p>
                    <h2
                        id="method-title"
                        class="mt-3 max-w-xl text-3xl font-semibold tracking-tight text-balance sm:text-4xl"
                    >
                        A prediction is not automatically a bet.
                    </h2>
                </div>
                <p
                    class="max-w-lg text-base leading-7 text-muted-foreground lg:justify-self-end"
                >
                    The recommendation layer can reject a model lean when the
                    price, evidence, or uncertainty does not support a wager.
                    Passing is part of the process.
                </p>
            </div>
            <ol
                class="mt-10 grid gap-8 border-t border-border pt-8 sm:grid-cols-2 lg:grid-cols-4 lg:gap-10"
            >
                <li v-for="(step, index) in steps" :key="step.label">
                    <div class="flex items-center justify-between">
                        <span
                            class="grid size-11 place-items-center rounded-lg border border-border bg-card"
                            ><component
                                :is="step.icon"
                                aria-hidden="true"
                                class="size-5 text-primary" /></span
                        ><span
                            aria-hidden="true"
                            class="font-mono text-xs text-muted-foreground"
                            >0{{ index + 1 }}</span
                        >
                    </div>
                    <h3 class="mt-5 text-lg font-semibold">{{ step.label }}</h3>
                    <p
                        class="mt-2 max-w-xs text-sm leading-6 text-muted-foreground"
                    >
                        {{ step.detail }}
                    </p>
                </li>
            </ol>
        </div>
    </section>
</template>
