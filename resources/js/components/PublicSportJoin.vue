<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { dashboard, login, register } from '@/routes';
</script>
<template>
    <section
        class="mt-8 overflow-hidden rounded-[2rem] border border-amber-300/20 bg-gradient-to-r from-amber-300/12 via-white/[0.05] to-sky-400/10 p-6 sm:p-8"
    >
        <div class="grid gap-6 lg:grid-cols-[1.2fr_0.8fr] lg:items-center">
            <div>
                <div
                    class="text-xs font-semibold tracking-[0.22em] text-amber-300 uppercase"
                >
                    Unlock The Full Experience
                </div>
                <h2 class="mt-3 text-2xl font-semibold text-white sm:text-4xl">
                    See the complete board, not just the teaser.
                </h2>
                <p class="mt-3 max-w-2xl text-sm leading-7 text-slate-300">
                    Register to unlock full prediction pages, deeper team and
                    player views, more filtering, and the workflows built for
                    actual daily decision-making.
                </p>
            </div>
            <div class="grid gap-3">
                <div
                    class="rounded-2xl border border-white/10 bg-slate-950/45 p-4 text-sm text-slate-200"
                >
                    Full predictions archive, team pages, game pages, and prop
                    workflows
                </div>
                <div
                    class="rounded-2xl border border-white/10 bg-slate-950/45 p-4 text-sm text-slate-200"
                >
                    Better filtering, deeper context, and a faster path from
                    signal to pick
                </div>
                <div class="flex flex-col gap-3 sm:flex-row">
                    <Link
                        v-if="!$page.props.auth.user"
                        :href="register()"
                        class="flex-1"
                    >
                        <Button
                            size="lg"
                            class="h-12 w-full bg-amber-400 text-base font-semibold text-slate-950 hover:bg-amber-300"
                        >
                            Create Free Account
                        </Button>
                    </Link>
                    <Link
                        v-if="!$page.props.auth.user"
                        :href="login()"
                        class="flex-1"
                    >
                        <Button
                            size="lg"
                            variant="ghost"
                            class="h-12 w-full border border-white/15 bg-white/5 text-base text-slate-100 hover:bg-white/10"
                        >
                            Sign In
                        </Button>
                    </Link>
                    <Link v-else :href="dashboard()" class="flex-1">
                        <Button
                            size="lg"
                            class="h-12 w-full bg-amber-400 text-base font-semibold text-slate-950 hover:bg-amber-300"
                        >
                            Open Dashboard
                        </Button>
                    </Link>
                </div>
            </div>
        </div>
    </section>
</template>
