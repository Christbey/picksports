<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronRight } from 'lucide-vue-next';
import { ref, watch } from 'vue';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarMenuSub,
    SidebarMenuSubButton,
    SidebarMenuSubItem,
    useSidebar,
} from '@/components/ui/sidebar';
import type { NavigationGroup } from '@/config/app-navigation';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { toUrl } from '@/lib/utils';
import { type NavItem } from '@/types';

const props = defineProps<{
    groups: NavigationGroup[];
}>();

const { currentUrl, isCurrentUrl } = useCurrentUrl();
const { isMobile, setOpenMobile, state } = useSidebar();

function itemPath(item: NavItem): string {
    const target = toUrl(item.href);

    if (target.startsWith('http')) {
        return new URL(target).pathname;
    }

    return target.split('?')[0];
}

function isGroupActive(item: NavItem): boolean {
    if (isCurrentUrl(item.href)) return true;
    if (item.items) {
        if (item.items.some((subItem) => isCurrentUrl(subItem.href))) {
            return true;
        }

        const rootSegment = itemPath(item).split('/').filter(Boolean)[0];

        return rootSegment
            ? currentUrl.value.startsWith(`/${rootSegment}/`)
            : false;
    }
    return false;
}

function activeGroupTitle(): string | null {
    for (const group of props.groups) {
        const activeItem = group.items.find(isGroupActive);

        if (activeItem) {
            return activeItem.title;
        }
    }

    return null;
}

const openTitle = ref<string | null>(activeGroupTitle());

watch(currentUrl, () => {
    openTitle.value = activeGroupTitle();
});

function updateOpenItem(title: string, open: boolean): void {
    openTitle.value = open ? title : null;
}

function handleNavigate(): void {
    if (isMobile.value) {
        setOpenMobile(false);
    }
}
</script>

<template>
    <SidebarGroup v-for="group in groups" :key="group.label" class="px-2 py-0">
        <SidebarGroupLabel>{{ group.label }}</SidebarGroupLabel>
        <SidebarMenu>
            <template v-for="item in group.items" :key="item.title">
                <DropdownMenu v-if="state === 'collapsed' && !isMobile">
                    <SidebarMenuItem>
                        <DropdownMenuTrigger as-child>
                            <SidebarMenuButton
                                :is-active="isGroupActive(item)"
                                :aria-label="`Open ${item.title} navigation`"
                            >
                                <component
                                    v-if="item.icon"
                                    :is="item.icon"
                                    v-bind="item.iconProps"
                                    class="size-4 text-slate-700 dark:text-slate-200"
                                />
                                <span>{{ item.title }}</span>
                            </SidebarMenuButton>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent
                            side="right"
                            align="start"
                            :side-offset="8"
                            class="w-52"
                        >
                            <DropdownMenuLabel>{{
                                item.title
                            }}</DropdownMenuLabel>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem
                                v-for="subItem in item.items"
                                :key="subItem.title"
                                as-child
                            >
                                <Link
                                    :href="subItem.href"
                                    class="w-full"
                                    @click="handleNavigate"
                                >
                                    {{ subItem.title }}
                                </Link>
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </SidebarMenuItem>
                </DropdownMenu>

                <Collapsible
                    v-else
                    as-child
                    :open="openTitle === item.title"
                    class="group/collapsible"
                    @update:open="updateOpenItem(item.title, $event)"
                >
                    <SidebarMenuItem>
                        <CollapsibleTrigger as-child>
                            <SidebarMenuButton
                                :is-active="isGroupActive(item)"
                                :tooltip="item.title"
                            >
                                <component
                                    v-if="item.icon"
                                    :is="item.icon"
                                    v-bind="item.iconProps"
                                    class="size-4 text-slate-700 dark:text-slate-200"
                                />
                                <span>{{ item.title }}</span>
                                <ChevronRight
                                    class="ml-auto transition-transform duration-200 group-data-[state=open]/collapsible:rotate-90"
                                />
                            </SidebarMenuButton>
                        </CollapsibleTrigger>
                        <CollapsibleContent>
                            <SidebarMenuSub>
                                <SidebarMenuSubItem
                                    v-for="subItem in item.items"
                                    :key="subItem.title"
                                >
                                    <SidebarMenuSubButton
                                        as-child
                                        :is-active="isCurrentUrl(subItem.href)"
                                    >
                                        <Link
                                            :href="subItem.href"
                                            @click="handleNavigate"
                                        >
                                            <span>{{ subItem.title }}</span>
                                        </Link>
                                    </SidebarMenuSubButton>
                                </SidebarMenuSubItem>
                            </SidebarMenuSub>
                        </CollapsibleContent>
                    </SidebarMenuItem>
                </Collapsible>
            </template>
        </SidebarMenu>
    </SidebarGroup>
</template>
