import '@inertiajs/vue3';
import type { AppPageProps } from './index';

// Extend the official Inertia/Vue declarations rather than redeclaring router APIs.
declare module '@inertiajs/core' {
    // eslint-disable-next-line @typescript-eslint/no-empty-object-type -- Inertia extends shared props through interface merging.
    interface PageProps extends AppPageProps {}
}

declare global {
    interface ImportMetaEnv {
        readonly VITE_APP_NAME?: string;
    }

    interface Window {
        dataLayer: Array<Record<string, unknown>>;
    }
}
