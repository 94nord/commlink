import { layoutDay } from '@/components/schedule/layout';
import { VisitListItem } from '@/components/visits/types';

const HOUR_PX = 48;
const DEFAULT_START_HOUR = 7;
const DEFAULT_END_HOUR = 18;

const pad = (n: number) => String(n).padStart(2, '0');
const timeOf = (d: Date) => `${pad(d.getHours())}:${pad(d.getMinutes())}`;

function addDays(date: Date, days: number) {
    const next = new Date(date);
    next.setDate(next.getDate() + days);
    return next;
}

function sameDay(a: Date, b: Date) {
    return a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate();
}

function dayLabel(d: Date) {
    return d.toLocaleDateString(undefined, { weekday: 'short', day: 'numeric', month: 'short' });
}

// Locale-independent (unlike dayLabel), so tests can predict the slot names.
function dayIso(d: Date) {
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}

interface WeekCalendarProps {
    weekStart: Date; // local Monday 00:00
    visits: VisitListItem[]; // full list — the component filters to the week
    staffColorClass: (staffId: number | null) => string;
    onVisitClick: (visit: VisitListItem) => void;
    onSlotClick: (start: Date) => void;
    slotsDisabled?: boolean;
}

export default function WeekCalendar({
    weekStart,
    visits,
    staffColorClass,
    onVisitClick,
    onSlotClick,
    slotsDisabled = false,
}: WeekCalendarProps) {
    const days = Array.from({ length: 7 }, (_, i) => addDays(weekStart, i));
    const weekEnd = addDays(weekStart, 7);

    const weekVisits = visits.filter((v) => {
        if (!v.startsAt) return false;
        const start = new Date(v.startsAt);
        return start >= weekStart && start < weekEnd;
    });

    // All day/hour math in local time, matching the datetime-local inputs.
    let startHour = DEFAULT_START_HOUR;
    let endHour = DEFAULT_END_HOUR;
    for (const v of weekVisits) {
        const start = new Date(v.startsAt!);
        const end = new Date(v.endsAt!);
        startHour = Math.min(startHour, start.getHours());
        if (sameDay(start, end)) {
            endHour = Math.max(endHour, end.getHours() + (end.getMinutes() > 0 ? 1 : 0));
        } else {
            endHour = 24; // runs past midnight — clipped to its start day below
        }
    }
    const hours = Array.from({ length: endHour - startHour }, (_, i) => startHour + i);

    const today = new Date();

    return (
        <div className="overflow-x-auto rounded-xl border">
            <div className="grid min-w-[700px] grid-cols-[3rem_repeat(7,minmax(0,1fr))]">
                {/* Header row */}
                <div className="border-b" />
                {days.map((day) => (
                    <div
                        key={day.toDateString()}
                        className={`border-b border-l p-2 text-center text-sm font-medium ${
                            sameDay(day, today) ? '' : 'text-neutral-500 dark:text-neutral-400'
                        }`}
                    >
                        {dayLabel(day)}
                    </div>
                ))}

                {/* Time gutter */}
                <div>
                    {hours.map((hour) => (
                        <div
                            key={hour}
                            style={{ height: HOUR_PX }}
                            className="border-t pr-1.5 pt-0.5 text-right text-xs text-neutral-400 first:border-t-0"
                        >
                            {pad(hour)}
                        </div>
                    ))}
                </div>

                {/* Day columns */}
                {days.map((day) => {
                    const dayVisits = layoutDay(weekVisits.filter((v) => sameDay(new Date(v.startsAt!), day)));

                    return (
                        <div key={day.toDateString()} className="relative border-l">
                            {hours.map((hour) => (
                                <button
                                    key={hour}
                                    type="button"
                                    disabled={slotsDisabled}
                                    aria-label={`New visit ${dayIso(day)} ${pad(hour)}:00`}
                                    style={{ height: HOUR_PX }}
                                    className="block w-full border-t first:border-t-0 enabled:hover:bg-neutral-100 dark:enabled:hover:bg-neutral-800"
                                    onClick={() =>
                                        onSlotClick(new Date(day.getFullYear(), day.getMonth(), day.getDate(), hour))
                                    }
                                />
                            ))}

                            {dayVisits.map(({ visit, start, end, lane, laneCount }) => {
                                const startMinutes = start.getHours() * 60 + start.getMinutes();
                                // A visit ending past midnight renders clipped to its start day.
                                const endMinutes = sameDay(start, end) ? end.getHours() * 60 + end.getMinutes() : 24 * 60;
                                const top = ((startMinutes - startHour * 60) / 60) * HOUR_PX;
                                const height = Math.max(((endMinutes - startMinutes) / 60) * HOUR_PX, 20);
                                const width = 100 / laneCount;

                                return (
                                    <button
                                        key={visit.id}
                                        type="button"
                                        aria-label={`Visit ${visit.caseAddress ?? visit.type} ${timeOf(start)} – ${timeOf(end)}`}
                                        style={{ top, height, width: `${width}%`, left: `${lane * width}%` }}
                                        className={`absolute z-10 overflow-hidden rounded-md border px-1.5 py-0.5 text-left text-xs leading-tight ${staffColorClass(visit.staffId)}`}
                                        onClick={() => onVisitClick(visit)}
                                    >
                                        <span className="block font-medium">
                                            {timeOf(start)} – {timeOf(end)}
                                        </span>
                                        {visit.caseAddress && <span className="block truncate">{visit.caseAddress}</span>}
                                        {visit.customerName && (
                                            <span className="block truncate opacity-75">{visit.customerName}</span>
                                        )}
                                    </button>
                                );
                            })}
                        </div>
                    );
                })}
            </div>
        </div>
    );
}
