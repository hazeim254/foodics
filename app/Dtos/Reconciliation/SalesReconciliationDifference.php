<?php

namespace App\Dtos\Reconciliation;

readonly class SalesReconciliationDifference
{
    public function __construct(
        public string $component,
        public float $foodicsAmount,
        public ?float $daftraAmount,
        public float $delta,
        public string $severity,
        public ?string $explanation = null,
    ) {}

    /**
     * @return array{component: string, foodics_amount: float, daftra_amount: float|null, delta: float, severity: string, explanation: string|null}
     */
    public function toArray(): array
    {
        return [
            'component' => $this->component,
            'foodics_amount' => $this->foodicsAmount,
            'daftra_amount' => $this->daftraAmount,
            'delta' => $this->delta,
            'severity' => $this->severity,
            'explanation' => $this->explanation,
        ];
    }
}
