import { api, ApiError } from '@/api/client';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { sniffAddress, type ConversationSummary, type CustomerOption } from '@/pages/inbox/shared';
import { useMutation, useQuery } from '@tanstack/react-query';
import { FormEventHandler, useState } from 'react';
import { useTranslation } from 'react-i18next';

/**
 * Picks who a new conversation is with — and nothing else. Writing the first
 * message happens in the inbox afterwards, in the same box every other reply
 * is written in.
 *
 * Choosing somebody the firm already talks to opens that conversation rather
 * than starting a second one. For a typed number that means asking the
 * server, since the address we hold may be filed under a customer's name and
 * unrecognizable from the list alone.
 */
export default function StartConversationDialog({
    conversations,
    availableAddressTypes,
    onExisting,
    onDraft,
}: {
    conversations: ConversationSummary[];
    /** What the firm can start something new on, per the server. */
    availableAddressTypes: string[];
    onExisting: (conversationId: number) => void;
    onDraft: (draft: { address?: string; customerId?: number; label: string }) => void;
}) {
    const { t } = useTranslation('inbox');
    const [open, setOpen] = useState(false);
    const [recipient, setRecipient] = useState('');
    const [picked, setPicked] = useState<CustomerOption | null>(null);
    const [phone, setPhone] = useState('');
    const [errors, setErrors] = useState<Record<string, string[]>>({});

    const { data } = useQuery({
        queryKey: ['customers'],
        queryFn: () => api.get<{ customers: CustomerOption[] }>('/api/customers'),
        enabled: open,
    });

    // What was typed decides the channel: an address of either kind, or a
    // name to search the customer list with. Only the server's answer is
    // authoritative — this just keeps a typo from becoming a round trip.
    const typedKind = sniffAddress(recipient);
    const looksLikeAddress = typedKind !== null || /^[+0-9][0-9\s@.]*$/.test(recipient.trim());

    const matches =
        picked || recipient.trim() === '' || looksLikeAddress
            ? []
            : (data?.customers ?? []).filter((customer) => customer.name.toLowerCase().includes(recipient.trim().toLowerCase())).slice(0, 6);

    // Reachable if the firm holds an address for them — a fact about the
    // customer, not about whether they happen to be in the inbox yet.
    const pickedIsReachable = (picked?.phones.length ?? 0) > 0;

    function reset() {
        setRecipient('');
        setPicked(null);
        setPhone('');
        setErrors({});
    }

    function close() {
        setOpen(false);
        reset();
    }

    /** Ask the server whether we already know this address. */
    const resolve = useMutation({
        mutationFn: (value: string) =>
            api.get<{ address: string; addressType: string; conversation: ConversationSummary | null }>(
                `/api/conversations/resolve?address=${encodeURIComponent(value)}`,
            ),
        onError: (err) => {
            if (err instanceof ApiError) setErrors(err.errors);
            else throw err;
        },
    });

    const submit: FormEventHandler = async (e) => {
        e.preventDefault();
        setErrors({});

        // A known customer we already have a number for: just open it.
        if (picked && pickedIsReachable) {
            const existing = conversations.find((conversation) => conversation.customerId === picked.id);
            if (existing) {
                close();
                onExisting(existing.id);
            }

            return;
        }

        const typed = picked ? phone : recipient;

        if (typed.trim() === '') {
            setErrors({ address: [t('give_an_address')] });

            return;
        }

        // Neither a number nor a mailbox is a typo, and saying so here means
        // the person fixes it without a round trip.
        if (sniffAddress(typed) === null) {
            setErrors({ address: [t('not_an_address')] });

            return;
        }

        const result = await resolve.mutateAsync(typed).catch(() => null);
        if (!result) return;

        if (result.conversation && !picked) {
            // Already talking to this number — continue there.
            close();
            onExisting(result.conversation.id);

            return;
        }

        close();
        onDraft({
            address: result.address,
            customerId: picked?.id,
            label: picked ? picked.name : result.address,
        });
    };

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                setOpen(next);
                if (!next) reset();
            }}
        >
            <DialogTrigger asChild>
                <Button size="sm">{t('start_conversation')}</Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{t('start_conversation_title')}</DialogTitle>
                </DialogHeader>

                {/* No channel switched on and provisioned means there is
                    nothing to write on. Saying so here beats taking a whole
                    message and failing at the send. */}
                {availableAddressTypes.length === 0 ? (
                    <div className="space-y-4">
                        <p className="text-sm text-neutral-600 dark:text-neutral-400">{t('no_channels_available')}</p>
                        <div className="flex justify-end">
                            <Button type="button" variant="outline" onClick={close}>
                                {t('cancel', { ns: 'common' })}
                            </Button>
                        </div>
                    </div>
                ) : (
                    <form onSubmit={submit} className="space-y-4">
                        <div className="grid gap-2">
                            <Label htmlFor="start-to">{t('to_label')}</Label>
                            <Input
                                id="start-to"
                                value={recipient}
                                onChange={(e) => {
                                    setRecipient(e.target.value);
                                    setPicked(null);
                                }}
                                placeholder={t('recipient_placeholder')}
                                autoFocus
                                autoComplete="off"
                            />
                            <InputError message={errors.address?.[0] ?? errors.customerId?.[0]} />

                            {matches.length > 0 && (
                                <ul className="divide-y rounded-md border">
                                    {matches.map((customer) => (
                                        <li key={customer.id}>
                                            <button
                                                type="button"
                                                className="w-full px-3 py-2 text-left text-sm hover:bg-neutral-100 dark:hover:bg-neutral-800"
                                                onClick={() => {
                                                    setPicked(customer);
                                                    setRecipient(customer.name);
                                                }}
                                            >
                                                <span className="font-medium">{customer.name}</span>
                                                <span className="block text-neutral-600 dark:text-neutral-400">{customer.email}</span>
                                            </button>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </div>

                        {picked && !pickedIsReachable && (
                            <div className="grid gap-2">
                                <Label htmlFor="start-phone">
                                    {t('address_label')} <span className="text-neutral-400">{t('no_number_for_yet', { name: picked.name })}</span>
                                </Label>
                                <Input
                                    id="start-phone"
                                    value={phone}
                                    onChange={(e) => setPhone(e.target.value)}
                                    placeholder={t('address_placeholder')}
                                    autoComplete="off"
                                />
                            </div>
                        )}

                        <div className="flex justify-end gap-3 pt-2">
                            <Button type="button" variant="outline" onClick={close}>
                                {t('cancel', { ns: 'common' })}
                            </Button>
                            <Button disabled={resolve.isPending}>{t('start_conversation')}</Button>
                        </div>
                    </form>
                )}
            </DialogContent>
        </Dialog>
    );
}
