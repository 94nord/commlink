import { SidebarGroup, SidebarGroupLabel, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem } from '@/types';
import { useTranslation } from 'react-i18next';
import { Link, useLocation } from 'react-router-dom';

export function NavMain({ items = [] }: { items: NavItem[] }) {
    const location = useLocation();
    const { t } = useTranslation('nav');
    return (
        <SidebarGroup className="px-2 py-0">
            <SidebarGroupLabel>{t('platform')}</SidebarGroupLabel>
            <SidebarMenu>
                {items.map((item) => (
                    <SidebarMenuItem key={item.title}>
                        {/* Stay lit on child routes too, e.g. /inbox/12. */}
                        <SidebarMenuButton asChild isActive={item.url === location.pathname || location.pathname.startsWith(`${item.url}/`)}>
                            <Link to={item.url}>
                                {item.icon && <item.icon />}
                                <span>{item.title}</span>
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                ))}
            </SidebarMenu>
        </SidebarGroup>
    );
}
