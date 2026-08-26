import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { toIso } from '@/components/visits/datetime';
import { CaseOption, StaffOption, visitTypes } from '@/components/visits/types';
import { useForm } from '@inertiajs/react';
import { FormEventHandler, useEffect } from 'react';

interface NewVisitDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    cases: CaseOption[];
    staffOptions: StaffOption[];
    // datetime-local strings to prefill the time fields (e.g. a clicked slot)
    initial?: { starts_at: string; ends_at: string } | null;
}

export default function NewVisitDialog({ open, onOpenChange, cases, staffOptions, initial }: NewVisitDialogProps) {
    const form = useForm({ work_order_id: '', type: '', staff_id: '', starts_at: '', ends_at: '', round_id: '' });

    // The dialog stays mounted, so reset on every open — otherwise a closed
    // dialog reopens with the previous attempt's fields and errors.
    useEffect(() => {
        if (open) {
            form.setData({
                work_order_id: '',
                type: '',
                staff_id: '',
                starts_at: initial?.starts_at ?? '',
                ends_at: initial?.ends_at ?? '',
                round_id: '',
            });
            form.clearErrors();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    // Round options follow the picked case: linking requires membership.
    const roundOptions = cases.find((c) => String(c.id) === form.data.work_order_id)?.rounds ?? [];

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.transform((data) => ({
            ...data,
            starts_at: toIso(data.starts_at),
            ends_at: toIso(data.ends_at),
            round_id: data.round_id || null,
        }));
        form.post(route('visits.store'), {
            onSuccess: () => {
                form.reset();
                onOpenChange(false);
            },
        });
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>New visit</DialogTitle>
                </DialogHeader>
                <form onSubmit={submit} className="space-y-4">
                    <div className="grid gap-2">
                        <Label htmlFor="work_order_id">Case</Label>
                        <Select
                            value={form.data.work_order_id}
                            onValueChange={(v) => form.setData({ ...form.data, work_order_id: v, round_id: '' })}
                        >
                            <SelectTrigger id="work_order_id">
                                <SelectValue placeholder="Select a case…" />
                            </SelectTrigger>
                            <SelectContent>
                                {cases.map((c) => (
                                    <SelectItem key={c.id} value={String(c.id)}>
                                        {c.address} — {c.customerName}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={form.errors.work_order_id} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="add-type">Type</Label>
                        <Select value={form.data.type} onValueChange={(v) => form.setData('type', v)}>
                            <SelectTrigger id="add-type">
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

                    <div className="grid gap-2">
                        <Label htmlFor="add-staff">Staff</Label>
                        <Select value={form.data.staff_id} onValueChange={(v) => form.setData('staff_id', v)}>
                            <SelectTrigger id="add-staff">
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
                            <Label htmlFor="add-starts">From</Label>
                            <Input
                                id="add-starts"
                                type="datetime-local"
                                value={form.data.starts_at}
                                onChange={(e) => form.setData('starts_at', e.target.value)}
                            />
                            <InputError message={form.errors.starts_at} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="add-ends">To</Label>
                            <Input
                                id="add-ends"
                                type="datetime-local"
                                value={form.data.ends_at}
                                onChange={(e) => form.setData('ends_at', e.target.value)}
                            />
                            <InputError message={form.errors.ends_at} />
                        </div>
                    </div>

                    {roundOptions.length > 0 && (
                        <div className="grid gap-2">
                            <Label htmlFor="add-round">Round <span className="text-neutral-400">(optional)</span></Label>
                            <Select
                                value={form.data.round_id}
                                onValueChange={(v) => form.setData('round_id', v === 'none' ? '' : v)}
                            >
                                <SelectTrigger id="add-round">
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

                    <div className="flex justify-end gap-3 pt-2">
                        <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
                            Cancel
                        </Button>
                        <Button disabled={form.processing}>Create visit</Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}
