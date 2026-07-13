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
import { Head, Link, router, useForm } from '@inertiajs/react';
import { Pencil, Trash2 } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

interface ProjectDetail {
    id: number;
    name: string;
}

interface WorkOrderListItem {
    id: number;
    title: string | null;
    address: string;
    customerName: string;
    customerEmail: string;
}

interface AddableCase {
    id: number;
    title: string | null;
    address: string;
    customerName: string;
}

interface RoundListItem {
    id: number;
    type: string;
    status: 'draft' | 'open' | 'closed';
    closeOffOn: string;
    caseCount: number;
}

const roundStatusVariant = { draft: 'outline', open: 'default', closed: 'secondary' } as const;

function roundTypeLabel(value: string) {
    return value.charAt(0).toUpperCase() + value.slice(1);
}

export default function ProjectsShow({
    project,
    workOrders,
    addableCases,
    rounds,
}: {
    project: ProjectDetail;
    workOrders: WorkOrderListItem[];
    addableCases: AddableCase[];
    rounds: RoundListItem[];
}) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Projects', href: '/projects' },
        { title: project.name, href: `/projects/${project.id}` },
    ];

    const [addOpen, setAddOpen] = useState(false);
    const [editOpen, setEditOpen] = useState(false);
    const [deleteProjectOpen, setDeleteProjectOpen] = useState(false);
    const [removingCaseId, setRemovingCaseId] = useState<number | null>(null);
    const [processing, setProcessing] = useState(false);
    const [selectedIds, setSelectedIds] = useState<number[]>([]);
    const [addingCases, setAddingCases] = useState(false);

    const editForm = useForm({ name: project.name });

    const submitEdit: FormEventHandler = (e) => {
        e.preventDefault();
        editForm.patch(route('projects.update', project.id), {
            onSuccess: () => setEditOpen(false),
        });
    };

    function toggleCase(id: number, checked: boolean) {
        setSelectedIds((prev) => (checked ? [...prev, id] : prev.filter((x) => x !== id)));
    }

    function submitAddCases() {
        if (selectedIds.length === 0) return;
        setAddingCases(true);
        router.post(route('projects.work-orders.store', project.id), { work_order_ids: selectedIds }, {
            onSuccess: () => { setSelectedIds([]); setAddOpen(false); },
            onFinish: () => setAddingCases(false),
        });
    }

    function confirmRemoveCase() {
        if (!removingCaseId) return;
        setProcessing(true);
        router.delete(route('projects.work-orders.destroy', [project.id, removingCaseId]), {
            onFinish: () => { setProcessing(false); setRemovingCaseId(null); },
        });
    }

    function confirmDeleteProject() {
        setProcessing(true);
        router.delete(route('projects.destroy', project.id), {
            onFinish: () => setProcessing(false),
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
                        description="The project will be removed. Its cases are kept as standalone cases."
                        confirmLabel="Delete project"
                        onConfirm={confirmDeleteProject}
                        processing={processing}
                    />

                    {/* Add existing cases */}
                    <div className="ml-auto">
                        <Dialog open={addOpen} onOpenChange={(next) => { setAddOpen(next); if (!next) setSelectedIds([]); }}>
                            <DialogTrigger asChild>
                                <Button disabled={addableCases.length === 0}>Add cases</Button>
                            </DialogTrigger>
                            <DialogContent>
                                <DialogHeader>
                                    <DialogTitle>Add cases</DialogTitle>
                                </DialogHeader>
                                <div className="space-y-4">
                                    <p className="text-sm text-neutral-600 dark:text-neutral-400">
                                        Only standalone cases (not already in a project) are shown. Create new cases on the{' '}
                                        <Link href={route('cases.index')} className="underline">Cases</Link> page.
                                    </p>
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
                                        <Button type="button" variant="outline" onClick={() => setAddOpen(false)}>
                                            Cancel
                                        </Button>
                                        <Button onClick={submitAddCases} disabled={addingCases || selectedIds.length === 0}>
                                            Add {selectedIds.length > 0 ? selectedIds.length : ''} {selectedIds.length === 1 ? 'case' : 'cases'}
                                        </Button>
                                    </div>
                                </div>
                            </DialogContent>
                        </Dialog>
                    </div>
                </div>

                {workOrders.length === 0 ? (
                    <p className="text-sm text-neutral-600 dark:text-neutral-400">No cases in this project yet.</p>
                ) : (
                    <ul className="divide-y rounded-xl border">
                        {workOrders.map((workOrder) => (
                            <li key={workOrder.id} className="flex items-center justify-between gap-4 p-4">
                                <Link href={route('cases.show', workOrder.id)} className="min-w-0 flex-1">
                                    <p className="font-medium hover:underline">{workOrder.title ?? workOrder.address}</p>
                                    <p className="text-sm text-neutral-600 dark:text-neutral-400">
                                        {workOrder.title ? `${workOrder.address} · ` : ''}{workOrder.customerName} · {workOrder.customerEmail}
                                    </p>
                                </Link>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    className="shrink-0 text-neutral-400 hover:text-red-600"
                                    onClick={() => setRemovingCaseId(workOrder.id)}
                                >
                                    <Trash2 className="h-4 w-4" />
                                    <span className="sr-only">Remove from project</span>
                                </Button>
                            </li>
                        ))}
                    </ul>
                )}

                {rounds.length > 0 && (
                    <section className="space-y-3">
                        <h2 className="font-medium">Rounds</h2>
                        <ul className="divide-y rounded-xl border">
                            {rounds.map((round) => (
                                <li key={round.id} className="flex items-center justify-between gap-4 p-4">
                                    <Link href={route('rounds.show', round.id)} className="min-w-0 flex-1">
                                        <p className="font-medium hover:underline">
                                            {roundTypeLabel(round.type)} round · closes{' '}
                                            {new Date(round.closeOffOn).toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' })}
                                        </p>
                                        <p className="text-sm text-neutral-600 dark:text-neutral-400">
                                            {round.caseCount} {round.caseCount === 1 ? 'case' : 'cases'}
                                        </p>
                                    </Link>
                                    <Badge variant={roundStatusVariant[round.status]} className="capitalize">
                                        {round.status}
                                    </Badge>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                {/* Remove-from-project confirmation */}
                <ConfirmDialog
                    open={removingCaseId !== null}
                    onOpenChange={(open) => { if (!open) setRemovingCaseId(null); }}
                    title="Remove case from project?"
                    description="The case itself is kept — it just leaves this project."
                    confirmLabel="Remove case"
                    onConfirm={confirmRemoveCase}
                    processing={processing}
                />
            </div>
        </AppLayout>
    );
}
