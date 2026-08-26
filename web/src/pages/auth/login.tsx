import { ApiError } from '@/api/client';
import { useAuth } from '@/auth/AuthContext';
import { usePageTitle } from '@/hooks/use-page-title';
import { LoaderCircle } from 'lucide-react';
import { FormEventHandler, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useLocation, useNavigate } from 'react-router-dom';

import InputError from '@/components/input-error';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/auth-layout';

export default function Login() {
    const { t } = useTranslation('auth');
    usePageTitle(t('login_title'));
    const { login } = useAuth();
    const navigate = useNavigate();
    const location = useLocation();

    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<Record<string, string[]>>({});

    const submit: FormEventHandler = async (e) => {
        e.preventDefault();
        setProcessing(true);
        setErrors({});

        try {
            await login(email, password);
            const from = (location.state as { from?: Location })?.from?.pathname ?? '/inbox';
            navigate(from, { replace: true });
        } catch (err) {
            if (err instanceof ApiError) {
                setErrors(err.errors);
            } else {
                throw err;
            }
        } finally {
            setProcessing(false);
            setPassword('');
        }
    };

    return (
        <AuthLayout title={t('login_heading')} description={t('login_description')}>
            <form className="flex flex-col gap-6" onSubmit={submit}>
                <div className="grid gap-6">
                    <div className="grid gap-2">
                        <Label htmlFor="email">{t('email_address')}</Label>
                        <Input
                            id="email"
                            type="email"
                            required
                            autoFocus
                            tabIndex={1}
                            autoComplete="email"
                            value={email}
                            onChange={(e) => setEmail(e.target.value)}
                            placeholder={t('email_placeholder')}
                        />
                        <InputError message={errors.email?.[0]} />
                    </div>

                    <div className="grid gap-2">
                        <div className="flex items-center">
                            <Label htmlFor="password">{t('password')}</Label>
                            <TextLink to="/forgot-password" className="ml-auto text-sm" tabIndex={5}>
                                {t('forgot_password')}
                            </TextLink>
                        </div>
                        <Input
                            id="password"
                            type="password"
                            required
                            tabIndex={2}
                            autoComplete="current-password"
                            value={password}
                            onChange={(e) => setPassword(e.target.value)}
                            placeholder={t('password_placeholder')}
                        />
                        <InputError message={errors.password?.[0]} />
                    </div>

                    <div className="flex items-center space-x-3">
                        <Checkbox id="remember" name="remember" tabIndex={3} />
                        <Label htmlFor="remember">{t('remember_me')}</Label>
                    </div>

                    <Button type="submit" className="mt-4 w-full" tabIndex={4} disabled={processing}>
                        {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                        {t('log_in')}
                    </Button>
                </div>
            </form>
        </AuthLayout>
    );
}
