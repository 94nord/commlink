import { api } from '@/api/client';
import { useAuth } from '@/auth/AuthContext';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import { usePageTitle } from '@/hooks/use-page-title';
import AppLayout from '@/layouts/app-layout';
import { getEcho } from '@/lib/echo';
import { cn } from '@/lib/utils';
import ConversationPane from '@/pages/inbox/conversation';
import NewConversationPane from '@/pages/inbox/new-conversation';
import { formatTimestamp, POLL_MS, type ConversationSummary } from '@/pages/inbox/shared';
import StartConversationDialog from '@/pages/inbox/start-conversation-dialog';
import { type BreadcrumbItem } from '@/types';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useLocation, useNavigate, useParams } from 'react-router-dom';

/**
 * The shared inbox: the firm's queue on the left, the open conversation on
 * the right. On a narrow screen only one of the two is shown, since picking
 * a conversation is a navigation.
 *
 * Live via a Reverb socket (see the effect below), with polling underneath
 * as a fallback — see POLL_MS in shared.ts.
 */
export default function InboxIndex() {
    const { t } = useTranslation('inbox');
    usePageTitle(t('title'));
    const breadcrumbs: BreadcrumbItem[] = [{ title: t('title'), href: '/inbox' }];
    const navigate = useNavigate();
    const params = useParams();
    const location = useLocation();
    const { user } = useAuth();
    const queryClient = useQueryClient();

    // One socket for both panes: invalidating the same top-level key each
    // already invalidates after its own mutations (see conversation.tsx)
    // refreshes the list and whichever conversation is open in one go —
    // no separate listener needed there. POLL_MS above is what keeps things
    // moving if this connection is down or never came up.
    useEffect(() => {
        if (!user) return;

        const channelName = `corporation.${user.corporation_id}.inbox`;
        getEcho()
            .private(channelName)
            .listen('.inbox.updated', () => {
                queryClient.invalidateQueries({ queryKey: ['conversations'] });
            });

        return () => {
            getEcho().leave(channelName);
        };
    }, [user, queryClient]);

    // A conversation being written but not yet sent takes the same pane.
    const isDraft = location.pathname === '/inbox/new';
    const selectedId = !isDraft && params.id ? Number(params.id) : null;
    const showsThread = isDraft || selectedId !== null;

    const { data, isLoading } = useQuery({
        queryKey: ['conversations'],
        queryFn: () => api.get<{ conversations: ConversationSummary[]; availableAddressTypes: string[] }>('/api/conversations'),
        refetchInterval: POLL_MS,
    });

    const conversations = data?.conversations ?? [];
    const availableAddressTypes = data?.availableAddressTypes ?? [];

    const [search, setSearch] = useState('');
    // Matches the customer's name or number — displayName already resolves
    // to the number for anonymous customers, so this covers both. No
    // pagination on this endpoint yet, so filtering the fetched list is
    // enough; see link-customer-dialog.tsx for the same pattern.
    const trimmedSearch = search.trim().toLowerCase();
    const filtered =
        trimmedSearch === ''
            ? conversations
            : conversations.filter((conversation) => conversation.displayName?.toLowerCase().includes(trimmedSearch));

    return (
        <AppLayout breadcrumbs={breadcrumbs} fullHeight>
            <div className="flex min-h-0 flex-1 overflow-hidden">
                <aside
                    aria-label={t('conversations_label')}
                    className={cn('flex w-full min-w-0 shrink-0 flex-col border-r md:w-80', showsThread && 'hidden md:flex')}
                >
                    <div className="flex items-center justify-between gap-2 border-b p-4">
                        <h1 className="text-xl font-semibold">{t('heading')}</h1>
                        <StartConversationDialog
                            conversations={conversations}
                            availableAddressTypes={availableAddressTypes}
                            onExisting={(id) => navigate(`/inbox/${id}`)}
                            onDraft={({ address, customerId, label }) => {
                                const query = new URLSearchParams({ label });
                                if (address) query.set('address', address);
                                if (customerId) query.set('customerId', String(customerId));
                                navigate(`/inbox/new?${query.toString()}`);
                            }}
                        />
                    </div>

                    {conversations.length > 0 && (
                        <div className="border-b p-2">
                            <Input
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder={t('search_placeholder')}
                                aria-label={t('search_placeholder')}
                            />
                        </div>
                    )}

                    <div className="min-h-0 flex-1 overflow-y-auto">
                        {isLoading ? (
                            <ConversationListSkeleton />
                        ) : conversations.length === 0 ? (
                            <p className="p-4 text-sm text-neutral-600 dark:text-neutral-400">{t('empty_queue')}</p>
                        ) : filtered.length === 0 ? (
                            <p className="p-4 text-sm text-neutral-600 dark:text-neutral-400">{t('no_search_matches', { query: search.trim() })}</p>
                        ) : (
                            <ul className="divide-y">
                                {filtered.map((conversation) => {
                                    // The one you are reading is read, whatever the
                                    // last poll said — otherwise the badge lingers for
                                    // a beat after you click into it.
                                    const unread = conversation.unreadCount > 0 && conversation.id !== selectedId;
                                    // An attachment-only message has an empty
                                    // body — without this the row would show
                                    // nothing at all where the last message
                                    // ought to be.
                                    const lastMessage = conversation.lastMessage;
                                    const preview = lastMessage
                                        ? `${lastMessage.direction === 'outbound' ? t('you_prefix') : ''}${
                                              lastMessage.body ||
                                              (lastMessage.attachments.length > 0
                                                  ? t('attachments_sent', { count: lastMessage.attachments.length })
                                                  : '')
                                          }`
                                        : t('no_messages_yet');

                                    return (
                                        <li key={conversation.id}>
                                            <Link
                                                to={`/inbox/${conversation.id}`}
                                                className={cn(
                                                    'block px-4 py-3 hover:bg-neutral-100 dark:hover:bg-neutral-800',
                                                    conversation.id === selectedId && 'bg-neutral-100 dark:bg-neutral-800',
                                                )}
                                            >
                                                <div className="flex items-baseline justify-between gap-2">
                                                    <span className={cn('truncate', unread ? 'font-semibold' : 'font-medium')}>
                                                        {conversation.displayName}
                                                    </span>
                                                    <span
                                                        className={cn(
                                                            'shrink-0 text-xs',
                                                            unread ? 'text-neutral-700 dark:text-neutral-300' : 'text-neutral-500',
                                                        )}
                                                    >
                                                        {formatTimestamp(conversation.lastMessageAt)}
                                                    </span>
                                                </div>
                                                <div className="mt-0.5 flex items-center gap-2">
                                                    <p
                                                        className={cn(
                                                            'min-w-0 flex-1 truncate text-sm',
                                                            unread
                                                                ? 'font-medium text-neutral-900 dark:text-neutral-100'
                                                                : 'text-neutral-600 dark:text-neutral-400',
                                                        )}
                                                    >
                                                        {preview}
                                                    </p>
                                                    {unread && (
                                                        <Badge
                                                            // A count, not a control: the row is the
                                                            // link, so the badge must not light up
                                                            // under the cursor.
                                                            className="hover:bg-primary shrink-0"
                                                            aria-label={t('unread_aria', { count: conversation.unreadCount })}
                                                        >
                                                            {conversation.unreadCount}
                                                        </Badge>
                                                    )}
                                                </div>
                                            </Link>
                                        </li>
                                    );
                                })}
                            </ul>
                        )}
                    </div>
                </aside>

                <section
                    aria-label={t('conversation_label')}
                    className={cn('flex min-h-0 min-w-0 flex-1 flex-col', !showsThread && 'hidden md:flex')}
                >
                    {isDraft ? (
                        <NewConversationPane />
                    ) : selectedId !== null ? (
                        <ConversationPane
                            key={selectedId}
                            id={selectedId}
                            // What the list already knows, shown the instant
                            // loading starts — not known at all on a cold
                            // deep-link, where the pane falls back to its own
                            // skeleton.
                            initialDisplayName={conversations.find((item) => item.id === selectedId)?.displayName ?? null}
                        />
                    ) : (
                        <div className="flex flex-1 items-center justify-center p-4 text-sm text-neutral-600 dark:text-neutral-400">
                            {t('pick_conversation')}
                        </div>
                    )}
                </section>
            </div>
        </AppLayout>
    );
}

/** Stand-ins for a handful of rows, shaped like the real ones so the list doesn't jump once data arrives. */
function ConversationListSkeleton() {
    return (
        <ul className="divide-y">
            {Array.from({ length: 6 }).map((_, i) => (
                <li key={i} className="px-4 py-3">
                    <div className="flex items-baseline justify-between gap-2">
                        <Skeleton className="h-4 w-32" />
                        <Skeleton className="h-3 w-10 shrink-0" />
                    </div>
                    <Skeleton className="mt-2 h-3.5 w-3/4" />
                </li>
            ))}
        </ul>
    );
}
