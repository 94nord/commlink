import { api, ApiError } from '@/api/client';
import { usePageTitle } from '@/hooks/use-page-title';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem } from '@/types';
import { Transition } from '@headlessui/react';
import { FormEventHandler, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';

import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

export default function Password() {
    const { t } = useTranslation('settings');
    usePageTitle(t('password_title'));
    const breadcrumbs: BreadcrumbItem[] = [{ title: t('password_title'), href: '/settings/password' }];
    const passwordInput = useRef<HTMLInputElement>(null);
    const currentPasswordInput = useRef<HTMLInputElement>(null);

    const [currentPassword, setCurrentPassword] = useState('');
    const [password, setPassword] = useState('');
    const [passwordConfirmation, setPasswordConfirmation] = useState('');
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const [processing, setProcessing] = useState(false);
    const [recentlySuccessful, setRecentlySuccessful] = useState(false);

    const updatePassword: FormEventHandler = async (e) => {
        e.preventDefault();
        setProcessing(true);
        setErrors({});

        try {
            await api.put('/api/settings/password', {
                current_password: currentPassword,
                password,
                password_confirmation: passwordConfirmation,
            });
            setPassword('');
            setPasswordConfirmation('');
            setCurrentPassword('');
            setRecentlySuccessful(true);
            setTimeout(() => setRecentlySuccessful(false), 2000);
        } catch (err) {
            if (err instanceof ApiError) {
                setErrors(err.errors);
                if (err.errors.password) {
                    setPassword('');
                    setPasswordConfirmation('');
                    passwordInput.current?.focus();
                }
                if (err.errors.current_password) {
                    setCurrentPassword('');
                    currentPasswordInput.current?.focus();
                }
            } else {
                throw err;
            }
        } finally {
            setProcessing(false);
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall title={t('password_heading')} description={t('password_description')} />

                    <form onSubmit={updatePassword} className="space-y-6">
                        <div className="grid gap-2">
                            <Label htmlFor="current_password">{t('current_password')}</Label>

                            <Input
                                id="current_password"
                                ref={currentPasswordInput}
                                value={currentPassword}
                                onChange={(e) => setCurrentPassword(e.target.value)}
                                type="password"
                                className="mt-1 block w-full"
                                autoComplete="current-password"
                                placeholder={t('current_password_placeholder')}
                            />

                            <InputError message={errors.current_password?.[0]} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="password">{t('new_password')}</Label>

                            <Input
                                id="password"
                                ref={passwordInput}
                                value={password}
                                onChange={(e) => setPassword(e.target.value)}
                                type="password"
                                className="mt-1 block w-full"
                                autoComplete="new-password"
                                placeholder={t('new_password_placeholder')}
                            />

                            <InputError message={errors.password?.[0]} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="password_confirmation">{t('confirm_password')}</Label>

                            <Input
                                id="password_confirmation"
                                value={passwordConfirmation}
                                onChange={(e) => setPasswordConfirmation(e.target.value)}
                                type="password"
                                className="mt-1 block w-full"
                                autoComplete="new-password"
                                placeholder={t('confirm_password_placeholder')}
                            />

                            <InputError message={errors.password_confirmation?.[0]} />
                        </div>

                        <div className="flex items-center gap-4">
                            <Button disabled={processing}>{t('save_password')}</Button>

                            <Transition
                                show={recentlySuccessful}
                                enter="transition ease-in-out"
                                enterFrom="opacity-0"
                                leave="transition ease-in-out"
                                leaveTo="opacity-0"
                            >
                                <p className="text-sm text-neutral-600">{t('saved')}</p>
                            </Transition>
                        </div>
                    </form>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
