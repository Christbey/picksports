<script setup lang="ts">
import {
    faBaseball,
    faBasketball,
    faFootball,
} from '@fortawesome/free-solid-svg-icons';
import { FontAwesomeIcon } from '@fortawesome/vue-fontawesome';
import { Link } from '@inertiajs/vue3';
import { LayoutGrid, MessageSquare } from 'lucide-vue-next';
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
    nflTeamMetrics,
    nflPlayerStats,
    nflInjuries,
    cfbPredictions,
    cfbPlayerStats,
    cfbInjuries,
    nbaPredictions,
    nbaTeamMetrics,
    nbaInjuries,
    nbaPlayerStats,
    nbaFutures,
    wnbaPredictions,
    wnbaTeamMetrics,
    wnbaInjuries,
    cbbPredictions,
    cbbTeamMetrics,
    cbbInjuries,
    cbbPlayerStats,
    cbbTournamentForecast,
    wcbbPredictions,
    wcbbTeamMetrics,
    wcbbInjuries,
    wcbbTournamentForecast,
    mlbPredictions,
    mlbTeamMetrics,
    mlbInjuries,
    mlbPlayerStats,
    mlbFutures,
} from '@/routes';
import { type NavItem } from '@/types';
import AppLogo from './AppLogo.vue';

const footballIconProps = { icon: faFootball };
const basketballIconProps = { icon: faBasketball };
const baseballIconProps = { icon: faBaseball };
const playerPropsHref = (sport: 'nfl' | 'nba' | 'mlb') =>
    `/${sport}/player-props`;

const openFeedbackModal = () => {
    if (typeof window === 'undefined') {
        return;
    }

    window.dispatchEvent(new CustomEvent('open-feedback-submission-modal'));
};

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
        icon: FontAwesomeIcon,
        iconProps: footballIconProps,
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
                href: nflInjuries(),
            },
            {
                title: 'Player Stats',
                href: nflPlayerStats(),
            },
            {
                title: 'Player Props',
                href: playerPropsHref('nfl'),
            },
        ],
    },
    {
        title: 'NBA',
        href: nbaPredictions(),
        icon: FontAwesomeIcon,
        iconProps: basketballIconProps,
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
                href: nbaInjuries(),
            },
            {
                title: 'Player Stats',
                href: nbaPlayerStats(),
            },
            {
                title: 'Futures',
                href: nbaFutures(),
            },
            {
                title: 'Player Props',
                href: playerPropsHref('nba'),
            },
        ],
    },
    {
        title: 'WNBA',
        href: wnbaPredictions(),
        icon: FontAwesomeIcon,
        iconProps: basketballIconProps,
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
                href: wnbaInjuries(),
            },
        ],
    },
    {
        title: 'MLB',
        href: mlbPredictions(),
        icon: FontAwesomeIcon,
        iconProps: baseballIconProps,
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
                href: mlbInjuries(),
            },
            {
                title: 'Player Stats',
                href: mlbPlayerStats(),
            },
            {
                title: 'Futures',
                href: mlbFutures(),
            },
            {
                title: 'Player Props',
                href: playerPropsHref('mlb'),
            },
        ],
    },
    {
        title: 'CFB',
        href: cfbPredictions(),
        icon: FontAwesomeIcon,
        iconProps: footballIconProps,
        items: [
            {
                title: 'Predictions',
                href: cfbPredictions(),
            },
            {
                title: 'Player Stats',
                href: cfbPlayerStats(),
            },
            {
                title: 'Injuries',
                href: cfbInjuries(),
            },
        ],
    },
    {
        title: 'CBB',
        href: cbbPredictions(),
        icon: FontAwesomeIcon,
        iconProps: basketballIconProps,
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
                href: cbbInjuries(),
            },
            {
                title: 'Player Stats',
                href: cbbPlayerStats(),
            },
            {
                title: 'Tournament Forecast',
                href: cbbTournamentForecast(),
            },
        ],
    },
    {
        title: 'WCBB',
        href: wcbbPredictions(),
        icon: FontAwesomeIcon,
        iconProps: basketballIconProps,
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
                href: wcbbInjuries(),
            },
            {
                title: 'Tournament Forecast',
                href: wcbbTournamentForecast(),
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
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton
                        type="button"
                        aria-label="Open feedback form"
                        @click="openFeedbackModal"
                    >
                        <MessageSquare />
                        <span>Feedback</span>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
