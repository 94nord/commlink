import ConfirmDialog from '@/components/confirm-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatRange } from '@/components/visits/datetime';
import EditVisitDialog from '@/components/visits/edit-visit-dialog';
import NewVisitDialog from '@/components/visits/new-visit-dialog';
import { CaseOption, StaffOption, VisitListItem, typeLabel } from '@/components/visits/types';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { Pencil } from 'lucide-react';
import { useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Visits', href: '/visits' }];

type Tab = 'upcoming' | 'previous';

export default function VisitsIndex({
    visits,
    cases,
    staffOptions,
}: {
    visits: VisitListItem[];
    cases: CaseOption[];
    staffOptions: StaffOption[];
}) {
    const now = new Date();
    const upcoming = visits
        .filter((v) => v.startsAt && new Date(v.startsAt) >= now)
        .sort((a, b) => new Date(a.startsAt!).getTime() - new Date(b.startsAt!).getTime());
    const previous = visits
        .filter((v) => v.startsAt && new Date(v.startsAt) < now)
        .sort((a, b) => new Date(b.startsAt!).getTime() - new Date(a.startsAt!).getTime());

    const [tab, setTab] = useState<Tab>('upcoming');
    const [addOpen, setAddOpen] = useState(false);
    const [editing, setEditing] = useState<VisitListItem | null>(null);
    const [deletingId, setDeletingId] = useState<number | null>(null);
    const [deleting, setDeleting] = useState(false);

    function confirmDelete() {
        if (!deletingId) return;
        setDeleting(true);
        router.delete(route('visits.destroy', deletingId), {
            onFinish: () => { setDeleting(false); setDeletingId(null); },
        });
    }

    const displayed = tab === 'upcoming' ? upcoming : previous;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Visits" />
            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                <div className="flex items-center justify-between">
                    <h1 className="text-xl font-semibold">Visits</h1>

                    <Button disabled={cases.length === 0} onClick={() => setAddOpen(true)}>
                        New visit
                    </Button>
                </div>

                {/* Tabs */}
                <div className="flex border-b">
                    {(['upcoming', 'previous'] as Tab[]).map((t) => (
                        <button
                            key={t}
                            onClick={() => setTab(t)}
                            className={`px-4 pb-2 text-sm font-medium capitalize transition-colors ${
                                tab === t
                                    ? 'border-b-2 border-neutral-900 text-neutral-900 dark:border-neutral-100 dark:text-neutral-100'
                                    : 'text-neutral-500 hover:text-neutral-700 dark:text-neutral-400 dark:hover:text-neutral-200'
                            }`}
                        >
                            {t} <span className="ml-1 text-xs text-neutral-400">{(t === 'upcoming' ? upcoming : previous).length}</span>
                        </button>
                    ))}
                </div>

                {displayed.length === 0 ? (
                    <p className="text-sm text-neutral-600 dark:text-neutral-400">
                        {tab === 'upcoming'
                            ? cases.length === 0
                                ? <><Link href={route('cases.index')} className="underline">Add a case</Link> to get started.</>
                                : 'No upcoming visits. Use "New visit" to schedule one.'
                            : 'No previous visits yet.'
                        }
                    </p>
                ) : (
                    <ul className="divide-y rounded-xl border">
                        {displayed.map((visit) => {
                            const range = formatRange(visit.startsAt, visit.endsAt);

                            return (
                                <li key={visit.id} className="flex items-center justify-between gap-4 p-4">
                                    <div className="flex min-w-0 flex-1 items-center gap-3">
                                        <Badge variant="secondary">{typeLabel(visit.type)}</Badge>
                                        <div className="min-w-0">
                                            <Link href={route('visits.show', visit.id)} className="font-medium hover:underline">
                                                {range}
                                            </Link>
                                            <p className="text-sm text-neutral-600 dark:text-neutral-400">
                                                {visit.staffName}
                                                {visit.caseId && (
                                                    <>
                                                        {' · '}
                                                        <Link href={route('cases.show', visit.caseId)} className="hover:underline">
                                                            {visit.caseAddress}
                                                        </Link>
                                                        {visit.customerName && ` (${visit.customerName})`}
                                                    </>
                                                )}
                                            </p>
                                        </div>
                                    </div>

                                    <div className="flex items-center gap-1">
                                        {tab === 'upcoming' && (
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                className="text-neutral-400 hover:text-neutral-700 dark:hover:text-neutral-200"
                                                onClick={() => setEditing(visit)}
                                            >
                                                <Pencil className="h-4 w-4" />
                                                <span className="sr-only">Edit visit</span>
                                            </Button>
                                        )}
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            className="text-neutral-400 hover:text-red-600"
                                            onClick={() => setDeletingId(visit.id)}
                                        >
                                            Delete
                                        </Button>
                                    </div>
                                </li>
                            );
                        })}
                    </ul>
                )}
            </div>

            <NewVisitDialog open={addOpen} onOpenChange={setAddOpen} cases={cases} staffOptions={staffOptions} />

            <EditVisitDialog visit={editing} onClose={() => setEditing(null)} cases={cases} staffOptions={staffOptions} />

            <ConfirmDialog
                open={deletingId !== null}
                onOpenChange={(open) => { if (!open) setDeletingId(null); }}
                title="Delete visit?"
                description="The visit will be permanently removed."
                confirmLabel="Delete"
                onConfirm={confirmDelete}
                processing={deleting}
            />
        </AppLayout>
    );
}
