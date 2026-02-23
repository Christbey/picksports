<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { LayoutGrid, TrendingUp, BarChart3 } from 'lucide-vue-next';
import NavCollapsible from '@/components/NavCollapsible.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import {
    dashboard,
    nflPredictions,
    cfbPredictions,
    nbaPredictions,
    wnbaPredictions,
    cbbPredictions,
    wcbbPredictions,
    mlbPredictions,
    cbbTeamMetrics,
    wcbbTeamMetrics,
    nbaTeamMetrics,
    nbaPlayerStats,
    cbbPlayerStats,
    wnbaTeamMetrics,
    mlbTeamMetrics,
    nflTeamMetrics,
} from '@/routes';
import { type NavItem } from '@/types';
import AppLogo from './AppLogo.vue';

const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },
];

const sportNavItems: NavItem[] = [
    {
        title: 'NFL',
        href: nflPredictions(),
        icon: TrendingUp,
        items: [
            {
                title: 'Predictions',
                href: nflPredictions(),
            },
            {
                title: 'Team Metrics',
                href: nflTeamMetrics(),
            },
        ],
    },
    {
        title: 'NBA',
        href: nbaPredictions(),
        icon: BarChart3,
        items: [
            {
                title: 'Predictions',
                href: nbaPredictions(),
            },
            {
                title: 'Team Metrics',
                href: nbaTeamMetrics(),
            },
            {
                title: 'Player Stats',
                href: nbaPlayerStats(),
            },
        ],
    },
    {
        title: 'WNBA',
        href: wnbaPredictions(),
        icon: BarChart3,
        items: [
            {
                title: 'Predictions',
                href: wnbaPredictions(),
            },
            {
                title: 'Team Metrics',
                href: wnbaTeamMetrics(),
            },
        ],
    },
    {
        title: 'MLB',
        href: mlbPredictions(),
        icon: TrendingUp,
        items: [
            {
                title: 'Predictions',
                href: mlbPredictions(),
            },
            {
                title: 'Team Metrics',
                href: mlbTeamMetrics(),
            },
        ],
    },
    {
        title: 'CFB',
        href: cfbPredictions(),
        icon: TrendingUp,
        items: [
            {
                title: 'Predictions',
                href: cfbPredictions(),
            },
        ],
    },
    {
        title: 'CBB',
        href: cbbPredictions(),
        icon: BarChart3,
        items: [
            {
                title: 'Predictions',
                href: cbbPredictions(),
            },
            {
                title: 'Team Metrics',
                href: cbbTeamMetrics(),
            },
            {
                title: 'Player Stats',
                href: cbbPlayerStats(),
            },
        ],
    },
    {
        title: 'WCBB',
        href: wcbbPredictions(),
        icon: BarChart3,
        items: [
            {
                title: 'Predictions',
                href: wcbbPredictions(),
            },
            {
                title: 'Team Metrics',
                href: wcbbTeamMetrics(),
            },
        ],
    },
];
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboard()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
            <NavCollapsible :items="sportNavItems" label="Sports" />
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
