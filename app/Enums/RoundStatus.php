<?php

namespace App\Enums;

/**
 * A round's lifecycle: assembled as a draft, opened for booking, and closed
 * when done. Opening requires at least one targeted case; closing makes the
 * round read-only.
 */
enum RoundStatus: string
{
    case Draft = 'draft';
    case Open = 'open';
    case Closed = 'closed';
}
