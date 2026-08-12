import { createInertiaApp, router } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import type { DefineComponent } from 'vue';
import { createApp, h } from 'vue';
import '../css/app.css';
import { initializeTheme } from './composables/useAppearance';
import { flushPendingAnalyticsEvent, trackPageView } from './lib/analytics';
import { initializePerformanceMonitoring } from './lib/performance';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    resolve: (name) =>
        resolvePageComponent(
            `./pages/${name}.vue`,
            import.meta.glob<DefineComponent>('./pages/**/*.vue'),
        ),
    setup({ el, App, props, plugin }) {
        const app = createApp({ render: () => h(App, props) });

        app.use(plugin).mount(el);

        const initialPage = props.initialPage;
        trackPageView(initialPage);
        flushPendingAnalyticsEvent(initialPage);
    },
    progress: {
        color: '#4B5563',
    },
});

router.on('navigate', (event) => {
    const page = event.detail.page;
    window.requestAnimationFrame(() => {
        trackPageView(page);
        flushPendingAnalyticsEvent(page);
    });
});

// This will set light / dark mode on page load...
initializeTheme();
initializePerformanceMonitoring();

if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').catch(() => {
            // Ignore registration errors in unsupported or restricted contexts.
        });
    });
}
