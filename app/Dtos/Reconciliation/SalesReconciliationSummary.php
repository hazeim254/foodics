<?php

namespace App\Dtos\Reconciliation;

use App\Enums\SalesReconciliationStatus;

readonly class SalesReconciliationSummary
{
    /**
     * @param  array<int, SalesReconciliationDifference>  $differences
     */
    public function __construct(
        public float $subtotal,
        public float $productTotal,
        public float $optionTotal,
        public float $comboProductTotal,
        public float $chargeTotal,
        public float $productDiscountTotal,
        public float $optionDiscountTotal,
        public float $comboDiscountTotal,
        public float $orderDiscount,
        public float $taxTotal,
        public float $tipTotal,
        public float $roundingAmount,
        public float $paymentTotal,
        public float $expectedTotal,
        public SalesReconciliationStatus $status,
        public string $type,
        public array $differences,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'subtotal' => $this->subtotal,
            'product_total' => $this->productTotal,
            'option_total' => $this->optionTotal,
            'combo_product_total' => $this->comboProductTotal,
            'charge_total' => $this->chargeTotal,
            'product_discount_total' => $this->productDiscountTotal,
            'option_discount_total' => $this->optionDiscountTotal,
            'combo_discount_total' => $this->comboDiscountTotal,
            'order_discount' => $this->orderDiscount,
            'tax_total' => $this->taxTotal,
            'tip_total' => $this->tipTotal,
            'rounding_amount' => $this->roundingAmount,
            'payment_total' => $this->paymentTotal,
            'expected_total' => $this->expectedTotal,
            'status' => $this->status->value,
            'type' => $this->type,
            'differences' => array_map(fn (SalesReconciliationDifference $d) => $d->toArray(), $this->differences),
        ];
    }
}
