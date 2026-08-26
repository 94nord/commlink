import { useAuth } from '@/auth/AuthContext';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem } from '@/types';
import { Building2, Inbox, UserCog, Users } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router-dom';
import AppLogo from './app-logo';

export function AppSidebar() {
    const { user } = useAuth();
    const { t } = useTranslation('nav');

    const baseNavItems: NavItem[] = [
        {
            title: t('inbox'),
            url: '/inbox',
            icon: Inbox,
        },
        {
            title: t('customers'),
            url: '/customers',
            icon: Users,
        },
    ];

    // Admin-only: managing staff and corporation settings.
    const adminNavItems: NavItem[] = [
        {
            title: t('staff'),
            url: '/staff',
            icon: UserCog,
        },
        {
            title: t('corporation'),
            url: '/corporation',
            icon: Building2,
        },
    ];

    const mainNavItems = user?.is_admin ? [...baseNavItems, ...adminNavItems] : baseNavItems;

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link to="/inbox">
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={mainNavItems} />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
