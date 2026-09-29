import { computed } from 'vue';
import { usePage } from '@/platform';

/** Presentation only; the API remains responsible for authorization. */
export function useViewerAccess() {
    const page = usePage();
    const isAdmin = computed(() => Boolean(page.props?.auth?.user?.is_admin));
    return { isAdmin };
}
