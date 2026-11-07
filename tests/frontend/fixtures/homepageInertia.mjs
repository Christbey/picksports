import { defineComponent, h } from 'vue';
export const Head = defineComponent({
    props: ['title'],
    setup(props, { slots }) {
        return () =>
            h(
                'div',
                { 'data-head-title': props.title, hidden: true },
                slots.default?.(),
            );
    },
});
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
