<?php

namespace App\Enums;

/**
 * Team-level queue state. Archiving clears a conversation out of the working
 * queue; a later inbound message reopens that same conversation rather than
 * starting a new one.
 */
enum ConversationStatus: string
{
    case Open = 'open';
    case Archived = 'archived';
}
