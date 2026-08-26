import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { FormEventHandler, useRef, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Corporation', href: '/corporation' }];

interface CorporationDetail {
    name: string;
    hasLogo: boolean;
}

export default function CorporationEdit({ corporation }: { corporation: CorporationDetail }) {
    const nameForm = useForm({ name: corporation.name });
    const logoForm = useForm<{ logo: File | null }>({ logo: null });
    const fileInput = useRef<HTMLInputElement>(null);
    const [removing, setRemoving] = useState(false);
    // Bumped after each change to bust the browser cache on the preview.
    const [logoVersion, setLogoVersion] = useState(0);

    const submitName: FormEventHandler = (e) => {
        e.preventDefault();
        nameForm.patch(route('corporation.update'), { preserveScroll: true });
    };

    function uploadLogo(file: File) {
        logoForm.setData('logo', file);
        router.post(route('corporation.logo.store'), { logo: file }, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => { setLogoVersion((v) => v + 1); logoForm.reset(); },
        });
    }

    function removeLogo() {
        setRemoving(true);
        router.delete(route('corporation.logo.destroy'), {
            preserveScroll: true,
            onFinish: () => { setRemoving(false); setLogoVersion((v) => v + 1); },
        });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Corporation" />
            <div className="flex h-full flex-1 flex-col gap-8 rounded-xl p-4">
                <div>
                    <h1 className="text-xl font-semibold">Corporation</h1>
                    <p className="text-sm text-neutral-600 dark:text-neutral-400">
                        Your company name and the logo shown at the foot of every email.
                    </p>
                </div>

                <form onSubmit={submitName} className="max-w-md space-y-4">
                    <div className="grid gap-2">
                        <Label htmlFor="name">Corporation name</Label>
                        <Input
                            id="name"
                            value={nameForm.data.name}
                            onChange={(e) => nameForm.setData('name', e.target.value)}
                        />
                        <InputError message={nameForm.errors.name} />
                    </div>
                    <Button disabled={nameForm.processing}>Save</Button>
                </form>

                <div className="max-w-md space-y-4">
                    <div>
                        <Label>Email logo</Label>
                        <p className="text-sm text-neutral-600 dark:text-neutral-400">
                            PNG, JPG or WebP, up to 2 MB.
                        </p>
                    </div>

                    {corporation.hasLogo && (
                        <img
                            src={`${route('corporation.logo')}?v=${logoVersion}`}
                            alt="Corporation logo"
                            className="max-h-24 rounded-md border bg-white p-2"
                        />
                    )}

                    <input
                        ref={fileInput}
                        type="file"
                        accept="image/png,image/jpeg,image/webp"
                        className="hidden"
                        onChange={(e) => { const f = e.target.files?.[0]; if (f) uploadLogo(f); e.target.value = ''; }}
                    />

                    <div className="flex items-center gap-3">
                        <Button
                            type="button"
                            variant="outline"
                            disabled={logoForm.processing}
                            onClick={() => fileInput.current?.click()}
                        >
                            {corporation.hasLogo ? 'Replace logo' : 'Upload logo'}
                        </Button>
                        {corporation.hasLogo && (
                            <Button type="button" variant="ghost" className="text-red-600" disabled={removing} onClick={removeLogo}>
                                Remove
                            </Button>
                        )}
                    </div>
                    <InputError message={logoForm.errors.logo} />
                </div>
            </div>
        </AppLayout>
    );
}
