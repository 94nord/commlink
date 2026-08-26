import { api, ApiError } from '@/api/client';
import { usePageTitle } from '@/hooks/use-page-title';
import { LoaderCircle } from 'lucide-react';
import { FormEventHandler, useState } from 'react';
import { useTranslation } from 'react-i18next';

import InputError from '@/components/input-error';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/auth-layout';

export default function ForgotPassword() {
    const { t } = useTranslation('auth');
    usePageTitle(t('forgot_password_title'));
    const [email, setEmail] = useState('');
    const [processing, setProcessing] = useState(false);
    const [status, setStatus] = useState<string>();
    const [error, setError] = useState<string>();

    const submit: FormEventHandler = async (e) => {
        e.preventDefault();
        setProcessing(true);
        setError(undefined);

        try {
            const response = await api.post<{ status: string }>('/api/forgot-password', { email });
            setStatus(response.status);
        } catch (err) {
            if (err instanceof ApiError) {
                setError(err.fieldError('email'));
            } else {
                throw err;
            }
        } finally {
            setProcessing(false);
        }
    };

    return (
        <AuthLayout title={t('forgot_password_heading')} description={t('forgot_password_description')}>
            {status && <div className="mb-4 text-center text-sm font-medium text-green-600">{status}</div>}

            <div className="space-y-6">
                <form onSubmit={submit}>
                    <div className="grid gap-2">
                        <Label htmlFor="email">{t('email_address')}</Label>
                        <Input
                            id="email"
                            type="email"
                            name="email"
                            autoComplete="off"
                            value={email}
                            autoFocus
                            onChange={(e) => setEmail(e.target.value)}
                            placeholder={t('email_placeholder')}
                        />

                        <InputError message={error} />
                    </div>

                    <div className="my-6 flex items-center justify-start">
                        <Button className="w-full" disabled={processing}>
                            {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                            {t('email_reset_link')}
                        </Button>
                    </div>
                </form>

                <div className="text-muted-foreground space-x-1 text-center text-sm">
                    <span>{t('or_return_to')}</span>
                    <TextLink to="/login">{t('log_in_link')}</TextLink>
                </div>
            </div>
        </AuthLayout>
    );
}
