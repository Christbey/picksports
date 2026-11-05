<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { dashboard, home, login, register } from '@/routes';
import { usePublicSportContext } from '@/composables/usePublicSport';
const { sportLabel } = usePublicSportContext();
</script>
<template>
    <nav
        class="sticky top-0 z-40 border-b border-white/10 bg-slate-950/70 backdrop-blur"
    >
        <div
            class="mx-auto flex max-w-7xl items-center justify-between px-4 py-4 sm:px-6 lg:px-8"
        >
            <Link :href="home()" class="flex items-center gap-3">
                <div
                    class="flex size-9 items-center justify-center rounded-xl bg-amber-400 text-sm font-bold text-slate-950"
                >
                    PS
                </div>
                <div>
                    <div
                        class="text-sm font-semibold tracking-[0.22em] text-amber-300"
                    >
                        PICKSPORTS
                    </div>
                    <div class="text-xs text-slate-400">
                        {{ sportLabel }} public board
                    </div>
                </div>
            </Link>

            <div class="flex items-center gap-2">
                <template v-if="$page.props.auth.user">
                    <Link :href="dashboard()">
                        <Button size="sm">Dashboard</Button>
                    </Link>
                </template>
                <template v-else>
                    <Link :href="login()">
                        <Button
                            size="sm"
                            variant="ghost"
                            class="text-slate-100 hover:bg-white/10"
                            >Log in</Button
                        >
                    </Link>
                    <Link :href="register()">
                        <Button
                            size="sm"
                            class="bg-amber-400 text-slate-950 hover:bg-amber-300"
                            >Get Started</Button
                        >
                    </Link>
                </template>
            </div>
        </div>
    </nav>
</template>
