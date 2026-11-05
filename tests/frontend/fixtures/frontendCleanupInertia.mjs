import { defineComponent, h } from 'vue';
export const Head = { props: ['title'], render: () => null };
export const Link = defineComponent({
    props: ['href'],
    setup(props, { slots }) {
        return () =>
            h(
                'a',
                {
                    href:
                        typeof props.href === 'object'
                            ? props.href.url
                            : props.href,
                },
                slots.default?.(),
            );
    },
});
export const router = {
    get: (...args) => globalThis.__cleanupCalls.push({ method: 'get', args }),
    post: (url, data, options) => {
        globalThis.__cleanupCalls.push({ method: 'post', url, data });
        globalThis.__cleanupFinish = () => options?.onFinish?.();
    },
};
