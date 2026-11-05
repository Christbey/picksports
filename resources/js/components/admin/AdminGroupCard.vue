<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { useAdminSettingsContext } from '@/composables/useAdminSettings';
import type { AdminGroup } from '@/types/admin-settings';
defineProps<{ group: AdminGroup }>();
import AdminGroupUserSearch from '@/components/admin/AdminGroupUserSearch.vue';
const {
    rotateJoinLink,
    copyLink,
    copiedLinkKey,
    formatDate,
    removeUserFromGroup,
} = useAdminSettingsContext();
</script>

<template>
    <div class="rounded-lg border border-sidebar-border bg-sidebar-accent p-4">
        <div
            class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between"
        >
            <div>
                <h4 class="text-sm font-semibold">
                    {{ group.name }}
                </h4>
                <p class="text-xs text-muted-foreground">
                    Season {{ group.season ?? 'N/A' }} ·
                    {{ group.members_count }} members ·
                    {{ group.brackets_count }} brackets
                </p>
            </div>
            <div class="flex gap-2">
                <Button
                    type="button"
                    variant="outline"
                    @click="rotateJoinLink(group.id)"
                >
                    {{
                        group.join_link
                            ? 'Rotate Join Link'
                            : 'Create Join Link'
                    }}
                </Button>
            </div>
        </div>

        <div
            class="mt-3 rounded-lg border border-sidebar-border bg-white p-3 dark:bg-sidebar"
        >
            <p
                class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
            >
                Shared Join Link
            </p>
            <div v-if="group.join_link" class="mt-2">
                <div
                    class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between"
                >
                    <p class="text-sm break-all text-primary">
                        {{ group.join_link.join_url }}
                    </p>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        @click="
                            copyLink(
                                group.join_link.join_url,
                                `join-${group.id}`,
                            )
                        "
                    >
                        {{
                            copiedLinkKey === `join-${group.id}`
                                ? 'Copied'
                                : 'Copy Link'
                        }}
                    </Button>
                </div>
                <p class="mt-1 text-xs text-muted-foreground">
                    Anyone with this link can join
                    {{ group.name }} after login or registration.
                </p>
            </div>
            <p v-else class="mt-2 text-sm text-muted-foreground">
                No shared join link yet.
            </p>
        </div>

        <AdminGroupUserSearch :group="group" />

        <div
            class="mt-3 overflow-x-auto rounded-lg border border-sidebar-border bg-white dark:bg-sidebar"
        >
            <table class="w-full text-left text-sm">
                <thead class="bg-sidebar-accent">
                    <tr>
                        <th class="px-3 py-2 font-medium">Member</th>
                        <th class="px-3 py-2 font-medium">Role</th>
                        <th class="px-3 py-2 font-medium">Joined</th>
                        <th class="px-3 py-2 font-medium">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="group.members.length === 0">
                        <td colspan="4" class="px-3 py-3 text-muted-foreground">
                            No members yet.
                        </td>
                    </tr>
                    <tr
                        v-for="member in group.members"
                        :key="member.id"
                        class="border-t border-sidebar-border"
                    >
                        <td class="px-3 py-2">
                            <div class="font-medium">
                                {{ member.name }}
                            </div>
                            <div class="text-xs text-muted-foreground">
                                {{ member.email }}
                            </div>
                        </td>
                        <td class="px-3 py-2 capitalize">
                            {{ member.role }}
                        </td>
                        <td class="px-3 py-2">
                            {{ formatDate(member.joined_at) }}
                        </td>
                        <td class="px-3 py-2">
                            <Button
                                v-if="member.role !== 'owner'"
                                type="button"
                                variant="outline"
                                size="sm"
                                @click="
                                    removeUserFromGroup(
                                        group.id,
                                        member.id,
                                        member.name,
                                    )
                                "
                            >
                                Remove
                            </Button>
                            <span v-else class="text-xs text-muted-foreground"
                                >Owner</span
                            >
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div
            class="mt-3 overflow-x-auto rounded-lg border border-sidebar-border bg-white dark:bg-sidebar"
        >
            <table class="w-full text-left text-sm">
                <thead class="bg-sidebar-accent">
                    <tr>
                        <th class="px-3 py-2 font-medium">Invite Email</th>
                        <th class="px-3 py-2 font-medium">Link</th>
                        <th class="px-3 py-2 font-medium">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="group.invitations.length === 0">
                        <td colspan="3" class="px-3 py-3 text-muted-foreground">
                            No invites yet.
                        </td>
                    </tr>
                    <tr
                        v-for="invite in group.invitations"
                        :key="invite.id"
                        class="border-t border-sidebar-border"
                    >
                        <td class="px-3 py-2">
                            {{ invite.email }}
                        </td>
                        <td class="px-3 py-2">
                            <div
                                class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between"
                            >
                                <p class="break-all text-primary">
                                    {{ invite.invite_url }}
                                </p>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    @click="
                                        copyLink(
                                            invite.invite_url,
                                            `invite-${invite.id}`,
                                        )
                                    "
                                >
                                    {{
                                        copiedLinkKey === `invite-${invite.id}`
                                            ? 'Copied'
                                            : 'Copy Link'
                                    }}
                                </Button>
                            </div>
                        </td>
                        <td class="px-3 py-2">
                            {{
                                invite.accepted_at
                                    ? `Accepted ${formatDate(invite.accepted_at)}`
                                    : 'Pending'
                            }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
