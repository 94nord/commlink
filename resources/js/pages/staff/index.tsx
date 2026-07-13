import ConfirmDialog from '@/components/confirm-dialog';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
    email: string | null;
    isAdmin: boolean;
    hasLogin: boolean;
}

export default function StaffIndex({ staff }: { staff: StaffListItem[] }) {
    const { errors: pageErrors } = usePage().props as { errors: Record<string, string> };

    const [addOpen, setAddOpen] = useState(false);
    const [editing, setEditing] = useState<StaffListItem | null>(null);
    const [deletingId, setDeletingId] = useState<number | null>(null);
    const [deleting, setDeleting] = useState(false);
    const [invitingId, setInvitingId] = useState<number | null>(null);

    const addForm = useForm({ name: '', email: '', is_admin: false });
    const editForm = useForm({ name: '', email: '', is_admin: false });

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
        editForm.setData({ name: member.name, email: member.email ?? '', is_admin: member.isAdmin });
        editForm.clearErrors();
        setEditing(member);
    }

    function invite(member: StaffListItem) {
        setInvitingId(member.id);
        router.post(route('staff.invite', member.id), {}, {
            preserveScroll: true,
            onFinish: () => setInvitingId(null),
        });
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
                                <div className="grid gap-2">
                                    <Label htmlFor="add-email">Email <span className="text-neutral-400">(needed for login)</span></Label>
                                    <Input
                                        id="add-email"
                                        type="email"
                                        value={addForm.data.email}
                                        onChange={(e) => addForm.setData('email', e.target.value)}
                                        placeholder="email@example.com"
                                    />
                                    <InputError message={addForm.errors.email} />
                                </div>
                                <label className="flex items-center gap-2 text-sm">
                                    <Checkbox
                                        checked={addForm.data.is_admin}
                                        onCheckedChange={(c) => addForm.setData('is_admin', c === true)}
                                    />
                                    Admin (can manage staff and corporation settings)
                                </label>
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
                                <div className="min-w-0">
                                    <div className="flex items-center gap-2">
                                        <span className="font-medium">{member.name}</span>
                                        {member.isAdmin && <Badge variant="secondary">Admin</Badge>}
                                        {member.hasLogin ? (
                                            <Badge variant="outline">Can log in</Badge>
                                        ) : (
                                            member.email && <Badge variant="outline">Invited?</Badge>
                                        )}
                                    </div>
                                    {member.email && (
                                        <p className="text-sm text-neutral-600 dark:text-neutral-400">{member.email}</p>
                                    )}
                                </div>
                                <div className="flex items-center gap-1">
                                    {member.email && (
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            className="text-neutral-400 hover:text-neutral-700 dark:hover:text-neutral-200"
                                            disabled={invitingId === member.id}
                                            onClick={() => invite(member)}
                                        >
                                            {member.hasLogin ? 'Resend invite' : 'Invite to log in'}
                                        </Button>
                                    )}
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
                            <div className="grid gap-2">
                                <Label htmlFor="edit-email">Email <span className="text-neutral-400">(needed for login)</span></Label>
                                <Input
                                    id="edit-email"
                                    type="email"
                                    value={editForm.data.email}
                                    onChange={(e) => editForm.setData('email', e.target.value)}
                                />
                                <InputError message={editForm.errors.email} />
                            </div>
                            <label className="flex items-center gap-2 text-sm">
                                <Checkbox
                                    checked={editForm.data.is_admin}
                                    onCheckedChange={(c) => editForm.setData('is_admin', c === true)}
                                />
                                Admin (can manage staff and corporation settings)
                            </label>
                            <InputError message={editForm.errors.is_admin} />
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
                    description="This staff member will be removed, along with their login if they have one."
                    confirmLabel="Delete staff member"
                    onConfirm={confirmDelete}
                    processing={deleting}
                />
            </div>
        </AppLayout>
    );
}
