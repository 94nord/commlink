import { usePageTitle } from '@/hooks/use-page-title';

import HeadingSmall from '@/components/heading-small';
import LanguageTabs from '@/components/language-tabs';

import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem } from '@/types';
import { useTranslation } from 'react-i18next';

export default function LanguageSettings() {
    const { t } = useTranslation('settings');
    usePageTitle(t('language_title'));

    const breadcrumbs: BreadcrumbItem[] = [{ title: t('language_title'), href: '/settings/language' }];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall title={t('language_heading')} description={t('language_description')} />
                    <LanguageTabs />
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
