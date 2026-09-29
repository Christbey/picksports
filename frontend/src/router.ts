import { createRouter, createWebHistory } from 'vue-router';
import { loadSession, session } from './context';

export const router = createRouter({
    history: createWebHistory(),
    routes: [
        { path: '/', redirect: '/nfl/predictions' },
        {
            path: '/login',
            component: () => import('./pages/Login.vue'),
            meta: { public: true },
        },
        {
            path: '/nfl/predictions',
            component: () => import('./pages/NflPredictions.vue'),
        },
        {
            path: '/nfl/games/:gameId(\\d+)',
            component: () => import('@/pages/NFL/Game.vue'),
            props: (route) => ({ gameId: Number(route.params.gameId) }),
        },
        {
            path: '/:pathMatch(.*)*',
            component: () => import('./pages/NotFound.vue'),
            meta: { public: true, fallback: true },
        },
    ],
    scrollBehavior: () => ({ top: 0 }),
});
router.beforeEach(async (to) => {
    if (to.meta.public) return true;
    if (!session.loaded) await loadSession();
    if (!session.context)
        return { path: '/login', query: { next: to.fullPath } };
    return true;
});
