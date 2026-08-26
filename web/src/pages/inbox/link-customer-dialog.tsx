import { api, ApiError } from '@/api/client';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import type { ConversationSummary, CustomerOption } from '@/pages/inbox/shared';
import { useMutation, useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

/**
 * Puts a name to a number nobody has identified yet.
 *
 * Linking can merge this conversation into one the customer was already
 * having, in which case the surviving conversation has a different id — the
 * caller is told which so it can follow.
 */
export default function LinkCustomerDialog({ conversationId, onLinked }: { conversationId: number; onLinked: (survivingId: number) => void }) {
    const { t } = useTranslation('inbox');
    const [open, setOpen] = useState(false);
    const [filter, setFilter] = useState('');
    const [error, setError] = useState<string | undefined>();

    const { data } = useQuery({
        queryKey: ['customers'],
        queryFn: () => api.get<{ customers: CustomerOption[] }>('/api/customers'),
        enabled: open,
    });

    const customers = (data?.customers ?? []).filter((customer) => customer.name.toLowerCase().includes(filter.trim().toLowerCase()));

    const link = useMutation({
        mutationFn: (customerId: number) =>
            api.post<{ conversation: ConversationSummary }>(`/api/conversations/${conversationId}/link`, { customerId }),
        onSuccess: (result) => {
            setOpen(false);
            setFilter('');
            onLinked(result.conversation.id);
        },
        onError: (err) => {
            if (err instanceof ApiError) setError(err.fieldError('customerId') ?? err.message);
            else throw err;
        },
    });

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                setOpen(next);
                if (!next) {
                    setFilter('');
                    setError(undefined);
                }
            }}
        >
            <DialogTrigger asChild>
                <Button variant="outline" size="sm">
                    {t('link_to_customer')}
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{t('who_is_this')}</DialogTitle>
                </DialogHeader>

                <Input
                    value={filter}
                    onChange={(e) => setFilter(e.target.value)}
                    placeholder={t('search_customers')}
                    aria-label={t('search_customers')}
                    autoFocus
                />

                <InputError message={error} />

                {customers.length === 0 ? (
                    <p className="text-sm text-neutral-600 dark:text-neutral-400">{t('no_customers_match')}</p>
                ) : (
                    <ul className="max-h-64 divide-y overflow-y-auto rounded-md border">
                        {customers.map((customer) => (
                            <li key={customer.id}>
                                <button
                                    type="button"
                                    className="w-full px-3 py-2 text-left text-sm hover:bg-neutral-100 disabled:opacity-50 dark:hover:bg-neutral-800"
                                    disabled={link.isPending}
                                    onClick={() => link.mutate(customer.id)}
                                >
                                    <span className="font-medium">{customer.name}</span>
                                    <span className="block text-neutral-600 dark:text-neutral-400">{customer.email}</span>
                                </button>
                            </li>
                        ))}
                    </ul>
                )}
            </DialogContent>
        </Dialog>
    );
}
