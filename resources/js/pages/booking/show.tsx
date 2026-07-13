import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';

const visitTypes = [
    { value: 'survey', label: 'Survey' },
    { value: 'progress', label: 'Progress' },
    { value: 'handover', label: 'Handover' },
    { value: 'warranty', label: 'Warranty' },
];

function typeLabel(value: string) {
    return visitTypes.find((t) => t.value === value)?.label ?? value;
}

function formatDay(iso: string) {
    return new Date(iso).toLocaleDateString(undefined, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
}

function formatTime(iso: string) {
    return new Date(iso).toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit', hour12: false });
}

function formatBooking(startsAt: string, endsAt: string) {
    return `${formatDay(startsAt)} · ${formatTime(startsAt)} – ${formatTime(endsAt)}`;
}

interface Slot {
    startsAt: string;
    endsAt: string;
}

interface Day {
    date: string;
    slots: Slot[];
}

export default function BookingShow({
    token,
    corporationName,
    address,
    customerName,
    type,
    appointmentMinutes,
    closeOffOn,
    bookable,
    days,
    booking,
}: {
    token: string;
    corporationName: string;
    address: string;
    customerName: string;
    type: string;
    appointmentMinutes: number;
    closeOffOn: string;
    bookable: boolean;
    days: Day[];
    booking: { startsAt: string; endsAt: string } | null;
}) {
    const [selected, setSelected] = useState<string | null>(null);

    const form = useForm({ starts_at: '' });

    function submit() {
        if (!selected) return;
        form.transform((data) => ({ ...data, starts_at: selected }));
        form.post(route('booking.store', token), {
            onSuccess: () => setSelected(null),
        });
    }

    return (
        <div className="min-h-screen bg-neutral-50 dark:bg-neutral-950">
            <Head title={`Book your ${typeLabel(type).toLowerCase()} visit`} />
            <div className="mx-auto max-w-2xl space-y-6 p-6">
                <header className="space-y-1 pt-6">
                    <p className="text-sm text-neutral-600 dark:text-neutral-400">{corporationName}</p>
                    <h1 className="text-2xl font-semibold">
                        {typeLabel(type)} visit · {address}
                    </h1>
                    <p className="text-sm text-neutral-600 dark:text-neutral-400">
                        {customerName} · {appointmentMinutes} minutes
                    </p>
                </header>

                {booking && (
                    <div className="rounded-xl border bg-white p-4 dark:bg-neutral-900">
                        <div className="flex items-center justify-between gap-4">
                            <div>
                                <p className="text-sm text-neutral-600 dark:text-neutral-400">Your booked time</p>
                                <p className="font-medium">{formatBooking(booking.startsAt, booking.endsAt)}</p>
                            </div>
                            <Badge>Booked</Badge>
                        </div>
                        {bookable && (
                            <p className="mt-2 text-sm text-neutral-600 dark:text-neutral-400">
                                Need a different time? Pick a new slot below — you can reschedule until{' '}
                                {new Date(closeOffOn).toLocaleDateString(undefined, { day: 'numeric', month: 'long', year: 'numeric' })}.
                            </p>
                        )}
                    </div>
                )}

                {!bookable ? (
                    <div className="rounded-xl border bg-white p-4 dark:bg-neutral-900">
                        <p className="text-sm text-neutral-600 dark:text-neutral-400">
                            {booking
                                ? 'Booking is closed — your time is locked in. Contact us if something comes up.'
                                : 'Booking is closed for this round. Contact us to arrange a time.'}
                        </p>
                    </div>
                ) : (
                    <>
                        <section className="space-y-4">
                            {days.length === 0 ? (
                                <p className="text-sm text-neutral-600 dark:text-neutral-400">
                                    No times are available right now. Contact us to arrange a time.
                                </p>
                            ) : (
                                days.map((day) => (
                                    <div key={day.date} className="space-y-2">
                                        <h2 className="text-sm font-medium">{formatDay(day.date)}</h2>
                                        <div className="flex flex-wrap gap-2">
                                            {day.slots.map((slot) => (
                                                <Button
                                                    key={slot.startsAt}
                                                    variant={selected === slot.startsAt ? 'default' : 'outline'}
                                                    size="sm"
                                                    onClick={() => setSelected(slot.startsAt)}
                                                >
                                                    {formatTime(slot.startsAt)}
                                                </Button>
                                            ))}
                                        </div>
                                    </div>
                                ))
                            )}
                        </section>

                        <InputError message={form.errors.starts_at} />

                        <div className="flex items-center gap-3 pb-6">
                            <Button onClick={submit} disabled={!selected || form.processing}>
                                {booking ? 'Reschedule' : 'Book'}{selected ? ` ${formatTime(selected)}` : ''}
                            </Button>
                            {selected && (
                                <p className="text-sm text-neutral-600 dark:text-neutral-400">
                                    {formatDay(selected)}
                                </p>
                            )}
                        </div>
                    </>
                )}
            </div>
        </div>
    );
}
