import i18n from 'i18next';
import { initReactI18next } from 'react-i18next';

import authDa from './locales/da/auth.json';
import commonDa from './locales/da/common.json';
import corporationDa from './locales/da/corporation.json';
import customersDa from './locales/da/customers.json';
import inboxDa from './locales/da/inbox.json';
import navDa from './locales/da/nav.json';
import settingsDa from './locales/da/settings.json';
import staffDa from './locales/da/staff.json';
import uploadsDa from './locales/da/uploads.json';

import authEn from './locales/en/auth.json';
import commonEn from './locales/en/common.json';
import corporationEn from './locales/en/corporation.json';
import customersEn from './locales/en/customers.json';
import inboxEn from './locales/en/inbox.json';
import navEn from './locales/en/nav.json';
import settingsEn from './locales/en/settings.json';
import staffEn from './locales/en/staff.json';
import uploadsEn from './locales/en/uploads.json';

export const SUPPORTED_LANGUAGES = ['da', 'en'] as const;
export type Language = (typeof SUPPORTED_LANGUAGES)[number];

const STORAGE_KEY = 'language';

/**
 * The language to boot into: whatever this device last chose, or Danish —
 * the product's actual audience — if it has never chosen one. Not
 * OS/browser detection: a language switch is a deliberate choice a person
 * makes in Settings, not something to infer from `navigator.language`.
 */
export function getStoredLanguage(): Language {
    const stored = typeof window !== 'undefined' ? window.localStorage.getItem(STORAGE_KEY) : null;

    return SUPPORTED_LANGUAGES.includes(stored as Language) ? (stored as Language) : 'da';
}

i18n.use(initReactI18next).init({
    resources: {
        da: {
            common: commonDa,
            nav: navDa,
            auth: authDa,
            inbox: inboxDa,
            customers: customersDa,
            staff: staffDa,
            corporation: corporationDa,
            settings: settingsDa,
            uploads: uploadsDa,
        },
        en: {
            common: commonEn,
            nav: navEn,
            auth: authEn,
            inbox: inboxEn,
            customers: customersEn,
            staff: staffEn,
            corporation: corporationEn,
            settings: settingsEn,
            uploads: uploadsEn,
        },
    },
    lng: getStoredLanguage(),
    fallbackLng: 'da',
    ns: ['common', 'nav', 'auth', 'inbox', 'customers', 'staff', 'corporation', 'settings', 'uploads'],
    defaultNS: 'common',
    interpolation: { escapeValue: false }, // React already escapes.
});

if (typeof document !== 'undefined') {
    document.documentElement.lang = i18n.language;
}

export default i18n;
