<?php

namespace App\Enums;

/**
 * What the UI is allowed to do with an address, as one answer.
 *
 * This exists so the frontend never re-derives the rules. Whether a firm can
 * text somebody depends on the module being on, an address being provisioned
 * to send from, and whether there is history worth still showing — three
 * facts the server holds and the browser would only get wrong. The composer
 * switches on this enum and nothing else.
 */
enum ChannelStatus: string
{
    /** Reachable: send away. */
    case Active = 'active';

    /**
     * There is history here, but nothing new can be sent — the firm is not
     * switched on for this module any more. Shown, greyed, no reply box.
     */
    case ReadOnly = 'read_only';

    /**
     * Not offered at all: no module, or no address to send from. An address
     * in this state with no history behind it is not shown to anyone.
     */
    case Unavailable = 'unavailable';
}
