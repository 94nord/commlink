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
import { Pencil, Trash2 } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

interface ProjectDetail {
    id: number;
    name: string;
}

interface WorkOrderListItem {
    id: number;
    address: string;
    customerName: string;
    customerEmail: string;
}

interface CustomerOption {
    id: number;
    name: string;
    email: string;
}

export default function ProjectsShow({
    project,
    workOrders,
    customers,
}: {
    project: ProjectDetail;
    workOrders: WorkOrderListItem[];
    customers: CustomerOption[];
}) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Projects', href: '/projects' },
        { title: project.name, href: `/projects/${project.id}` },
    ];

    const [addOpen, setAddOpen] = useState(false);
    const [editOpen, setEditOpen] = useState(false);
    const [deleteProjectOpen, setDeleteProjectOpen] = useState(false);
    const [deletingCaseId, setDeletingCaseId] = useState<number | null>(null);
    const [deleting, setDeleting] = useState(false);

    const addForm = useForm({ customer_id: '', address: '' });
    const editForm = useForm({ name: project.name });

    const submitAdd: FormEventHandler = (e) => {
        e.preventDefault();
        addForm.post(route('projects.work-orders.store', project.id), {
            onSuccess: () => { addForm.reset(); setAddOpen(false); },
        });
    };

    const submitEdit: FormEventHandler = (e) => {
        e.preventDefault();
        editForm.patch(route('projects.update', project.id), {
            onSuccess: () => setEditOpen(false),
        });
    };

    function confirmDeleteCase() {
        if (!deletingCaseId) return;
        setDeleting(true);
        router.delete(route('work-orders.destroy', deletingCaseId), {
            onFinish: () => {
                setDeleting(false);
                setDeletingCaseId(null);
            },
        });
    }

    function confirmDeleteProject() {
        setDeleting(true);
        router.delete(route('projects.destroy', project.id), {
            onFinish: () => setDeleting(false),
        });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={project.name} />
            <div className="flex h-full flex-1 flex-col gap-6 rounded-xl p-4">
                <div className="flex items-center gap-2">
                    <h1 className="text-xl font-semibold">{project.name}</h1>

                    {/* Edit project */}
                    <Dialog open={editOpen} onOpenChange={(next) => { setEditOpen(next); if (!next) editForm.reset(); }}>
                        <DialogTrigger asChild>
                            <Button variant="ghost" size="icon" className="text-neutral-400 hover:text-neutral-700 dark:hover:text-neutral-200">
                                <Pencil className="h-4 w-4" />
                                <span className="sr-only">Edit project</span>
                            </Button>
                        </DialogTrigger>
                        <DialogContent>
                            <DialogHeader>
                                <DialogTitle>Edit project</DialogTitle>
                            </DialogHeader>
                            <form onSubmit={submitEdit} className="space-y-4">
                                <div className="grid gap-2">
                                    <Label htmlFor="edit-name">Project name</Label>
                                    <Input
                                        id="edit-name"
                                        value={editForm.data.name}
                                        onChange={(e) => editForm.setData('name', e.target.value)}
                                        autoFocus
                                    />
                                    <InputError message={editForm.errors.name} />
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

                    {/* Delete project */}
                    <Button
                        variant="ghost"
                        size="icon"
                        className="text-neutral-400 hover:text-red-600"
                        onClick={() => setDeleteProjectOpen(true)}
                    >
                        <Trash2 className="h-4 w-4" />
                        <span className="sr-only">Delete project</span>
                    </Button>

                    <ConfirmDialog
                        open={deleteProjectOpen}
                        onOpenChange={setDeleteProjectOpen}
                        title="Delete project?"
                        description="The project will be removed. Its cases will be kept as standalone cases."
                        confirmLabel="Delete project"
                        onConfirm={confirmDeleteProject}
                        processing={deleting}
                    />

                    {/* Add case */}
                    <div className="ml-auto">
                        <Dialog open={addOpen} onOpenChange={(next) => { setAddOpen(next); if (!next) addForm.reset(); }}>
                            <DialogTrigger asChild>
                                <Button disabled={customers.length === 0}>Add case</Button>
                            </DialogTrigger>
                            <DialogContent>
                                <DialogHeader>
                                    <DialogTitle>Add case</DialogTitle>
                                </DialogHeader>

                                {customers.length === 0 ? (
                                    <p className="text-sm text-neutral-600 dark:text-neutral-400">
                                        No customers yet.{' '}
                                        <Link href={route('customers.index')} className="underline">
                                            Add a customer
                                        </Link>{' '}
                                        first.
                                    </p>
                                ) : (
                                    <form onSubmit={submitAdd} className="space-y-4">
                                        <div className="grid gap-2">
                                            <Label htmlFor="customer_id">Customer</Label>
                                            <Select
                                                value={addForm.data.customer_id}
                                                onValueChange={(value) => addForm.setData('customer_id', value)}
                                            >
                                                <SelectTrigger id="customer_id">
                                                    <SelectValue placeholder="Select a customer…" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {customers.map((customer) => (
                                                        <SelectItem key={customer.id} value={String(customer.id)}>
                                                            {customer.name} — {customer.email}
                                                        </SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                            <InputError message={addForm.errors.customer_id} />
                                        </div>

                                        <div className="grid gap-2">
                                            <Label htmlFor="address">Address</Label>
                                            <Input
                                                id="address"
                                                value={addForm.data.address}
                                                onChange={(e) => addForm.setData('address', e.target.value)}
                                                placeholder="Street, floor, door"
                                            />
                                            <InputError message={addForm.errors.address} />
                                        </div>

                                        <div className="flex justify-end gap-3 pt-2">
                                            <Button type="button" variant="outline" onClick={() => setAddOpen(false)}>
                                                Cancel
                                            </Button>
                                            <Button disabled={addForm.processing}>Add case</Button>
                                        </div>
                                    </form>
                                )}
                            </DialogContent>
                        </Dialog>
                    </div>
                </div>

                {workOrders.length === 0 ? (
                    <p className="text-sm text-neutral-600 dark:text-neutral-400">No cases registered yet.</p>
                ) : (
                    <ul className="divide-y rounded-xl border">
                        {workOrders.map((workOrder) => (
                            <li key={workOrder.id} className="flex items-center justify-between gap-4 p-4">
                                <div className="min-w-0 flex-1">
                                    <p className="font-medium">{workOrder.address}</p>
                                    <p className="text-sm text-neutral-600 dark:text-neutral-400">
                                        {workOrder.customerName} · {workOrder.customerEmail}
                                    </p>
                                </div>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    className="shrink-0 text-neutral-400 hover:text-red-600"
                                    onClick={() => setDeletingCaseId(workOrder.id)}
                                >
                                    <Trash2 className="h-4 w-4" />
                                    <span className="sr-only">Delete case</span>
                                </Button>
                            </li>
                        ))}
                    </ul>
                )}

                {/* Case delete confirmation */}
                <ConfirmDialog
                    open={deletingCaseId !== null}
                    onOpenChange={(open) => { if (!open) setDeletingCaseId(null); }}
                    title="Delete case?"
                    description="This case will be permanently removed."
                    confirmLabel="Delete case"
                    onConfirm={confirmDeleteCase}
                    processing={deleting}
                />
            </div>
        </AppLayout>
    );
}
