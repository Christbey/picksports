<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { MessageSquare } from 'lucide-vue-next';
import NavCollapsible from '@/components/NavCollapsible.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import {
    platformNavItems,
    sportNavigationGroups,
} from '@/config/app-navigation';
import AppLogo from './AppLogo.vue';
import { dashboard } from '@/routes';

const openFeedbackModal = () => {
    if (typeof window === 'undefined') {
        return;
    }

    window.dispatchEvent(new CustomEvent('open-feedback-submission-modal'));
};

const sportNavGroups = sportNavigationGroups();
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboard()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="platformNavItems" />
            <NavCollapsible :groups="sportNavGroups" />
        </SidebarContent>

        <SidebarFooter>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton
                        type="button"
                        aria-label="Open feedback form"
                        @click="openFeedbackModal"
                    >
                        <MessageSquare />
                        <span>Feedback</span>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
