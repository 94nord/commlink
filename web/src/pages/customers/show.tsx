import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { Pencil } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

interface CustomerDetail {
    id: number;
    name: string;
    email: string;
    address: string | null;
}

interface CaseListItem {
    id: number;
    title: string | null;
    address: string;
    projectId: number | null;
    projectName: string | null;
}

export default function CustomersShow({ customer, cases }: { customer: CustomerDetail; cases: CaseListItem[] }) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Customers', href: '/customers' },
        { title: customer.name, href: `/customers/${customer.id}` },
    ];

    const [editOpen, setEditOpen] = useState(false);

    const editForm = useForm({
        name: customer.name,
        email: customer.email,
        address: customer.address ?? '',
    });

    const submitEdit: FormEventHandler = (e) => {
        e.preventDefault();
        editForm.patch(route('customers.update', customer.id), {
            onSuccess: () => setEditOpen(false),
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={customer.name} />
            <div className="flex h-full flex-1 flex-col gap-6 rounded-xl p-4">
                <div className="flex items-start justify-between gap-4">
                    <div>
                        <div className="flex items-center gap-2">
                            <h1 className="text-xl font-semibold">{customer.name}</h1>

                            <Dialog open={editOpen} onOpenChange={(next) => { setEditOpen(next); if (!next) editForm.reset(); }}>
                                <DialogTrigger asChild>
                                    <Button variant="ghost" size="icon" className="text-neutral-400 hover:text-neutral-700 dark:hover:text-neutral-200">
                                        <Pencil className="h-4 w-4" />
                                        <span className="sr-only">Edit customer</span>
                                    </Button>
                                </DialogTrigger>
                                <DialogContent>
                                    <DialogHeader>
                                        <DialogTitle>Edit customer</DialogTitle>
                                    </DialogHeader>
                                    <form onSubmit={submitEdit} className="space-y-4">
                                        <div className="grid gap-2">
                                            <Label htmlFor="edit-name">Name</Label>
                                            <Input
                                                id="edit-name"
                                                value={editForm.data.name}
                                                onChange={(e) => editForm.setData('name', e.target.value)}
                                                autoFocus
                                            />
                                            <InputError message={editForm.errors.name} />
                                        </div>
                                        <div className="grid gap-2">
                                            <Label htmlFor="edit-email">Email address</Label>
                                            <Input
                                                id="edit-email"
                                                type="email"
                                                value={editForm.data.email}
                                                onChange={(e) => editForm.setData('email', e.target.value)}
                                            />
                                            <InputError message={editForm.errors.email} />
                                        </div>
                                        <div className="grid gap-2">
                                            <Label htmlFor="edit-address">Address <span className="text-neutral-400">(optional)</span></Label>
                                            <Input
                                                id="edit-address"
                                                value={editForm.data.address}
                                                onChange={(e) => editForm.setData('address', e.target.value)}
                                            />
                                            <InputError message={editForm.errors.address} />
                                        </div>
                                        <div className="flex justify-end gap-3 pt-2">
                                            <Button type="button" variant="outline" onClick={() => setEditOpen(false)}>
                                                Cancel
                                            </Button>
                                            <Button disabled={editForm.processing}>Save</Button>
                                        </div>
                                    </form>
                                </DialogContent>
                            </Dialog>
                        </div>
                        <p className="mt-1 text-sm text-neutral-600 dark:text-neutral-400">
                            {customer.email}{customer.address ? ` · ${customer.address}` : ''}
                        </p>
                    </div>
                </div>

                <section className="space-y-3">
                    <h2 className="font-medium">Cases</h2>

                    {cases.length === 0 ? (
                        <p className="text-sm text-neutral-600 dark:text-neutral-400">No cases for this customer yet.</p>
                    ) : (
                        <ul className="divide-y rounded-xl border">
                            {cases.map((c) => (
                                <li key={c.id} className="flex items-center justify-between gap-4 p-4">
                                    <Link href={route('cases.show', c.id)} className="min-w-0 flex-1">
                                        <p className="font-medium hover:underline">{c.title ?? c.address}</p>
                                        {c.title && (
                                            <p className="text-sm text-neutral-600 dark:text-neutral-400">{c.address}</p>
                                        )}
                                    </Link>
                                    {c.projectName && (
                                        <Link href={route('projects.show', c.projectId!)} className="shrink-0 text-sm text-neutral-600 hover:underline dark:text-neutral-400">
                                            {c.projectName}
                                        </Link>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>
        </AppLayout>
    );
}
