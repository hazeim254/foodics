<?php

namespace App\Enums;

enum SettingKey: string
{
    case DaftraDefaultClientId = 'daftra.default_client_id';
    case Locale = 'locale';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn ($case) => $case->value, self::cases());
    }
}
