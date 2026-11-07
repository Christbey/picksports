import { inject, ref, type InjectionKey, type Ref } from 'vue';

export const pickSportsThemeKey: InjectionKey<Readonly<Ref<boolean>>> =
    Symbol('PickSports theme');

export function usePickSportsTheme() {
    return inject(pickSportsThemeKey, ref(false));
}
