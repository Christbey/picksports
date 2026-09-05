import {
    faBaseball,
    faBasketball,
    faFootball,
} from '@fortawesome/free-solid-svg-icons';
import { FontAwesomeIcon } from '@fortawesome/vue-fontawesome';
import { LayoutGrid, ListChecks } from 'lucide-vue-next';
import {
    cbbInjuries,
    cbbPlayerStats,
    cbbPredictions,
    cbbTeamMetrics,
    cbbTournamentForecast,
    cfbInjuries,
    cfbPlayerStats,
    cfbPredictions,
    dashboard,
    mlbFutures,
    mlbInjuries,
    mlbPlayerStats,
    mlbPredictions,
    mlbTeamMetrics,
    myBets,
    nbaFutures,
    nbaInjuries,
    nbaPlayerStats,
    nbaPredictions,
    nbaTeamMetrics,
    nflFutures,
    nflInjuries,
    nflPlayerStats,
    nflPredictions,
    nflTeamMetrics,
    wcbbInjuries,
    wcbbPredictions,
    wcbbTeamMetrics,
    wcbbTournamentForecast,
    wnbaInjuries,
    wnbaPredictions,
    wnbaTeamMetrics,
} from '@/routes';
import type { NavItem } from '@/types';

export type NavigationGroup = {
    label: string;
    items: NavItem[];
};

type SeasonalNavItem = NavItem & {
    activeMonths: number[];
};

const footballIconProps = { icon: faFootball };
const basketballIconProps = { icon: faBasketball };
const baseballIconProps = { icon: faBaseball };

const playerPropsHref = (sport: 'nfl' | 'nba' | 'wnba' | 'mlb' | 'cbb') =>
    `/${sport}/player-props`;

export const platformNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },
    {
        title: 'My Bets',
        href: myBets(),
        icon: ListChecks,
    },
];

const sports: SeasonalNavItem[] = [
    {
        title: 'NFL',
        href: nflPredictions(),
        icon: FontAwesomeIcon,
        iconProps: footballIconProps,
        activeMonths: [1, 2, 8, 9, 10, 11, 12],
        items: [
            { title: 'Board', href: nflPredictions() },
            { title: 'Player Props', href: playerPropsHref('nfl') },
            { title: 'Futures', href: nflFutures() },
            { title: 'Team Metrics', href: nflTeamMetrics() },
            { title: 'Player Stats', href: nflPlayerStats() },
            { title: 'Injuries', href: nflInjuries() },
        ],
    },
    {
        title: 'NBA',
        href: nbaPredictions(),
        icon: FontAwesomeIcon,
        iconProps: basketballIconProps,
        activeMonths: [1, 2, 3, 4, 5, 6, 10, 11, 12],
        items: [
            { title: 'Board', href: nbaPredictions() },
            { title: 'Player Props', href: playerPropsHref('nba') },
            { title: 'Futures', href: nbaFutures() },
            { title: 'Team Metrics', href: nbaTeamMetrics() },
            { title: 'Player Stats', href: nbaPlayerStats() },
            { title: 'Injuries', href: nbaInjuries() },
        ],
    },
    {
        title: 'WNBA',
        href: wnbaPredictions(),
        icon: FontAwesomeIcon,
        iconProps: basketballIconProps,
        activeMonths: [5, 6, 7, 8, 9, 10],
        items: [
            { title: 'Board', href: wnbaPredictions() },
            { title: 'Player Props', href: playerPropsHref('wnba') },
            { title: 'Team Metrics', href: wnbaTeamMetrics() },
            { title: 'Injuries', href: wnbaInjuries() },
        ],
    },
    {
        title: 'MLB',
        href: mlbPredictions(),
        icon: FontAwesomeIcon,
        iconProps: baseballIconProps,
        activeMonths: [3, 4, 5, 6, 7, 8, 9, 10],
        items: [
            { title: 'Board', href: mlbPredictions() },
            { title: 'Player Props', href: playerPropsHref('mlb') },
            { title: 'Futures', href: mlbFutures() },
            { title: 'Team Metrics', href: mlbTeamMetrics() },
            { title: 'Player Stats', href: mlbPlayerStats() },
            { title: 'Injuries', href: mlbInjuries() },
        ],
    },
    {
        title: 'CFB',
        href: cfbPredictions(),
        icon: FontAwesomeIcon,
        iconProps: footballIconProps,
        activeMonths: [1, 8, 9, 10, 11, 12],
        items: [
            { title: 'Board', href: cfbPredictions() },
            { title: 'Player Stats', href: cfbPlayerStats() },
            { title: 'Injuries', href: cfbInjuries() },
        ],
    },
    {
        title: 'CBB',
        href: cbbPredictions(),
        icon: FontAwesomeIcon,
        iconProps: basketballIconProps,
        activeMonths: [1, 2, 3, 4, 11, 12],
        items: [
            { title: 'Board', href: cbbPredictions() },
            { title: 'Player Props', href: playerPropsHref('cbb') },
            { title: 'Tournament Forecast', href: cbbTournamentForecast() },
            { title: 'Team Metrics', href: cbbTeamMetrics() },
            { title: 'Player Stats', href: cbbPlayerStats() },
            { title: 'Injuries', href: cbbInjuries() },
        ],
    },
    {
        title: 'WCBB',
        href: wcbbPredictions(),
        icon: FontAwesomeIcon,
        iconProps: basketballIconProps,
        activeMonths: [1, 2, 3, 4, 11, 12],
        items: [
            { title: 'Board', href: wcbbPredictions() },
            { title: 'Tournament Forecast', href: wcbbTournamentForecast() },
            { title: 'Team Metrics', href: wcbbTeamMetrics() },
            { title: 'Injuries', href: wcbbInjuries() },
        ],
    },
];

const withoutSeasonMetadata = (sport: SeasonalNavItem): NavItem => ({
    title: sport.title,
    href: sport.href,
    icon: sport.icon,
    iconProps: sport.iconProps,
    items: sport.items,
});

export function sportNavigationGroups(date = new Date()): NavigationGroup[] {
    const month = date.getMonth() + 1;
    const inSeason = sports
        .filter((sport) => sport.activeMonths.includes(month))
        .map(withoutSeasonMetadata);
    const moreSports = sports
        .filter((sport) => !sport.activeMonths.includes(month))
        .map(withoutSeasonMetadata);

    return [
        { label: 'In Season', items: inSeason },
        { label: 'More Sports', items: moreSports },
    ].filter((group) => group.items.length > 0);
}
