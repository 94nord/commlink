import { api, ApiError } from '@/api/client';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import { usePageTitle } from '@/hooks/use-page-title';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { FormEventHandler, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router-dom';

interface CustomerListItem {
    id: number;
    name: string;
    email: string | null;
    phones: { id: number; value: string }[];
    address: string | null;
}

export default function CustomersIndex() {
    const { t } = useTranslation('customers');
    usePageTitle(t('title'));
    const breadcrumbs: BreadcrumbItem[] = [{ title: t('title'), href: '/customers' }];
    const queryClient = useQueryClient();
    const { data, isLoading } = useQuery({
        queryKey: ['customers'],
        queryFn: () => api.get<{ customers: CustomerListItem[] }>('/api/customers'),
    });
    const customers = data?.customers ?? [];

    const [search, setSearch] = useState('');
    // Matches the same fields shown in each row. No pagination on this
    // endpoint yet, so filtering the fetched list is enough; see
    // link-customer-dialog.tsx for the same pattern.
    const trimmedSearch = search.trim().toLowerCase();
    const filtered =
        trimmedSearch === ''
            ? customers
            : customers.filter((customer) =>
                  [customer.name, customer.email, customer.address, ...customer.phones.map((p) => p.value)]
                      .filter(Boolean)
                      .some((field) => field!.toLowerCase().includes(trimmedSearch)),
              );

    const [open, setOpen] = useState(false);
    const [name, setName] = useState('');
    const [email, setEmail] = useState('');
    const [phone, setPhone] = useState('');
    const [address, setAddress] = useState('');
    const [errors, setErrors] = useState<Record<string, string[]>>({});

    const createCustomer = useMutation({
        mutationFn: () => api.post('/api/customers', { name, email, phone, address }),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['customers'] });
            reset();
            setOpen(false);
        },
        onError: (err) => {
            if (err instanceof ApiError) setErrors(err.errors);
            else throw err;
        },
    });

    function reset() {
        setName('');
        setEmail('');
        setPhone('');
        setAddress('');
        setErrors({});
    }

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        createCustomer.mutate();
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                <div className="flex items-center justify-between">
                    <h1 className="text-xl font-semibold">{t('heading')}</h1>

                    <Dialog
                        open={open}
                        onOpenChange={(next) => {
                            setOpen(next);
                            if (!next) reset();
                        }}
                    >
                        <DialogTrigger asChild>
                            <Button>{t('new_customer')}</Button>
                        </DialogTrigger>
                        <DialogContent>
                            <DialogHeader>
                                <DialogTitle>{t('new_customer')}</DialogTitle>
                            </DialogHeader>

                            <form onSubmit={submit} className="space-y-4">
                                <div className="grid gap-2">
                                    <Label htmlFor="name">{t('name')}</Label>
                                    <Input
                                        id="name"
                                        value={name}
                                        onChange={(e) => setName(e.target.value)}
                                        autoFocus
                                        placeholder={t('name_placeholder')}
                                    />
                                    <InputError message={errors.name?.[0]} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="email">
                                        {t('email')} <span className="text-neutral-400">{t('optional', { ns: 'common' })}</span>
                                    </Label>
                                    <Input
                                        id="email"
                                        type="email"
                                        value={email}
                                        onChange={(e) => setEmail(e.target.value)}
                                        placeholder={t('email_placeholder')}
                                    />
                                    <InputError message={errors.email?.[0]} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="phone">
                                        {t('phone')} <span className="text-neutral-400">{t('optional', { ns: 'common' })}</span>
                                    </Label>
                                    <Input id="phone" value={phone} onChange={(e) => setPhone(e.target.value)} placeholder={t('phone_placeholder')} />
                                    <InputError message={errors.phone?.[0]} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="address">
                                        {t('address')} <span className="text-neutral-400">{t('optional', { ns: 'common' })}</span>
                                    </Label>
                                    <Input
                                        id="address"
                                        value={address}
                                        onChange={(e) => setAddress(e.target.value)}
                                        placeholder={t('address_placeholder')}
                                    />
                                    <InputError message={errors.address?.[0]} />
                                </div>

                                <div className="flex justify-end gap-3 pt-2">
                                    <Button type="button" variant="outline" onClick={() => setOpen(false)}>
                                        {t('cancel', { ns: 'common' })}
                                    </Button>
                                    <Button disabled={createCustomer.isPending}>{t('create_customer')}</Button>
                                </div>
                            </form>
                        </DialogContent>
                    </Dialog>
                </div>

                {customers.length > 0 && (
                    <Input
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        placeholder={t('search_placeholder')}
                        aria-label={t('search_placeholder')}
                        className="max-w-sm"
                    />
                )}

                {isLoading ? (
                    <CustomerListSkeleton />
                ) : customers.length === 0 ? (
                    <p className="text-sm text-neutral-600 dark:text-neutral-400">{t('empty_list')}</p>
                ) : filtered.length === 0 ? (
                    <p className="text-sm text-neutral-600 dark:text-neutral-400">{t('no_search_matches', { query: search.trim() })}</p>
                ) : (
                    <ul className="divide-y rounded-xl border">
                        {filtered.map((customer) => (
                            <li key={customer.id}>
                                <Link to={`/customers/${customer.id}`} className="block p-4 hover:bg-neutral-50 dark:hover:bg-neutral-900">
                                    <p className="font-medium">{customer.name}</p>
                                    <p className="text-sm text-neutral-600 dark:text-neutral-400">
                                        {[...customer.phones.map((p) => p.value), customer.email, customer.address].filter(Boolean).join(' · ')}
                                    </p>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </AppLayout>
    );
}

/** Stand-ins for a handful of rows, shaped like the real ones so the list doesn't jump once data arrives. */
function CustomerListSkeleton() {
    return (
        <ul className="divide-y rounded-xl border">
            {Array.from({ length: 6 }).map((_, i) => (
                <li key={i} className="p-4">
                    <Skeleton className="h-4 w-40" />
                    <Skeleton className="mt-2 h-3.5 w-56" />
                </li>
            ))}
        </ul>
    );
}
