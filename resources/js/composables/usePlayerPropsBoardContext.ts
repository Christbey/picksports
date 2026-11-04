import { inject, type InjectionKey, type UnwrapNestedRefs } from 'vue';
import type { usePlayerPropsBoard } from '@/composables/usePlayerPropsBoard';
export type PlayerPropsBoardState = UnwrapNestedRefs<
    ReturnType<typeof usePlayerPropsBoard>
>;
export const playerPropsBoardKey: InjectionKey<PlayerPropsBoardState> =
    Symbol('player-props-board');
export function usePlayerPropsBoardContext(): PlayerPropsBoardState {
    const board = inject(playerPropsBoardKey);
    if (!board) throw new Error('Player props sections require a board.');
    return board;
}
