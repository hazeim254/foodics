<?php

namespace App\Enums;

enum WebhookStatus: string
{
    case Pending = 'pending';
    case Processed = 'processed';
    case Failed = 'failed';

    static public function values(): array
    {
        return array_map(fn($case) => $case->value, self::cases());
    }
}
