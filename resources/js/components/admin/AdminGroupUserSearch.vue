<script setup lang="ts">
import UserSearchInput from '@/components/UserSearchInput.vue';
import { Button } from '@/components/ui/button';
import { useAdminSettingsContext } from '@/composables/useAdminSettings';
import type { AdminGroup } from '@/types/admin-settings';
defineProps<{ group: AdminGroup }>();
const {
    groupUserSearch,
    isSearchingGroupUsers,
    groupUserSuggestions,
    setGroupUserSearch,
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
            <UserSearchInput
                :model-value="groupUserSearch[group.id] ?? ''"
                :aria-label="'Search existing users for ' + group.name"
                placeholder="Search existing users by name or email"
                :suggestions="groupUserSuggestions[group.id] ?? []"
                :loading="isSearchingGroupUsers[group.id] ?? false"
                @update:model-value="setGroupUserSearch(group.id, $event)"
                @select="selectAssignableUser(group.id, $event)"
            />
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
