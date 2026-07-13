export interface RoundOption {
    id: number;
    label: string;
}

export interface VisitListItem {
    id: number;
    type: string;
    staffId: number | null;
    staffName: string | null;
    startsAt: string | null;
    endsAt: string | null;
    cancelledAt: string | null;
    caseId: number | null;
    caseAddress: string | null;
    customerName: string | null;
    roundId: number | null;
}

export interface CaseOption {
    id: number;
    address: string;
    customerName: string;
    rounds: RoundOption[];
}

export interface StaffOption {
    id: number;
    name: string;
}

export const visitTypes = [
    { value: 'survey', label: 'Survey' },
    { value: 'progress', label: 'Progress' },
    { value: 'handover', label: 'Handover' },
    { value: 'warranty', label: 'Warranty' },
];

export function typeLabel(value: string) {
    return visitTypes.find((t) => t.value === value)?.label ?? value;
}
