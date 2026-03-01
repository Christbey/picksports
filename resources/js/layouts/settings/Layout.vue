<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { toUrl } from '@/lib/utils';
import { edit as editAlertPreferences } from '@/routes/alert-preferences';
import { edit as editAppearance } from '@/routes/appearance';
import { edit as editProfile } from '@/routes/profile';
import { show } from '@/routes/two-factor';
import { edit as editPassword } from '@/routes/user-password';
import { type NavItem } from '@/types';

const page = usePage();
const user = computed(() => page.props.auth.user);
const props = withDefaults(defineProps<{
    fullWidth?: boolean;
}>(), {
    fullWidth: false,
});

const userNavItems = computed(() => {
    const items: NavItem[] = [
        {
            title: 'Profile',
            href: editProfile(),
        },
        {
            title: 'Password',
            href: editPassword(),
        },
        {
            title: 'Two-Factor Auth',
            href: show(),
        },
        {
            title: 'Subscription',
            href: '/settings/subscription',
        },
        {
            title: 'Alert Preferences',
            href: editAlertPreferences(),
        },
        {
            title: 'Appearance',
            href: editAppearance(),
        },
        {
            title: 'Onboarding',
            href: '/settings/onboarding',
        },
    ];

    return items;
});

const adminNavItems = computed(() => {
    if (!user.value.is_admin) {
        return [] as NavItem[];
    }

    return [
        {
            title: 'Admin Settings',
            href: '/settings/admin',
        },
    ] satisfies NavItem[];
});

const { currentUrl, isCurrentUrl } = useCurrentUrl();

function isSettingsItemActive(href: NavItem['href']): boolean {
    const target = toUrl(href);
    const path = target.startsWith('http')
        ? new URL(target, window.location.origin).pathname
        : target;

    return isCurrentUrl(href) || currentUrl.value.startsWith(`${path}/`);
}
</script>

<template>
    <div class="px-4 py-6">
        <Heading
            title="Settings"
            description="Manage your profile and account settings"
        />

        <div class="flex flex-col lg:flex-row lg:space-x-12">
            <aside class="w-full max-w-xl lg:w-56">
                <div class="space-y-4">
                    <div class="rounded-lg border border-sidebar-border p-2">
                        <p class="px-2 pb-1 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Account</p>
                        <nav
                            class="flex flex-col space-y-1 space-x-0"
                            aria-label="Account settings"
                        >
                            <Button
                                v-for="item in userNavItems"
                                :key="toUrl(item.href)"
                                variant="ghost"
                                :class="[
                                    'w-full justify-start',
                                    { 'bg-muted': isSettingsItemActive(item.href) },
                                ]"
                                as-child
                            >
                                <Link :href="item.href">
                                    <component :is="item.icon" class="h-4 w-4" />
                                    {{ item.title }}
                                </Link>
                            </Button>
                        </nav>
                    </div>

                    <div v-if="adminNavItems.length > 0" class="rounded-lg border border-sidebar-border p-2">
                        <p class="px-2 pb-1 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Admin</p>
                        <nav
                            class="flex flex-col space-y-1 space-x-0"
                            aria-label="Admin settings"
                        >
                            <Button
                                v-for="item in adminNavItems"
                                :key="toUrl(item.href)"
                                variant="ghost"
                                :class="[
                                    'w-full justify-start',
                                    { 'bg-muted': isSettingsItemActive(item.href) },
                                ]"
                                as-child
                            >
                                <Link :href="item.href">
                                    <component :is="item.icon" class="h-4 w-4" />
                                    {{ item.title }}
                                </Link>
                            </Button>
                        </nav>
                    </div>
                </div>
            </aside>

            <Separator class="my-6 lg:hidden" />

            <div :class="props.fullWidth ? 'min-w-0 flex-1' : 'min-w-0 flex-1 md:max-w-2xl'">
                <section :class="props.fullWidth ? 'space-y-12' : 'max-w-xl space-y-12'">
                    <slot />
                </section>
            </div>
        </div>
    </div>
</template>
