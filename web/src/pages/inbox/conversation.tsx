import { api, ApiError } from '@/api/client';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';
import AttachmentView from '@/pages/inbox/attachment';
import Composer, { type ComposerHandle } from '@/pages/inbox/composer';
import LinkCustomerDialog from '@/pages/inbox/link-customer-dialog';
import { formatExact, formatTimestamp, POLL_MS, type ConversationDetail, type ConversationSummary, type MessageItem } from '@/pages/inbox/shared';
import UploadLinkDialog from '@/pages/inbox/upload-link-dialog';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowLeft, Mail, MessageSquare } from 'lucide-react';
import { FormEventHandler, useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useNavigate } from 'react-router-dom';

type ConversationQueryKey = readonly ['conversations', number, number | null, number | null, number | null];

/**
 * One conversation: everyone the customer has written from, merged into a
 * single timeline, with the reply box underneath. Which address a reply
 * actually leaves on is the server's decision, not something shown here.
 */
export default function ConversationPane({ id, initialDisplayName }: { id: number; initialDisplayName: string | null }) {
    const { t } = useTranslation('inbox');
    const queryClient = useQueryClient();
    const navigate = useNavigate();
    const [isEmpty, setIsEmpty] = useState(true);
    const [resetKey, setResetKey] = useState(0);
    const [error, setError] = useState<string | undefined>();
    const [before, setBefore] = useState<number | null>(null);
    // What is being viewed, in two levels: an address, then one exchange on
    // it. `null` at either level means "everything below here".
    const [identity, setIdentity] = useState<number | null>(null);
    const [thread, setThread] = useState<number | null>(null);
    // Which exchange a reply goes into, while the view has not decided:
    // 'new' starts one, which is the default when no thread is open.
    const [target, setTarget] = useState<number | 'new' | null>(null);
    // Which channel to send on. Null until somebody says, then derived from
    // wherever the customer last wrote.
    const [sendType, setSendType] = useState<string | null>(null);
    const [subject, setSubject] = useState('');
    const scrollRef = useRef<HTMLDivElement>(null);
    const composerRef = useRef<ComposerHandle>(null);
    // Whether the reader is at the live end of the conversation. Only then
    // does new traffic pull the view down — reading older messages should
    // not be yanked away by an arriving reply.
    const atLiveEnd = useRef(true);
    // Which conversation we have already confirmed as read with the server.
    const confirmedRead = useRef<number | null>(null);

    const { data, isPlaceholderData } = useQuery({
        queryKey: ['conversations', id, before, identity, thread],
        queryFn: () => {
            const query = new URLSearchParams();
            if (before) query.set('before', String(before));
            if (thread) query.set('thread', String(thread));
            else if (identity) query.set('identity', String(identity));
            const suffix = query.size > 0 ? `?${query}` : '';

            return api.get<{ conversation: ConversationDetail }>(`/api/conversations/${id}${suffix}`);
        },
        // Narrowing the view changes the query key. Loading further back
        // (`before` changed, same address/exchange) keeps the old page
        // as-is so the timeline doesn't blank while older messages load in.
        // Switching which address or exchange is open is different: the
        // header-level fields (identities, threads — the server always
        // returns them in full regardless of the filter) still carry over
        // so those don't flash, but the message list resets to empty so the
        // skeleton takes over instead of the *previous* selection's
        // messages sitting under the newly-selected tab.
        placeholderData: (previousData, previousQuery) => {
            if (!previousData) return undefined;

            const prevKey = previousQuery?.queryKey as ConversationQueryKey | undefined;
            if (!prevKey || prevKey[1] !== id) return undefined;

            const [, , , prevIdentity, prevThread] = prevKey;
            if (prevIdentity === identity && prevThread === thread) return previousData;

            return { conversation: { ...previousData.conversation, messages: [], hasMore: false } };
        },
        refetchInterval: before ? false : POLL_MS,
    });

    const conversation = data?.conversation;
    const messages = conversation?.messages ?? [];
    // Placeholder data with no messages in it is the synthesized "switched
    // address/exchange" case above, not a real empty page — that's what
    // should show a skeleton rather than "nothing here yet".
    const messagesLoading = isPlaceholderData && messages.length === 0;

    function scrollToBottom() {
        const el = scrollRef.current;
        if (el) el.scrollTop = el.scrollHeight;
    }

    // A picture only takes up room once it has loaded, which happens after
    // the render that placed it. Without this the timeline stays short by
    // the height of every image in it, leaving the newest messages just
    // below the fold — looking, to the reader, like nothing arrived.
    function pinToBottom() {
        if (atLiveEnd.current) scrollToBottom();
    }

    // Opening a conversation reads it. Say so locally at once, so the queue
    // never claims unread messages you are looking at.
    useEffect(() => {
        queryClient.setQueryData(
            ['conversations'],
            (old?: { conversations: ConversationSummary[] }) =>
                old && {
                    conversations: old.conversations.map((item) => (item.id === id ? { ...item, unreadCount: 0 } : item)),
                },
        );
    }, [id, queryClient]);

    // …then make it stick. A list poll already in flight when the
    // conversation opened was answered before the server recorded the read,
    // so it lands afterwards and puts the count back — invisible while this
    // conversation is selected, but there again the moment you click away.
    // Fetching the request that loaded this pane is what marked it read, so
    // once it has answered the list can be trusted again.
    useEffect(() => {
        if (conversation === undefined || confirmedRead.current === id) return;

        confirmedRead.current = id;
        queryClient.invalidateQueries({ queryKey: ['conversations'], exact: true });
    }, [conversation, id, queryClient]);

    // Opening a conversation starts at the newest message, like every other
    // messaging app.
    useEffect(() => {
        setBefore(null);
        setIsEmpty(true);
        setError(undefined);
        atLiveEnd.current = true;
        scrollToBottom();
    }, [id]);

    // A thread id belongs to one conversation; carrying it to the next would
    // select nothing and 404.
    useEffect(() => {
        setIdentity(null);
        setThread(null);
        setBefore(null);
        setTarget(null);
        setSendType(null);
        setSubject('');
    }, [id]);

    // Follow the conversation as it grows, but only for a reader who is
    // already at the bottom. Deliberately every render rather than only on a
    // new message: the pane's height also changes when the composer clears
    // or an error appears, and staying pinned means re-pinning afterwards.
    useEffect(() => {
        if (atLiveEnd.current) scrollToBottom();
    });

    // Whether anything can still be sent here. The server has already
    // weighed the module, the firm's own address and the history behind this
    // person; all that is left to do is read its answer. An address that is
    // only read-only keeps its history on screen with no way to reply.
    const canReply = conversation?.identities.some((identity) => identity.status === 'active') ?? false;
    const isReadOnly = !!conversation && !canReply;

    const identities = conversation?.identities ?? [];
    const threads = conversation?.threads ?? [];
    const threadsById = new Map(threads.map((item) => [item.id, item]));

    // The address being viewed, and the exchanges on it. An address is the
    // only place threads mean anything: a number is one unbroken exchange, a
    // mailbox carries several unrelated ones.
    // One address means there is nothing to choose, so it counts as chosen —
    // otherwise the tabs stay hidden, nothing is selected, and a customer
    // reachable only by email never sees their exchanges at all.
    const viewedIdentityId = identity ?? (identities.length === 1 ? identities[0].id : null);
    const selectedIdentity = identities.find((item) => item.id === viewedIdentityId) ?? null;
    const identityThreads = threads.filter((item) => item.identityId === viewedIdentityId);

    // Capabilities come from the address itself, so an address nobody has
    // written to yet still knows it threads and still offers a subject.
    const channelOf = (identityId: number | null) => identities.find((item) => item.id === identityId) ?? null;
    const threadsByKey = selectedIdentity?.threadsByKey ?? false;

    // Which channel a reply goes out on. Defaults to wherever the customer
    // last wrote — the list is sorted by activity — until somebody says
    // otherwise with the buttons under the composer.
    // A fixed order, not whichever address happens to be first on the
    // record — the same two buttons swapping places between conversations
    // makes people misread which one is selected.
    const CHANNEL_ORDER = ['phone', 'email'];
    const sendableTypes = [...new Set(identities.filter((item) => item.status === 'active').map((item) => item.addressType))].sort(
        (a, b) => CHANNEL_ORDER.indexOf(a) - CHANNEL_ORDER.indexOf(b),
    );
    const effectiveType = selectedIdentity?.addressType ?? sendType ?? threads[0]?.addressType ?? sendableTypes[0] ?? null;

    // Which of that kind. The address chosen above wins; otherwise the one
    // the customer last wrote from, which is where they expect an answer —
    // not merely the first of its type on the record.
    const lastActive = identities.find((item) => item.id === threads[0]?.identityId) ?? null;
    const sendIdentity =
        selectedIdentity?.addressType === effectiveType
            ? selectedIdentity
            : lastActive?.addressType === effectiveType && lastActive.status === 'active'
              ? lastActive
              : (identities.find((item) => item.addressType === effectiveType && item.status === 'active') ?? null);
    const sendThreads = threads.filter((item) => item.identityId === sendIdentity?.id);
    const sendChannel = channelOf(sendIdentity?.id ?? null);
    const sendThreadsByKey = sendChannel?.threadsByKey ?? false;

    // Where the reply lands. A thread open above decides it outright;
    // otherwise the selector below does — and while it has not said
    // anything either, replying goes to whichever exchange on this address
    // is most recently active, never silently to a new one. A new exchange
    // is only what an untouched selector defaults to when there is nothing
    // yet to reply to.
    const latestSendThread = sendThreads[0] ?? null;
    const lockedToThread = thread !== null && sendIdentity?.id === viewedIdentityId;
    const targetedId = lockedToThread
        ? thread
        : target === 'new'
          ? null
          : target !== null
            ? target
            : sendThreadsByKey
              ? (latestSendThread?.id ?? null)
              : null;
    const startingNew = !lockedToThread && sendThreadsByKey && (target === 'new' || (target === null && latestSendThread === null));

    // Formatting follows the channel being written on, never the
    // conversation as a whole — which may span two. Asking whether *any*
    // identity was non-phone is what put `<p>` tags into SMS bodies.
    const canFormat = sendChannel?.supportsRichText ?? false;

    // The bottom bar is a choice only while the view has not made one.
    const showsThreadSelector = sendThreadsByKey && !isReadOnly;
    const showsChannelButtons = sendableTypes.length > 1 && !isReadOnly;
    const receivers = identities.filter((item) => item.addressType === effectiveType && item.status === 'active');
    const showsReceiver = receivers.length > 1;

    // A nudge, not a block: picking the channel this person has never
    // actually written back on is easy to do without noticing, especially
    // once a conversation has drifted to "reply on whatever's selected".
    // Only worth saying when they *have* replied somewhere — otherwise
    // every brand-new conversation would open with a warning already on it.
    const repliedTypes = new Set(identities.filter((item) => item.hasReplied).map((item) => item.addressType));
    const showsNoReplyWarning =
        showsChannelButtons && effectiveType !== null && !repliedTypes.has(effectiveType) && sendableTypes.some((type) => repliedTypes.has(type));

    // A specific address chosen above settles both which channel and which
    // address a reply uses — offering a second answer down here would let
    // the two disagree about the same message. Only the exchange within it
    // is still open, and only while no thread is open either.
    const addressLocked = identity !== null;

    const reply = useMutation({
        mutationFn: () => {
            const composer = composerRef.current!;

            return api.post(`/api/conversations/${id}/messages`, {
                // Always the plain form; the server derives the canonical
                // text from the HTML when there is any, so these can never
                // drift apart.
                body: composer.getText(),
                ...(canFormat ? { bodyHtml: composer.getHTML() } : {}),
                ...(startingNew ? { newThread: true, subject } : targetedId ? { threadId: targetedId } : {}),
                // A new exchange is created on the address being viewed, not
                // on whichever one the customer last used.
                ...(startingNew && sendIdentity ? { identityId: sendIdentity.id } : {}),
            });
        },
        onSuccess: () => {
            setIsEmpty(true);
            setResetKey((key) => key + 1);
            setError(undefined);
            setTarget(null);
            setSubject('');

            // Your own message always brings you back to the bottom.
            atLiveEnd.current = true;
            queryClient.invalidateQueries({ queryKey: ['conversations'] });
        },
        onError: (err) => {
            if (err instanceof ApiError) setError(err.fieldError('body') ?? err.fieldError('subject') ?? err.message);
            else throw err;
        },
    });

    // Picking an address drops any exchange chosen inside the previous one,
    // and abandons a half-named new one — both belonged to where you were.
    function selectIdentity(next: number | null) {
        setIdentity(next);
        setThread(null);
        setBefore(null);
        setTarget(null);
        setSubject('');

        // Picking an address decides the channel below too. Going back to
        // "all channels" decides nothing — it is a widening of the view, not
        // a statement about where to write — so whatever was chosen stays.
        if (next !== null) {
            setSendType(identities.find((item) => item.id === next)?.addressType ?? null);
        }
    }

    function selectThread(next: number | null) {
        setThread(next);
        setBefore(null);
        setTarget(null);
        setSubject('');
    }

    // Choosing a channel changes only where the reply goes. The view above
    // stays where it was: reading the whole person while answering on one
    // channel is a real thing to want, and yanking the timeline out from
    // under somebody mid-sentence is not.
    //
    // The influence runs the other way — picking an address above selects it
    // here, which is what selectIdentity() does.
    function selectSendType(type: string) {
        setSendType(type);
        setTarget(null);
        setSubject('');
    }

    const trySend = () => {
        // A new exchange needs a name before it can go anywhere.
        if (isEmpty || (startingNew && subject.trim() === '')) return;

        reply.mutate();
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
                    {conversation?.displayName ?? initialDisplayName ? (
                        <h2 className="truncate font-semibold">{conversation?.displayName ?? initialDisplayName}</h2>
                    ) : (
                        <Skeleton className="h-5 w-32" />
                    )}
                    {conversation ? (
                        <p className="truncate text-sm">
                            {identities.map((identity, index) => (
                                <span key={identity.id}>
                                    {index > 0 && <span className="text-neutral-600 dark:text-neutral-400"> · </span>}
                                    <span
                                        className={cn(
                                            identity.status === 'active'
                                                ? 'text-neutral-600 dark:text-neutral-400'
                                                : 'text-neutral-400 dark:text-neutral-600',
                                        )}
                                        title={identity.status === 'active' ? undefined : t('channel_read_only')}
                                    >
                                        {identity.value}
                                    </span>
                                    {/* Where the new traffic actually came in —
                                        the only thing worth saying when somebody
                                        is reachable two ways. */}
                                    {identity.unreadCount > 0 && (
                                        <Badge className="ml-1" aria-label={t('unread_on_address', { count: identity.unreadCount })}>
                                            {identity.unreadCount}
                                        </Badge>
                                    )}
                                </span>
                            ))}
                        </p>
                    ) : (
                        <Skeleton className="mt-1 h-4 w-40" />
                    )}
                </div>

                {conversation &&
                    (conversation.isAnonymous ? (
                        <LinkCustomerDialog
                            conversationId={conversation.id}
                            onLinked={(survivingId) => {
                                queryClient.invalidateQueries({ queryKey: ['conversations'] });
                                if (survivingId !== conversation.id) navigate(`/inbox/${survivingId}`);
                            }}
                        />
                    ) : (
                        <Badge variant="secondary">{t('customer_badge')}</Badge>
                    ))}
            </header>

            {/* Level one: which address. Everything below is scoped to it,
                because threads only mean something under one address — a
                number is one unbroken exchange, a mailbox carries several. */}
            {identities.length > 1 && (
                <div className="flex items-center gap-2 overflow-x-auto border-b px-4 py-2" role="group" aria-label={t('addresses_label')}>
                    <Button variant={identity === null ? 'default' : 'outline'} size="sm" onClick={() => selectIdentity(null)}>
                        {t('all_addresses')}
                    </Button>
                    {identities.map((item) => (
                        <Button
                            key={item.id}
                            variant={identity === item.id ? 'default' : 'outline'}
                            size="sm"
                            className="shrink-0 gap-1.5"
                            onClick={() => selectIdentity(item.id)}
                            title={item.status === 'active' ? undefined : t('channel_read_only')}
                        >
                            {item.addressType === 'email' ? (
                                <Mail className="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                            ) : (
                                <MessageSquare className="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                            )}
                            {item.value}
                            {item.unreadCount > 0 && <span className="ml-1 text-xs opacity-70">{item.unreadCount}</span>}
                        </Button>
                    ))}
                </div>
            )}

            {/* Level two: which exchange on that address. Only channels that
                thread have anything to offer here. */}
            {threadsByKey && identityThreads.length > 0 && (
                <div className="flex items-center gap-2 overflow-x-auto border-b px-4 py-2" role="group" aria-label={t('threads_label')}>
                    <Button variant={thread === null ? 'default' : 'outline'} size="sm" onClick={() => selectThread(null)}>
                        {t('all_threads')}
                    </Button>
                    {identityThreads.map((item) => (
                        <Button
                            key={item.id}
                            variant={thread === item.id ? 'default' : 'outline'}
                            size="sm"
                            className="shrink-0"
                            onClick={() => selectThread(item.id)}
                        >
                            {item.subject ?? t('untitled_thread')}
                            <span className="ml-1 text-xs opacity-70">{formatTimestamp(item.lastActivityAt)}</span>
                        </Button>
                    ))}
                </div>
            )}

            {/* What the open exchange is called. A title only — starting a
                new one belongs next to the composer, with the rest of the
                addressing. */}
            {threadsByKey && (
                <div className="flex items-center gap-2 border-b px-4 py-2">
                    <h3 className="min-w-0 flex-1 truncate text-sm font-medium">
                        {thread
                            ? (identityThreads.find((item) => item.id === thread)?.subject ?? t('untitled_thread'))
                            : t('all_threads_on', { address: selectedIdentity?.value ?? '' })}
                    </h3>
                </div>
            )}

            <div
                ref={scrollRef}
                data-slot="timeline"
                onScroll={(e) => {
                    const el = e.currentTarget;
                    atLiveEnd.current = el.scrollHeight - el.scrollTop - el.clientHeight < 120;
                }}
                className="min-h-0 flex-1 overflow-y-auto p-4"
            >
                {conversation?.hasMore && (
                    <div className="mb-4 flex justify-center">
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() => {
                                atLiveEnd.current = false;
                                setBefore(messages[0]?.id ?? null);
                            }}
                        >
                            {t('load_earlier')}
                        </Button>
                    </div>
                )}

                {!conversation || messagesLoading ? (
                    <MessageListSkeleton />
                ) : messages.length === 0 ? (
                    <p className="text-sm text-neutral-600 dark:text-neutral-400">{t('nothing_here_yet')}</p>
                ) : (
                    <ul className="space-y-3">
                        {messages.map((message, index) => {
                            // Which exchange each message belongs to is only
                            // ambiguous while several are shown merged — a
                            // single thread already names itself in the
                            // header above. SMS never sets a subject, so it
                            // never carries one here either. Shown only on
                            // the message that started the thread, not
                            // repeated on every reply after it.
                            const previous = messages[index - 1];
                            const startsNewThread = thread === null && message.threadId !== previous?.threadId;
                            const subject = startsNewThread ? (threadsById.get(message.threadId)?.subject ?? null) : null;

                            return <Bubble key={message.id} message={message} subject={subject} onMediaLoad={pinToBottom} />;
                        })}
                    </ul>
                )}
            </div>

            {isReadOnly ? (
                /* Frozen, not gone: the timeline above stays exactly as it
                   was, and the box that would answer it is replaced by the
                   reason it cannot. */
                <div className="border-t p-4">
                    <p className="text-sm text-neutral-600 dark:text-neutral-400">{t('channel_read_only')}</p>
                </div>
            ) : (
                <form onSubmit={submit} className="border-t p-4">
                    <Composer
                        // canFormat is in the key too: it changes StarterKit's
                        // extension set inside Composer, and TipTap only
                        // reads that at mount — switching from an SMS thread
                        // to an email one otherwise left the same editor
                        // instance behind, built without the bold/list/link
                        // marks at all, so the toolbar looked live but every
                        // button was a silent no-op.
                        key={`${id}:${resetKey}:${canFormat}`}
                        ref={composerRef}
                        placeholder={t('reply_placeholder')}
                        ariaLabel={t('reply_aria')}
                        canFormat={canFormat}
                        disabled={!conversation}
                        onSubmit={trySend}
                        onEmptyChange={setIsEmpty}
                        subject={
                            startingNew
                                ? {
                                      value: subject,
                                      placeholder: t('subject_placeholder'),
                                      label: t('subject_label'),
                                      onChange: setSubject,
                                  }
                                : undefined
                        }
                    />
                    <InputError message={error} className="mt-2" />

                    {/* Where this reply is going, in the order the decision
                        is actually made: which channel, which of that
                        person's addresses, which exchange on it. Only the
                        parts with something to choose are drawn. */}
                    {(showsChannelButtons || showsReceiver || showsThreadSelector) && (
                        <div className="mt-2 flex flex-wrap items-center gap-x-3 gap-y-2">
                            {showsChannelButtons && (
                                <div className="flex items-center gap-2">
                                    <span id="send-as-label" className="text-xs text-neutral-600 dark:text-neutral-400">
                                        {t('send_on_label')}
                                    </span>
                                    <div className="flex items-center gap-1" role="group" aria-labelledby="send-as-label">
                                        {sendableTypes.map((type) => (
                                            <Button
                                                key={type}
                                                type="button"
                                                size="sm"
                                                className="h-8"
                                                variant={effectiveType === type ? 'default' : 'outline'}
                                                disabled={addressLocked}
                                                onClick={() => selectSendType(type)}
                                            >
                                                {t(type === 'phone' ? 'channel_sms' : 'channel_email')}
                                            </Button>
                                        ))}
                                    </div>
                                </div>
                            )}

                            {showsReceiver && (
                                <div className="flex items-center gap-2">
                                    <label htmlFor="reply-receiver" className="text-xs text-neutral-600 dark:text-neutral-400">
                                        {t('receiver_label')}
                                    </label>
                                    <select
                                        id="reply-receiver"
                                        value={String(sendIdentity?.id ?? '')}
                                        disabled={addressLocked}
                                        onChange={(e) => selectIdentity(Number(e.target.value))}
                                        className="border-input bg-background h-8 min-w-0 rounded-md border px-2 text-sm disabled:opacity-60"
                                    >
                                        {receivers.map((item) => (
                                            <option key={item.id} value={item.id}>
                                                {item.value}
                                            </option>
                                        ))}
                                    </select>
                                </div>
                            )}

                            {showsThreadSelector && (
                                <div className="flex min-w-0 flex-1 items-center gap-2">
                                    <label htmlFor="reply-target" className="shrink-0 text-xs text-neutral-600 dark:text-neutral-400">
                                        {t('reply_target')}
                                    </label>
                                    <select
                                        id="reply-target"
                                        value={lockedToThread ? String(thread) : targetedId ? String(targetedId) : 'new'}
                                        disabled={lockedToThread}
                                        onChange={(e) => setTarget(e.target.value === 'new' ? 'new' : Number(e.target.value))}
                                        className="border-input bg-background h-8 min-w-0 flex-1 truncate rounded-md border px-2 text-sm disabled:opacity-60"
                                    >
                                        {/* Default, because "all exchanges" is
                                            not somewhere a message can go. */}
                                        <option value="new">{t('new_thread')}</option>
                                        {sendThreads.map((item) => (
                                            <option key={item.id} value={item.id}>
                                                {item.subject ?? t('untitled_thread')}
                                            </option>
                                        ))}
                                    </select>
                                </div>
                            )}
                        </div>
                    )}

                    {showsNoReplyWarning && (
                        <p className="mt-1 text-xs text-red-600 dark:text-red-400">
                            {t('channel_no_reply_warning', { channel: t(effectiveType === 'phone' ? 'channel_sms' : 'channel_email') })}
                        </p>
                    )}

                    <div className="mt-2 flex items-center justify-end gap-2">
                        <UploadLinkDialog conversationId={id} />
                        <Button disabled={!conversation || reply.isPending || isEmpty || (startingNew && subject.trim() === '')}>{t('send')}</Button>
                    </div>
                </form>
            )}
        </div>
    );
}

/** Stand-ins for a handful of bubbles, shaped like the real ones so the timeline doesn't jump once data arrives. */
function MessageListSkeleton() {
    const bubbles = ['w-40', 'w-56 justify-self-end', 'w-32', 'w-48 justify-self-end', 'w-36'];

    return (
        <ul className="space-y-3">
            {bubbles.map((width, i) => (
                <li key={i} className={cn('flex', width.includes('justify-self-end') && 'justify-end')}>
                    <Skeleton className={cn('h-10 rounded-xl', width.replace(' justify-self-end', ''))} />
                </li>
            ))}
        </ul>
    );
}

function Bubble({ message, subject, onMediaLoad }: { message: MessageItem; subject: string | null; onMediaLoad: () => void }) {
    const outbound = message.direction === 'outbound';
    const images = message.attachments.filter((attachment) => attachment.contentType.startsWith('image/'));
    const files = message.attachments.filter((attachment) => !attachment.contentType.startsWith('image/'));

    return (
        <li className={cn('flex', outbound && 'justify-end')}>
            <div
                className={cn(
                    'max-w-[75%] rounded-xl px-3 py-2 text-sm',
                    outbound ? 'bg-neutral-900 text-neutral-50 dark:bg-neutral-100 dark:text-neutral-900' : 'bg-neutral-100 dark:bg-neutral-800',
                )}
            >
                {/* The exchange this reply belongs to, said once at the top
                    of whichever message started it — not repeated down the
                    thread, where it would just be noise. */}
                {subject && (
                    <p
                        className={cn(
                            'mb-2 truncate text-xs font-semibold',
                            outbound ? 'text-neutral-300 dark:text-neutral-600' : 'text-neutral-500 dark:text-neutral-400',
                        )}
                    >
                        {subject}
                    </p>
                )}
                {(images.length > 0 || files.length > 0) && (
                    <div className="mb-1 space-y-1">
                        {/* Several images from one text read as a set — a grid
                            says so at a glance instead of a long scroll. */}
                        {images.length > 0 && (
                            <div className={cn(images.length > 1 && 'grid grid-cols-2 gap-1')}>
                                {images.map((attachment) => (
                                    <AttachmentView key={attachment.id} attachment={attachment} onMediaLoad={onMediaLoad} />
                                ))}
                            </div>
                        )}
                        {files.map((attachment) => (
                            <AttachmentView key={attachment.id} attachment={attachment} onMediaLoad={onMediaLoad} />
                        ))}
                    </div>
                )}
                {/* A picture texted on its own has no caption to show.

                    Which of the two bodies is shown turns on `bodyHtml`
                    being there, never on the channel: a channel that sends
                    rich text produces one, and every channel produces
                    `body`. The HTML was sanitized before it was stored — on
                    write, so nothing dangerous is in the database at all —
                    and is rendered here without being re-interpreted. */}
                {message.bodyHtml ? (
                    <div className="rich-body break-words" dangerouslySetInnerHTML={{ __html: message.bodyHtml }} />
                ) : (
                    message.body !== '' && <p className="break-words whitespace-pre-wrap">{message.body}</p>
                )}
                <div
                    className={cn('mt-2 flex items-center gap-1 text-xs', outbound ? 'text-neutral-400' : 'text-neutral-500')}
                    title={formatExact(message.sentAt)}
                >
                    <ChannelIcon channel={message.channel} />
                    <span>
                        {message.author ? `${message.author.name} · ` : ''}
                        {formatTimestamp(message.sentAt)}
                    </span>
                </div>
            </div>
        </li>
    );
}

/** Which transport a message went out on — only worth a glance, so an icon rather than a label. */
function ChannelIcon({ channel }: { channel: string }) {
    const { t } = useTranslation('inbox');
    const Icon = channel === 'email' ? Mail : MessageSquare;

    return <Icon className="h-3 w-3 shrink-0" aria-label={t(channel === 'email' ? 'channel_email' : 'channel_sms')} />;
}
