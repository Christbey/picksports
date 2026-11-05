<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { useAdminSettingsContext } from '@/composables/useAdminSettings';
import AdminGroupCard from '@/components/admin/AdminGroupCard.vue';
const { groups, groupForm, inviteForm, createGroup, inviteToGroup } =
    useAdminSettingsContext();
</script>

<template>
    <div
        class="rounded-xl border border-sidebar-border bg-white p-5 dark:bg-sidebar"
    >
        <div class="flex flex-col gap-4">
            <div>
                <h3 class="text-sm font-semibold">Bracket Groups</h3>
                <p class="mt-1 text-sm text-muted-foreground">
                    Create March Madness groups, generate invite links, and
                    onboard users directly into bracket pools.
                </p>
            </div>

            <form
                class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_160px_auto]"
                @submit.prevent="createGroup"
            >
                <div>
                    <label
                        class="mb-1 block text-xs font-medium text-muted-foreground"
                        for="group-name"
                    >
                        Group Name
                    </label>
                    <input
                        id="group-name"
                        v-model="groupForm.name"
                        type="text"
                        required
                        class="w-full rounded-lg border border-sidebar-border bg-white px-3 py-2 text-sm dark:bg-sidebar"
                    />
                    <p
                        v-if="groupForm.errors.name"
                        class="mt-1 text-xs text-red-600"
                    >
                        {{ groupForm.errors.name }}
                    </p>
                </div>
                <div>
                    <label
                        class="mb-1 block text-xs font-medium text-muted-foreground"
                        for="group-season"
                    >
                        Season
                    </label>
                    <input
                        id="group-season"
                        v-model.number="groupForm.season"
                        type="number"
                        required
                        class="w-full rounded-lg border border-sidebar-border bg-white px-3 py-2 text-sm dark:bg-sidebar"
                    />
                    <p
                        v-if="groupForm.errors.season"
                        class="mt-1 text-xs text-red-600"
                    >
                        {{ groupForm.errors.season }}
                    </p>
                </div>
                <div class="flex items-end">
                    <Button type="submit" :disabled="groupForm.processing">
                        {{
                            groupForm.processing
                                ? 'Creating...'
                                : 'Create Group'
                        }}
                    </Button>
                </div>
            </form>

            <form
                class="grid gap-3 sm:grid-cols-[220px_minmax(0,1fr)_auto]"
                @submit.prevent="inviteToGroup"
            >
                <div>
                    <label
                        class="mb-1 block text-xs font-medium text-muted-foreground"
                        for="invite-group"
                    >
                        Group
                    </label>
                    <select
                        id="invite-group"
                        v-model="inviteForm.group_id"
                        class="w-full rounded-lg border border-sidebar-border bg-white px-3 py-2 text-sm dark:bg-sidebar"
                    >
                        <option :value="null">Select group</option>
                        <option
                            v-for="group in groups"
                            :key="group.id"
                            :value="group.id"
                        >
                            {{ group.name }}
                        </option>
                    </select>
                    <p
                        v-if="inviteForm.errors.group_id"
                        class="mt-1 text-xs text-red-600"
                    >
                        {{ inviteForm.errors.group_id }}
                    </p>
                </div>
                <div>
                    <label
                        class="mb-1 block text-xs font-medium text-muted-foreground"
                        for="invite-email"
                    >
                        Invite Email
                    </label>
                    <input
                        id="invite-email"
                        v-model="inviteForm.email"
                        type="email"
                        required
                        placeholder="user@example.com"
                        class="w-full rounded-lg border border-sidebar-border bg-white px-3 py-2 text-sm dark:bg-sidebar"
                    />
                    <p
                        v-if="inviteForm.errors.email"
                        class="mt-1 text-xs text-red-600"
                    >
                        {{ inviteForm.errors.email }}
                    </p>
                </div>
                <div class="flex items-end">
                    <Button
                        type="submit"
                        :disabled="
                            inviteForm.processing || !inviteForm.group_id
                        "
                    >
                        {{
                            inviteForm.processing
                                ? 'Inviting...'
                                : 'Create Invite'
                        }}
                    </Button>
                </div>
            </form>

            <div class="space-y-4">
                <AdminGroupCard
                    v-for="group in groups"
                    :key="group.id"
                    :group="group"
                />
            </div>
        </div>
    </div>
</template>
