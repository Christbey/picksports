import type { InertiaLinkProps } from '@inertiajs/vue3';
import { usePage } from '@inertiajs/vue3';
import type { ComputedRef, DeepReadonly } from 'vue';
import { computed, readonly } from 'vue';
import { toUrl } from '@/lib/utils';

export type UseCurrentUrlReturn = {
    currentUrl: DeepReadonly<ComputedRef<string>>;
    isCurrentUrl: (
        urlToCheck: NonNullable<InertiaLinkProps['href']>,
        currentUrl?: string,
    ) => boolean;
    whenCurrentUrl: <T, F = null>(
        urlToCheck: NonNullable<InertiaLinkProps['href']>,
        ifTrue: T,
        ifFalse?: F,
    ) => T | F;
};

export function useCurrentUrl(): UseCurrentUrlReturn {
    const page = usePage();
    const path = (value: string): string => {
        if (value.startsWith('/') && !value.startsWith('//')) {
            return value.split(/[?#]/, 1)[0];
        }
        try {
            return new URL(value).pathname;
        } catch {
            return '';
        }
    };
    const currentUrlReactive = computed(() => path(page.url));
    function isCurrentUrl(
        urlToCheck: NonNullable<InertiaLinkProps['href']>,
        currentUrl?: string,
    ) {
        const value = toUrl(urlToCheck);
        if (!value.startsWith('/') || value.startsWith('//')) {
            try {
                const target = new URL(value);
                const origin =
                    typeof window !== 'undefined'
                        ? window.location.origin
                        : new URL(page.url).origin;
                if (target.origin !== origin) return false;
            } catch {
                return false;
            }
        }
        return (
            path(value) !== '' && path(value) === path(currentUrl ?? page.url)
        );
    }

    function whenCurrentUrl(
        urlToCheck: NonNullable<InertiaLinkProps['href']>,
        ifTrue: any,
        ifFalse: any = null,
    ) {
        return isCurrentUrl(urlToCheck) ? ifTrue : ifFalse;
    }

    return {
        currentUrl: readonly(currentUrlReactive),
        isCurrentUrl,
        whenCurrentUrl,
    };
}
