import { defineComponent, h } from 'vue';
export default defineComponent({
    inheritAttrs: false,
    setup(_, { slots }) {
        return () => h('div', slots.default?.());
    },
});
