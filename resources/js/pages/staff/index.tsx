import ConfirmDialog from '@/components/confirm-dialog';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Pencil, Trash2 } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Staff', href: '/staff' },
];

interface StaffListItem {
    id: number;
    name: string;
}

export default function StaffIndex({ staff }: { staff: StaffListItem[] }) {
    const { errors: pageErrors } = usePage().props as { errors: Record<string, string> };

    const [addOpen, setAddOpen] = useState(false);
    const [editing, setEditing] = useState<StaffListItem | null>(null);
    const [deletingId, setDeletingId] = useState<number | null>(null);
    const [deleting, setDeleting] = useState(false);

    const addForm = useForm({ name: '' });
    const editForm = useForm({ name: '' });

    const submitAdd: FormEventHandler = (e) => {
        e.preventDefault();
        addForm.post(route('staff.store'), {
            onSuccess: () => { addForm.reset(); setAddOpen(false); },
        });
    };

    const submitEdit: FormEventHandler = (e) => {
        e.preventDefault();
        if (!editing) return;
        editForm.patch(route('staff.update', editing.id), {
            onSuccess: () => setEditing(null),
        });
    };

    function openEdit(member: StaffListItem) {
        editForm.setData('name', member.name);
        editForm.clearErrors();
        setEditing(member);
    }

    function confirmDelete() {
        if (!deletingId) return;
        setDeleting(true);
        router.delete(route('staff.destroy', deletingId), {
            onFinish: () => { setDeleting(false); setDeletingId(null); },
        });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Staff" />
            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                <div className="flex items-center justify-between">
                    <h1 className="text-xl font-semibold">Staff</h1>

                    <Dialog open={addOpen} onOpenChange={(next) => { setAddOpen(next); if (!next) addForm.reset(); }}>
                        <DialogTrigger asChild>
                            <Button>New staff member</Button>
                        </DialogTrigger>
                        <DialogContent>
                            <DialogHeader>
                                <DialogTitle>New staff member</DialogTitle>
                            </DialogHeader>
                            <form onSubmit={submitAdd} className="space-y-4">
                                <div className="grid gap-2">
                                    <Label htmlFor="add-name">Name</Label>
                                    <Input
                                        id="add-name"
                                        value={addForm.data.name}
                                        onChange={(e) => addForm.setData('name', e.target.value)}
                                        autoFocus
                                        placeholder="Full name"
                                    />
                                    <InputError message={addForm.errors.name} />
                                </div>
                                <div className="flex justify-end gap-3 pt-2">
                                    <Button type="button" variant="outline" onClick={() => setAddOpen(false)}>
                                        Cancel
                                    </Button>
                                    <Button disabled={addForm.processing}>Create staff member</Button>
                                </div>
                            </form>
                        </DialogContent>
                    </Dialog>
                </div>

                {pageErrors.staff && (
                    <p className="text-sm text-red-600 dark:text-red-400">{pageErrors.staff}</p>
                )}

                {staff.length === 0 ? (
                    <p className="text-sm text-neutral-600 dark:text-neutral-400">No staff members yet.</p>
                ) : (
                    <ul className="divide-y rounded-xl border">
                        {staff.map((member) => (
                            <li key={member.id} className="flex items-center justify-between gap-4 p-4">
                                <span className="font-medium">{member.name}</span>
                                <div className="flex items-center gap-1">
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        className="text-neutral-400 hover:text-neutral-700 dark:hover:text-neutral-200"
                                        onClick={() => openEdit(member)}
                                    >
                                        <Pencil className="h-4 w-4" />
                                        <span className="sr-only">Edit staff member</span>
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        className="text-neutral-400 hover:text-red-600"
                                        onClick={() => setDeletingId(member.id)}
                                    >
                                        <Trash2 className="h-4 w-4" />
                                        <span className="sr-only">Delete staff member</span>
                                    </Button>
                                </div>
                            </li>
                        ))}
                    </ul>
                )}

                {/* Edit staff dialog */}
                <Dialog open={editing !== null} onOpenChange={(open) => { if (!open) setEditing(null); }}>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>Edit staff member</DialogTitle>
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
                            <div className="flex justify-end gap-3 pt-2">
                                <Button type="button" variant="outline" onClick={() => setEditing(null)}>
                                    Cancel
                                </Button>
                                <Button disabled={editForm.processing}>Save</Button>
                            </div>
                        </form>
                    </DialogContent>
                </Dialog>

                <ConfirmDialog
                    open={deletingId !== null}
                    onOpenChange={(open) => { if (!open) setDeletingId(null); }}
                    title="Delete staff member?"
                    description="This staff member will be removed."
                    confirmLabel="Delete staff member"
                    onConfirm={confirmDelete}
                    processing={deleting}
                />
            </div>
        </AppLayout>
    );
}
