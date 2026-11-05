import { defineComponent, h } from 'vue';
export const Head = { props: ['title'], render: () => null };
export const Link = defineComponent({
    inheritAttrs: false,
    props: ['href'],
    setup(props, { attrs, slots }) {
        return () =>
            h(
                'a',
                {
                    ...attrs,
                    href:
                        typeof props.href === 'string'
                            ? props.href
                            : props.href?.url,
                },
                slots.default?.(),
            );
    },
});
export const usePage = () => ({
    props: {
        subscription: {
            tier: globalThis.__playerViewerTier ?? 'free',
            tier_name: 'Free',
            tiers_enabled: true,
        },
    },
});
