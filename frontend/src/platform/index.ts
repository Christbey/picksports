import { defineComponent, h, type PropType } from 'vue';
import { RouterLink } from 'vue-router';
import { router } from '../router';
import { session } from '../context';

export { default as Head } from './Head.vue';
export const Link = defineComponent({
    inheritAttrs: false,
    props: {
        href: {
            type: [String, Object] as PropType<string | { url: string }>,
            required: true,
        },
    },
    setup(props, { attrs, slots }) {
        return () => {
            const href =
                typeof props.href === 'string' ? props.href : props.href.url;
            if (
                !href.startsWith('/') ||
                href.startsWith('//') ||
                router.resolve(href).meta.fallback
            ) {
                const base = import.meta.env.VITE_LEGACY_ORIGIN || '';
                const url =
                    href.startsWith('/') && !href.startsWith('//') && base
                        ? new URL(href, base).href
                        : href;
                return h('a', { ...attrs, href: url }, slots.default?.());
            }
            return h(RouterLink, { ...attrs, to: href }, slots);
        };
    },
});
export function usePage() {
    return {
        get url() {
            return router.currentRoute.value.fullPath;
        },
        get props() {
            return session.context ?? {};
        },
    };
}
