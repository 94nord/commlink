import { useEffect } from 'react';

/** Replaces Inertia's <Head title=... /> now that pages don't render server-side. */
export function usePageTitle(title: string) {
    useEffect(() => {
        document.title = `${title} — Commlink`;
    }, [title]);
}
