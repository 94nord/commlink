import DetailsGrid from '@/components/details-grid';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import EditVisitDialog from '@/components/visits/edit-visit-dialog';
import { CaseOption, StaffOption, VisitListItem, typeLabel } from '@/components/visits/types';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';

interface VisitDetail extends VisitListItem {
    caseTitle: string | null;
    customerId: number | null;
    roundLabel: string | null;
}

function formatDateTime(iso: string | null) {
    if (!iso) return '—';
    const d = new Date(iso);
    const date = d.toLocaleDateString(undefined, { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' });
    const time = d.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit', hour12: false });
    return `${date} · ${time}`;
}

export default function VisitsShow({
    visit,
    cases,
    staffOptions,
}: {
    visit: VisitDetail;
    cases: CaseOption[];
    staffOptions: StaffOption[];
}) {
    const heading = `${typeLabel(visit.type)} visit`;
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Visits', href: '/visits' },
        { title: heading, href: `/visits/${visit.id}` },
    ];

    const [editing, setEditing] = useState<VisitListItem | null>(null);
    const isCancelled = visit.cancelledAt !== null;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={heading} />
            <div className="flex h-full flex-1 flex-col gap-6 rounded-xl p-4">
                <div className="flex items-start justify-between gap-4">
                    <div className="flex items-center gap-3">
                        <h1 className="text-xl font-semibold">{heading}</h1>
                        {isCancelled && <Badge variant="outline">Cancelled</Badge>}
                    </div>

                    {!isCancelled && <Button onClick={() => setEditing(visit)}>Edit visit</Button>}
                </div>

                <DetailsGrid
                    items={[
                        { label: 'Type', value: typeLabel(visit.type) },
                        { label: 'Staff', value: visit.staffName ?? '—' },
                        { label: 'From', value: formatDateTime(visit.startsAt) },
                        { label: 'To', value: formatDateTime(visit.endsAt) },
                        { label: 'Status', value: isCancelled ? 'Cancelled' : 'Scheduled' },
                        {
                            label: 'Case',
                            value: visit.caseId ? (
                                <Link href={route('cases.show', visit.caseId)} className="hover:underline">
                                    {visit.caseTitle ?? visit.caseAddress}
                                </Link>
                            ) : '—',
                        },
                        {
                            label: 'Customer',
                            value: visit.customerId ? (
                                <Link href={route('customers.show', visit.customerId)} className="hover:underline">
                                    {visit.customerName}
                                </Link>
                            ) : '—',
                        },
                        {
                            label: 'Round',
                            value: visit.roundId ? (
                                <Link href={route('rounds.show', visit.roundId)} className="hover:underline">
                                    {visit.roundLabel}
                                </Link>
                            ) : '—',
                        },
                    ]}
                />
            </div>

            <EditVisitDialog visit={editing} onClose={() => setEditing(null)} cases={cases} staffOptions={staffOptions} />
        </AppLayout>
    );
}
