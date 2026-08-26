import { api, ApiError } from '@/api/client';
import ConfirmDialog from '@/components/confirm-dialog';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import { usePageTitle } from '@/hooks/use-page-title';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Pencil, Trash2 } from 'lucide-react';
import { FormEventHandler, useState } from 'react';
import { useTranslation } from 'react-i18next';

interface StaffListItem {
    id: number;
    name: string;
    email: string | null;
    isAdmin: boolean;
    isFounder: boolean;
    hasLogin: boolean;
}

const emptyForm = { name: '', email: '', is_admin: false };

export default function StaffIndex() {
    const { t } = useTranslation('staff');
    usePageTitle(t('title'));
    const breadcrumbs: BreadcrumbItem[] = [{ title: t('title'), href: '/staff' }];
    const queryClient = useQueryClient();
    const { data, isLoading } = useQuery({
        queryKey: ['staff'],
        queryFn: () => api.get<{ staff: StaffListItem[] }>('/api/staff'),
    });
    const staff = data?.staff ?? [];

    const [search, setSearch] = useState('');
    // No pagination on this endpoint yet, so filtering the fetched list is
    // enough; see link-customer-dialog.tsx for the same pattern.
    const trimmedSearch = search.trim().toLowerCase();
    const filtered =
        trimmedSearch === ''
            ? staff
            : staff.filter((member) => member.name.toLowerCase().includes(trimmedSearch) || member.email?.toLowerCase().includes(trimmedSearch));

    const [addOpen, setAddOpen] = useState(false);
    const [addForm, setAddForm] = useState(emptyForm);
    const [addErrors, setAddErrors] = useState<Record<string, string[]>>({});

    const [editing, setEditing] = useState<StaffListItem | null>(null);
    const [editForm, setEditForm] = useState(emptyForm);
    const [editErrors, setEditErrors] = useState<Record<string, string[]>>({});

    const [deletingId, setDeletingId] = useState<number | null>(null);
    const [deleteError, setDeleteError] = useState<string | undefined>();
    const [invitingId, setInvitingId] = useState<number | null>(null);

    const invalidate = () => queryClient.invalidateQueries({ queryKey: ['staff'] });

    const createStaff = useMutation({
        mutationFn: () => api.post('/api/staff', addForm),
        onSuccess: () => {
            invalidate();
            setAddForm(emptyForm);
            setAddOpen(false);
        },
        onError: (err) => {
            if (err instanceof ApiError) setAddErrors(err.errors);
            else throw err;
        },
    });

    const updateStaff = useMutation({
        mutationFn: () => api.patch(`/api/staff/${editing!.id}`, editForm),
        onSuccess: () => {
            invalidate();
            setEditing(null);
        },
        onError: (err) => {
            if (err instanceof ApiError) setEditErrors(err.errors);
            else throw err;
        },
    });

    const deleteStaff = useMutation({
        mutationFn: (id: number) => api.delete(`/api/staff/${id}`),
        onSuccess: () => {
            invalidate();
            setDeletingId(null);
            setDeleteError(undefined);
        },
        onError: (err) => {
            if (err instanceof ApiError) setDeleteError(err.fieldError('staff'));
            else throw err;
        },
    });

    const inviteStaff = useMutation({
        mutationFn: (id: number) => api.post(`/api/staff/${id}/invite`),
        onSuccess: invalidate,
        onSettled: () => setInvitingId(null),
    });

    const submitAdd: FormEventHandler = (e) => {
        e.preventDefault();
        createStaff.mutate();
    };

    const submitEdit: FormEventHandler = (e) => {
        e.preventDefault();
        if (editing) updateStaff.mutate();
    };

    function openEdit(member: StaffListItem) {
        setEditForm({ name: member.name, email: member.email ?? '', is_admin: member.isAdmin });
        setEditErrors({});
        setEditing(member);
    }

    function invite(member: StaffListItem) {
        setInvitingId(member.id);
        inviteStaff.mutate(member.id);
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                <div className="flex items-center justify-between">
                    <h1 className="text-xl font-semibold">{t('heading')}</h1>

                    <Dialog
                        open={addOpen}
                        onOpenChange={(next) => {
                            setAddOpen(next);
                            if (!next) {
                                setAddForm(emptyForm);
                                setAddErrors({});
                            }
                        }}
                    >
                        <DialogTrigger asChild>
                            <Button>{t('new_staff_member')}</Button>
                        </DialogTrigger>
                        <DialogContent>
                            <DialogHeader>
                                <DialogTitle>{t('new_staff_member')}</DialogTitle>
                            </DialogHeader>
                            <form onSubmit={submitAdd} className="space-y-4">
                                <div className="grid gap-2">
                                    <Label htmlFor="add-name">{t('name')}</Label>
                                    <Input
                                        id="add-name"
                                        value={addForm.name}
                                        onChange={(e) => setAddForm({ ...addForm, name: e.target.value })}
                                        autoFocus
                                        placeholder={t('name_placeholder')}
                                    />
                                    <InputError message={addErrors.name?.[0]} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="add-email">
                                        {t('email', { ns: 'customers' })} <span className="text-neutral-400">{t('email_needed_for_login')}</span>
                                    </Label>
                                    <Input
                                        id="add-email"
                                        type="email"
                                        value={addForm.email}
                                        onChange={(e) => setAddForm({ ...addForm, email: e.target.value })}
                                        placeholder={t('email_placeholder')}
                                    />
                                    <InputError message={addErrors.email?.[0]} />
                                </div>
                                <label className="flex items-center gap-2 text-sm">
                                    <Checkbox checked={addForm.is_admin} onCheckedChange={(c) => setAddForm({ ...addForm, is_admin: c === true })} />
                                    {t('admin_checkbox_label')}
                                </label>
                                <div className="flex justify-end gap-3 pt-2">
                                    <Button type="button" variant="outline" onClick={() => setAddOpen(false)}>
                                        {t('cancel')}
                                    </Button>
                                    <Button disabled={createStaff.isPending}>{t('create_staff_member')}</Button>
                                </div>
                            </form>
                        </DialogContent>
                    </Dialog>
                </div>

                {deleteError && <p className="text-sm text-red-600 dark:text-red-400">{deleteError}</p>}

                {staff.length > 0 && (
                    <Input
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        placeholder={t('search_placeholder')}
                        aria-label={t('search_placeholder')}
                        className="max-w-sm"
                    />
                )}

                {isLoading ? (
                    <StaffListSkeleton />
                ) : staff.length === 0 ? (
                    <p className="text-sm text-neutral-600 dark:text-neutral-400">{t('empty_list')}</p>
                ) : filtered.length === 0 ? (
                    <p className="text-sm text-neutral-600 dark:text-neutral-400">{t('no_search_matches', { query: search.trim() })}</p>
                ) : (
                    <ul className="divide-y rounded-xl border">
                        {filtered.map((member) => (
                            <li key={member.id} className="flex items-center justify-between gap-4 p-4">
                                <div className="min-w-0">
                                    <div className="flex items-center gap-2">
                                        <span className="font-medium">{member.name}</span>
                                        {member.isFounder && <Badge variant="secondary">{t('founder_badge')}</Badge>}
                                        {member.isAdmin && <Badge variant="secondary">{t('admin_badge')}</Badge>}
                                        {member.hasLogin ? (
                                            <Badge variant="outline">{t('can_log_in_badge')}</Badge>
                                        ) : (
                                            member.email && <Badge variant="outline">{t('invited_badge')}</Badge>
                                        )}
                                    </div>
                                    {member.email && <p className="text-sm text-neutral-600 dark:text-neutral-400">{member.email}</p>}
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
                                            {member.hasLogin ? t('resend_invite') : t('invite_to_log_in')}
                                        </Button>
                                    )}
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        className="text-neutral-400 hover:text-neutral-700 dark:hover:text-neutral-200"
                                        onClick={() => openEdit(member)}
                                    >
                                        <Pencil className="h-4 w-4" />
                                        <span className="sr-only">{t('edit_staff_member_sr')}</span>
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        className="text-neutral-400 hover:text-red-600 disabled:opacity-40"
                                        disabled={member.isFounder}
                                        title={member.isFounder ? t('founder_cannot_be_deleted') : undefined}
                                        onClick={() => {
                                            setDeleteError(undefined);
                                            setDeletingId(member.id);
                                        }}
                                    >
                                        <Trash2 className="h-4 w-4" />
                                        <span className="sr-only">{t('delete_staff_member_sr')}</span>
                                    </Button>
                                </div>
                            </li>
                        ))}
                    </ul>
                )}

                {/* Edit staff dialog */}
                <Dialog
                    open={editing !== null}
                    onOpenChange={(open) => {
                        if (!open) setEditing(null);
                    }}
                >
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>{t('edit_staff_member_title')}</DialogTitle>
                        </DialogHeader>
                        <form onSubmit={submitEdit} className="space-y-4">
                            <div className="grid gap-2">
                                <Label htmlFor="edit-name">{t('name')}</Label>
                                <Input
                                    id="edit-name"
                                    value={editForm.name}
                                    onChange={(e) => setEditForm({ ...editForm, name: e.target.value })}
                                    autoFocus
                                />
                                <InputError message={editErrors.name?.[0]} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="edit-email">
                                    {t('email', { ns: 'customers' })} <span className="text-neutral-400">{t('email_needed_for_login')}</span>
                                </Label>
                                <Input
                                    id="edit-email"
                                    type="email"
                                    value={editForm.email}
                                    onChange={(e) => setEditForm({ ...editForm, email: e.target.value })}
                                />
                                <InputError message={editErrors.email?.[0]} />
                            </div>
                            <label className="flex items-center gap-2 text-sm">
                                <Checkbox
                                    checked={editForm.is_admin}
                                    disabled={editing?.isFounder}
                                    onCheckedChange={(c) => setEditForm({ ...editForm, is_admin: c === true })}
                                />
                                {t('admin_checkbox_label')}
                            </label>
                            {editing?.isFounder && <p className="text-sm text-neutral-500">{t('founder_is_always_admin')}</p>}
                            <InputError message={editErrors.is_admin?.[0]} />
                            <div className="flex justify-end gap-3 pt-2">
                                <Button type="button" variant="outline" onClick={() => setEditing(null)}>
                                    {t('cancel')}
                                </Button>
                                <Button disabled={updateStaff.isPending}>{t('save')}</Button>
                            </div>
                        </form>
                    </DialogContent>
                </Dialog>

                <ConfirmDialog
                    open={deletingId !== null}
                    onOpenChange={(open) => {
                        if (!open) setDeletingId(null);
                    }}
                    title={t('delete_staff_member_title')}
                    description={t('delete_staff_member_description')}
                    confirmLabel={t('delete_staff_member_confirm')}
                    onConfirm={() => deletingId && deleteStaff.mutate(deletingId)}
                    processing={deleteStaff.isPending}
                />
            </div>
        </AppLayout>
    );
}

/** Stand-ins for a handful of rows, shaped like the real ones so the list doesn't jump once data arrives. */
function StaffListSkeleton() {
    return (
        <ul className="divide-y rounded-xl border">
            {Array.from({ length: 6 }).map((_, i) => (
                <li key={i} className="flex items-center justify-between gap-4 p-4">
                    <div>
                        <Skeleton className="h-4 w-36" />
                        <Skeleton className="mt-2 h-3.5 w-48" />
                    </div>
                </li>
            ))}
        </ul>
    );
}
