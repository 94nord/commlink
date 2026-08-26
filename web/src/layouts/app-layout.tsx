import AppLayoutTemplate from '@/layouts/app/app-sidebar-layout';
import { type BreadcrumbItem } from '@/types';

interface AppLayoutProps {
    children: React.ReactNode;
    breadcrumbs?: BreadcrumbItem[];
    /** Fill the viewport exactly, for pages that scroll internally. */
    fullHeight?: boolean;
}

export default ({ children, breadcrumbs, fullHeight, ...props }: AppLayoutProps) => (
    <AppLayoutTemplate breadcrumbs={breadcrumbs} fullHeight={fullHeight} {...props}>
        {children}
    </AppLayoutTemplate>
);
