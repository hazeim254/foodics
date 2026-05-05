<?php

namespace App\Dtos\Reconciliation;

use App\Enums\SalesReconciliationStatus;
use Carbon\CarbonImmutable;

readonly class SalesReconciliationResult
{
    /**
     * @param  array<int, SalesReconciliationDifference>  $differences
     */
    public function __construct(
        public SalesReconciliationStatus $status,
        public SalesReconciliationSummary $summary,
        public array $differences,
        public float $tolerance,
        public CarbonImmutable $checkedAt,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'summary' => $this->summary->toArray(),
            'differences' => array_map(fn (SalesReconciliationDifference $d) => $d->toArray(), $this->differences),
            'tolerance' => $this->tolerance,
            'checked_at' => $this->checkedAt->toIso8601String(),
        ];
    }
}
