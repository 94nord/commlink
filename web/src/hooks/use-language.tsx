import i18n, { type Language } from '@/i18n';
import { useState } from 'react';

/**
 * The interface language. Mirrors use-appearance.tsx's shape: read from
 * localStorage, write back on change, apply immediately.
 *
 * Deliberately per-device, not synced to the account — see docs/inbox-plan.md
 * equivalent decisions on preferences with no backend column. A page reload
 * with a fresh session still reads the same browser's localStorage, so it
 * survives logout/login on the same device; it does not follow to another.
 */
export function useLanguage() {
    const [language, setLanguage] = useState<Language>(i18n.language as Language);

    const updateLanguage = (next: Language) => {
        localStorage.setItem('language', next);
        i18n.changeLanguage(next);
        document.documentElement.lang = next;
        setLanguage(next);
    };

    return { language, updateLanguage };
}
