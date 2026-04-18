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

    /**
     * Tailwind classes for the status pill in the invoices view.
     */
    public function badgeClasses(): string
    {
        return match ($this) {
            self::Synced => 'bg-green-50 dark:bg-green-900/20 text-green-700 dark:text-green-400',
            self::Pending => 'bg-amber-50 dark:bg-amber-900/20 text-amber-700 dark:text-amber-400',
            self::Failed => 'bg-red-50 dark:bg-red-900/20 text-red-700 dark:text-red-400',
        };
    }
}
