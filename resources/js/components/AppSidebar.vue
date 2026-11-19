<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Activity, ChartColumn, Coins, History, ImagePlus } from '@lucide/vue';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
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
import { system } from '@/routes/admin';
import { spend } from '@/routes/admin/reports';
import { index as credits } from '@/routes/credits';
import { create, index } from '@/routes/generations';
import type { NavItem } from '@/types';

const mainNavItems: NavItem[] = [
    {
        title: 'Generate',
        href: create(),
        icon: ImagePlus,
    },
    {
        title: 'History',
        href: index(),
        icon: History,
    },
    {
        title: 'Credits',
        href: credits(),
        icon: Coins,
    },
];

const adminNavItems: NavItem[] = [
    { title: 'Spend report', href: spend(), icon: ChartColumn },
    { title: 'System status', href: system(), icon: Activity },
];

const page = usePage();
const isAdmin = computed(() => page.props.auth.user.is_admin);
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="create()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
            <NavMain v-if="isAdmin" :items="adminNavItems" label="Admin" />
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
