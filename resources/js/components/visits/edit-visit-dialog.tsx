import ConfirmDialog from '@/components/confirm-dialog';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { toInputValue, toIso } from '@/components/visits/datetime';
import { CaseOption, StaffOption, VisitListItem } from '@/components/visits/types';
import { router, useForm } from '@inertiajs/react';
import { FormEventHandler, useEffect, useState } from 'react';

interface EditVisitDialogProps {
    visit: VisitListItem | null;
    onClose: () => void;
    cases: CaseOption[];
    staffOptions: StaffOption[];
}

export default function EditVisitDialog({ visit, onClose, cases, staffOptions }: EditVisitDialogProps) {
    const form = useForm({ staff_id: '', starts_at: '', ends_at: '', round_id: '' });
    const [confirmingCancel, setConfirmingCancel] = useState(false);
    const [cancelling, setCancelling] = useState(false);

    // The dialog stays mounted, so re-seed the form from each newly opened visit.
    useEffect(() => {
        if (visit) {
            form.setData({
                staff_id: visit.staffId ? String(visit.staffId) : '',
                starts_at: toInputValue(visit.startsAt),
                ends_at: toInputValue(visit.endsAt),
                round_id: visit.roundId ? String(visit.roundId) : '',
            });
            form.clearErrors();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [visit?.id]);

    const roundOptions = cases.find((c) => c.id === visit?.caseId)?.rounds ?? [];

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        if (!visit) return;
        form.transform((data) => ({
            ...data,
            starts_at: toIso(data.starts_at),
            ends_at: toIso(data.ends_at),
            round_id: data.round_id || null,
        }));
        form.patch(route('visits.update', visit.id), {
            onSuccess: () => onClose(),
        });
    };

    const confirmCancel = () => {
        if (!visit) return;
        setCancelling(true);
        router.post(route('visits.cancel', visit.id), {}, {
            onSuccess: () => {
                setConfirmingCancel(false);
                onClose();
            },
            onFinish: () => setCancelling(false),
        });
    };

    return (
        <>
            <Dialog open={visit !== null} onOpenChange={(open) => { if (!open) onClose(); }}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Edit visit</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={submit} className="space-y-4">
                        <div className="grid gap-2">
                            <Label htmlFor="edit-staff">Staff</Label>
                            <Select value={form.data.staff_id} onValueChange={(v) => form.setData('staff_id', v)}>
                                <SelectTrigger id="edit-staff">
                                    <SelectValue placeholder="Select staff…" />
                                </SelectTrigger>
                                <SelectContent>
                                    {staffOptions.map((s) => (
                                        <SelectItem key={s.id} value={String(s.id)}>{s.name}</SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={form.errors.staff_id} />
                        </div>

                        <div className="grid grid-cols-2 gap-4">
                            <div className="grid gap-2">
                                <Label htmlFor="edit-starts">From</Label>
                                <Input
                                    id="edit-starts"
                                    type="datetime-local"
                                    value={form.data.starts_at}
                                    onChange={(e) => form.setData('starts_at', e.target.value)}
                                />
                                <InputError message={form.errors.starts_at} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="edit-ends">To</Label>
                                <Input
                                    id="edit-ends"
                                    type="datetime-local"
                                    value={form.data.ends_at}
                                    onChange={(e) => form.setData('ends_at', e.target.value)}
                                />
                                <InputError message={form.errors.ends_at} />
                            </div>
                        </div>

                        {roundOptions.length > 0 && (
                            <div className="grid gap-2">
                                <Label htmlFor="edit-round">Round <span className="text-neutral-400">(optional)</span></Label>
                                <Select
                                    value={form.data.round_id}
                                    onValueChange={(v) => form.setData('round_id', v === 'none' ? '' : v)}
                                >
                                    <SelectTrigger id="edit-round">
                                        <SelectValue placeholder="No round" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="none">No round</SelectItem>
                                        {roundOptions.map((r) => (
                                            <SelectItem key={r.id} value={String(r.id)}>{r.label}</SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <InputError message={form.errors.round_id} />
                            </div>
                        )}

                        <div className="flex items-center justify-between gap-3 pt-2">
                            <Button
                                type="button"
                                variant="ghost"
                                className="text-neutral-500 hover:text-red-600"
                                onClick={() => setConfirmingCancel(true)}
                            >
                                Cancel visit
                            </Button>
                            <div className="flex gap-3">
                                <Button type="button" variant="outline" onClick={onClose}>
                                    Cancel
                                </Button>
                                <Button disabled={form.processing}>Save</Button>
                            </div>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>

            <ConfirmDialog
                open={confirmingCancel}
                onOpenChange={setConfirmingCancel}
                title="Cancel visit?"
                description="The appointment is called off and its time is freed. The visit stays visible as history."
                confirmLabel="Yes, cancel it"
                onConfirm={confirmCancel}
                processing={cancelling}
            />
        </>
    );
}
