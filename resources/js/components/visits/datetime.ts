export function formatRange(startsAt: string | null, endsAt: string | null) {
    if (!startsAt || !endsAt) return null;
    const start = new Date(startsAt);
    const end = new Date(endsAt);
    const date = start.toLocaleDateString(undefined, { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' });
    const time = (d: Date) => d.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit', hour12: false });
    return `${date} · ${time(start)} – ${time(end)}`;
}

// datetime-local values must be built from local Date parts — an ISO slice
// would shift the wall-clock time by the UTC offset.
export function toInputValueFromDate(d: Date) {
    const pad = (n: number) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

export function toInputValue(iso: string | null) {
    if (!iso) return '';
    return toInputValueFromDate(new Date(iso));
}

export function toIso(local: string) {
    return local ? new Date(local).toISOString() : null;
}
