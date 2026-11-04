import type { ComputedRef, Ref } from 'vue';
import { computed, onMounted, ref } from 'vue';
import type { Appearance, ResolvedAppearance } from '@/types';

export type { Appearance, ResolvedAppearance };

const appearance = ref<Appearance>('system');
const systemPrefersDark = ref(false);
let systemThemeQuery: MediaQueryList | null = null;

export type UseAppearanceReturn = {
    appearance: Ref<Appearance>;
    resolvedAppearance: ComputedRef<ResolvedAppearance>;
    updateAppearance: (value: Appearance) => void;
};

export function updateTheme(value: Appearance): void {
    if (typeof window === 'undefined') {
        return;
    }

    systemPrefersDark.value = prefersDark();
    const dark =
        value === 'system' ? systemPrefersDark.value : value === 'dark';
    document.documentElement.classList.toggle('dark', dark);
}

const setCookie = (name: string, value: string, days = 365) => {
    if (typeof document === 'undefined') {
        return;
    }

    const maxAge = days * 24 * 60 * 60;

    document.cookie = `${name}=${value};path=/;max-age=${maxAge};SameSite=Lax`;
};

const mediaQuery = () => {
    if (typeof window === 'undefined') {
        return null;
    }

    return window.matchMedia('(prefers-color-scheme: dark)');
};

const getStoredAppearance = () => {
    if (typeof window === 'undefined') {
        return null;
    }

    const stored = localStorage.getItem('appearance');
    return stored === 'light' || stored === 'dark' || stored === 'system'
        ? stored
        : null;
};

const prefersDark = (): boolean => {
    if (typeof window === 'undefined') {
        return false;
    }

    return window.matchMedia('(prefers-color-scheme: dark)').matches;
};

const handleSystemThemeChange = () => {
    updateTheme(appearance.value);
};

export function initializeTheme(): void {
    if (typeof window === 'undefined') {
        return;
    }

    appearance.value = getStoredAppearance() ?? 'system';
    updateTheme(appearance.value);
    systemThemeQuery?.removeEventListener('change', handleSystemThemeChange);
    systemThemeQuery = mediaQuery();
    systemThemeQuery?.addEventListener('change', handleSystemThemeChange);
}

export function useAppearance(): UseAppearanceReturn {
    onMounted(() => {
        appearance.value = getStoredAppearance() ?? 'system';
        systemPrefersDark.value = prefersDark();
    });

    const resolvedAppearance = computed<ResolvedAppearance>(() => {
        if (appearance.value === 'system') {
            return systemPrefersDark.value ? 'dark' : 'light';
        }

        return appearance.value;
    });

    function updateAppearance(value: Appearance) {
        appearance.value = value;

        localStorage.setItem('appearance', value);

        setCookie('appearance', value);

        updateTheme(value);
    }

    return {
        appearance,
        resolvedAppearance,
        updateAppearance,
    };
}
