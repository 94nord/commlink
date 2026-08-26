import { SidebarInset } from '@/components/ui/sidebar';
import { cn } from '@/lib/utils';
import * as React from 'react';

interface AppContentProps extends React.ComponentProps<'div'> {
    variant?: 'header' | 'sidebar';
    /**
     * Pin the page to the viewport instead of letting it grow.
     *
     * Most pages are documents: they get as tall as their content and the
     * window scrolls. The inbox is an application pane — its message list has
     * to scroll inside itself so the reply box stays put — and that only
     * works if something above it has a real height to divide up.
     */
    fullHeight?: boolean;
}

export function AppContent({ variant = 'header', fullHeight = false, children, ...props }: AppContentProps) {
    if (variant === 'sidebar') {
        return (
            <SidebarInset
                className={cn(fullHeight && 'h-svh overflow-hidden md:peer-data-[variant=inset]:h-[calc(100svh-(--spacing(4)))]')}
                {...props}
            >
                {children}
            </SidebarInset>
        );
    }

    return (
        <main className="mx-auto flex h-full w-full max-w-7xl flex-1 flex-col gap-4 rounded-xl" {...props}>
            {children}
        </main>
    );
}
