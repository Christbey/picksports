import { h } from 'vue';
export default {
    props: ['prediction'],
    setup: (props) => () =>
        h(
            'article',
            { 'data-testid': 'stored-candidate' },
            String(props.prediction.id),
        ),
};
