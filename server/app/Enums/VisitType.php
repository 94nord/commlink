<?php

namespace App\Enums;

/**
 * The kind of appointment a visit is (B6): a small extensible set — the
 * column is a plain string, so adding a type here needs no migration. Not a
 * user-defined type builder. Type is load-bearing: rounds will filter on it.
 */
enum VisitType: string
{
    case Survey = 'survey';
    case Progress = 'progress';
    case Handover = 'handover';
    case Warranty = 'warranty';
}
