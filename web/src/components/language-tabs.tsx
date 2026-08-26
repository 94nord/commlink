import { useLanguage } from '@/hooks/use-language';
import { type Language } from '@/i18n';
import { cn } from '@/lib/utils';
import { HTMLAttributes } from 'react';
import { useTranslation } from 'react-i18next';

export default function LanguageTabs({ className = '', ...props }: HTMLAttributes<HTMLDivElement>) {
    const { t } = useTranslation('settings');
    const { language, updateLanguage } = useLanguage();

    const tabs: { value: Language; label: string }[] = [
        { value: 'da', label: t('language_danish') },
        { value: 'en', label: t('language_english') },
    ];

    return (
        <div className={cn('inline-flex gap-1 rounded-lg bg-neutral-100 p-1 dark:bg-neutral-800', className)} {...props}>
            {tabs.map(({ value, label }) => (
                <button
                    key={value}
                    onClick={() => updateLanguage(value)}
                    className={cn(
                        'flex items-center rounded-md px-3.5 py-1.5 text-sm transition-colors',
                        language === value
                            ? 'bg-white shadow-xs dark:bg-neutral-700 dark:text-neutral-100'
                            : 'text-neutral-500 hover:bg-neutral-200/60 hover:text-black dark:text-neutral-400 dark:hover:bg-neutral-700/60',
                    )}
                >
                    {label}
                </button>
            ))}
        </div>
    );
}
