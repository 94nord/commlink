import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { cn } from '@/lib/utils';
import { useTranslation } from 'react-i18next';
import { Link, useLocation } from 'react-router-dom';

export default function SettingsLayout({ children }: { children: React.ReactNode }) {
    const { t } = useTranslation('settings');
    const location = useLocation();

    const sidebarNavItems = [
        { title: t('nav_password'), url: '/settings/password' },
        { title: t('nav_appearance'), url: '/settings/appearance' },
        { title: t('nav_language'), url: '/settings/language' },
    ];

    return (
        <div className="px-4 py-6">
            <Heading title={t('nav_heading')} description={t('nav_description')} />

            <div className="flex flex-col space-y-8 lg:flex-row lg:space-y-0 lg:space-x-12">
                <aside className="w-full max-w-xl lg:w-48">
                    <nav className="flex flex-col space-y-1 space-x-0">
                        {sidebarNavItems.map((item) => (
                            <Button
                                key={item.url}
                                size="sm"
                                variant="ghost"
                                asChild
                                className={cn('w-full justify-start', {
                                    'bg-muted': location.pathname === item.url,
                                })}
                            >
                                <Link to={item.url}>{item.title}</Link>
                            </Button>
                        ))}
                    </nav>
                </aside>

                <Separator className="my-6 md:hidden" />

                <div className="flex-1 md:max-w-2xl">
                    <section className="max-w-xl space-y-12">{children}</section>
                </div>
            </div>
        </div>
    );
}
