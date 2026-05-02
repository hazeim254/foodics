<?php

namespace App\Enums;

enum InvoiceSyncStatus: string
{
    case Pending = 'pending';
    case Failed = 'failed';
    case Synced = 'synced';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn ($case) => $case->value, self::cases());
    }

    public function label(): string
    {
        return ucfirst(__($this->value));
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Synced => 'bg-tone-success-soft text-tone-success',
            self::Pending => 'bg-tone-warn-soft text-tone-warn',
            self::Failed => 'bg-tone-danger-soft text-tone-danger border border-tone-danger-border/60 badge-pulse',
        };
    }
}
