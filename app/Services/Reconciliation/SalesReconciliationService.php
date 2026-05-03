<?php

namespace App\Services\Reconciliation;

use App\Dtos\Reconciliation\SalesReconciliationDifference;
use App\Dtos\Reconciliation\SalesReconciliationResult;
use App\Dtos\Reconciliation\SalesReconciliationSummary;
use App\Enums\SalesReconciliationStatus;
use Carbon\CarbonImmutable;

class SalesReconciliationService
{
    public function summarizeFoodicsOrder(array $order): SalesReconciliationSummary
    {
        $isReturn = (int) ($order['status'] ?? 0) === 5;
        $type = $isReturn ? 'credit_note' : 'invoice';

        $productTotal = $this->sumProductTotals($order);
        $optionTotal = $this->sumOptionTotals($order);
        $comboProductTotal = $this->sumComboProductTotals($order);
        $chargeTotal = $this->sumChargeTotals($order);

        $productDiscountTotal = $this->sumProductDiscounts($order);
        $optionDiscountTotal = $this->sumOptionDiscounts($order);
        $comboDiscountTotal = $this->sumComboDiscounts($order);
        $orderDiscount = (float) ($order['discount_amount'] ?? 0);

        $taxTotal = $this->sumAllTaxes($order);
        $tipTotal = $this->sumTips($order);
        $roundingAmount = (float) ($order['rounding_amount'] ?? 0);
        $paymentTotal = $this->sumPayments($order);

        $subtotal = (float) ($order['subtotal_price'] ?? 0);
        $expectedTotal = (float) ($order['total_price'] ?? 0);

        $differences = [];
        $status = SalesReconciliationStatus::Ok;

        $comboOptionTotal = $this->sumComboOptionTotals($order);
        if ($comboOptionTotal > 0) {
            $differences[] = new SalesReconciliationDifference(
                component: 'combo_option_total',
                foodicsAmount: $comboOptionTotal,
                daftraAmount: 0.0,
                delta: $comboOptionTotal,
                severity: 'known_gap',
                explanation: 'Combo product options are not synced to Daftra',
            );
        }

        $comboWrapperDiscount = $this->sumComboWrapperDiscounts($order);
        if ($comboWrapperDiscount > 0) {
            $differences[] = new SalesReconciliationDifference(
                component: 'combo_wrapper_discount',
                foodicsAmount: $comboWrapperDiscount,
                daftraAmount: 0.0,
                delta: $comboWrapperDiscount,
                severity: 'known_gap',
                explanation: 'Combo wrapper discounts are not synced to Daftra',
            );
        }

        if ($isReturn && $paymentTotal > 0) {
            $differences[] = new SalesReconciliationDifference(
                component: 'return_payments',
                foodicsAmount: $paymentTotal,
                daftraAmount: 0.0,
                delta: $paymentTotal,
                severity: 'known_gap',
                explanation: 'Return order payments are not yet synced',
            );
        }

        if ($tipTotal > 0) {
            $differences[] = new SalesReconciliationDifference(
                component: 'tips',
                foodicsAmount: $tipTotal,
                daftraAmount: 0.0,
                delta: $tipTotal,
                severity: 'known_gap',
                explanation: 'Tips are not synced to Daftra',
            );
        }

        if (abs($roundingAmount) > 0) {
            $differences[] = new SalesReconciliationDifference(
                component: 'rounding',
                foodicsAmount: $roundingAmount,
                daftraAmount: 0.0,
                delta: $roundingAmount,
                severity: 'known_gap',
                explanation: 'Rounding amount is not represented on Daftra invoices',
            );
        }

        if ($differences !== []) {
            $status = SalesReconciliationStatus::KnownGap;
        }

        return new SalesReconciliationSummary(
            subtotal: $subtotal,
            productTotal: $productTotal,
            optionTotal: $optionTotal,
            comboProductTotal: $comboProductTotal,
            chargeTotal: $chargeTotal,
            productDiscountTotal: $productDiscountTotal,
            optionDiscountTotal: $optionDiscountTotal,
            comboDiscountTotal: $comboDiscountTotal,
            orderDiscount: $orderDiscount,
            taxTotal: $taxTotal,
            tipTotal: $tipTotal,
            roundingAmount: $roundingAmount,
            paymentTotal: $paymentTotal,
            expectedTotal: $expectedTotal,
            status: $status,
            type: $type,
            differences: $differences,
        );
    }

