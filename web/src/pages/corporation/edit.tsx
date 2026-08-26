import { api, ApiError, apiUrl } from '@/api/client';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { usePageTitle } from '@/hooks/use-page-title';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';

interface CorporationDetail {
    name: string;
    hasLogo: boolean;
}

export default function CorporationEdit() {
    const { t } = useTranslation('corporation');
    usePageTitle(t('title'));
    const breadcrumbs: BreadcrumbItem[] = [{ title: t('title'), href: '/corporation' }];
    const queryClient = useQueryClient();
    const { data } = useQuery({
        queryKey: ['corporation'],
        queryFn: () => api.get<{ corporation: CorporationDetail }>('/api/corporation'),
    });
    const corporation = data?.corporation;

    const [logoError, setLogoError] = useState<string>();
    const fileInput = useRef<HTMLInputElement>(null);
    const [logoVersion, setLogoVersion] = useState(0);

    const invalidate = () => queryClient.invalidateQueries({ queryKey: ['corporation'] });

    const uploadLogo = useMutation({
        mutationFn: (file: File) => {
            const form = new FormData();
            form.append('logo', file);
            return api.post('/api/corporation/logo', form);
        },
        onSuccess: () => {
            invalidate();
            setLogoVersion((v) => v + 1);
            setLogoError(undefined);
        },
        onError: (err) => {
            if (err instanceof ApiError) setLogoError(err.fieldError('logo'));
            else throw err;
        },
    });

    const removeLogo = useMutation({
        mutationFn: () => api.delete('/api/corporation/logo'),
        onSuccess: () => {
            invalidate();
            setLogoVersion((v) => v + 1);
        },
    });

    if (!corporation) {
        return (
            <AppLayout breadcrumbs={breadcrumbs}>
                <div className="p-4" />
            </AppLayout>
        );
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <div className="flex h-full flex-1 flex-col gap-8 rounded-xl p-4">
                <div>
                    <h1 className="text-xl font-semibold">{t('heading')}</h1>
                    <p className="text-sm text-neutral-600 dark:text-neutral-400">{t('description')}</p>
                </div>

                <div className="max-w-md space-y-2">
                    <Label>{t('corporation_name')}</Label>
                    <p className="text-sm font-medium">{corporation.name}</p>
                </div>

                <div className="max-w-md space-y-4">
                    <div>
                        <Label>{t('email_logo')}</Label>
                        <p className="text-sm text-neutral-600 dark:text-neutral-400">{t('logo_hint')}</p>
                    </div>

                    {corporation?.hasLogo && (
                        <img
                            src={`${apiUrl('/api/corporation/logo')}?v=${logoVersion}`}
                            alt={t('logo_alt')}
                            crossOrigin="use-credentials"
                            className="max-h-24 rounded-md border bg-white p-2"
                        />
                    )}

                    <input
                        ref={fileInput}
                        type="file"
                        accept="image/png,image/jpeg,image/webp"
                        className="hidden"
                        onChange={(e) => {
                            const f = e.target.files?.[0];
                            if (f) uploadLogo.mutate(f);
                            e.target.value = '';
                        }}
                    />

                    <div className="flex items-center gap-3">
                        <Button type="button" variant="outline" disabled={uploadLogo.isPending} onClick={() => fileInput.current?.click()}>
                            {corporation?.hasLogo ? t('replace_logo') : t('upload_logo')}
                        </Button>
                        {corporation?.hasLogo && (
                            <Button
                                type="button"
                                variant="ghost"
                                className="text-red-600"
                                disabled={removeLogo.isPending}
                                onClick={() => removeLogo.mutate()}
                            >
                                {t('remove')}
                            </Button>
                        )}
                    </div>
                    <InputError message={logoError} />
                </div>
            </div>
        </AppLayout>
    );
}
