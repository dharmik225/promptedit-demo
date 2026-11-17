<script setup lang="ts">
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { useIsAdmin } from '@/composables/useIsAdmin';
import { type NavItem } from '@/types';
import { Link } from '@inertiajs/vue3';
import { BarChart3, History, Sparkles } from 'lucide-vue-next';
import { computed } from 'vue';
import AppLogo from './AppLogo.vue';

const isAdmin = useIsAdmin();

const homeHref = computed(() => (isAdmin.value ? route('admin.generations.index') : route('dashboard')));

const mainNavItems = computed<NavItem[]>(() => {
    if (isAdmin.value) {
        return [
            {
                title: 'Admin analytics',
                href: '/admin/generations',
                icon: BarChart3,
            },
        ];
    }

    return [
        {
            title: 'Generate',
            href: '/dashboard',
            icon: Sparkles,
        },
        {
            title: 'History',
            href: '/generations',
            icon: History,
        },
    ];
});
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="homeHref">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
