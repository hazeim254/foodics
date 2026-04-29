<?php

namespace App\Enums;

enum WebhookStatus: string
{
    case Pending = 'pending';
    case Processed = 'processed';
    case Failed = 'failed';

    public static function values(): array
    {
        return array_map(fn ($case) => $case->value, self::cases());
    }

    public function label(): string
    {
        return ucfirst(__($this->value));
    }
}
