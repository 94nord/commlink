import { api, ApiError } from '@/api/client';
import ConfirmDialog from '@/components/confirm-dialog';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { usePageTitle } from '@/hooks/use-page-title';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Pencil } from 'lucide-react';
import { FormEventHandler, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate, useParams } from 'react-router-dom';

interface CustomerDetail {
    id: number;
    name: string;
    email: string | null;
    phones: { id: number; value: string; hasMessages: boolean }[];
    address: string | null;
}

export default function CustomersShow() {
    const { t } = useTranslation('customers');
    const { id } = useParams();
    const navigate = useNavigate();
    const queryClient = useQueryClient();

    const { data } = useQuery({
        queryKey: ['customers', id],
        queryFn: () => api.get<{ customer: CustomerDetail }>(`/api/customers/${id}`),
    });
    const customer = data?.customer;

    usePageTitle(customer?.name ?? t('customer_fallback_title'));

    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('title'), href: '/customers' },
        { title: customer?.name ?? '…', href: `/customers/${id}` },
    ];

    const [editOpen, setEditOpen] = useState(false);
    const [confirmingErase, setConfirmingErase] = useState(false);
    const [name, setName] = useState('');
    const [email, setEmail] = useState('');
    const [newPhone, setNewPhone] = useState('');
    const [phoneError, setPhoneError] = useState<string | undefined>();
    // Which number, kept separate from whether the dialog is open: Radix
    // keeps the dialog mounted while it fades out, so clearing this on close
    // would visibly rewrite the title mid-animation.
    const [removing, setRemoving] = useState<CustomerDetail['phones'][number] | null>(null);
    const [removeOpen, setRemoveOpen] = useState(false);
    const [address, setAddress] = useState('');
    const [errors, setErrors] = useState<Record<string, string[]>>({});

    function openEdit() {
        if (!customer) return;
        setName(customer.name);
        setEmail(customer.email ?? '');
        setAddress(customer.address ?? '');
        setErrors({});
        setEditOpen(true);
    }

    const updateCustomer = useMutation({
        mutationFn: () => api.patch(`/api/customers/${id}`, { name, email, address }),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['customers', id] });
            queryClient.invalidateQueries({ queryKey: ['customers'] });
            setEditOpen(false);
        },
        onError: (err) => {
            if (err instanceof ApiError) setErrors(err.errors);
            else throw err;
        },
    });

    const eraseCustomer = useMutation({
        mutationFn: () => api.delete(`/api/customers/${id}`),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['customers'] });
            navigate('/customers');
        },
    });

    const addPhone = useMutation({
        mutationFn: () => api.post(`/api/customers/${id}/phones`, { phone: newPhone }),
        onSuccess: () => {
            setNewPhone('');
            setPhoneError(undefined);
            queryClient.invalidateQueries({ queryKey: ['customers'] });
        },
        onError: (err) => {
            if (err instanceof ApiError) setPhoneError(err.fieldError('phone') ?? err.message);
            else throw err;
        },
    });

    const removePhone = useMutation({
        mutationFn: (phoneId: number) => api.delete(`/api/customers/${id}/phones/${phoneId}`),
        onSuccess: () => {
            setRemoveOpen(false);
            queryClient.invalidateQueries({ queryKey: ['customers'] });
        },
    });

    const submitEdit: FormEventHandler = (e) => {
        e.preventDefault();
        updateCustomer.mutate();
    };

    if (!customer) {
        return (
            <AppLayout breadcrumbs={breadcrumbs}>
                <div className="p-4" />
            </AppLayout>
        );
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <div className="flex h-full flex-1 flex-col gap-6 rounded-xl p-4">
                <div className="flex items-start justify-between gap-4">
                    <div>
                        <div className="flex items-center gap-2">
                            <h1 className="text-xl font-semibold">{customer.name}</h1>

                            <Dialog
                                open={editOpen}
                                onOpenChange={(next) => {
                                    if (next) {
                                        openEdit();
                                    } else {
                                        setEditOpen(false);
                                        setErrors({});
                                    }
                                }}
                            >
                                <DialogTrigger asChild>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        className="text-neutral-400 hover:text-neutral-700 dark:hover:text-neutral-200"
                                    >
                                        <Pencil className="h-4 w-4" />
                                        <span className="sr-only">{t('edit_customer_sr')}</span>
                                    </Button>
                                </DialogTrigger>
                                <DialogContent>
                                    <DialogHeader>
                                        <DialogTitle>{t('edit_customer')}</DialogTitle>
                                    </DialogHeader>
                                    <form onSubmit={submitEdit} className="space-y-4">
                                        <div className="grid gap-2">
                                            <Label htmlFor="edit-name">{t('name')}</Label>
                                            <Input id="edit-name" value={name} onChange={(e) => setName(e.target.value)} autoFocus />
                                            <InputError message={errors.name?.[0]} />
                                        </div>
                                        <div className="grid gap-2">
                                            <Label htmlFor="edit-email">
                                                {t('email')} <span className="text-neutral-400">{t('optional', { ns: 'common' })}</span>
                                            </Label>
                                            <Input id="edit-email" type="email" value={email} onChange={(e) => setEmail(e.target.value)} />
                                            <InputError message={errors.email?.[0]} />
                                        </div>
                                        <div className="grid gap-2">
                                            <Label htmlFor="edit-address">
                                                {t('address')} <span className="text-neutral-400">{t('optional', { ns: 'common' })}</span>
                                            </Label>
                                            <Input id="edit-address" value={address} onChange={(e) => setAddress(e.target.value)} />
                                            <InputError message={errors.address?.[0]} />
                                        </div>
                                        <div className="flex justify-end gap-3 pt-2">
                                            <Button type="button" variant="outline" onClick={() => setEditOpen(false)}>
                                                {t('cancel', { ns: 'common' })}
                                            </Button>
                                            <Button disabled={updateCustomer.isPending}>{t('save', { ns: 'common' })}</Button>
                                        </div>
                                    </form>
                                </DialogContent>
                            </Dialog>
                        </div>
                        <p className="mt-1 text-sm text-neutral-600 dark:text-neutral-400">
                            {[customer.email, customer.address].filter(Boolean).join(' · ') || t('no_other_details')}
                        </p>
                    </div>

                    <Button variant="ghost" className="shrink-0 text-neutral-400 hover:text-red-600" onClick={() => setConfirmingErase(true)}>
                        {t('erase_customer')}
                    </Button>
                </div>

                {/* Numbers are inbox addresses, so adding one here makes the
                    customer reachable and folds in any history already on it. */}
                <section className="rounded-xl border">
                    <h2 className="border-b p-4 font-medium">{t('phone_numbers_heading')}</h2>

                    {customer.phones.length === 0 ? (
                        <p className="p-4 text-sm text-neutral-600 dark:text-neutral-400">{t('no_numbers_yet')}</p>
                    ) : (
                        <ul className="divide-y">
                            {customer.phones.map((phone) => (
                                <li key={phone.id} className="flex items-center justify-between gap-4 p-4">
                                    <span className="font-medium">{phone.value}</span>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        className="text-neutral-400 hover:text-red-600"
                                        onClick={() => {
                                            setRemoving(phone);
                                            setRemoveOpen(true);
                                        }}
                                    >
                                        {t('remove', { ns: 'common' })}
                                    </Button>
                                </li>
                            ))}
                        </ul>
                    )}

                    <form
                        className="flex items-start gap-2 border-t p-4"
                        onSubmit={(e) => {
                            e.preventDefault();
                            if (newPhone.trim() !== '') addPhone.mutate();
                        }}
                    >
                        <div className="grid flex-1 gap-2">
                            <Input
                                value={newPhone}
                                onChange={(e) => setNewPhone(e.target.value)}
                                placeholder={t('phone_placeholder')}
                                aria-label={t('add_phone_aria')}
                            />
                            <InputError message={phoneError} />
                        </div>
                        <Button disabled={addPhone.isPending || newPhone.trim() === ''}>{t('add')}</Button>
                    </form>
                </section>

                <ConfirmDialog
                    open={removeOpen}
                    onOpenChange={setRemoveOpen}
                    title={t('remove_number_title', { value: removing?.value ?? t('this_number') })}
                    description={removing?.hasMessages ? t('remove_number_has_messages') : t('remove_number_no_messages')}
                    confirmLabel={t('remove_number_confirm')}
                    onConfirm={() => removing && removePhone.mutate(removing.id)}
                    processing={removePhone.isPending}
                />

                <ConfirmDialog
                    open={confirmingErase}
                    onOpenChange={setConfirmingErase}
                    title={t('erase_customer_title')}
                    description={t('erase_customer_description')}
                    confirmLabel={t('erase_customer')}
                    onConfirm={() => eraseCustomer.mutate()}
                    processing={eraseCustomer.isPending}
                />
            </div>
        </AppLayout>
    );
}
