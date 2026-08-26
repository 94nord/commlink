<?php

namespace App\Enums;

/**
 * Which way a message travelled: inbound is the customer writing to the
 * firm, outbound is the firm writing to the customer.
 */
enum MessageDirection: string
{
    case Inbound = 'inbound';
    case Outbound = 'outbound';
}
