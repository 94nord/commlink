import i18n from '@/i18n';

/** Shapes and formatting shared by the inbox list, thread and dialogs. */

const LOCALE_TAGS: Record<string, string> = { da: 'da-DK', en: 'en-US' };

/** The BCP-47 tag for whichever language the UI is currently in. */
function activeLocale(): string {
    return LOCALE_TAGS[i18n.language] ?? LOCALE_TAGS.da;
}

export interface MessageAttachment {
    id: number;
    contentType: string;
    /** Null for an inbound MMS attachment — Twilio gives no filename. */
    filename: string | null;
    byteSize: number;
    /** Served through the API, not straight from the bucket. */
    url: string;
}

export interface MessageItem {
    id: number;
    channel: string;
    threadId: number;
    direction: 'inbound' | 'outbound';
    /** The plain-text canonical form — every channel has one. */
    body: string;
    /**
     * The rich form, where the channel produced one. Sanitized server-side
     * before it was stored, so this is safe to render as HTML; a presence
     * check on it is the *only* thing that decides how a message renders —
     * never a check on `channel`.
     */
    bodyHtml: string | null;
    sentAt: string;
    author: { id: number; name: string } | null;
    attachments: MessageAttachment[];
}

export interface ConversationSummary {
    id: number;
    displayName: string | null;
    customerId: number | null;
    isAnonymous: boolean;
    status: string;
    lastMessageAt: string | null;
    lastMessage: MessageItem | null;
    /** Messages from the customer this person has not read. */
    unreadCount: number;
}

/**
 * What the firm may do with one address, decided entirely by the server.
 *
 * Whether a channel is usable depends on the module being switched on, an
 * address being provisioned to send from, and whether there is history worth
 * still showing. The UI never re-derives any of that — it switches on this
 * value and nothing else.
 */
export type ChannelStatus = 'active' | 'read_only' | 'unavailable';

export interface ConversationIdentity {
    id: number;
    addressType: string;
    value: string;
    status: ChannelStatus;
    /** Messages from the customer that arrived on *this* address unread. */
    unreadCount: number;
    /** Whether this person has ever written back on this specific address. */
    hasReplied: boolean;
    /**
     * What the channel behind this address can do. On the identity rather
     * than on a thread because an address nobody has written to yet still
     * threads and still carries formatting.
     */
    supportsRichText: boolean;
    threadsByKey: boolean;
}

/** One exchange inside a conversation. SMS gives each address exactly one. */
export interface ConversationThread {
    id: number;
    identityId: number;
    /** Null on SMS — a number is one unbroken exchange with nothing to name. */
    subject: string | null;
    lastActivityAt: string | null;
    messageCount: number;
    /** The address type this exchange happens on, e.g. 'phone' or 'email'. */
    addressType: string;
    /** Whether this channel can carry formatting and separate exchanges. */
    supportsRichText: boolean;
    threadsByKey: boolean;
}

export interface ConversationDetail extends ConversationSummary {
    identities: ConversationIdentity[];
    threads: ConversationThread[];
    messages: MessageItem[];
    hasMore: boolean;
}

export interface CustomerOption {
    id: number;
    name: string;
    email: string | null;
    /** Numbers the firm can already reach them at. */
    phones: { id: number; value: string }[];
}

/**
 * What a typed recipient turned out to be. Crude on purpose — it only routes
 * the value to the right validator, and the server decides for real.
 */
export function sniffAddress(value: string): 'phone' | 'email' | null {
    const trimmed = value.trim();

    if (trimmed === '') return null;
    if (trimmed.includes('@')) return /^[^\s@]+@[^\s@.]+\.[^\s@]+$/.test(trimmed) ? 'email' : null;

    return /^[+0-9][0-9\s]*$/.test(trimmed) && trimmed.replace(/\D/g, '').length >= 4 ? 'phone' : null;
}

/**
 * The socket (see `@/lib/echo`) is what makes the inbox feel live; this is
 * only the fallback for a dropped or never-established connection, so it
 * can be slow.
 */
export const POLL_MS = 30_000;

/**
 * Time of day for something from today, a date for anything older — what a
 * messaging app shows next to a conversation.
 */
export function formatTimestamp(iso: string | null): string {
    if (!iso) return '';

    const date = new Date(iso);
    const isToday = new Date().toDateString() === date.toDateString();

    return isToday
        ? date.toLocaleTimeString(activeLocale(), { hour: '2-digit', minute: '2-digit' })
        : date.toLocaleDateString(activeLocale(), { day: 'numeric', month: 'short' });
}

/** Full date and time, for the title attribute on a bubble. */
export function formatExact(iso: string): string {
    return new Date(iso).toLocaleString(activeLocale());
}
