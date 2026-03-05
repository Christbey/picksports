<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { CircleDot, Dribbble, Goal, LayoutGrid } from 'lucide-vue-next';
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
        icon: Goal,
        items: [
            {
                title: 'Predictions',
                href: nflPredictions(),
            },
            {
                title: 'Team Metrics',
                href: nflTeamMetrics(),
            },
            {
                title: 'Injuries',
                href: '/nfl-injuries',
            },
            {
                title: 'Player Stats',
                href: '/nfl-player-stats',
            },
            {
                title: 'Player Props',
                href: '/nfl-player-props',
            },
        ],
    },
    {
        title: 'NBA',
        href: nbaPredictions(),
        icon: Dribbble,
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
                title: 'Injuries',
                href: '/nba-injuries',
            },
            {
                title: 'Player Stats',
                href: nbaPlayerStats(),
            },
            {
                title: 'Futures',
                href: '/nba-futures',
            },
            {
                title: 'Player Props',
                href: '/nba-player-props',
            },
        ],
    },
    {
        title: 'WNBA',
        href: wnbaPredictions(),
        icon: Dribbble,
        items: [
            {
                title: 'Predictions',
                href: wnbaPredictions(),
            },
            {
                title: 'Team Metrics',
                href: wnbaTeamMetrics(),
            },
            {
                title: 'Injuries',
                href: '/wnba-injuries',
            },
        ],
    },
    {
        title: 'MLB',
        href: mlbPredictions(),
        icon: CircleDot,
        items: [
            {
                title: 'Predictions',
                href: mlbPredictions(),
            },
            {
                title: 'Team Metrics',
                href: mlbTeamMetrics(),
            },
            {
                title: 'Injuries',
                href: '/mlb-injuries',
            },
            {
                title: 'Player Stats',
                href: '/mlb-player-stats',
            },
            {
                title: 'Futures',
                href: '/mlb-futures',
            },
            {
                title: 'Player Props',
                href: '/mlb-player-props',
            },
        ],
    },
    {
        title: 'CFB',
        href: cfbPredictions(),
        icon: Goal,
        items: [
            {
                title: 'Predictions',
                href: cfbPredictions(),
            },
            {
                title: 'Player Stats',
                href: '/cfb-player-stats',
            },
            {
                title: 'Injuries',
                href: '/cfb-injuries',
            },
        ],
    },
    {
        title: 'CBB',
        href: cbbPredictions(),
        icon: Dribbble,
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
                title: 'Injuries',
                href: '/cbb-injuries',
            },
            {
                title: 'Player Stats',
                href: cbbPlayerStats(),
            },
            {
                title: 'Tournament Forecast',
                href: '/cbb-tournament-forecast',
            },
            {
                title: 'Player Props',
                href: '/cbb-player-props',
            },
        ],
    },
    {
        title: 'WCBB',
        href: wcbbPredictions(),
        icon: Dribbble,
        items: [
            {
                title: 'Predictions',
                href: wcbbPredictions(),
            },
            {
                title: 'Team Metrics',
                href: wcbbTeamMetrics(),
            },
            {
                title: 'Injuries',
                href: '/wcbb-injuries',
            },
            {
                title: 'Tournament Forecast',
                href: '/wcbb-tournament-forecast',
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
