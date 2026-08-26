import { api, ApiError } from '@/api/client';
import { usePageTitle } from '@/hooks/use-page-title';
import { LoaderCircle } from 'lucide-react';
import { FormEventHandler, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate, useParams, useSearchParams } from 'react-router-dom';

import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/auth-layout';

export default function ResetPassword() {
    const { t } = useTranslation('auth');
    usePageTitle(t('reset_password_title'));
    const { token = '' } = useParams();
    const [searchParams] = useSearchParams();
    const navigate = useNavigate();

    const [email, setEmail] = useState(searchParams.get('email') ?? '');
    const [password, setPassword] = useState('');
    const [passwordConfirmation, setPasswordConfirmation] = useState('');
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<Record<string, string[]>>({});

    const submit: FormEventHandler = async (e) => {
        e.preventDefault();
        setProcessing(true);
        setErrors({});

        try {
            await api.post('/api/reset-password', {
                token,
                email,
                password,
                password_confirmation: passwordConfirmation,
            });
            navigate('/login');
        } catch (err) {
            if (err instanceof ApiError) {
                setErrors(err.errors);
            } else {
                throw err;
            }
        } finally {
            setProcessing(false);
            setPassword('');
            setPasswordConfirmation('');
        }
    };

    return (
        <AuthLayout title={t('reset_password_heading')} description={t('reset_password_description')}>
            <form onSubmit={submit}>
                <div className="grid gap-6">
                    <div className="grid gap-2">
                        <Label htmlFor="email">{t('email')}</Label>
                        <Input
                            id="email"
                            type="email"
                            name="email"
                            autoComplete="email"
                            value={email}
                            className="mt-1 block w-full"
                            readOnly
                            onChange={(e) => setEmail(e.target.value)}
                        />
                        <InputError message={errors.email?.[0]} className="mt-2" />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="password">{t('password', { ns: 'auth' })}</Label>
                        <Input
                            id="password"
                            type="password"
                            name="password"
                            autoComplete="new-password"
                            value={password}
                            className="mt-1 block w-full"
                            autoFocus
                            onChange={(e) => setPassword(e.target.value)}
                            placeholder={t('password_placeholder')}
                        />
                        <InputError message={errors.password?.[0]} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="password_confirmation">{t('confirm_password')}</Label>
                        <Input
                            id="password_confirmation"
                            type="password"
                            name="password_confirmation"
                            autoComplete="new-password"
                            value={passwordConfirmation}
                            className="mt-1 block w-full"
                            onChange={(e) => setPasswordConfirmation(e.target.value)}
                            placeholder={t('confirm_password_placeholder')}
                        />
                        <InputError message={errors.password_confirmation?.[0]} className="mt-2" />
                    </div>

                    <Button type="submit" className="mt-4 w-full" disabled={processing}>
                        {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                        {t('reset_password_button')}
                    </Button>
                </div>
            </form>
        </AuthLayout>
    );
}
