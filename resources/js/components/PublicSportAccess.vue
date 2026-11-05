<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { register } from '@/routes';
</script>
<template>
    <section
        class="mt-6 overflow-hidden rounded-[2rem] border border-white/10 bg-gradient-to-r from-slate-950/80 via-slate-900/60 to-slate-950/80 p-6"
    >
        <div class="grid gap-6 lg:grid-cols-[1.1fr_0.9fr] lg:items-center">
            <div>
                <div
                    class="text-xs font-semibold tracking-[0.22em] text-amber-300 uppercase"
                >
                    Unlock More Than The Teaser
                </div>
                <h2 class="mt-3 text-2xl font-semibold text-white sm:text-3xl">
                    Visitors should understand the edge before they hit
                    register.
                </h2>
                <p class="mt-3 max-w-2xl text-sm leading-7 text-slate-300">
                    This page shows enough to build trust. Registration opens
                    the full prediction archive, player pages, team pages, prop
                    workflows, and deeper filtering.
                </p>
            </div>
            <div class="grid gap-3 sm:grid-cols-2">
                <div
                    class="rounded-2xl border border-white/10 bg-white/[0.04] p-4 text-sm text-slate-200"
                >
                    Featured plays and live market context above the fold
                </div>
                <div
                    class="rounded-2xl border border-white/10 bg-white/[0.04] p-4 text-sm text-slate-200"
                >
                    Full-game, team, and player workflows behind the account
                    wall
                </div>
                <Link
                    v-if="!$page.props.auth.user"
                    :href="register()"
                    class="sm:col-span-2"
                >
                    <Button
                        size="lg"
                        class="h-12 w-full bg-amber-400 text-base font-semibold text-slate-950 hover:bg-amber-300"
                    >
                        Create Free Account
                    </Button>
                </Link>
            </div>
        </div>
    </section>
</template>
