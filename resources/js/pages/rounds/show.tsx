import ConfirmDialog from '@/components/confirm-dialog';
import DetailsGrid from '@/components/details-grid';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { Check, Copy, Pencil, Trash2 } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

const visitTypes = [
    { value: 'survey', label: 'Survey' },
    { value: 'progress', label: 'Progress' },
    { value: 'handover', label: 'Handover' },
    { value: 'warranty', label: 'Warranty' },
];

function typeLabel(value: string) {
    return visitTypes.find((t) => t.value === value)?.label ?? value;
}

function formatDate(iso: string | null) {
    if (!iso) return null;
    return new Date(iso).toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' });
}

function formatDateTime(iso: string) {
    const d = new Date(iso);
    return `${formatDate(iso)} ${d.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit', hour12: false })}`;
}

// datetime-local inputs want "YYYY-MM-DDTHH:mm" in local time.
function toInputValue(iso: string | null) {
    if (!iso) return '';
    const d = new Date(iso);
    const pad = (n: number) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

// The backend stores UTC; send real instants, not naive local strings.
function toIso(local: string) {
    return local ? new Date(local).toISOString() : null;
}

const statusVariant = { draft: 'outline', open: 'default', closed: 'secondary' } as const;

interface RoundDetail {
    id: number;
    type: string;
    status: 'draft' | 'open' | 'closed';
    appointmentMinutes: number;
    bufferMinutes: number;
    windowStartsOn: string | null;
    windowEndsOn: string | null;
    dayStartsAt: string;
    dayEndsAt: string;
    closeOffOn: string;
    scheduledOpenAt: string | null;
    projectId: number | null;
    projectName: string | null;
}

interface CaseListItem {
    id: number;
    title: string | null;
    address: string;
    customerName: string;
    customerEmail: string;
    bookingUrl: string | null;
    bookedAt: string | null;
    inviteSentAt: string | null;
    inviteFailedAt: string | null;
}

interface AddableCase {
    id: number;
    title: string | null;
    address: string;
    customerName: string;
    projectId: number | null;
}

interface ProjectOption {
    id: number;
    name: string;
}

export default function RoundsShow({
    round,
    cases,
    addableCases,
    projects,
}: {
    round: RoundDetail;
    cases: CaseListItem[];
    addableCases: AddableCase[];
    projects: ProjectOption[];
}) {
    const title = `${typeLabel(round.type)} round`;
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Rounds', href: '/rounds' },
        { title, href: `/rounds/${round.id}` },
    ];

    const isDraft = round.status === 'draft';
    const isOpen = round.status === 'open';

    const [editOpen, setEditOpen] = useState(false);
    const [addCasesOpen, setAddCasesOpen] = useState(false);
    const [selectedIds, setSelectedIds] = useState<number[]>([]);
    const [addingCases, setAddingCases] = useState(false);
    const [confirming, setConfirming] = useState<'open' | 'close' | 'delete' | null>(null);
    const [processing, setProcessing] = useState(false);
    const [removingCaseId, setRemovingCaseId] = useState<number | null>(null);
    const [copiedCaseId, setCopiedCaseId] = useState<number | null>(null);

    const editForm = useForm({
        type: round.type,
        appointment_minutes: String(round.appointmentMinutes),
        buffer_minutes: String(round.bufferMinutes),
        window_starts_on: round.windowStartsOn ?? '',
        window_ends_on: round.windowEndsOn ?? '',
        day_starts_at: round.dayStartsAt,
        day_ends_at: round.dayEndsAt,
        close_off_on: round.closeOffOn,
        scheduled_open_at: toInputValue(round.scheduledOpenAt),
        project_id: round.projectId ? String(round.projectId) : '',
    });

    const submitEdit: FormEventHandler = (e) => {
        e.preventDefault();
        editForm.transform((data) => ({
            ...data,
            window_starts_on: data.window_starts_on || null,
            window_ends_on: data.window_ends_on || null,
            scheduled_open_at: toIso(data.scheduled_open_at),
            project_id: data.project_id || null,
        }));
        editForm.patch(route('rounds.update', round.id), {
            onSuccess: () => setEditOpen(false),
        });
    };

    function toggleCase(id: number, checked: boolean) {
        setSelectedIds((prev) => (checked ? [...prev, id] : prev.filter((x) => x !== id)));
    }

    // Point-in-time populate: checks the project's addable cases right now;
    // cases already in the round aren't listed, so re-running skips them.
    function populateFromProject(projectId: string) {
        const ids = addableCases.filter((c) => c.projectId === Number(projectId)).map((c) => c.id);
        setSelectedIds((prev) => [...new Set([...prev, ...ids])]);
    }

    function submitAddCases() {
        if (selectedIds.length === 0) return;
        setAddingCases(true);
        router.post(route('rounds.cases.store', round.id), { work_order_ids: selectedIds }, {
            onSuccess: () => { setSelectedIds([]); setAddCasesOpen(false); },
            onFinish: () => setAddingCases(false),
        });
    }

    function confirmAction() {
        setProcessing(true);
        const options = { onFinish: () => { setProcessing(false); setConfirming(null); } };
        if (confirming === 'open') router.post(route('rounds.open', round.id), {}, options);
        if (confirming === 'close') router.post(route('rounds.close', round.id), {}, options);
        if (confirming === 'delete') router.delete(route('rounds.destroy', round.id), options);
    }

    async function copyBookingLink(c: CaseListItem) {
        if (!c.bookingUrl) return;
        await navigator.clipboard.writeText(c.bookingUrl);
        setCopiedCaseId(c.id);
        setTimeout(() => setCopiedCaseId(null), 2000);
    }

    const [sendingInviteId, setSendingInviteId] = useState<number | null>(null);

    function sendInvite(c: CaseListItem) {
        setSendingInviteId(c.id);
        router.post(route('rounds.cases.send-invite', [round.id, c.id]), {}, {
            onFinish: () => setSendingInviteId(null),
        });
    }

    const unsentCount = cases.filter((c) => !c.inviteSentAt).length;

    function confirmRemoveCase() {
        if (!removingCaseId) return;
        setProcessing(true);
        router.delete(route('rounds.cases.destroy', [round.id, removingCaseId]), {
            onFinish: () => { setProcessing(false); setRemovingCaseId(null); },
        });
    }

    const confirmCopy = {
        open: {
            title: 'Open round?',
            description:
                `Booking opens for ${cases.length} ${cases.length === 1 ? 'case' : 'cases'}. ` +
                `This will email ${unsentCount} ${unsentCount === 1 ? 'customer' : 'customers'} their booking link. ` +
                'Cases can still be added while the round is open, but the round can no longer be edited or deleted.' +
                (round.scheduledOpenAt ? ' This overrides the scheduled start.' : ''),
            label: 'Open round',
        },
        close: {
            title: 'Close round?',
            description: 'The round becomes read-only. This cannot be undone.',
            label: 'Close round',
        },
        delete: {
            title: 'Delete round?',
            description: 'The draft round will be removed. Its cases are unaffected.',
            label: 'Delete round',
        },
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />
            <div className="flex h-full flex-1 flex-col gap-6 rounded-xl p-4">
                <div className="flex items-start justify-between gap-4">
                    <div>
                        <div className="flex items-center gap-2">
                            <h1 className="text-xl font-semibold">{title}</h1>
                            <Badge variant={statusVariant[round.status]} className="capitalize">{round.status}</Badge>

                            {isDraft && (
                                <>
                                    {/* Edit round */}
                                    <Dialog open={editOpen} onOpenChange={setEditOpen}>
                                        <DialogTrigger asChild>
                                            <Button variant="ghost" size="icon" className="text-neutral-400 hover:text-neutral-700 dark:hover:text-neutral-200">
                                                <Pencil className="h-4 w-4" />
                                                <span className="sr-only">Edit round</span>
                                            </Button>
                                        </DialogTrigger>
                                        <DialogContent>
                                            <DialogHeader>
                                                <DialogTitle>Edit round</DialogTitle>
                                            </DialogHeader>
                                            <form onSubmit={submitEdit} className="space-y-4">
                                                <div className="grid gap-2">
                                                    <Label htmlFor="edit-type">Visit type</Label>
                                                    <Select value={editForm.data.type} onValueChange={(v) => editForm.setData('type', v)}>
                                                        <SelectTrigger id="edit-type">
                                                            <SelectValue placeholder="Select a visit type…" />
                                                        </SelectTrigger>
                                                        <SelectContent>
                                                            {visitTypes.map((t) => (
                                                                <SelectItem key={t.value} value={t.value}>{t.label}</SelectItem>
                                                            ))}
                                                        </SelectContent>
                                                    </Select>
                                                    <InputError message={editForm.errors.type} />
                                                </div>

                                                <div className="grid grid-cols-2 gap-4">
                                                    <div className="grid gap-2">
                                                        <Label htmlFor="edit-appointment">Appointment (minutes)</Label>
                                                        <Input
                                                            id="edit-appointment"
                                                            type="number"
                                                            min={1}
                                                            value={editForm.data.appointment_minutes}
                                                            onChange={(e) => editForm.setData('appointment_minutes', e.target.value)}
                                                        />
                                                        <InputError message={editForm.errors.appointment_minutes} />
                                                    </div>
                                                    <div className="grid gap-2">
                                                        <Label htmlFor="edit-buffer">Buffer (minutes)</Label>
                                                        <Input
                                                            id="edit-buffer"
                                                            type="number"
                                                            min={0}
                                                            value={editForm.data.buffer_minutes}
                                                            onChange={(e) => editForm.setData('buffer_minutes', e.target.value)}
                                                        />
                                                        <InputError message={editForm.errors.buffer_minutes} />
                                                    </div>
                                                </div>

                                                <div className="grid grid-cols-2 gap-4">
                                                    <div className="grid gap-2">
                                                        <Label htmlFor="edit-window-start">Window from <span className="text-neutral-400">(optional)</span></Label>
                                                        <Input
                                                            id="edit-window-start"
                                                            type="date"
                                                            value={editForm.data.window_starts_on}
                                                            onChange={(e) => editForm.setData('window_starts_on', e.target.value)}
                                                        />
                                                        <InputError message={editForm.errors.window_starts_on} />
                                                    </div>
                                                    <div className="grid gap-2">
                                                        <Label htmlFor="edit-window-end">Window to</Label>
                                                        <Input
                                                            id="edit-window-end"
                                                            type="date"
                                                            value={editForm.data.window_ends_on}
                                                            onChange={(e) => editForm.setData('window_ends_on', e.target.value)}
                                                        />
                                                        <InputError message={editForm.errors.window_ends_on} />
                                                    </div>
                                                </div>

                                                <div className="grid grid-cols-2 gap-4">
                                                    <div className="grid gap-2">
                                                        <Label htmlFor="edit-day-start">Bookable from</Label>
                                                        <Input
                                                            id="edit-day-start"
                                                            type="time"
                                                            value={editForm.data.day_starts_at}
                                                            onChange={(e) => editForm.setData('day_starts_at', e.target.value)}
                                                        />
                                                        <InputError message={editForm.errors.day_starts_at} />
                                                    </div>
                                                    <div className="grid gap-2">
                                                        <Label htmlFor="edit-day-end">Bookable to</Label>
                                                        <Input
                                                            id="edit-day-end"
                                                            type="time"
                                                            value={editForm.data.day_ends_at}
                                                            onChange={(e) => editForm.setData('day_ends_at', e.target.value)}
                                                        />
                                                        <InputError message={editForm.errors.day_ends_at} />
                                                    </div>
                                                </div>

                                                <div className="grid gap-2">
                                                    <Label htmlFor="edit-close-off">Close-off date</Label>
                                                    <Input
                                                        id="edit-close-off"
                                                        type="date"
                                                        value={editForm.data.close_off_on}
                                                        onChange={(e) => editForm.setData('close_off_on', e.target.value)}
                                                    />
                                                    <InputError message={editForm.errors.close_off_on} />
                                                </div>

                                                <div className="grid gap-2">
                                                    <Label htmlFor="edit-scheduled-open">Scheduled start <span className="text-neutral-400">(optional)</span></Label>
                                                    <Input
                                                        id="edit-scheduled-open"
                                                        type="datetime-local"
                                                        value={editForm.data.scheduled_open_at}
                                                        onChange={(e) => editForm.setData('scheduled_open_at', e.target.value)}
                                                    />
                                                    <p className="text-sm text-neutral-600 dark:text-neutral-400">
                                                        The round opens by itself at this time. Clear the field to cancel the schedule.
                                                    </p>
                                                    <InputError message={editForm.errors.scheduled_open_at} />
                                                </div>

                                                <div className="grid gap-2">
                                                    <Label htmlFor="edit-project">Project <span className="text-neutral-400">(optional)</span></Label>
                                                    <Select
                                                        value={editForm.data.project_id}
                                                        onValueChange={(v) => editForm.setData('project_id', v === 'none' ? '' : v)}
                                                    >
                                                        <SelectTrigger id="edit-project">
                                                            <SelectValue placeholder="No project" />
                                                        </SelectTrigger>
                                                        <SelectContent>
                                                            <SelectItem value="none">No project</SelectItem>
                                                            {projects.map((p) => (
                                                                <SelectItem key={p.id} value={String(p.id)}>{p.name}</SelectItem>
                                                            ))}
                                                        </SelectContent>
                                                    </Select>
                                                    <InputError message={editForm.errors.project_id} />
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

                                    {/* Delete round */}
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        className="text-neutral-400 hover:text-red-600"
                                        onClick={() => setConfirming('delete')}
                                    >
                                        <Trash2 className="h-4 w-4" />
                                        <span className="sr-only">Delete round</span>
                                    </Button>
                                </>
                            )}
                        </div>
                        {isDraft && round.scheduledOpenAt && (
                            <p className="mt-1 text-sm text-neutral-600 dark:text-neutral-400">
                                Opens {formatDateTime(round.scheduledOpenAt)}
                                {new Date(round.scheduledOpenAt) < new Date() &&
                                    ' — waiting: the round needs at least one case and a window to open.'}
                            </p>
                        )}
                    </div>

                    <div className="flex shrink-0 items-center gap-2">
                        {(isDraft || isOpen) && (
                            <Dialog
                                open={addCasesOpen}
                                onOpenChange={(next) => { setAddCasesOpen(next); if (!next) setSelectedIds([]); }}
                            >
                                <DialogTrigger asChild>
                                    <Button variant="outline" disabled={addableCases.length === 0}>Add cases</Button>
                                </DialogTrigger>
                                <DialogContent>
                                    <DialogHeader>
                                        <DialogTitle>Add cases</DialogTitle>
                                    </DialogHeader>
                                    <div className="space-y-4">
                                        {projects.length > 0 && (
                                            <div className="grid gap-2">
                                                <Label htmlFor="populate">Populate from project</Label>
                                                <Select value="" onValueChange={populateFromProject}>
                                                    <SelectTrigger id="populate">
                                                        <SelectValue placeholder="Select a project to check its cases…" />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        {projects.map((p) => (
                                                            <SelectItem key={p.id} value={String(p.id)}>{p.name}</SelectItem>
                                                        ))}
                                                    </SelectContent>
                                                </Select>
                                            </div>
                                        )}

                                        <ul className="max-h-64 divide-y overflow-y-auto rounded-xl border">
                                            {addableCases.map((c) => (
                                                <li key={c.id} className="flex items-center gap-3 p-3">
                                                    <Checkbox
                                                        id={`case-${c.id}`}
                                                        checked={selectedIds.includes(c.id)}
                                                        onCheckedChange={(checked) => toggleCase(c.id, checked === true)}
                                                    />
                                                    <label htmlFor={`case-${c.id}`} className="min-w-0 flex-1 cursor-pointer">
                                                        <p className="text-sm font-medium">{c.title ?? c.address}</p>
                                                        <p className="text-sm text-neutral-600 dark:text-neutral-400">
                                                            {c.title ? `${c.address} · ` : ''}{c.customerName}
                                                        </p>
                                                    </label>
                                                </li>
                                            ))}
                                        </ul>

                                        <div className="flex justify-end gap-3 pt-2">
                                            <Button type="button" variant="outline" onClick={() => setAddCasesOpen(false)}>
                                                Cancel
                                            </Button>
                                            <Button onClick={submitAddCases} disabled={addingCases || selectedIds.length === 0}>
                                                Add {selectedIds.length > 0 ? selectedIds.length : ''} {selectedIds.length === 1 ? 'case' : 'cases'}
                                            </Button>
                                        </div>
                                    </div>
                                </DialogContent>
                            </Dialog>
                        )}
                        {isDraft && (
                            <Button onClick={() => setConfirming('open')}>Open round</Button>
                        )}
                        {isOpen && (
                            <Button variant="outline" onClick={() => setConfirming('close')}>Close round</Button>
                        )}
                    </div>
                </div>

                {/* Round details */}
                <DetailsGrid
                    items={[
                        { label: 'Type', value: typeLabel(round.type) },
                        { label: 'Appointment', value: `${round.appointmentMinutes} min` },
                        { label: 'Buffer', value: `${round.bufferMinutes} min` },
                        { label: 'Bookable hours', value: `${round.dayStartsAt}–${round.dayEndsAt}` },
                        {
                            label: 'Window',
                            value: round.windowStartsOn
                                ? `${formatDate(round.windowStartsOn)} – ${formatDate(round.windowEndsOn)}`
                                : 'Not set',
                        },
                        { label: 'Close-off', value: formatDate(round.closeOffOn) },
                        {
                            label: 'Scheduled start',
                            value: round.scheduledOpenAt ? formatDateTime(round.scheduledOpenAt) : 'Manual',
                        },
                        {
                            label: 'Project',
                            value: round.projectName ? (
                                <Link href={route('projects.show', round.projectId!)} className="hover:underline">
                                    {round.projectName}
                                </Link>
                            ) : '—',
                        },
                    ]}
                />

                <section className="space-y-3">
                    <h2 className="font-medium">Cases</h2>

                    {cases.length === 0 ? (
                        <p className="text-sm text-neutral-600 dark:text-neutral-400">
                            No cases in this round yet. Add at least one before opening it.
                        </p>
                    ) : (
                        <ul className="divide-y rounded-xl border">
                            {cases.map((c) => (
                                <li key={c.id} className="flex items-center justify-between gap-4 p-4">
                                    <Link href={route('cases.show', c.id)} className="min-w-0 flex-1">
                                        <p className="font-medium hover:underline">{c.title ?? c.address}</p>
                                        <p className="text-sm text-neutral-600 dark:text-neutral-400">
                                            {c.title ? `${c.address} · ` : ''}{c.customerName} · {c.customerEmail}
                                        </p>
                                    </Link>

                                    {!isDraft && (
                                        c.inviteFailedAt && !c.inviteSentAt ? (
                                            <Badge variant="destructive">Invite failed</Badge>
                                        ) : c.inviteSentAt ? (
                                            <Badge variant="secondary">Invite sent</Badge>
                                        ) : (
                                            <Badge variant="outline">Invite not sent</Badge>
                                        )
                                    )}

                                    {c.bookedAt ? (
                                        <Badge>
                                            Booked · {new Date(c.bookedAt).toLocaleDateString(undefined, { day: 'numeric', month: 'short' })}{' '}
                                            {new Date(c.bookedAt).toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit', hour12: false })}
                                        </Badge>
                                    ) : (
                                        !isDraft && <Badge variant="outline">Not booked</Badge>
                                    )}

                                    <div className="flex shrink-0 items-center gap-1">
                                        {isOpen && (
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                className="text-neutral-400 hover:text-neutral-700 dark:hover:text-neutral-200"
                                                disabled={sendingInviteId === c.id}
                                                onClick={() => sendInvite(c)}
                                            >
                                                {c.inviteSentAt || c.inviteFailedAt ? 'Resend' : 'Send invite'}
                                            </Button>
                                        )}
                                        {c.bookingUrl && (
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                className="text-neutral-400 hover:text-neutral-700 dark:hover:text-neutral-200"
                                                onClick={() => copyBookingLink(c)}
                                            >
                                                {copiedCaseId === c.id ? <Check className="h-4 w-4" /> : <Copy className="h-4 w-4" />}
                                                <span className="sr-only">Copy booking link</span>
                                            </Button>
                                        )}
                                        {isDraft && (
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                className="text-neutral-400 hover:text-red-600"
                                                onClick={() => setRemovingCaseId(c.id)}
                                            >
                                                <Trash2 className="h-4 w-4" />
                                                <span className="sr-only">Remove case</span>
                                            </Button>
                                        )}
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                <ConfirmDialog
                    open={confirming !== null}
                    onOpenChange={(open) => { if (!open) setConfirming(null); }}
                    title={confirming ? confirmCopy[confirming].title : ''}
                    description={confirming ? confirmCopy[confirming].description : ''}
                    confirmLabel={confirming ? confirmCopy[confirming].label : ''}
                    onConfirm={confirmAction}
                    processing={processing}
                />

                <ConfirmDialog
                    open={removingCaseId !== null}
                    onOpenChange={(open) => { if (!open) setRemovingCaseId(null); }}
                    title="Remove case from round?"
                    description="The case itself is unaffected — it just leaves this round."
                    confirmLabel="Remove case"
                    onConfirm={confirmRemoveCase}
                    processing={processing}
                />
            </div>
        </AppLayout>
    );
}
