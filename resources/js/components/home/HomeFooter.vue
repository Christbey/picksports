<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import PickSportsBrand from '@/components/brand/PickSportsBrand.vue';
import { performance, terms, privacy, responsibleGambling } from '@/routes';
</script>
<template>
    <footer class="border-t border-border bg-card/50">
        <div class="ps-container py-9 sm:py-12">
            <div
                class="flex flex-col justify-between gap-7 sm:flex-row sm:items-center"
            >
                <PickSportsBrand />
                <nav
                    aria-label="Footer navigation"
                    class="flex flex-wrap items-center gap-x-6 gap-y-2 text-sm text-muted-foreground"
                >
                    <Link
                        :href="performance()"
                        class="inline-flex min-h-11 items-center hover:text-foreground"
                        >Performance</Link
                    ><Link
                        :href="terms()"
                        class="inline-flex min-h-11 items-center hover:text-foreground"
                        >Terms</Link
                    ><Link
                        :href="privacy()"
                        class="inline-flex min-h-11 items-center hover:text-foreground"
                        >Privacy</Link
                    ><Link
                        :href="responsibleGambling()"
                        class="inline-flex min-h-11 items-center hover:text-foreground"
                        >Responsible gambling</Link
                    >
                </nav>
            </div>
            <div
                class="mt-7 grid gap-5 border-t border-border pt-7 text-xs leading-6 text-muted-foreground lg:grid-cols-[1.2fr_0.8fr] lg:gap-16"
            >
                <p>
                    For entertainment and educational purposes only. Gambling
                    involves risk of loss. Never wager more than you can afford
                    to lose. Call 1-800-GAMBLER for help.
                </p>
                <p class="lg:text-right">
                    &copy; 2026 PickSports. Not affiliated with any professional
                    sports league or gambling operator.
                </p>
            </div>
        </div>
    </footer>
</template>
