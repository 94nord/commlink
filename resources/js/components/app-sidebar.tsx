import { NavFooter } from '@/components/nav-footer';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem, type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { Building2, BookOpen, Calendar, CalendarRange, ClipboardList, Folder, FolderKanban, LayoutGrid, UserCog, Users } from 'lucide-react';
import AppLogo from './app-logo';

const baseNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        url: '/dashboard',
        icon: LayoutGrid,
    },
    {
        title: 'Projects',
        url: '/projects',
        icon: FolderKanban,
    },
    {
        title: 'Cases',
        url: '/cases',
        icon: ClipboardList,
    },
    {
        title: 'Visits',
        url: '/visits',
        icon: Calendar,
    },
    {
        title: 'Rounds',
        url: '/rounds',
        icon: CalendarRange,
    },
    {
        title: 'Customers',
        url: '/customers',
        icon: Users,
    },
];

// Admin-only: managing staff and corporation settings.
const adminNavItems: NavItem[] = [
    {
        title: 'Staff',
        url: '/staff',
        icon: UserCog,
    },
    {
        title: 'Corporation',
        url: '/corporation',
        icon: Building2,
    },
];

const footerNavItems: NavItem[] = [
    {
        title: 'Repository',
        url: 'https://github.com/laravel/react-starter-kit',
        icon: Folder,
    },
    {
        title: 'Documentation',
        url: 'https://laravel.com/docs/starter-kits',
        icon: BookOpen,
    },
];

export function AppSidebar() {
    const { auth } = usePage<SharedData>().props;
    const mainNavItems = auth.user.is_admin ? [...baseNavItems, ...adminNavItems] : baseNavItems;

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href="/dashboard">
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
