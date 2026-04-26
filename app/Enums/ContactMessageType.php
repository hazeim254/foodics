<?php

namespace App\Enums;

enum ContactMessageType: string
{
    case Inquiry = 'inquiry';
    case Suggestion = 'suggestion';
    case Complaint = 'complaint';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn ($case) => $case->value, self::cases());
    }
}
