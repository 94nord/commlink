<?php

namespace App\Enums;

enum CustomerKind: string
{
    case Person = 'person';
    case Organization = 'organization';
}
