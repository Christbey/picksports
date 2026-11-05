import { inject, type InjectionKey } from 'vue';
import type { useMarchMadnessBracket } from '@/composables/useMarchMadnessBracket';
export type BracketBuilderState = ReturnType<typeof useMarchMadnessBracket>;
export const bracketBuilderKey: InjectionKey<BracketBuilderState> =
    Symbol('bracket-builder');
export function useBracketBuilderContext(): BracketBuilderState {
    const context = inject(bracketBuilderKey);
    if (!context)
        throw new Error('Bracket sections require a bracket builder.');
    return context;
}
