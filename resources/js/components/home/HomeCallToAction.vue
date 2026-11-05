<script setup lang="ts">
import PickSportsAccountButton from '@/components/brand/PickSportsAccountButton.vue';
defineProps<{ authenticated: boolean; canRegister: boolean }>();
</script>
<template>
    <div class="ps-container pb-14 sm:pb-20">
        <section
            aria-labelledby="start-title"
            class="flex flex-col justify-between gap-7 rounded-2xl bg-foreground px-7 py-9 text-background sm:px-10 sm:py-12 lg:flex-row lg:items-center"
        >
            <div>
                <p class="text-xs font-medium tracking-wide text-background/65">
                    YOUR NEXT READ STARTS HERE
                </p>
                <h2
                    id="start-title"
                    class="mt-3 text-3xl font-semibold tracking-tight sm:text-4xl"
                >
                    Keep every result accountable.
                </h2>
                <p class="mt-4 max-w-xl text-sm leading-7 text-background/75">
                    Review the current board, follow settled outcomes, and see
                    when the system chooses not to bet.
                </p>
            </div>
            <PickSportsAccountButton
                :authenticated="authenticated"
                :can-register="canRegister"
                class="self-start lg:shrink-0 lg:self-center"
            />
        </section>
    </div>
</template>