    public function compare(array $order, array $daftraPayload, ?array $daftraDocument = null): SalesReconciliationResult
    {
        $tolerance = 0.01;
        $summary = $this->summarizeFoodicsOrder($order);

        $differences = [];

        $daftraTotal = $this->resolveDaftraTotal($daftraPayload, $daftraDocument);
        $foodicsExpectedTotal = $summary->expectedTotal;

        $totalDelta = abs($foodicsExpectedTotal - $daftraTotal);

        if ($totalDelta > $tolerance) {
            $knownGapAmount = $this->knownGapAmountForTotal($summary);

            if (abs($totalDelta - $knownGapAmount) <= $tolerance) {
                $severity = 'known_gap';
                $explanation = 'Total difference explained by known gaps (tips, rounding, combo options, or combo wrapper discounts)';
            } else {
                $severity = 'mismatch';
                $explanation = 'Unexplained total drift between Foodics and Daftra';
            }

            $differences[] = new SalesReconciliationDifference(
                component: 'total',
                foodicsAmount: $foodicsExpectedTotal,
                daftraAmount: $daftraTotal,
                delta: $totalDelta,
                severity: $severity,
                explanation: $explanation,
            );
        } elseif ($totalDelta > 0) {
            $differences[] = new SalesReconciliationDifference(
                component: 'total',
                foodicsAmount: $foodicsExpectedTotal,
                daftraAmount: $daftraTotal,
                delta: $totalDelta,
                severity: 'rounding_only',
                explanation: 'Total difference within rounding tolerance',
            );
        }

        $daftraPaymentTotal = $this->extractDaftraPaymentTotal($daftraPayload);
        if ($daftraPaymentTotal !== null) {
            $paymentDelta = abs($summary->paymentTotal - $daftraPaymentTotal);
            if ($paymentDelta > $tolerance) {
                $knownGapForPayments = 0.0;
                if ($summary->tipTotal > 0) {
                    $knownGapForPayments += $summary->tipTotal;
                }

                if (abs($summary->roundingAmount) > 0) {
                    $knownGapForPayments += abs($summary->roundingAmount);
                }

                $isKnownGap = abs($paymentDelta - $knownGapForPayments) <= $tolerance;

                $differences[] = new SalesReconciliationDifference(
                    component: 'payments',
                    foodicsAmount: $summary->paymentTotal,
                    daftraAmount: $daftraPaymentTotal,
                    delta: $paymentDelta,
                    severity: $isKnownGap ? 'known_gap' : 'mismatch',
                    explanation: $isKnownGap
                        ? 'Payment difference explained by tips or rounding'
                        : 'Unexplained payment difference',
                );
            } elseif ($paymentDelta > 0) {
                $differences[] = new SalesReconciliationDifference(
                    component: 'payments',
                    foodicsAmount: $summary->paymentTotal,
                    daftraAmount: $daftraPaymentTotal,
                    delta: $paymentDelta,
                    severity: 'rounding_only',
                    explanation: 'Payment difference within rounding tolerance',
                );
            }
        }

        foreach ($summary->differences as $existingDiff) {
            $differences[] = $existingDiff;
        }

        $status = $this->classifyStatus($differences, $tolerance);

        return new SalesReconciliationResult(
            status: $status,
            summary: $summary,
            differences: $differences,
            tolerance: $tolerance,
            checkedAt: CarbonImmutable::now(),
        );
    }

