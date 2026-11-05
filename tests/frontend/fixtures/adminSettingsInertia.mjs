import { defineComponent, h, reactive } from 'vue';
export const Head = { props: ['title'], render: () => null };
export const Link = defineComponent({
    props: ['href'],
    setup(props, { slots }) {
        return () => h('a', { href: props.href }, slots.default?.());
    },
});
export const router = {
    post: (url, data, options) =>
        globalThis.__adminSettingsCalls.push({
            method: 'post',
            url,
            data,
            preserveScroll: options.preserveScroll,
        }),
    delete: (url, options) =>
        globalThis.__adminSettingsCalls.push({
            method: 'delete',
            url,
            data: options.data,
            preserveScroll: options.preserveScroll,
        }),
};
export function useForm(initial) {
    const form = reactive({
        ...initial,
        errors: {},
        processing: false,
        reset(...fields) {
            for (const key of fields.length ? fields : Object.keys(initial))
                form[key] = initial[key];
        },
        post(url, options) {
            globalThis.__adminSettingsCalls.push({
                method: 'post',
                url,
                data: Object.fromEntries(
                    Object.keys(initial).map((key) => [key, form[key]]),
                ),
                preserveScroll: options.preserveScroll,
            });
            globalThis.__adminSettingsSuccess = () => options.onSuccess?.();
        },
    });
    (globalThis.__adminSettingsForms ??= []).push(form);
    return form;
}
