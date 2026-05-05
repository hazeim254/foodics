<?php

namespace App\Enums;

enum SalesReconciliationStatus: string
{
    case Ok = 'ok';
    case RoundingOnly = 'rounding_only';
    case KnownGap = 'known_gap';
    case Mismatch = 'mismatch';
}
