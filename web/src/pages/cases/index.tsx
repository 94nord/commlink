import ConfirmDialog from '@/components/confirm-dialog';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Cases', href: '/cases' },
];

interface CaseListItem {
    id: number;
    title: string | null;
    address: string;
    customerId: number;
    customerName: string;
    customerEmail: string;
    projectId: number | null;
    projectName: string | null;
}

interface CustomerOption {
    id: number;
    name: string;
    email: string;
    address: string | null;
}

interface ProjectOption {
    id: number;
    name: string;
}

export default function CasesIndex({
    cases,
    customers,
    projects,
}: {
    cases: CaseListItem[];
    customers: CustomerOption[];
    projects: ProjectOption[];
}) {
    const [addOpen, setAddOpen] = useState(false);
    const [deletingId, setDeletingId] = useState<number | null>(null);
    const [deleting, setDeleting] = useState(false);

    const form = useForm({ customer_id: '', title: '', address: '', notes: '', project_id: '' });

    // Picking a customer prefills the case address from their default one
    // (still editable). Also sets customer_id in the same update to avoid a
    // stale-closure race between two setData calls.
    function pickCustomer(value: string) {
        const customer = customers.find((c) => String(c.id) === value);
        form.setData({ ...form.data, customer_id: value, address: customer?.address ?? form.data.address });
    }

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.post(route('work-orders.store'), {
            onSuccess: () => { form.reset(); setAddOpen(false); },
        });
    };

    function confirmDelete() {
        if (!deletingId) return;
        setDeleting(true);
        router.delete(route('work-orders.destroy', deletingId), {
            onFinish: () => { setDeleting(false); setDeletingId(null); },
        });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Cases" />
            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                <div className="flex items-center justify-between">
                    <h1 className="text-xl font-semibold">Cases</h1>

                    <Dialog open={addOpen} onOpenChange={(next) => { setAddOpen(next); if (!next) form.reset(); }}>
                        <DialogTrigger asChild>
                            <Button disabled={customers.length === 0}>New case</Button>
                        </DialogTrigger>
                        <DialogContent>
                            <DialogHeader>
                                <DialogTitle>New case</DialogTitle>
                            </DialogHeader>

                            <form onSubmit={submit} className="space-y-4">
                                <div className="grid gap-2">
                                    <Label htmlFor="customer_id">Customer</Label>
                                    <Select
                                        value={form.data.customer_id}
                                        onValueChange={pickCustomer}
                                    >
                                        <SelectTrigger id="customer_id">
                                            <SelectValue placeholder="Select a customer…" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {customers.map((c) => (
                                                <SelectItem key={c.id} value={String(c.id)}>
                                                    {c.name} — {c.email}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <InputError message={form.errors.customer_id} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="title">Title <span className="text-neutral-400">(optional)</span></Label>
                                    <Input
                                        id="title"
                                        value={form.data.title}
                                        onChange={(e) => form.setData('title', e.target.value)}
                                        placeholder="e.g. Apartment 3B"
                                    />
                                    <InputError message={form.errors.title} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="address">Address</Label>
                                    <Input
                                        id="address"
                                        value={form.data.address}
                                        onChange={(e) => form.setData('address', e.target.value)}
                                        placeholder="Street, floor, door"
                                    />
                                    <InputError message={form.errors.address} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="notes">Notes <span className="text-neutral-400">(optional)</span></Label>
                                    <textarea
                                        id="notes"
                                        value={form.data.notes}
                                        onChange={(e) => form.setData('notes', e.target.value)}
                                        rows={3}
                                        className="flex w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
                                    />
                                    <InputError message={form.errors.notes} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="project_id">Project <span className="text-neutral-400">(optional)</span></Label>
                                    <Select
                                        value={form.data.project_id}
                                        onValueChange={(v) => form.setData('project_id', v === 'none' ? '' : v)}
                                    >
                                        <SelectTrigger id="project_id">
                                            <SelectValue placeholder="No project" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="none">No project</SelectItem>
                                            {projects.map((p) => (
                                                <SelectItem key={p.id} value={String(p.id)}>
                                                    {p.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <InputError message={form.errors.project_id} />
                                </div>

                                <div className="flex justify-end gap-3 pt-2">
                                    <Button type="button" variant="outline" onClick={() => setAddOpen(false)}>
                                        Cancel
                                    </Button>
                                    <Button disabled={form.processing}>Create case</Button>
                                </div>
                            </form>
                        </DialogContent>
                    </Dialog>
                </div>

                {cases.length === 0 ? (
                    <p className="text-sm text-neutral-600 dark:text-neutral-400">
                        No cases yet.{customers.length === 0 ? (
                            <> <Link href={route('customers.index')} className="underline">Add a customer</Link> to get started.</>
                        ) : (
                            ' Use "New case" to register the first one.'
                        )}
                    </p>
                ) : (
                    <ul className="divide-y rounded-xl border">
                        {cases.map((c) => (
                            <li key={c.id} className="flex items-center justify-between gap-4 p-4">
                                <div className="min-w-0 flex-1">
                                    <Link href={route('cases.show', c.id)}>
                                        <p className="font-medium hover:underline">{c.title ?? c.address}</p>
                                    </Link>
                                    <p className="text-sm text-neutral-600 dark:text-neutral-400">
                                        {c.title ? `${c.address} · ` : ''}
                                        <Link href={route('customers.show', c.customerId)} className="hover:underline">
                                            {c.customerName}
                                        </Link>
                                    </p>
                                </div>
                                <span className="shrink-0 text-sm text-neutral-600 dark:text-neutral-400">
                                    {c.projectName ? (
                                        <Link href={route('projects.show', c.projectId!)} className="hover:underline">
                                            {c.projectName}
                                        </Link>
                                    ) : '–'}
                                </span>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    className="shrink-0 text-neutral-400 hover:text-red-600"
                                    onClick={() => setDeletingId(c.id)}
                                >
                                    <Trash2 className="h-4 w-4" />
                                    <span className="sr-only">Delete case</span>
                                </Button>
                            </li>
                        ))}
                    </ul>
                )}

                <ConfirmDialog
                    open={deletingId !== null}
                    onOpenChange={(open) => { if (!open) setDeletingId(null); }}
                    title="Delete case?"
                    description="This case will be permanently removed."
                    confirmLabel="Delete case"
                    onConfirm={confirmDelete}
                    processing={deleting}
                />
            </div>
        </AppLayout>
    );
}
