// Synthetic transport only. No labels, ARIA relationships or focus repair are injected.
import { defineComponent, h, reactive } from 'vue';
export const usePage = () =>
    new Proxy(
        {},
        {
            get: (_, key) =>
                (globalThis.__a11yPage ?? globalThis.__dashboardPage)?.[key],
        },
    );
export const Head = defineComponent({
    props: ['title'],
    setup(p) {
        if (p.title) document.title = p.title;
        return () => null;
    },
});
export const Link = defineComponent({
    inheritAttrs: false,
    props: ['href'],
    setup(p, { attrs, slots }) {
        return () =>
            h(
                'a',
                {
                    ...attrs,
                    href: typeof p.href === 'string' ? p.href : p.href?.url,
                },
                slots.default?.(),
            );
    },
});
const record = (method, url, data, options) => {
    (globalThis.__a11yCalls ??= []).push({ method, url, data, options });
};
export const router = {
    get: (u, d, o) => record('get', u, d, o),
    visit: (u) => record('visit', u),
    post: (u, d, o) => record('post', u, d, o),
    patch: (u, d, o) => record('patch', u, d, o),
    delete: (u, o) => record('delete', u, null, o),
    reload: (o) => {
        (globalThis.__dashboardReloads ??= []).push(o.only);
        o.onFinish?.();
    },
    flushAll() {},
    on() {
        return () => {};
    },
};
export const Form = defineComponent({
    inheritAttrs: false,
    emits: ['submit', 'error', 'success', 'finish'],
    setup(_, { attrs, slots, emit }) {
        const state = reactive({
            errors: {},
            processing: false,
            recentlySuccessful: false,
            reset() {},
            clearErrors() {
                state.errors = {};
            },
        });
        (globalThis.__a11yForms ??= []).push(state);
        return () =>
            h(
                'form',
                {
                    ...attrs,
                    onSubmit: (e) => {
                        e.preventDefault();
                        emit('submit', e);
                        record(
                            'form',
                            attrs.action,
                            Array.from(new FormData(e.currentTarget).entries()),
                        );
                        state.processing = true;
                        queueMicrotask(() => {
                            state.processing = false;
                            state.errors = globalThis.__a11yValidation ?? {};
                            emit('error', state.errors);
                            emit('finish');
                        });
                    },
                },
                slots.default?.(state),
            );
    },
});
export function useForm(data = {}) {
    return reactive({
        ...data,
        errors: {},
        processing: false,
        reset() {},
        clearErrors() {
            this.errors = {};
        },
        post(u, o) {
            record('post', u, data, o);
        },
        patch(u, o) {
            record('patch', u, data, o);
        },
        delete(u, o) {
            record('delete', u, data, o);
        },
    });
}
export const Deferred = {
    setup(_, { slots }) {
        return () => slots.default?.();
    },
};
export const WhenVisible = Deferred;
