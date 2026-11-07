export { Head, Link } from './frontendCleanupInertia.mjs';
export const usePage = () => ({
    url: '/nfl/teams/1',
    props: {
        auth: { user: null },
        subscription: { tier: 'free', tiers_enabled: true },
    },
});
