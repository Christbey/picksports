<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { useAdminSettingsContext } from '@/composables/useAdminSettings';
import type { AdminGroup } from '@/types/admin-settings';
defineProps<{ group: AdminGroup }>();
const {
    groupUserSearch,
    isSearchingGroupUsers,
    groupUserSuggestions,
    handleAssignableUserInput,
    selectAssignableUser,
    assignMemberForm,
    addUserToGroup,
} = useAdminSettingsContext();
</script>

<template>
    <div
        class="mt-3 rounded-lg border border-sidebar-border bg-white p-3 dark:bg-sidebar"
    >
        <p
            class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
        >
            Add Existing User
        </p>
        <div class="mt-2 flex flex-col gap-3 sm:flex-row sm:items-start">
            <div class="relative flex-1">
                <input
                    :value="groupUserSearch[group.id] ?? ''"
                    type="text"
                    placeholder="Search existing users by name or email"
                    autocomplete="off"
                    class="w-full rounded-lg border border-sidebar-border bg-white px-3 py-2 text-sm dark:bg-sidebar"
                    @input="handleAssignableUserInput(group.id, $event)"
                />
                <div
                    v-if="
                        (isSearchingGroupUsers[group.id] ?? false) ||
                        (groupUserSuggestions[group.id]?.length ?? 0) > 0
                    "
                    class="absolute z-10 mt-1 w-full overflow-hidden rounded-lg border border-sidebar-border bg-white shadow-lg dark:bg-sidebar"
                >
                    <div
                        v-if="isSearchingGroupUsers[group.id] ?? false"
                        class="px-3 py-2 text-xs text-muted-foreground"
                    >
                        Searching...
                    </div>
                    <button
                        v-for="user in groupUserSuggestions[group.id] ?? []"
                        :key="user.id"
                        type="button"
                        class="block w-full border-t border-sidebar-border px-3 py-2 text-left text-sm first:border-t-0 hover:bg-sidebar-accent"
                        @click="selectAssignableUser(group.id, user)"
                    >
                        <span class="font-medium">{{ user.name }}</span>
                        <span class="ml-1 text-muted-foreground">{{
                            user.email
                        }}</span>
                    </button>
                </div>
            </div>
            <Button
                type="button"
                :disabled="
                    assignMemberForm.processing ||
                    assignMemberForm.group_id !== group.id ||
                    !assignMemberForm.user_id
                "
                @click="addUserToGroup(group.id)"
            >
                {{
                    assignMemberForm.processing &&
                    assignMemberForm.group_id === group.id
                        ? 'Adding...'
                        : 'Add User'
                }}
            </Button>
        </div>
        <p
            v-if="
                assignMemberForm.errors.group_id &&
                assignMemberForm.group_id === group.id
            "
            class="mt-1 text-xs text-red-600"
        >
            {{ assignMemberForm.errors.group_id }}
        </p>
        <p
            v-if="
                assignMemberForm.errors.user_id &&
                assignMemberForm.group_id === group.id
            "
            class="mt-1 text-xs text-red-600"
        >
            {{ assignMemberForm.errors.user_id }}
        </p>
    </div>
</template>
