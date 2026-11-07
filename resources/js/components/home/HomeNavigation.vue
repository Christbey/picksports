<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import PickSportsBrand from '@/components/brand/PickSportsBrand.vue';
import PickSportsAccountButton from '@/components/brand/PickSportsAccountButton.vue';
import { Button } from '@/components/ui/button';
import { login, performance } from '@/routes';
defineProps<{ authenticated: boolean; canRegister: boolean }>();
</script>
<template>
    <header
        class="sticky top-0 z-40 border-b border-border bg-background/95 backdrop-blur-md"
    >
        <nav
            aria-label="Primary navigation"
            class="ps-container flex h-20 items-center justify-between gap-4"
        >
            <PickSportsBrand compact />
            <div
                class="hidden items-center gap-8 text-sm font-medium text-muted-foreground md:flex"
            >
                <a
                    href="#sports"
                    class="inline-flex min-h-11 items-center transition-colors hover:text-foreground"
                    >Sports</a
                >
                <a
                    href="#method"
                    class="inline-flex min-h-11 items-center transition-colors hover:text-foreground"
                    >How it works</a
                >
                <Link
                    :href="performance()"
                    class="inline-flex min-h-11 items-center transition-colors hover:text-foreground"
                    >Performance</Link
                >
            </div>
            <div class="flex shrink-0 items-center gap-1 sm:gap-3">
                <Button
                    v-if="!authenticated && canRegister"
                    as-child
                    variant="ghost"
                    size="sm"
                    ><Link :href="login()">Log in</Link></Button
                >
                <PickSportsAccountButton
                    :authenticated="authenticated"
                    :can-register="canRegister"
                    compact
                />
            </div>
        </nav>
        <nav
            aria-label="Explore PickSports"
            class="ps-container flex items-center justify-center gap-8 border-t border-border text-xs font-medium text-muted-foreground md:hidden"
        >
            <a href="#sports" class="inline-flex min-h-11 items-center"
                >Sports</a
            ><a href="#method" class="inline-flex min-h-11 items-center"
                >How it works</a
            ><Link
                :href="performance()"
                class="inline-flex min-h-11 items-center"
                >Performance</Link
            >
        </nav>
    </header>
</template>
