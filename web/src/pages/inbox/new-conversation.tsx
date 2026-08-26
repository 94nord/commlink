import { api, ApiError } from '@/api/client';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import Composer, { type ComposerHandle } from '@/pages/inbox/composer';
import type { ConversationSummary } from '@/pages/inbox/shared';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { ArrowLeft } from 'lucide-react';
import { FormEventHandler, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';

/**
 * A conversation that does not exist yet.
 *
 * It looks and behaves like any other open conversation — same pane, same
 * box at the bottom — but nothing has been written to the database. That is
 * deliberate: a conversation only becomes real when a message actually goes
 * out, so abandoning a draft cannot leave empty rows cluttering the queue,
 * and a send that fails leaves nothing behind either.
 */
export default function NewConversationPane() {
    const { t } = useTranslation('inbox');
    const [params] = useSearchParams();
    const navigate = useNavigate();
    const queryClient = useQueryClient();
    const [isEmpty, setIsEmpty] = useState(true);
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const composerRef = useRef<ComposerHandle>(null);

    const address = params.get('address') ?? undefined;
    const customerId = params.get('customerId') ? Number(params.get('customerId')) : undefined;
    const label = params.get('label') ?? address ?? t('new_conversation_label');

    const send = useMutation({
        mutationFn: () =>
            api.post<{ conversation: ConversationSummary }>('/api/conversations', {
                // Which channel this goes out on follows from the address,
                // and the server decides that — nothing here needs to know.
                // Plain text either way until a channel that carries
                // formatting can actually send.
                body: composerRef.current!.getText(),
                ...(address ? { address } : {}),
                ...(customerId ? { customerId } : {}),
            }),
        onSuccess: (result) => {
            queryClient.invalidateQueries({ queryKey: ['conversations'] });
            navigate(`/inbox/${result.conversation.id}`, { replace: true });
        },
        onError: (err) => {
            if (err instanceof ApiError) setErrors(err.errors);
            else throw err;
        },
    });

    const trySend = () => {
        if (!isEmpty) send.mutate();
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        trySend();
    };

    return (
        <div className="flex min-h-0 flex-1 flex-col">
            <header className="flex items-center gap-3 border-b p-4">
                <Button variant="ghost" size="icon" className="md:hidden" asChild>
                    <Link to="/inbox">
                        <ArrowLeft className="h-4 w-4" />
                        <span className="sr-only">{t('back_to_inbox')}</span>
                    </Link>
                </Button>

                <div className="min-w-0 flex-1">
                    <h2 className="truncate font-semibold">{label}</h2>
                    <p className="truncate text-sm text-neutral-600 dark:text-neutral-400">{address}</p>
                </div>
            </header>

            <div className="flex min-h-0 flex-1 items-center justify-center p-4">
                <p className="text-sm text-neutral-600 dark:text-neutral-400">{t('draft_empty')}</p>
            </div>

            <form onSubmit={submit} className="border-t p-4">
                <Composer
                    ref={composerRef}
                    placeholder={t('first_message_placeholder')}
                    ariaLabel={t('reply_aria')}
                    canFormat={false}
                    autoFocus
                    onSubmit={trySend}
                    onEmptyChange={setIsEmpty}
                />
                <InputError message={errors.body?.[0] ?? errors.address?.[0] ?? errors.customerId?.[0]} className="mt-2" />
                <div className="mt-2 flex justify-end">
                    <Button disabled={send.isPending || isEmpty}>{t('send')}</Button>
                </div>
            </form>
        </div>
    );
}
