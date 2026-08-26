import WeekCalendar from '@/components/schedule/week-calendar';
import { Button } from '@/components/ui/button';
import { toInputValueFromDate } from '@/components/visits/datetime';
import EditVisitDialog from '@/components/visits/edit-visit-dialog';
import NewVisitDialog from '@/components/visits/new-visit-dialog';
import { CaseOption, StaffOption, VisitListItem } from '@/components/visits/types';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: '/dashboard',
    },
];

const STAFF_COLORS = [
    'border-sky-300 bg-sky-100 text-sky-900 dark:border-sky-700 dark:bg-sky-950 dark:text-sky-100',
    'border-emerald-300 bg-emerald-100 text-emerald-900 dark:border-emerald-700 dark:bg-emerald-950 dark:text-emerald-100',
    'border-amber-300 bg-amber-100 text-amber-900 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-100',
    'border-violet-300 bg-violet-100 text-violet-900 dark:border-violet-700 dark:bg-violet-950 dark:text-violet-100',
    'border-rose-300 bg-rose-100 text-rose-900 dark:border-rose-700 dark:bg-rose-950 dark:text-rose-100',
    'border-teal-300 bg-teal-100 text-teal-900 dark:border-teal-700 dark:bg-teal-950 dark:text-teal-100',
];

const FALLBACK_COLOR = 'border-neutral-300 bg-neutral-100 text-neutral-900 dark:border-neutral-600 dark:bg-neutral-800 dark:text-neutral-100';

// setDate-based day math so the Monday-00:00 boundary survives DST weeks.
function addDays(date: Date, days: number) {
    const next = new Date(date);
    next.setDate(next.getDate() + days);
    return next;
}

function startOfWeek(d: Date) {
    const monday = new Date(d.getFullYear(), d.getMonth(), d.getDate());
    monday.setDate(monday.getDate() - ((monday.getDay() + 6) % 7));
    return monday;
}

function weekLabel(weekStart: Date) {
    const end = addDays(weekStart, 6);
    const sameMonth = weekStart.getMonth() === end.getMonth() && weekStart.getFullYear() === end.getFullYear();
    const start = weekStart.toLocaleDateString(undefined, sameMonth ? { day: 'numeric' } : { day: 'numeric', month: 'short' });
    return `${start} – ${end.toLocaleDateString(undefined, { day: 'numeric', month: 'short' })} ${end.getFullYear()}`;
}

interface DashboardProps {
    corporationName: string;
    visits: VisitListItem[];
    cases: CaseOption[];
    staffOptions: StaffOption[];
}

export default function Dashboard({ corporationName, visits, cases, staffOptions }: DashboardProps) {
    const [weekStart, setWeekStart] = useState(() => startOfWeek(new Date()));
    const [addOpen, setAddOpen] = useState(false);
    const [newVisitInitial, setNewVisitInitial] = useState<{ starts_at: string; ends_at: string } | null>(null);
    const [moving, setMoving] = useState<VisitListItem | null>(null);

    const staffColorIndex = new Map(staffOptions.map((s, i) => [s.id, i]));

    function staffColorClass(staffId: number | null) {
        const index = staffId === null ? undefined : staffColorIndex.get(staffId);
        return index === undefined ? FALLBACK_COLOR : STAFF_COLORS[index % STAFF_COLORS.length];
    }

    function openSlot(start: Date) {
        const end = new Date(start);
        end.setHours(end.getHours() + 1);
        setNewVisitInitial({ starts_at: toInputValueFromDate(start), ends_at: toInputValueFromDate(end) });
        setAddOpen(true);
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Dashboard" />
            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                <h1 className="text-xl font-semibold">{corporationName}</h1>

                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="flex items-center gap-2">
                        <Button variant="outline" size="sm" onClick={() => setWeekStart(addDays(weekStart, -7))}>
                            Previous
                        </Button>
                        <Button variant="outline" size="sm" onClick={() => setWeekStart(startOfWeek(new Date()))}>
                            Today
                        </Button>
                        <Button variant="outline" size="sm" onClick={() => setWeekStart(addDays(weekStart, 7))}>
                            Next
                        </Button>
                        <span className="ml-2 text-sm font-medium">{weekLabel(weekStart)}</span>
                    </div>

                    {staffOptions.length > 1 && (
                        <div className="flex flex-wrap items-center gap-3 text-sm text-neutral-600 dark:text-neutral-400">
                            {staffOptions.map((s) => (
                                <span key={s.id} className="flex items-center gap-1.5">
                                    <span className={`h-3 w-3 rounded-full border ${staffColorClass(s.id)}`} />
                                    {s.name}
                                </span>
                            ))}
                        </div>
                    )}
                </div>

                {cases.length === 0 && (
                    <p className="text-sm text-neutral-600 dark:text-neutral-400">
                        <Link href={route('cases.index')} className="underline">Add a case</Link> to start scheduling visits.
                    </p>
                )}

                <WeekCalendar
                    weekStart={weekStart}
                    visits={visits}
                    staffColorClass={staffColorClass}
                    onVisitClick={setMoving}
                    onSlotClick={openSlot}
                    slotsDisabled={cases.length === 0}
                />
            </div>

            <NewVisitDialog
                open={addOpen}
                onOpenChange={setAddOpen}
                cases={cases}
                staffOptions={staffOptions}
                initial={newVisitInitial}
            />

            <EditVisitDialog visit={moving} onClose={() => setMoving(null)} cases={cases} staffOptions={staffOptions} />
        </AppLayout>
    );
}
