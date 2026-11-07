import { defineComponent, h, reactive } from 'vue';
export const Head = { props: ['title'], render: () => null };
export const Link = defineComponent({
    props: ['href'],
    setup:
        (props, { slots }) =>
        () =>
            h(
                'a',
                {
                    href:
                        typeof props.href === 'object'
                            ? props.href.url
                            : props.href,
                },
                slots.default?.(),
            ),
});
const record = (method, url, data, options) => {
    const call = { method, url, data, options };
    (globalThis.__settingsSplitCalls ??= []).push(call);
    return call;
};
export const router = {
    visit: (url) => record('visit', url),
    patch: (url, data, options) => record('patch', url, data, options),
    delete: (url, options) => record('delete', url, undefined, options),
    post: (url, data, options) => record('post', url, data, options),
};
export const Form = defineComponent({
    inheritAttrs: false,
    setup(_, { attrs, slots }) {
        const state = reactive({
            errors: globalThis.__settingsSplitErrors ?? {},
            processing: false,
            recentlySuccessful: false,
        });
        globalThis.__settingsSplitForm = state;
        return () =>
            h(
                'form',
                {
                    ...attrs,
                    onSubmit: (event) => {
                        event.preventDefault();
                        const formData = new FormData(event.currentTarget);
                        record(
                            'form',
                            attrs.action,
                            Array.from(formData.entries()),
                            { method: attrs.method },
                        );
                        state.processing = true;
                    },
                },
                slots.default?.(state),
            );
    },
});
