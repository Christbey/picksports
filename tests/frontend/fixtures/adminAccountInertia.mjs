import { h } from 'vue';
export const Head = { props: ['title'], render: () => null };
export const Link = {
    props: ['href'],
    setup:
        (props, { slots }) =>
        () =>
            h('a', { href: props.href }, slots.default?.()),
};
export const router = {
    get: (...args) => globalThis.__accountRouter?.get?.(...args),
    reload: () => {},
    patch: (...args) => globalThis.__accountRouter?.patch?.(...args),
};
