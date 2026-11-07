import { reactive } from 'vue';
export { Head, Link } from './homepageInertia.mjs';
export const usePage = () =>
    new Proxy({}, { get: (_, key) => globalThis.__dashboardPage?.[key] });
export const router = {
    reload(options) {
        globalThis.__dashboardReloads.push(options.only);
        if (globalThis.__dashboardHoldReload)
            globalThis.__dashboardHoldReload(options);
        else options.onFinish?.();
    },
    post(url) {
        globalThis.__dashboardPosts.push(url);
    },
    flushAll() {},
};
export function useForm(initial) {
    return reactive({
        ...initial,
        errors: {},
        processing: false,
        reset() {},
        clearErrors() {},
        post(url) {
            globalThis.__dashboardPosts.push(url);
        },
    });
}
