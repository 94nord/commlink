<?php

namespace App\Enums;

/**
 * An outbox row's delivery state: queued by the event spine, then either
 * handed to the mail transport or failed with the error kept on the row.
 */
enum OutboxStatus: string
{
    case Pending = 'pending';
    case Sent = 'sent';
    case Failed = 'failed';
}