    protected function resolveDaftraTotal(array $daftraPayload, ?array $daftraDocument): float
    {
        if ($daftraDocument !== null) {
            $invoiceData = $daftraDocument['Invoice'] ?? $daftraDocument['CreditNote'] ?? $daftraDocument;

            foreach (['total', 'net', 'amount', 'total_price', 'grand_total'] as $key) {
                if (isset($invoiceData[$key]) && is_numeric($invoiceData[$key])) {
                    return (float) $invoiceData[$key];
                }
            }
        }

        return $this->extractDaftraLineTotal($daftraPayload);
    }

    protected function extractDaftraLineTotal(array $daftraPayload): float
    {
        $invoice = $daftraPayload['Invoice'] ?? $daftraPayload['CreditNote'] ?? [];
        $items = $daftraPayload['InvoiceItem'] ?? [];

        $lineSubtotal = collect($items)->sum(function (array $item): float {
            $unitPrice = (float) ($item['unit_price'] ?? 0);
            $quantity = (float) ($item['quantity'] ?? 1);
            $perUnitDiscount = (float) ($item['discount'] ?? 0);

            return ($unitPrice - $perUnitDiscount) * $quantity;
        });

        $discountAmount = (float) ($invoice['discount_amount'] ?? 0);

        return round($lineSubtotal - $discountAmount, 2);
    }

    protected function extractDaftraPaymentTotal(?array $daftraPayload): ?float
    {
        $payments = $daftraPayload['InvoicePayment'] ?? $daftraPayload['payments'] ?? null;

        if ($payments === null) {
            return null;
        }

        return collect($payments)->sum(function (array $payment): float {
            if (isset($payment['InvoicePayment'])) {
                return (float) ($payment['InvoicePayment']['amount'] ?? 0);
            }

            return (float) ($payment['amount'] ?? 0);
        });
    }

    protected function knownGapAmountForTotal(SalesReconciliationSummary $summary): float
    {
        $totalDriftComponents = ['tips', 'rounding', 'combo_option_total', 'combo_wrapper_discount'];

        $total = 0.0;

        foreach ($summary->differences as $difference) {
            if ($difference->severity === 'known_gap' && in_array($difference->component, $totalDriftComponents, true)) {
                $total += abs($difference->delta);
            }
        }

        return $total;
    }

    protected function classifyStatus(array $differences, float $tolerance): SalesReconciliationStatus
    {
        if ($differences === []) {
            return SalesReconciliationStatus::Ok;
        }

        $allRounding = true;
        $hasKnownGap = false;
        $hasMismatch = false;

        foreach ($differences as $difference) {
            if ($difference->severity !== 'rounding_only') {
                $allRounding = false;
            }

            if ($difference->severity === 'known_gap') {
                $hasKnownGap = true;
            }

            if ($difference->severity === 'mismatch') {
                $hasMismatch = true;
            }
        }

        if ($hasMismatch) {
            return SalesReconciliationStatus::Mismatch;
        }

        if ($hasKnownGap) {
            return SalesReconciliationStatus::KnownGap;
        }

        if ($allRounding) {
            return SalesReconciliationStatus::RoundingOnly;
        }

        return SalesReconciliationStatus::Ok;
    }

    protected function sumProductTotals(array $order): float
    {
        return collect($order['products'] ?? [])
            ->sum(fn (array $product) => (float) ($product['total_price'] ?? 0));
    }

    protected function sumOptionTotals(array $order): float
    {
        return collect($order['products'] ?? [])
            ->flatMap(fn (array $product) => $product['options'] ?? [])
            ->sum(fn (array $option) => (float) ($option['total_price'] ?? 0));
    }

    protected function sumComboProductTotals(array $order): float
    {
        return collect($order['combos'] ?? [])
            ->flatMap(fn (array $combo) => $combo['products'] ?? [])
            ->sum(fn (array $product) => (float) ($product['total_price'] ?? 0));
    }

