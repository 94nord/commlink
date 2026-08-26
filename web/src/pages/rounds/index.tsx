import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Rounds', href: '/rounds' }];

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

// The backend stores UTC; send real instants, not naive local strings.
function toIso(local: string) {
    return local ? new Date(local).toISOString() : null;
}

const statusVariant = { draft: 'outline', open: 'default', closed: 'secondary' } as const;

interface RoundListItem {
    id: number;
    type: string;
    status: 'draft' | 'open' | 'closed';
    windowStartsOn: string | null;
    windowEndsOn: string | null;
    closeOffOn: string;
    scheduledOpenAt: string | null;
    caseCount: number;
    projectName: string | null;
}

interface ProjectOption {
    id: number;
    name: string;
}

type Tab = 'draft' | 'open' | 'closed';

export default function RoundsIndex({ rounds, projects }: { rounds: RoundListItem[]; projects: ProjectOption[] }) {
    const [addOpen, setAddOpen] = useState(false);
    const [tab, setTab] = useState<Tab>('open');

    const byStatus = (status: Tab) => rounds.filter((r) => r.status === status);
    const displayed = byStatus(tab);

    const form = useForm({
        type: '',
        appointment_minutes: '60',
        buffer_minutes: '0',
        window_starts_on: '',
        window_ends_on: '',
        day_starts_at: '08:00',
        day_ends_at: '16:00',
        close_off_on: '',
        scheduled_open_at: '',
        project_id: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.transform((data) => ({
            ...data,
            window_starts_on: data.window_starts_on || null,
            window_ends_on: data.window_ends_on || null,
            scheduled_open_at: toIso(data.scheduled_open_at),
            project_id: data.project_id || null,
        }));
        form.post(route('rounds.store'), {
            onSuccess: () => { form.reset(); setAddOpen(false); },
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Rounds" />
            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                <div className="flex items-center justify-between">
                    <h1 className="text-xl font-semibold">Rounds</h1>

                    <Dialog open={addOpen} onOpenChange={(next) => { setAddOpen(next); if (!next) form.reset(); }}>
                        <DialogTrigger asChild>
                            <Button>New round</Button>
                        </DialogTrigger>
                        <DialogContent>
                            <DialogHeader>
                                <DialogTitle>New round</DialogTitle>
                            </DialogHeader>
                            <form onSubmit={submit} className="space-y-4">
                                <div className="grid gap-2">
                                    <Label htmlFor="type">Visit type</Label>
                                    <Select value={form.data.type} onValueChange={(v) => form.setData('type', v)}>
                                        <SelectTrigger id="type">
                                            <SelectValue placeholder="Select a visit type…" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {visitTypes.map((t) => (
                                                <SelectItem key={t.value} value={t.value}>{t.label}</SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <InputError message={form.errors.type} />
                                </div>

                                <div className="grid grid-cols-2 gap-4">
                                    <div className="grid gap-2">
                                        <Label htmlFor="appointment">Appointment (minutes)</Label>
                                        <Input
                                            id="appointment"
                                            type="number"
                                            min={1}
                                            value={form.data.appointment_minutes}
                                            onChange={(e) => form.setData('appointment_minutes', e.target.value)}
                                        />
                                        <InputError message={form.errors.appointment_minutes} />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="buffer">Buffer (minutes)</Label>
                                        <Input
                                            id="buffer"
                                            type="number"
                                            min={0}
                                            value={form.data.buffer_minutes}
                                            onChange={(e) => form.setData('buffer_minutes', e.target.value)}
                                        />
                                        <InputError message={form.errors.buffer_minutes} />
                                    </div>
                                </div>

                                <div className="grid grid-cols-2 gap-4">
                                    <div className="grid gap-2">
                                        <Label htmlFor="window-start">Window from <span className="text-neutral-400">(optional)</span></Label>
                                        <Input
                                            id="window-start"
                                            type="date"
                                            value={form.data.window_starts_on}
                                            onChange={(e) => form.setData('window_starts_on', e.target.value)}
                                        />
                                        <InputError message={form.errors.window_starts_on} />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="window-end">Window to</Label>
                                        <Input
                                            id="window-end"
                                            type="date"
                                            value={form.data.window_ends_on}
                                            onChange={(e) => form.setData('window_ends_on', e.target.value)}
                                        />
                                        <InputError message={form.errors.window_ends_on} />
                                    </div>
                                </div>

                                <div className="grid grid-cols-2 gap-4">
                                    <div className="grid gap-2">
                                        <Label htmlFor="day-start">Bookable from</Label>
                                        <Input
                                            id="day-start"
                                            type="time"
                                            value={form.data.day_starts_at}
                                            onChange={(e) => form.setData('day_starts_at', e.target.value)}
                                        />
                                        <InputError message={form.errors.day_starts_at} />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="day-end">Bookable to</Label>
                                        <Input
                                            id="day-end"
                                            type="time"
                                            value={form.data.day_ends_at}
                                            onChange={(e) => form.setData('day_ends_at', e.target.value)}
                                        />
                                        <InputError message={form.errors.day_ends_at} />
                                    </div>
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="close-off">Close-off date</Label>
                                    <Input
                                        id="close-off"
                                        type="date"
                                        value={form.data.close_off_on}
                                        onChange={(e) => form.setData('close_off_on', e.target.value)}
                                    />
                                    <InputError message={form.errors.close_off_on} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="scheduled-open">Scheduled start <span className="text-neutral-400">(optional)</span></Label>
                                    <Input
                                        id="scheduled-open"
                                        type="datetime-local"
                                        value={form.data.scheduled_open_at}
                                        onChange={(e) => form.setData('scheduled_open_at', e.target.value)}
                                    />
                                    <p className="text-sm text-neutral-600 dark:text-neutral-400">
                                        The round opens by itself at this time. You can still open it manually first.
                                    </p>
                                    <InputError message={form.errors.scheduled_open_at} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="project">Project <span className="text-neutral-400">(optional)</span></Label>
                                    <Select
                                        value={form.data.project_id}
                                        onValueChange={(v) => form.setData('project_id', v === 'none' ? '' : v)}
                                    >
                                        <SelectTrigger id="project">
                                            <SelectValue placeholder="No project" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="none">No project</SelectItem>
                                            {projects.map((p) => (
                                                <SelectItem key={p.id} value={String(p.id)}>{p.name}</SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <InputError message={form.errors.project_id} />
                                </div>

                                <div className="flex justify-end gap-3 pt-2">
                                    <Button type="button" variant="outline" onClick={() => setAddOpen(false)}>
                                        Cancel
                                    </Button>
                                    <Button disabled={form.processing}>Create round</Button>
                                </div>
                            </form>
                        </DialogContent>
                    </Dialog>
                </div>

                {/* Tabs */}
                <div className="flex border-b">
                    {(['draft', 'open', 'closed'] as Tab[]).map((t) => (
                        <button
                            key={t}
                            onClick={() => setTab(t)}
                            className={`px-4 pb-2 text-sm font-medium capitalize transition-colors ${
                                tab === t
                                    ? 'border-b-2 border-neutral-900 text-neutral-900 dark:border-neutral-100 dark:text-neutral-100'
                                    : 'text-neutral-500 hover:text-neutral-700 dark:text-neutral-400 dark:hover:text-neutral-200'
                            }`}
                        >
                            {t} <span className="ml-1 text-xs text-neutral-400">{byStatus(t).length}</span>
                        </button>
                    ))}
                </div>

                {displayed.length === 0 ? (
                    <p className="text-sm text-neutral-600 dark:text-neutral-400">
                        {tab === 'draft'
                            ? 'No draft rounds. Use "New round" to start one.'
                            : `No ${tab} rounds.`}
                    </p>
                ) : (
                    <ul className="divide-y rounded-xl border">
                        {displayed.map((round) => (
                            <li key={round.id} className="flex items-center justify-between gap-4 p-4">
                                <Link href={route('rounds.show', round.id)} className="min-w-0 flex-1">
                                    <p className="font-medium hover:underline">
                                        {typeLabel(round.type)} round · closes {formatDate(round.closeOffOn)}
                                    </p>
                                    <p className="text-sm text-neutral-600 dark:text-neutral-400">
                                        {round.caseCount} {round.caseCount === 1 ? 'case' : 'cases'}
                                        {round.windowStartsOn && ` · ${formatDate(round.windowStartsOn)} – ${formatDate(round.windowEndsOn)}`}
                                        {round.status === 'draft' && round.scheduledOpenAt && ` · opens ${formatDateTime(round.scheduledOpenAt)}`}
                                        {round.projectName && ` · ${round.projectName}`}
                                    </p>
                                </Link>
                                <Badge variant={statusVariant[round.status]} className="capitalize">
                                    {round.status}
                                </Badge>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </AppLayout>
    );
}
