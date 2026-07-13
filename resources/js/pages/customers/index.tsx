import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Customers', href: '/customers' },
];

interface CustomerListItem {
    id: number;
    name: string;
    email: string;
    address: string | null;
}

export default function CustomersIndex({ customers }: { customers: CustomerListItem[] }) {
    const [open, setOpen] = useState(false);

    const { data, setData, post, errors, processing, reset } = useForm({
        name: '',
        email: '',
        address: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('customers.store'), {
            onSuccess: () => {
                reset();
                setOpen(false);
            },
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Customers" />
            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                <div className="flex items-center justify-between">
                    <h1 className="text-xl font-semibold">Customers</h1>

                    <Dialog open={open} onOpenChange={(next) => { setOpen(next); if (!next) reset(); }}>
                        <DialogTrigger asChild>
                            <Button>New customer</Button>
                        </DialogTrigger>
                        <DialogContent>
                            <DialogHeader>
                                <DialogTitle>New customer</DialogTitle>
                            </DialogHeader>

                            <form onSubmit={submit} className="space-y-4">
                                <div className="grid gap-2">
                                    <Label htmlFor="name">Name</Label>
                                    <Input
                                        id="name"
                                        value={data.name}
                                        onChange={(e) => setData('name', e.target.value)}
                                        autoFocus
                                        placeholder="Full name"
                                    />
                                    <InputError message={errors.name} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="email">Email address</Label>
                                    <Input
                                        id="email"
                                        type="email"
                                        value={data.email}
                                        onChange={(e) => setData('email', e.target.value)}
                                        placeholder="email@example.com"
                                    />
                                    <InputError message={errors.email} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="address">Address <span className="text-neutral-400">(optional)</span></Label>
                                    <Input
                                        id="address"
                                        value={data.address}
                                        onChange={(e) => setData('address', e.target.value)}
                                        placeholder="Street, floor, door"
                                    />
                                    <InputError message={errors.address} />
                                </div>

                                <div className="flex justify-end gap-3 pt-2">
                                    <Button type="button" variant="outline" onClick={() => setOpen(false)}>
                                        Cancel
                                    </Button>
                                    <Button disabled={processing}>Create customer</Button>
                                </div>
                            </form>
                        </DialogContent>
                    </Dialog>
                </div>

                {customers.length === 0 ? (
                    <p className="text-sm text-neutral-600 dark:text-neutral-400">
                        No customers yet. Add one to start registering cases.
                    </p>
                ) : (
                    <ul className="divide-y rounded-xl border">
                        {customers.map((customer) => (
                            <li key={customer.id}>
                                <Link
                                    href={route('customers.show', customer.id)}
                                   
                                    className="block p-4 hover:bg-neutral-50 dark:hover:bg-neutral-900"
                                >
                                    <p className="font-medium">{customer.name}</p>
                                    <p className="text-sm text-neutral-600 dark:text-neutral-400">
                                        {customer.email}{customer.address ? ` · ${customer.address}` : ''}
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
