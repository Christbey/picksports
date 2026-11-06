import { router, useForm } from '@inertiajs/vue3';
import { useClipboard } from '@vueuse/core';
import { computed, ref, watch, toRefs, inject, type InjectionKey } from 'vue';
import {
    useAdminUserSearch,
    type AdminUserSuggestion as UserLookupResult,
} from '@/composables/useAdminUserSearch';
import foundingUserRoutes from '@/routes/admin/settings/founding-users';
import groupRoutes from '@/routes/admin/settings/groups';
import type { AdminSettingsProps } from '@/types/admin-settings';

export function useAdminSettings(props: AdminSettingsProps) {
    const grantForm = useForm({
        email: '',
    });
    const limitForm = useForm({
        limit: props.foundingUsers.limit,
    });
    const groupForm = useForm({
        name: '',
        season: new Date().getFullYear(),
    });
    const inviteForm = useForm({
        group_id: (props.groups[0]?.id ?? null) as number | null,
        email: '',
    });
    const assignMemberForm = useForm({
        group_id: null as number | null,
        user_id: null as number | null,
    });
    const copiedLinkKey = ref<string | null>(null);
    const { copy } = useClipboard();

    const userSearch = ref('');
    const userLookup = useAdminUserSearch((_, query) =>
        foundingUserRoutes.search.url({ query: { query } }),
    );
    const userSuggestions = computed(
        () => userLookup.suggestions.value.founding ?? [],
    );
    const isSearchingUsers = computed(
        () => userLookup.searching.value.founding ?? false,
    );
    const groupUserSearch = ref<Record<number, string>>({});
    const groupLookup = useAdminUserSearch((groupId, query) =>
        groupRoutes.users.search.url({ query: { group_id: groupId, query } }),
    );
    const groupUserSuggestions = groupLookup.suggestions;
    const isSearchingGroupUsers = groupLookup.searching;
    watch(userSearch, (value) => userLookup.search('founding', value), {
        flush: 'sync',
    });

    function selectUserSuggestion(user: UserLookupResult): void {
        grantForm.email = user.email;
        userSearch.value = `${user.name} (${user.email})`;
        userLookup.clear('founding');
    }

    function grantFoundingAccess(): void {
        grantForm.post(foundingUserRoutes.grant.url(), {
            preserveScroll: true,
            onSuccess: () => {
                grantForm.reset();
                userSearch.value = '';
                userLookup.clear('founding');
            },
        });
    }

    function updateFoundingLimit(): void {
        limitForm.post(foundingUserRoutes.limit.url(), {
            preserveScroll: true,
        });
    }

    function revokeFoundingAccess(userId: number): void {
        if (!confirm('Revoke founding access for this user?')) {
            return;
        }

        router.post(
            foundingUserRoutes.revoke.url(),
            { user_id: userId },
            {
                preserveScroll: true,
            },
        );
    }

    function createGroup(): void {
        groupForm.post(groupRoutes.store.url(), {
            preserveScroll: true,
            onSuccess: () => groupForm.reset('name'),
        });
    }

    function inviteToGroup(): void {
        inviteForm.post(groupRoutes.invite.url(), {
            preserveScroll: true,
            onSuccess: () => inviteForm.reset('email'),
        });
    }

    function searchAssignableUsers(groupId: number): void {
        groupLookup.search(groupId, groupUserSearch.value[groupId] ?? '');
    }

    function setGroupUserSearch(groupId: number, value: string): void {
        groupUserSearch.value = { ...groupUserSearch.value, [groupId]: value };
        searchAssignableUsers(groupId);
    }

    function selectAssignableUser(
        groupId: number,
        user: UserLookupResult,
    ): void {
        assignMemberForm.group_id = groupId;
        assignMemberForm.user_id = user.id;
        groupUserSearch.value = {
            ...groupUserSearch.value,
            [groupId]: `${user.name} (${user.email})`,
        };
        groupLookup.clear(groupId);
    }

    function addUserToGroup(groupId: number): void {
        if (
            !assignMemberForm.user_id ||
            assignMemberForm.group_id !== groupId
        ) {
            return;
        }

        assignMemberForm.post(groupRoutes.users.store.url(), {
            preserveScroll: true,
            onSuccess: () => {
                assignMemberForm.reset();
                groupUserSearch.value = {
                    ...groupUserSearch.value,
                    [groupId]: '',
                };
                groupLookup.clear(groupId);
            },
        });
    }

    function removeUserFromGroup(
        groupId: number,
        userId: number,
        userName: string,
    ): void {
        if (!confirm(`Remove ${userName} from this group?`)) {
            return;
        }

        router.delete(groupRoutes.users.destroy.url(), {
            data: {
                group_id: groupId,
                user_id: userId,
            },
            preserveScroll: true,
        });
    }

    function rotateJoinLink(groupId: number): void {
        router.post(
            groupRoutes.joinLink.url(),
            { group_id: groupId },
            {
                preserveScroll: true,
            },
        );
    }

    async function copyLink(link: string, key: string): Promise<void> {
        try {
            await copy(link);
            copiedLinkKey.value = key;
            window.setTimeout(() => {
                if (copiedLinkKey.value === key) {
                    copiedLinkKey.value = null;
                }
            }, 2000);
        } catch {
            copiedLinkKey.value = null;
        }
    }

    function formatDate(date: string | null): string {
        if (!date) {
            return 'N/A';
        }

        return new Date(date).toLocaleString();
    }

    return {
        ...toRefs(props),
        grantForm,
        limitForm,
        groupForm,
        inviteForm,
        assignMemberForm,
        copiedLinkKey,
        userSearch,
        userSuggestions,
        isSearchingUsers,
        groupUserSearch,
        groupUserSuggestions,
        isSearchingGroupUsers,
        selectUserSuggestion,
        grantFoundingAccess,
        updateFoundingLimit,
        revokeFoundingAccess,
        createGroup,
        inviteToGroup,
        setGroupUserSearch,
        selectAssignableUser,
        addUserToGroup,
        removeUserFromGroup,
        rotateJoinLink,
        copyLink,
        formatDate,
    };
}

const adminSettingsKey: InjectionKey<ReturnType<typeof useAdminSettings>> =
    Symbol('admin-settings');
export { adminSettingsKey };
export function useAdminSettingsContext() {
    const state = inject(adminSettingsKey);
    if (!state) throw new Error('Admin settings provider is missing.');
    return state;
}