    protected function sumComboOptionTotals(array $order): float
    {
        return collect($order['combos'] ?? [])
            ->flatMap(fn (array $combo) => $combo['products'] ?? [])
            ->flatMap(fn (array $product) => $product['options'] ?? [])
            ->sum(fn (array $option) => (float) ($option['total_price'] ?? 0));
    }

    protected function sumChargeTotals(array $order): float
    {
        return collect($order['charges'] ?? [])
            ->sum(fn (array $charge) => (float) ($charge['amount'] ?? 0));
    }

    protected function sumProductDiscounts(array $order): float
    {
        return collect($order['products'] ?? [])
            ->sum(fn (array $product) => (float) ($product['discount_amount'] ?? 0));
    }

    protected function sumOptionDiscounts(array $order): float
    {
        return collect($order['products'] ?? [])
            ->flatMap(fn (array $product) => $product['options'] ?? [])
            ->sum(fn (array $option) => (float) ($option['discount_amount'] ?? $option['tax_exclusive_discount_amount'] ?? 0));
    }

    protected function sumComboDiscounts(array $order): float
    {
        $childDiscounts = collect($order['combos'] ?? [])
            ->flatMap(fn (array $combo) => $combo['products'] ?? [])
            ->sum(fn (array $product) => (float) ($product['discount_amount'] ?? 0));

        return $childDiscounts;
    }

    protected function sumComboWrapperDiscounts(array $order): float
    {
        return collect($order['combos'] ?? [])
            ->sum(fn (array $combo) => (float) ($combo['discount_amount'] ?? 0));
    }

    protected function sumProductTaxes(array $order): float
    {
        return collect($order['products'] ?? [])
            ->flatMap(fn (array $product) => $product['taxes'] ?? [])
            ->sum(fn (array $tax) => (float) ($tax['pivot']['amount'] ?? 0));
    }

    protected function sumOptionTaxes(array $order): float
    {
        return collect($order['products'] ?? [])
            ->flatMap(fn (array $product) => $product['options'] ?? [])
            ->flatMap(fn (array $option) => $option['taxes'] ?? [])
            ->sum(fn (array $tax) => (float) ($tax['pivot']['amount'] ?? 0));
    }

    protected function sumChargeTaxes(array $order): float
    {
        return collect($order['charges'] ?? [])
            ->flatMap(fn (array $charge) => $charge['taxes'] ?? [])
            ->sum(fn (array $tax) => (float) ($tax['pivot']['amount'] ?? 0));
    }

    protected function sumComboProductTaxes(array $order): float
    {
        return collect($order['combos'] ?? [])
            ->flatMap(fn (array $combo) => $combo['products'] ?? [])
            ->flatMap(fn (array $product) => $product['taxes'] ?? [])
            ->sum(fn (array $tax) => (float) ($tax['pivot']['amount'] ?? 0));
    }

    protected function sumComboOptionTaxes(array $order): float
    {
        return collect($order['combos'] ?? [])
            ->flatMap(fn (array $combo) => $combo['products'] ?? [])
            ->flatMap(fn (array $product) => $product['options'] ?? [])
            ->flatMap(fn (array $option) => $option['taxes'] ?? [])
            ->sum(fn (array $tax) => (float) ($tax['pivot']['amount'] ?? 0));
    }

    protected function sumAllTaxes(array $order): float
    {
        return $this->sumProductTaxes($order)
            + $this->sumOptionTaxes($order)
            + $this->sumChargeTaxes($order)
            + $this->sumComboProductTaxes($order);
    }

    protected function sumTips(array $order): float
    {
        return collect($order['payments'] ?? [])
            ->sum(fn (array $payment) => (float) ($payment['tips'] ?? 0));
    }

    protected function sumPayments(array $order): float
    {
        return collect($order['payments'] ?? [])
            ->sum(fn (array $payment) => (float) ($payment['amount'] ?? 0));
    }
}
