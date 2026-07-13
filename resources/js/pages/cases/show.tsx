import ConfirmDialog from '@/components/confirm-dialog';
import DetailsGrid from '@/components/details-grid';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { formatRange, toIso } from '@/components/visits/datetime';
import EditVisitDialog from '@/components/visits/edit-visit-dialog';
import { RoundOption, StaffOption, VisitListItem, typeLabel, visitTypes } from '@/components/visits/types';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { Pencil } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

interface CaseDetail {
    id: number;
    title: string | null;
    address: string;
    notes: string | null;
    customerId: number;
    customerName: string;
    customerEmail: string;
    projectId: number | null;
    projectName: string | null;
}

interface ProjectOption {
    id: number;
    name: string;
}

export default function CasesShow({
    workOrder,
    visits,
    staffOptions,
    roundOptions,
    projectOptions,
}: {
    workOrder: CaseDetail;
    visits: VisitListItem[];
    staffOptions: StaffOption[];
    roundOptions: RoundOption[];
    projectOptions: ProjectOption[];
}) {
    const heading = workOrder.title ?? workOrder.address;
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Cases', href: '/cases' },
        { title: heading, href: `/cases/${workOrder.id}` },
    ];

    const [addOpen, setAddOpen] = useState(false);
    const [editCaseOpen, setEditCaseOpen] = useState(false);
    const [editing, setEditing] = useState<VisitListItem | null>(null);
    const [cancellingId, setCancellingId] = useState<number | null>(null);
    const [cancelling, setCancelling] = useState(false);

    const caseForm = useForm({
        title: workOrder.title ?? '',
        address: workOrder.address,
        notes: workOrder.notes ?? '',
        project_id: workOrder.projectId ? String(workOrder.projectId) : '',
    });

    const submitCaseEdit: FormEventHandler = (e) => {
        e.preventDefault();
        caseForm.transform((data) => ({ ...data, project_id: data.project_id || null }));
        caseForm.patch(route('work-orders.update', workOrder.id), {
            onSuccess: () => setEditCaseOpen(false),
        });
    };

    const addForm = useForm({ type: '', staff_id: '', starts_at: '', ends_at: '', round_id: '' });

    const submitAdd: FormEventHandler = (e) => {
        e.preventDefault();
        addForm.transform((data) => ({
            ...data,
            starts_at: toIso(data.starts_at),
            ends_at: toIso(data.ends_at),
            round_id: data.round_id || null,
        }));
        addForm.post(route('cases.visits.store', workOrder.id), {
            onSuccess: () => { addForm.reset(); setAddOpen(false); },
        });
    };

    function confirmCancel() {
        if (!cancellingId) return;
        setCancelling(true);
        router.post(route('visits.cancel', cancellingId), {}, {
            onFinish: () => { setCancelling(false); setCancellingId(null); },
        });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={heading} />
            <div className="flex h-full flex-1 flex-col gap-6 rounded-xl p-4">
                <div className="flex items-start justify-between gap-4">
                    <div className="flex items-center gap-2">
                        <h1 className="text-xl font-semibold">{heading}</h1>

                        {/* Edit case dialog */}
                        <Dialog open={editCaseOpen} onOpenChange={(next) => { setEditCaseOpen(next); if (!next) caseForm.reset(); }}>
                            <DialogTrigger asChild>
                                <Button variant="ghost" size="icon" className="text-neutral-400 hover:text-neutral-700 dark:hover:text-neutral-200">
                                    <Pencil className="h-4 w-4" />
                                    <span className="sr-only">Edit case</span>
                                </Button>
                            </DialogTrigger>
                            <DialogContent>
                                <DialogHeader>
                                    <DialogTitle>Edit case</DialogTitle>
                                </DialogHeader>
                                <form onSubmit={submitCaseEdit} className="space-y-4">
                                    <div className="grid gap-2">
                                        <Label htmlFor="case-title">Title <span className="text-neutral-400">(optional)</span></Label>
                                        <Input
                                            id="case-title"
                                            value={caseForm.data.title}
                                            onChange={(e) => caseForm.setData('title', e.target.value)}
                                        />
                                        <InputError message={caseForm.errors.title} />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="case-address">Address</Label>
                                        <Input
                                            id="case-address"
                                            value={caseForm.data.address}
                                            onChange={(e) => caseForm.setData('address', e.target.value)}
                                        />
                                        <InputError message={caseForm.errors.address} />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="case-notes">Notes <span className="text-neutral-400">(optional)</span></Label>
                                        <textarea
                                            id="case-notes"
                                            value={caseForm.data.notes}
                                            onChange={(e) => caseForm.setData('notes', e.target.value)}
                                            rows={3}
                                            className="flex w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
                                        />
                                        <InputError message={caseForm.errors.notes} />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="case-project">Project <span className="text-neutral-400">(optional)</span></Label>
                                        <Select
                                            value={caseForm.data.project_id}
                                            onValueChange={(v) => caseForm.setData('project_id', v === 'none' ? '' : v)}
                                        >
                                            <SelectTrigger id="case-project">
                                                <SelectValue placeholder="No project" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="none">No project</SelectItem>
                                                {projectOptions.map((p) => (
                                                    <SelectItem key={p.id} value={String(p.id)}>{p.name}</SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        <InputError message={caseForm.errors.project_id} />
                                    </div>
                                    <div className="flex justify-end gap-3 pt-2">
                                        <Button type="button" variant="outline" onClick={() => setEditCaseOpen(false)}>
                                            Cancel
                                        </Button>
                                        <Button disabled={caseForm.processing}>Save</Button>
                                    </div>
                                </form>
                            </DialogContent>
                        </Dialog>
                    </div>

                    {/* New visit dialog */}
                    <Dialog open={addOpen} onOpenChange={(next) => { setAddOpen(next); if (!next) addForm.reset(); }}>
                        <DialogTrigger asChild>
                            <Button>New visit</Button>
                        </DialogTrigger>
                        <DialogContent>
                            <DialogHeader>
                                <DialogTitle>New visit</DialogTitle>
                            </DialogHeader>
                            <form onSubmit={submitAdd} className="space-y-4">
                                <div className="grid gap-2">
                                    <Label htmlFor="type">Type</Label>
                                    <Select value={addForm.data.type} onValueChange={(v) => addForm.setData('type', v)}>
                                        <SelectTrigger id="type">
                                            <SelectValue placeholder="Select a visit type…" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {visitTypes.map((t) => (
                                                <SelectItem key={t.value} value={t.value}>{t.label}</SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <InputError message={addForm.errors.type} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="add-staff">Staff</Label>
                                    <Select value={addForm.data.staff_id} onValueChange={(v) => addForm.setData('staff_id', v)}>
                                        <SelectTrigger id="add-staff">
                                            <SelectValue placeholder="Select staff…" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {staffOptions.map((s) => (
                                                <SelectItem key={s.id} value={String(s.id)}>{s.name}</SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <InputError message={addForm.errors.staff_id} />
                                </div>

                                <div className="grid grid-cols-2 gap-4">
                                    <div className="grid gap-2">
                                        <Label htmlFor="add-starts">From</Label>
                                        <Input
                                            id="add-starts"
                                            type="datetime-local"
                                            value={addForm.data.starts_at}
                                            onChange={(e) => addForm.setData('starts_at', e.target.value)}
                                        />
                                        <InputError message={addForm.errors.starts_at} />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="add-ends">To</Label>
                                        <Input
                                            id="add-ends"
                                            type="datetime-local"
                                            value={addForm.data.ends_at}
                                            onChange={(e) => addForm.setData('ends_at', e.target.value)}
                                        />
                                        <InputError message={addForm.errors.ends_at} />
                                    </div>
                                </div>

                                {roundOptions.length > 0 && (
                                    <div className="grid gap-2">
                                        <Label htmlFor="add-round">Round <span className="text-neutral-400">(optional)</span></Label>
                                        <Select
                                            value={addForm.data.round_id}
                                            onValueChange={(v) => addForm.setData('round_id', v === 'none' ? '' : v)}
                                        >
                                            <SelectTrigger id="add-round">
                                                <SelectValue placeholder="No round" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="none">No round</SelectItem>
                                                {roundOptions.map((r) => (
                                                    <SelectItem key={r.id} value={String(r.id)}>{r.label}</SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        <InputError message={addForm.errors.round_id} />
                                    </div>
                                )}

                                <div className="flex justify-end gap-3 pt-2">
                                    <Button type="button" variant="outline" onClick={() => setAddOpen(false)}>
                                        Cancel
                                    </Button>
                                    <Button disabled={addForm.processing}>Create visit</Button>
                                </div>
                            </form>
                        </DialogContent>
                    </Dialog>
                </div>

                {/* Case details */}
                <DetailsGrid
                    items={[
                        { label: 'Address', value: workOrder.address },
                        {
                            label: 'Customer',
                            value: (
                                <Link href={route('customers.show', workOrder.customerId)} className="hover:underline">
                                    {workOrder.customerName}
                                </Link>
                            ),
                        },
                        { label: 'Email', value: workOrder.customerEmail },
                        {
                            label: 'Project',
                            value: workOrder.projectName ? (
                                <Link href={route('projects.show', workOrder.projectId!)} className="hover:underline">
                                    {workOrder.projectName}
                                </Link>
                            ) : '—',
                        },
                        ...(workOrder.notes
                            ? [{
                                label: 'Notes',
                                value: <span className="font-normal whitespace-pre-line">{workOrder.notes}</span>,
                                fullWidth: true,
                            }]
                            : []),
                    ]}
                />

                <section className="space-y-3">
                    <h2 className="font-medium">Visits</h2>

                    {visits.length === 0 ? (
                        <p className="text-sm text-neutral-600 dark:text-neutral-400">No visits yet.</p>
                    ) : (
                        <ul className="divide-y rounded-xl border">
                            {visits.map((visit) => {
                                const range = formatRange(visit.startsAt, visit.endsAt);
                                const isCancelled = visit.cancelledAt !== null;

                                return (
                                    <li
                                        key={visit.id}
                                        className={`flex items-center justify-between gap-4 p-4 ${isCancelled ? 'opacity-60' : ''}`}
                                    >
                                        <div className="flex min-w-0 flex-1 items-center gap-3">
                                            <Badge variant="secondary">{typeLabel(visit.type)}</Badge>
                                            <div className="min-w-0">
                                                <Link href={route('visits.show', visit.id)} className="font-medium hover:underline">
                                                    {range}
                                                </Link>
                                                <p className="text-sm text-neutral-600 dark:text-neutral-400">
                                                    {visit.staffName}
                                                </p>
                                            </div>
                                        </div>

                                        {isCancelled ? (
                                            <Badge variant="outline">Cancelled</Badge>
                                        ) : (
                                            <div className="flex items-center gap-1">
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    className="text-neutral-400 hover:text-neutral-700 dark:hover:text-neutral-200"
                                                    onClick={() => setEditing(visit)}
                                                >
                                                    <Pencil className="h-4 w-4" />
                                                    <span className="sr-only">Edit visit</span>
                                                </Button>
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    className="text-neutral-400 hover:text-red-600"
                                                    onClick={() => setCancellingId(visit.id)}
                                                >
                                                    Cancel visit
                                                </Button>
                                            </div>
                                        )}
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                </section>

                <EditVisitDialog
                    visit={editing}
                    onClose={() => setEditing(null)}
                    cases={[{ id: workOrder.id, address: workOrder.address, customerName: workOrder.customerName, rounds: roundOptions }]}
                    staffOptions={staffOptions}
                />

                <ConfirmDialog
                    open={cancellingId !== null}
                    onOpenChange={(open) => { if (!open) setCancellingId(null); }}
                    title="Cancel visit?"
                    description="The appointment is called off and its time is freed. The visit stays visible as history."
                    confirmLabel="Yes, cancel it"
                    onConfirm={confirmCancel}
                    processing={cancelling}
                />
            </div>
        </AppLayout>
    );
}
