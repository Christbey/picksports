<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { useAdminSettingsContext } from '@/composables/useAdminSettings';
const {
    foundingUsers,
    limitForm,
    grantForm,
    userSearch,
    isSearchingUsers,
    userSuggestions,
    updateFoundingLimit,
    grantFoundingAccess,
    selectUserSuggestion,
    revokeFoundingAccess,
    formatDate,
} = useAdminSettingsContext();
</script>

<template>
    <div
        class="rounded-xl border border-sidebar-border bg-white p-5 dark:bg-sidebar"
    >
        <div class="flex flex-col gap-4">
            <div>
                <h3 class="text-sm font-semibold">Founding Users</h3>
                <p class="mt-1 text-sm text-muted-foreground">
                    Grant or revoke the
                    <code>{{ foundingUsers.role }}</code> role with access level
                    <code>{{ foundingUsers.tier_name }}</code
                    >.
                </p>
            </div>

            <div class="grid grid-cols-1 gap-3 sm:grid-cols-4">
                <div
                    class="rounded-lg border border-sidebar-border bg-sidebar-accent p-3"
                >
                    <p class="text-xs text-muted-foreground">Program</p>
                    <p class="text-sm font-semibold">
                        {{ foundingUsers.enabled ? 'Enabled' : 'Disabled' }}
                    </p>
                </div>
                <div
                    class="rounded-lg border border-sidebar-border bg-sidebar-accent p-3"
                >
                    <p class="text-xs text-muted-foreground">Limit</p>
                    <p class="text-sm font-semibold">
                        {{ foundingUsers.limit }}
                    </p>
                </div>
                <div
                    class="rounded-lg border border-sidebar-border bg-sidebar-accent p-3"
                >
                    <p class="text-xs text-muted-foreground">Used</p>
                    <p class="text-sm font-semibold">
                        {{ foundingUsers.used }}
                    </p>
                </div>
                <div
                    class="rounded-lg border border-sidebar-border bg-sidebar-accent p-3"
                >
                    <p class="text-xs text-muted-foreground">Remaining</p>
                    <p class="text-sm font-semibold">
                        {{ foundingUsers.remaining }}
                    </p>
                </div>
            </div>

            <form
                class="flex flex-col gap-3 sm:flex-row sm:items-end"
                @submit.prevent="updateFoundingLimit"
            >
                <div class="w-full sm:max-w-xs">
                    <label
                        class="mb-1 block text-xs font-medium text-muted-foreground"
                        for="founding-user-limit"
                    >
                        Founding User Limit (X)
                    </label>
                    <input
                        id="founding-user-limit"
                        v-model.number="limitForm.limit"
                        type="number"
                        min="0"
                        step="1"
                        required
                        class="w-full rounded-lg border border-sidebar-border bg-white px-3 py-2 text-sm dark:bg-sidebar"
                    />
                    <p
                        v-if="limitForm.errors.limit"
                        class="mt-1 text-xs text-red-600"
                    >
                        {{ limitForm.errors.limit }}
                    </p>
                </div>
                <Button type="submit" :disabled="limitForm.processing">
                    {{ limitForm.processing ? 'Saving...' : 'Save Limit' }}
                </Button>
            </form>

            <form
                class="flex flex-col gap-3 sm:flex-row sm:items-start"
                @submit.prevent="grantFoundingAccess"
            >
                <div class="relative flex-1">
                    <label
                        class="mb-1 block text-xs font-medium text-muted-foreground"
                        for="founding-user-search"
                    >
                        User Search
                    </label>
                    <input
                        id="founding-user-search"
                        v-model="userSearch"
                        type="text"
                        required
                        placeholder="Search by name or email (min 2 chars)"
                        autocomplete="off"
                        class="w-full rounded-lg border border-sidebar-border bg-white px-3 py-2 text-sm dark:bg-sidebar"
                    />
                    <div
                        v-if="isSearchingUsers || userSuggestions.length > 0"
                        class="absolute z-10 mt-1 w-full overflow-hidden rounded-lg border border-sidebar-border bg-white shadow-lg dark:bg-sidebar"
                    >
                        <div
                            v-if="isSearchingUsers"
                            class="px-3 py-2 text-xs text-muted-foreground"
                        >
                            Searching...
                        </div>
                        <button
                            v-for="user in userSuggestions"
                            :key="user.id"
                            type="button"
                            class="block w-full border-t border-sidebar-border px-3 py-2 text-left text-sm first:border-t-0 hover:bg-sidebar-accent"
                            @click="selectUserSuggestion(user)"
                        >
                            <span class="font-medium">{{ user.name }}</span>
                            <span class="ml-1 text-muted-foreground">{{
                                user.email
                            }}</span>
                        </button>
                    </div>
                </div>
                <div class="flex-1">
                    <label
                        class="mb-1 block text-xs font-medium text-muted-foreground"
                        for="founding-user-email"
                    >
                        Selected Email
                    </label>
                    <input
                        id="founding-user-email"
                        v-model="grantForm.email"
                        type="email"
                        required
                        placeholder="user@example.com"
                        class="w-full rounded-lg border border-sidebar-border bg-white px-3 py-2 text-sm dark:bg-sidebar"
                    />
                    <p
                        v-if="grantForm.errors.email"
                        class="mt-1 text-xs text-red-600"
                    >
                        {{ grantForm.errors.email }}
                    </p>
                </div>
                <Button
                    type="submit"
                    :disabled="
                        grantForm.processing ||
                        !foundingUsers.enabled ||
                        foundingUsers.remaining < 1
                    "
                >
                    {{
                        grantForm.processing
                            ? 'Granting...'
                            : 'Grant Founding Access'
                    }}
                </Button>
            </form>

            <div>
                <h4
                    class="text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                >
                    Current Founding Users
                </h4>
                <div
                    class="mt-2 overflow-x-auto rounded-lg border border-sidebar-border"
                >
                    <table class="w-full text-left text-sm">
                        <thead class="bg-sidebar-accent">
                            <tr>
                                <th class="px-3 py-2 font-medium">Name</th>
                                <th class="px-3 py-2 font-medium">Email</th>
                                <th class="px-3 py-2 font-medium">Created</th>
                                <th class="px-3 py-2 font-medium">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="foundingUsers.users.length === 0">
                                <td
                                    colspan="4"
                                    class="px-3 py-3 text-muted-foreground"
                                >
                                    No founding users assigned yet.
                                </td>
                            </tr>
                            <tr
                                v-for="user in foundingUsers.users"
                                :key="user.id"
                                class="border-t border-sidebar-border"
                            >
                                <td class="px-3 py-2">
                                    {{ user.name }}
                                </td>
                                <td class="px-3 py-2">
                                    {{ user.email }}
                                </td>
                                <td class="px-3 py-2">
                                    {{ formatDate(user.created_at) }}
                                </td>
                                <td class="px-3 py-2">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        class="h-8"
                                        @click="revokeFoundingAccess(user.id)"
                                    >
                                        Revoke
                                    </Button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</template>
