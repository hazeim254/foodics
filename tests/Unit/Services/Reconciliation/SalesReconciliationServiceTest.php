<?php

use App\Enums\SalesReconciliationStatus;
use App\Services\Reconciliation\SalesReconciliationService;

beforeEach(function () {
    $this->service = new SalesReconciliationService;
});

it('summarizes a simple completed order with one product and one payment', function () {
    $order = [
        'status' => 1,
        'subtotal_price' => 100.0,
        'total_price' => 115.0,
        'discount_amount' => 0,
        'rounding_amount' => 0,
        'products' => [
            [
                'id' => 'p1',
                'total_price' => 100.0,
                'unit_price' => 100.0,
                'quantity' => 1,
                'discount_amount' => 0,
                'product' => ['id' => 'p1', 'name' => 'Item', 'sku' => '', 'price' => 100, 'is_active' => true],
                'taxes' => [['id' => 't1', 'pivot' => ['amount' => 15.0]]],
                'options' => [],
            ],
        ],
        'combos' => [],
        'charges' => [],
        'payments' => [
            ['amount' => 115.0, 'tips' => 0],
        ],
    ];

    $summary = $this->service->summarizeFoodicsOrder($order);

    expect($summary->productTotal)->toBe(100.0)
        ->and($summary->taxTotal)->toBe(15.0)
        ->and($summary->paymentTotal)->toBe(115.0)
        ->and($summary->expectedTotal)->toBe(115.0)
        ->and($summary->type)->toBe('invoice')
        ->and($summary->status)->toBe(SalesReconciliationStatus::Ok)
        ->and($summary->differences)->toBeEmpty();
});

it('summarizes product taxes from products.taxes.*.pivot.amount', function () {
    $order = [
        'status' => 1,
        'subtotal_price' => 50.0,
        'total_price' => 56.0,
        'discount_amount' => 0,
        'products' => [
            [
                'id' => 'p1',
                'total_price' => 30.0,
                'unit_price' => 30.0,
                'quantity' => 1,
                'discount_amount' => 0,
                'taxes' => [
                    ['id' => 't1', 'pivot' => ['amount' => 4.0]],
                    ['id' => 't2', 'pivot' => ['amount' => 2.0]],
                ],
                'options' => [],
            ],
            [
                'id' => 'p2',
                'total_price' => 20.0,
                'unit_price' => 20.0,
                'quantity' => 1,
                'discount_amount' => 0,
                'taxes' => [
                    ['id' => 't1', 'pivot' => ['amount' => 6.0]],
                ],
                'options' => [],
            ],
        ],
        'combos' => [],
        'charges' => [],
        'payments' => [],
    ];

    $summary = $this->service->summarizeFoodicsOrder($order);

    expect($summary->taxTotal)->toBe(12.0);
});

it('summarizes modifier option totals and taxes', function () {
    $order = [
        'status' => 1,
        'subtotal_price' => 55.0,
        'total_price' => 60.0,
        'discount_amount' => 0,
        'products' => [
            [
                'id' => 'p1',
                'total_price' => 40.0,
                'unit_price' => 40.0,
                'quantity' => 1,
                'discount_amount' => 0,
                'taxes' => [],
                'options' => [
                    [
                        'id' => 'o1',
                        'total_price' => 10.0,
                        'unit_price' => 10.0,
                        'quantity' => 1,
                        'discount_amount' => 0,
                        'taxes' => [['id' => 't1', 'pivot' => ['amount' => 1.5]]],
                        'modifier_option' => ['id' => 'o1', 'name' => 'Extra'],
                    ],
                    [
                        'id' => 'o2',
                        'total_price' => 5.0,
                        'unit_price' => 5.0,
                        'quantity' => 1,
                        'discount_amount' => 0,
                        'taxes' => [['id' => 't2', 'pivot' => ['amount' => 0.5]]],
                        'modifier_option' => ['id' => 'o2', 'name' => 'Size'],
                    ],
                ],
            ],
        ],
        'combos' => [],
        'charges' => [],
        'payments' => [],
    ];

    $summary = $this->service->summarizeFoodicsOrder($order);

    expect($summary->optionTotal)->toBe(15.0)
        ->and($summary->taxTotal)->toBe(2.0);
});

it('summarizes charges and charge taxes', function () {
    $order = [
        'status' => 1,
        'subtotal_price' => 100.0,
        'total_price' => 115.0,
        'discount_amount' => 0,
        'products' => [],
        'combos' => [],
        'charges' => [
            [
                'amount' => 10.0,
                'charge' => ['name' => 'Delivery', 'value' => 10.0],
                'taxes' => [['id' => 't1', 'pivot' => ['amount' => 1.5]]],
            ],
            [
                'amount' => 5.0,
                'charge' => ['name' => 'Service', 'value' => 5.0],
                'taxes' => [['id' => 't2', 'pivot' => ['amount' => 0.75]]],
            ],
        ],
        'payments' => [],
    ];

    $summary = $this->service->summarizeFoodicsOrder($order);

    expect($summary->chargeTotal)->toBe(15.0)
        ->and($summary->taxTotal)->toBe(2.25);
});

it('summarizes order-level discounts', function () {
    $order = [
        'status' => 1,
        'subtotal_price' => 100.0,
        'total_price' => 90.0,
        'discount_amount' => 10.0,
        'products' => [
            [
                'id' => 'p1',
                'total_price' => 100.0,
                'unit_price' => 100.0,
                'quantity' => 1,
                'discount_amount' => 0,
                'taxes' => [],
                'options' => [],
            ],
        ],
        'combos' => [],
        'charges' => [],
        'payments' => [],
    ];

    $summary = $this->service->summarizeFoodicsOrder($order);

    expect($summary->orderDiscount)->toBe(10.0);
});

it('summarizes product discounts', function () {
    $order = [
        'status' => 1,
        'subtotal_price' => 90.0,
        'total_price' => 90.0,
        'discount_amount' => 0,
        'products' => [
            [
                'id' => 'p1',
                'total_price' => 80.0,
                'unit_price' => 100.0,
                'quantity' => 1,
                'discount_amount' => 20.0,
                'taxes' => [],
                'options' => [],
            ],
            [
                'id' => 'p2',
                'total_price' => 90.0,
                'unit_price' => 100.0,
                'quantity' => 1,
                'discount_amount' => 10.0,
                'taxes' => [],
                'options' => [],
            ],
        ],
        'combos' => [],
        'charges' => [],
        'payments' => [],
    ];

    $summary = $this->service->summarizeFoodicsOrder($order);

    expect($summary->productDiscountTotal)->toBe(30.0);
});

it('summarizes option discounts', function () {
    $order = [
        'status' => 1,
        'subtotal_price' => 50.0,
        'total_price' => 50.0,
        'discount_amount' => 0,
        'products' => [
            [
                'id' => 'p1',
                'total_price' => 40.0,
                'unit_price' => 50.0,
                'quantity' => 1,
                'discount_amount' => 0,
                'taxes' => [],
                'options' => [
                    [
                        'id' => 'o1',
                        'total_price' => 10.0,
                        'unit_price' => 15.0,
                        'quantity' => 1,
                        'discount_amount' => 5.0,
                        'taxes' => [],
                        'modifier_option' => ['id' => 'o1', 'name' => 'Extra'],
                    ],
                ],
            ],
        ],
        'combos' => [],
        'charges' => [],
        'payments' => [],
    ];

    $summary = $this->service->summarizeFoodicsOrder($order);

    expect($summary->optionDiscountTotal)->toBe(5.0);
});

it('summarizes option discounts using tax_exclusive_discount_amount fallback', function () {
    $order = [
        'status' => 1,
        'subtotal_price' => 50.0,
        'total_price' => 50.0,
        'discount_amount' => 0,
        'products' => [
            [
                'id' => 'p1',
                'total_price' => 40.0,
                'unit_price' => 50.0,
                'quantity' => 1,
                'discount_amount' => 0,
                'taxes' => [],
                'options' => [
                    [
                        'id' => 'o1',
                        'total_price' => 10.0,
                        'unit_price' => 15.0,
                        'quantity' => 1,
                        'tax_exclusive_discount_amount' => 5.0,
                        'taxes' => [],
                        'modifier_option' => ['id' => 'o1', 'name' => 'Extra'],
                    ],
                ],
            ],
        ],
        'combos' => [],
        'charges' => [],
        'payments' => [],
    ];

    $summary = $this->service->summarizeFoodicsOrder($order);

    expect($summary->optionDiscountTotal)->toBe(5.0);
});

it('summarizes payment tips', function () {
    $order = [
        'status' => 1,
        'subtotal_price' => 100.0,
        'total_price' => 115.0,
        'discount_amount' => 0,
        'products' => [
            [
                'id' => 'p1',
                'total_price' => 100.0,
                'unit_price' => 100.0,
                'quantity' => 1,
                'discount_amount' => 0,
                'taxes' => [],
                'options' => [],
            ],
        ],
        'combos' => [],
        'charges' => [],
        'payments' => [
            ['amount' => 105.0, 'tips' => 5.0],
            ['amount' => 10.0, 'tips' => 0],
        ],
    ];

    $summary = $this->service->summarizeFoodicsOrder($order);

    expect($summary->tipTotal)->toBe(5.0)
        ->and($summary->paymentTotal)->toBe(115.0);
});

it('preserves rounding amount', function () {
    $order = [
        'status' => 1,
        'subtotal_price' => 100.0,
        'total_price' => 100.02,
        'discount_amount' => 0,
        'rounding_amount' => 0.02,
        'products' => [
            [
                'id' => 'p1',
                'total_price' => 100.0,
                'unit_price' => 100.0,
                'quantity' => 1,
                'discount_amount' => 0,
                'taxes' => [],
                'options' => [],
            ],
        ],
        'combos' => [],
        'charges' => [],
        'payments' => [
            ['amount' => 100.02, 'tips' => 0],
        ],
    ];

    $summary = $this->service->summarizeFoodicsOrder($order);

    expect($summary->roundingAmount)->toBe(0.02);
});

it('summarizes a combo order with child products', function () {
    $order = [
        'status' => 1,
        'subtotal_price' => 80.0,
        'total_price' => 80.0,
        'discount_amount' => 0,
        'products' => [],
        'combos' => [
            [
                'id' => 'c1',
                'discount_amount' => 0,
                'products' => [
                    [
                        'id' => 'cp1',
                        'total_price' => 50.0,
                        'unit_price' => 50.0,
                        'quantity' => 1,
                        'discount_amount' => 5.0,
                        'taxes' => [['id' => 't1', 'pivot' => ['amount' => 7.5]]],
                        'options' => [],
                    ],
                    [
                        'id' => 'cp2',
                        'total_price' => 30.0,
                        'unit_price' => 30.0,
                        'quantity' => 1,
                        'discount_amount' => 0,
                        'taxes' => [],
                        'options' => [],
                    ],
                ],
            ],
        ],
        'charges' => [],
        'payments' => [],
    ];

    $summary = $this->service->summarizeFoodicsOrder($order);

    expect($summary->comboProductTotal)->toBe(80.0)
        ->and($summary->comboDiscountTotal)->toBe(5.0)
        ->and($summary->taxTotal)->toBe(7.5);
});

it('records combo product options as a known gap', function () {
    $order = [
        'status' => 1,
        'subtotal_price' => 90.0,
        'total_price' => 90.0,
        'discount_amount' => 0,
        'products' => [],
        'combos' => [
            [
                'id' => 'c1',
                'discount_amount' => 0,
                'products' => [
                    [
                        'id' => 'cp1',
                        'total_price' => 50.0,
                        'unit_price' => 50.0,
                        'quantity' => 1,
                        'discount_amount' => 0,
                        'taxes' => [],
                        'options' => [
                            [
                                'id' => 'co1',
                                'total_price' => 10.0,
                                'unit_price' => 10.0,
                                'quantity' => 1,
                                'discount_amount' => 0,
                                'taxes' => [],
                                'modifier_option' => ['id' => 'co1', 'name' => 'Add-on'],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'charges' => [],
        'payments' => [],
    ];

    $summary = $this->service->summarizeFoodicsOrder($order);

    expect($summary->status)->toBe(SalesReconciliationStatus::KnownGap)
        ->and($summary->differences)->toHaveCount(1)
        ->and($summary->differences[0]->component)->toBe('combo_option_total')
        ->and($summary->differences[0]->foodicsAmount)->toBe(10.0)
        ->and($summary->differences[0]->severity)->toBe('known_gap');
});

it('records combo wrapper discounts as a known gap', function () {
    $order = [
        'status' => 1,
        'subtotal_price' => 80.0,
        'total_price' => 70.0,
        'discount_amount' => 0,
        'products' => [],
        'combos' => [
            [
                'id' => 'c1',
                'discount_amount' => 10.0,
                'products' => [
                    [
                        'id' => 'cp1',
                        'total_price' => 80.0,
                        'unit_price' => 80.0,
                        'quantity' => 1,
                        'discount_amount' => 0,
                        'taxes' => [],
                        'options' => [],
                    ],
                ],
            ],
        ],
        'charges' => [],
        'payments' => [],
    ];

    $summary = $this->service->summarizeFoodicsOrder($order);

    $wrapperDiff = collect($summary->differences)->first(fn ($d) => $d->component === 'combo_wrapper_discount');

    expect($summary->status)->toBe(SalesReconciliationStatus::KnownGap)
        ->and($wrapperDiff)->not->toBeNull()
        ->and($wrapperDiff->foodicsAmount)->toBe(10.0)
        ->and($wrapperDiff->severity)->toBe('known_gap');
});

it('summarizes a returned order as a credit-note reconciliation', function () {
    $order = [
        'status' => 5,
        'subtotal_price' => 50.0,
        'total_price' => 50.0,
        'discount_amount' => 0,
        'original_order' => ['id' => 'orig-1'],
        'products' => [
            [
                'id' => 'p1',
                'total_price' => 50.0,
                'unit_price' => 50.0,
                'quantity' => 1,
                'discount_amount' => 0,
                'taxes' => [],
                'options' => [],
            ],
        ],
        'combos' => [],
        'charges' => [],
        'payments' => [],
    ];

    $summary = $this->service->summarizeFoodicsOrder($order);

    expect($summary->type)->toBe('credit_note')
        ->and($summary->productTotal)->toBe(50.0);
});

it('records returned-order payments as a known gap', function () {
    $order = [
        'status' => 5,
        'subtotal_price' => 50.0,
        'total_price' => 50.0,
        'discount_amount' => 0,
        'original_order' => ['id' => 'orig-1'],
        'products' => [
            [
                'id' => 'p1',
                'total_price' => 50.0,
                'unit_price' => 50.0,
                'quantity' => 1,
                'discount_amount' => 0,
                'taxes' => [],
                'options' => [],
            ],
        ],
        'combos' => [],
        'charges' => [],
        'payments' => [
            ['amount' => 50.0, 'tips' => 0],
        ],
    ];

    $summary = $this->service->summarizeFoodicsOrder($order);

    $paymentDiff = collect($summary->differences)->first(fn ($d) => $d->component === 'return_payments');

    expect($paymentDiff)->not->toBeNull()
        ->and($paymentDiff->foodicsAmount)->toBe(50.0)
        ->and($paymentDiff->severity)->toBe('known_gap');
});

it('handles missing nullable arrays as empty collections', function () {
    $order = [
        'status' => 1,
        'subtotal_price' => 0,
        'total_price' => 0,
        'discount_amount' => 0,
    ];

    $summary = $this->service->summarizeFoodicsOrder($order);

    expect($summary->productTotal)->toBe(0.0)
        ->and($summary->optionTotal)->toBe(0.0)
        ->and($summary->comboProductTotal)->toBe(0.0)
        ->and($summary->chargeTotal)->toBe(0.0)
        ->and($summary->taxTotal)->toBe(0.0)
        ->and($summary->tipTotal)->toBe(0.0)
        ->and($summary->paymentTotal)->toBe(0.0)
        ->and($summary->productDiscountTotal)->toBe(0.0)
        ->and($summary->optionDiscountTotal)->toBe(0.0)
        ->and($summary->comboDiscountTotal)->toBe(0.0)
        ->and($summary->roundingAmount)->toBe(0.0)
        ->and($summary->expectedTotal)->toBe(0.0)
        ->and($summary->status)->toBe(SalesReconciliationStatus::Ok);
});

it('summarizes charge taxes from charges.*.taxes.*.pivot.amount', function () {
    $order = [
        'status' => 1,
        'subtotal_price' => 100.0,
        'total_price' => 111.5,
        'discount_amount' => 0,
        'products' => [],
        'combos' => [],
        'charges' => [
            [
                'amount' => 10.0,
                'charge' => ['name' => 'Delivery', 'value' => 10.0],
                'taxes' => [
                    ['id' => 't1', 'pivot' => ['amount' => 1.5]],
                ],
            ],
        ],
        'payments' => [],
    ];

    $summary = $this->service->summarizeFoodicsOrder($order);

    expect($summary->chargeTotal)->toBe(10.0)
        ->and($summary->taxTotal)->toBe(1.5);
});

it('classifies ok when Foodics and Daftra amounts match', function () {
    $order = [
        'status' => 1,
        'subtotal_price' => 100.0,
        'total_price' => 90.0,
        'discount_amount' => 10.0,
        'products' => [
            [
                'id' => 'p1',
                'total_price' => 100.0,
                'unit_price' => 100.0,
                'quantity' => 1,
                'discount_amount' => 0,
                'taxes' => [],
                'options' => [],
            ],
        ],
        'combos' => [],
        'charges' => [],
        'payments' => [],
    ];

    $daftraPayload = [
        'Invoice' => [
            'discount_amount' => 10.0,
        ],
        'InvoiceItem' => [
            [
                'unit_price' => 100.0,
                'quantity' => 1,
                'discount' => 0,
            ],
        ],
    ];

    $result = $this->service->compare($order, $daftraPayload);

    expect($result->status)->toBe(SalesReconciliationStatus::Ok);
});

it('returns rounding_only when total differences are within tolerance', function () {
    $order = [
        'status' => 1,
        'subtotal_price' => 100.0,
        'total_price' => 100.005,
        'discount_amount' => 0,
        'products' => [
            [
                'id' => 'p1',
                'total_price' => 100.0,
                'unit_price' => 100.0,
                'quantity' => 1,
                'discount_amount' => 0,
                'taxes' => [],
                'options' => [],
            ],
        ],
        'combos' => [],
        'charges' => [],
        'payments' => [
            ['amount' => 100.005, 'tips' => 0],
        ],
    ];

    $daftraPayload = [
        'Invoice' => [
            'discount_amount' => 0,
        ],
        'InvoiceItem' => [
            [
                'unit_price' => 100.0,
                'quantity' => 1,
                'discount' => 0,
            ],
        ],
        'InvoicePayment' => [
            ['amount' => 100.005],
        ],
    ];

    $result = $this->service->compare($order, $daftraPayload);

    expect($result->status)->toBe(SalesReconciliationStatus::RoundingOnly);
});

it('returns known_gap for tips not represented on the Daftra payload', function () {
    $order = [
        'status' => 1,
        'subtotal_price' => 100.0,
        'total_price' => 105.0,
        'discount_amount' => 0,
        'products' => [
            [
                'id' => 'p1',
                'total_price' => 100.0,
                'unit_price' => 100.0,
                'quantity' => 1,
                'discount_amount' => 0,
                'taxes' => [],
                'options' => [],
            ],
        ],
        'combos' => [],
        'charges' => [],
        'payments' => [
            ['amount' => 105.0, 'tips' => 5.0],
        ],
    ];

    $daftraPayload = [
        'Invoice' => [
            'discount_amount' => 0,
        ],
        'InvoiceItem' => [
            [
                'unit_price' => 100.0,
                'quantity' => 1,
                'discount' => 0,
            ],
        ],
    ];

    $result = $this->service->compare($order, $daftraPayload);

    expect($result->status)->toBe(SalesReconciliationStatus::KnownGap);

    $tipsDiff = collect($result->differences)->first(fn ($d) => $d->component === 'tips');
    expect($tipsDiff)->not->toBeNull()
        ->and($tipsDiff->severity)->toBe('known_gap');
});

it('returns known_gap for rounding not represented on Daftra payload', function () {
    $order = [
        'status' => 1,
        'subtotal_price' => 100.0,
        'total_price' => 100.02,
        'discount_amount' => 0,
        'rounding_amount' => 0.02,
        'products' => [
            [
                'id' => 'p1',
                'total_price' => 100.0,
                'unit_price' => 100.0,
                'quantity' => 1,
                'discount_amount' => 0,
                'taxes' => [],
                'options' => [],
            ],
        ],
        'combos' => [],
        'charges' => [],
        'payments' => [],
    ];

    $daftraPayload = [
        'Invoice' => [
            'discount_amount' => 0,
        ],
        'InvoiceItem' => [
            [
                'unit_price' => 100.0,
                'quantity' => 1,
                'discount' => 0,
            ],
        ],
    ];

    $result = $this->service->compare($order, $daftraPayload);

    $roundingDiff = collect($result->differences)->first(fn ($d) => $d->component === 'rounding');
    expect($roundingDiff)->not->toBeNull()
        ->and($roundingDiff->severity)->toBe('known_gap');
});

it('returns known_gap for combo option amounts ignored by current sync', function () {
    $order = [
        'status' => 1,
        'subtotal_price' => 90.0,
        'total_price' => 90.0,
        'discount_amount' => 0,
        'products' => [],
        'combos' => [
            [
                'id' => 'c1',
                'discount_amount' => 0,
                'products' => [
                    [
                        'id' => 'cp1',
                        'total_price' => 80.0,
                        'unit_price' => 80.0,
                        'quantity' => 1,
                        'discount_amount' => 0,
                        'taxes' => [],
                        'options' => [
                            [
                                'id' => 'co1',
                                'total_price' => 10.0,
                                'unit_price' => 10.0,
                                'quantity' => 1,
                                'discount_amount' => 0,
                                'taxes' => [],
                                'modifier_option' => ['id' => 'co1', 'name' => 'Add-on'],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'charges' => [],
        'payments' => [],
    ];

    $result = $this->service->summarizeFoodicsOrder($order);

    $comboOptionDiff = collect($result->differences)->first(fn ($d) => $d->component === 'combo_option_total');
    expect($comboOptionDiff)->not->toBeNull()
        ->and($comboOptionDiff->severity)->toBe('known_gap');
});

it('returns mismatch for unexplained total drift', function () {
    $order = [
        'status' => 1,
        'subtotal_price' => 100.0,
        'total_price' => 100.0,
        'discount_amount' => 0,
        'products' => [
            [
                'id' => 'p1',
                'total_price' => 100.0,
                'unit_price' => 100.0,
                'quantity' => 1,
                'discount_amount' => 0,
                'taxes' => [],
                'options' => [],
            ],
        ],
        'combos' => [],
        'charges' => [],
        'payments' => [],
    ];

    $daftraPayload = [
        'Invoice' => [
            'discount_amount' => 0,
        ],
        'InvoiceItem' => [
            [
                'unit_price' => 85.0,
                'quantity' => 1,
                'discount' => 0,
            ],
        ],
    ];

    $result = $this->service->compare($order, $daftraPayload);

    expect($result->status)->toBe(SalesReconciliationStatus::Mismatch);
});

it('includes component-level deltas in the result', function () {
    $order = [
        'status' => 1,
        'subtotal_price' => 100.0,
        'total_price' => 100.0,
        'discount_amount' => 0,
        'products' => [
            [
                'id' => 'p1',
                'total_price' => 100.0,
                'unit_price' => 100.0,
                'quantity' => 1,
                'discount_amount' => 0,
                'taxes' => [],
                'options' => [],
            ],
        ],
        'combos' => [],
        'charges' => [],
        'payments' => [],
    ];

    $daftraPayload = [
        'Invoice' => [
            'discount_amount' => 0,
        ],
        'InvoiceItem' => [
            [
                'unit_price' => 90.0,
                'quantity' => 1,
                'discount' => 0,
            ],
        ],
    ];

    $result = $this->service->compare($order, $daftraPayload);

    $totalDiff = collect($result->differences)->first(fn ($d) => $d->component === 'total');
    expect($totalDiff)->not->toBeNull()
        ->and($totalDiff->foodicsAmount)->toBe(100.0)
        ->and($totalDiff->daftraAmount)->toBe(90.0)
        ->and($totalDiff->delta)->toBe(10.0);
});

it('classifies known_gap when tips explain total difference', function () {
    $order = [
        'status' => 1,
        'subtotal_price' => 100.0,
        'total_price' => 105.0,
        'discount_amount' => 0,
        'products' => [
            [
                'id' => 'p1',
                'total_price' => 100.0,
                'unit_price' => 100.0,
                'quantity' => 1,
                'discount_amount' => 0,
                'taxes' => [],
                'options' => [],
            ],
        ],
        'combos' => [],
        'charges' => [],
        'payments' => [
            ['amount' => 105.0, 'tips' => 5.0],
        ],
    ];

    $daftraPayload = [
        'Invoice' => [
            'discount_amount' => 0,
        ],
        'InvoiceItem' => [
            [
                'unit_price' => 100.0,
                'quantity' => 1,
                'discount' => 0,
            ],
        ],
    ];

    $result = $this->service->compare($order, $daftraPayload);

    expect($result->status)->toBe(SalesReconciliationStatus::KnownGap);
});

it('uses daftraDocument total when available instead of recalculating from lines', function () {
    $order = [
        'status' => 1,
        'subtotal_price' => 100.0,
        'total_price' => 115.0,
        'discount_amount' => 0,
        'products' => [
            [
                'id' => 'p1',
                'total_price' => 100.0,
                'unit_price' => 100.0,
                'quantity' => 1,
                'discount_amount' => 0,
                'taxes' => [['id' => 't1', 'pivot' => ['amount' => 15.0]]],
                'options' => [],
            ],
        ],
        'combos' => [],
        'charges' => [],
        'payments' => [],
    ];

    $daftraPayload = [
        'Invoice' => [
            'discount_amount' => 0,
        ],
        'InvoiceItem' => [
            [
                'unit_price' => 100.0,
                'quantity' => 1,
                'discount' => 0,
            ],
        ],
    ];

    $daftraDocument = [
        'Invoice' => [
            'total' => 115.0,
        ],
    ];

    $result = $this->service->compare($order, $daftraPayload, $daftraDocument);

    expect($result->status)->toBe(SalesReconciliationStatus::Ok);
});

it('does not classify as known_gap when known gap amount does not cover the drift', function () {
    $order = [
        'status' => 1,
        'subtotal_price' => 100.0,
        'total_price' => 120.0,
        'discount_amount' => 0,
        'products' => [
            [
                'id' => 'p1',
                'total_price' => 100.0,
                'unit_price' => 100.0,
                'quantity' => 1,
                'discount_amount' => 0,
                'taxes' => [],
                'options' => [],
            ],
        ],
        'combos' => [],
        'charges' => [],
        'payments' => [
            ['amount' => 120.0, 'tips' => 2.0],
        ],
    ];

    $daftraPayload = [
        'Invoice' => [
            'discount_amount' => 0,
        ],
        'InvoiceItem' => [
            [
                'unit_price' => 100.0,
                'quantity' => 1,
                'discount' => 0,
            ],
        ],
    ];

    $result = $this->service->compare($order, $daftraPayload);

    expect($result->status)->toBe(SalesReconciliationStatus::Mismatch);

    $totalDiff = collect($result->differences)->first(fn ($d) => $d->component === 'total');
    expect($totalDiff)->not->toBeNull()
        ->and($totalDiff->severity)->toBe('mismatch');
});

it('classifies known_gap when known gap fully explains total drift', function () {
    $order = [
        'status' => 1,
        'subtotal_price' => 100.0,
        'total_price' => 103.0,
        'discount_amount' => 0,
        'products' => [
            [
                'id' => 'p1',
                'total_price' => 100.0,
                'unit_price' => 100.0,
                'quantity' => 1,
                'discount_amount' => 0,
                'taxes' => [],
                'options' => [],
            ],
        ],
        'combos' => [],
        'charges' => [],
        'payments' => [
            ['amount' => 103.0, 'tips' => 3.0],
        ],
    ];

    $daftraPayload = [
        'Invoice' => [
            'discount_amount' => 0,
        ],
        'InvoiceItem' => [
            [
                'unit_price' => 100.0,
                'quantity' => 1,
                'discount' => 0,
            ],
        ],
    ];

    $result = $this->service->compare($order, $daftraPayload);

    expect($result->status)->toBe(SalesReconciliationStatus::KnownGap);

    $totalDiff = collect($result->differences)->first(fn ($d) => $d->component === 'total');
    expect($totalDiff)->not->toBeNull()
        ->and($totalDiff->severity)->toBe('known_gap');
});

it('prefers daftraDocument total over payload line calculation', function () {
    $order = [
        'status' => 1,
        'subtotal_price' => 100.0,
        'total_price' => 115.0,
        'discount_amount' => 0,
        'products' => [
            [
                'id' => 'p1',
                'total_price' => 100.0,
                'unit_price' => 100.0,
                'quantity' => 1,
                'discount_amount' => 0,
                'taxes' => [['id' => 't1', 'pivot' => ['amount' => 15.0]]],
                'options' => [],
            ],
        ],
        'combos' => [],
        'charges' => [],
        'payments' => [],
    ];

    $daftraPayload = [
        'Invoice' => [
            'discount_amount' => 0,
        ],
        'InvoiceItem' => [
            [
                'unit_price' => 100.0,
                'quantity' => 1,
                'discount' => 0,
            ],
        ],
    ];

    $daftraDocument = [
        'Invoice' => [
            'total' => 115.0,
        ],
    ];

    $resultWithoutDoc = $this->service->compare($order, $daftraPayload);
    $resultWithDoc = $this->service->compare($order, $daftraPayload, $daftraDocument);

    expect($resultWithoutDoc->differences[0]->daftraAmount)->toBe(100.0);

    $totalDiff = collect($resultWithDoc->differences)->first(fn ($d) => $d->component === 'total');
    expect($totalDiff)->toBeNull();

    expect($resultWithDoc->status)->toBe(SalesReconciliationStatus::Ok);
});

it('uses daftraDocument net field when total is not present', function () {
    $order = [
        'status' => 1,
        'subtotal_price' => 100.0,
        'total_price' => 115.0,
        'discount_amount' => 0,
        'products' => [
            [
                'id' => 'p1',
                'total_price' => 100.0,
                'unit_price' => 100.0,
                'quantity' => 1,
                'discount_amount' => 0,
                'taxes' => [],
                'options' => [],
            ],
        ],
        'combos' => [],
        'charges' => [],
        'payments' => [],
    ];

    $daftraPayload = [
        'Invoice' => [
            'discount_amount' => 0,
        ],
        'InvoiceItem' => [
            [
                'unit_price' => 100.0,
                'quantity' => 1,
                'discount' => 0,
            ],
        ],
    ];

    $daftraDocument = [
        'Invoice' => [
            'net' => 115.0,
        ],
    ];

    $result = $this->service->compare($order, $daftraPayload, $daftraDocument);

    expect($result->status)->toBe(SalesReconciliationStatus::Ok);
});

it('classifies as mismatch when known gaps over-explain total drift', function () {
    $order = [
        'status' => 1,
        'subtotal_price' => 100.0,
        'total_price' => 103.0,
        'discount_amount' => 0,
        'products' => [
            [
                'id' => 'p1',
                'total_price' => 100.0,
                'unit_price' => 100.0,
                'quantity' => 1,
                'discount_amount' => 0,
                'taxes' => [],
                'options' => [],
            ],
        ],
        'combos' => [],
        'charges' => [],
        'payments' => [
            ['amount' => 103.0, 'tips' => 10.0],
        ],
    ];

    $daftraPayload = [
        'Invoice' => [
            'discount_amount' => 0,
        ],
        'InvoiceItem' => [
            [
                'unit_price' => 100.0,
                'quantity' => 1,
                'discount' => 0,
            ],
        ],
    ];

    $result = $this->service->compare($order, $daftraPayload);

    expect($result->status)->toBe(SalesReconciliationStatus::Mismatch);

    $totalDiff = collect($result->differences)->first(fn ($d) => $d->component === 'total');
    expect($totalDiff)->not->toBeNull()
        ->and($totalDiff->severity)->toBe('mismatch');
});

it('classifies as mismatch when known gap amount exceeds drift', function () {
    $order = [
        'status' => 1,
        'subtotal_price' => 100.0,
        'total_price' => 102.0,
        'discount_amount' => 0,
        'rounding_amount' => 5.0,
        'products' => [
            [
                'id' => 'p1',
                'total_price' => 100.0,
                'unit_price' => 100.0,
                'quantity' => 1,
                'discount_amount' => 0,
                'taxes' => [],
                'options' => [],
            ],
        ],
        'combos' => [],
        'charges' => [],
        'payments' => [],
    ];

    $daftraPayload = [
        'Invoice' => [
            'discount_amount' => 0,
        ],
        'InvoiceItem' => [
            [
                'unit_price' => 100.0,
                'quantity' => 1,
                'discount' => 0,
            ],
        ],
    ];

    $result = $this->service->compare($order, $daftraPayload);

    expect($result->status)->toBe(SalesReconciliationStatus::Mismatch);
});

it('uses daftraDocument CreditNote total for credit-note comparison', function () {
    $order = [
        'status' => 5,
        'subtotal_price' => 50.0,
        'total_price' => 57.5,
        'discount_amount' => 0,
        'original_order' => ['id' => 'orig-1'],
        'products' => [
            [
                'id' => 'p1',
                'total_price' => 50.0,
                'unit_price' => 50.0,
                'quantity' => 1,
                'discount_amount' => 0,
                'taxes' => [['id' => 't1', 'pivot' => ['amount' => 7.5]]],
                'options' => [],
            ],
        ],
        'combos' => [],
        'charges' => [],
        'payments' => [],
    ];

    $daftraPayload = [
        'CreditNote' => [
            'discount_amount' => 0,
        ],
        'InvoiceItem' => [
            [
                'unit_price' => 50.0,
                'quantity' => 1,
                'discount' => 0,
            ],
        ],
    ];

    $daftraDocument = [
        'total' => 57.5,
    ];

    $result = $this->service->compare($order, $daftraPayload, $daftraDocument);

    expect($result->status)->toBe(SalesReconciliationStatus::Ok);
});

it('does not use return_payments to explain credit-note total drift', function () {
    $order = [
        'status' => 5,
        'subtotal_price' => 100.0,
        'total_price' => 100.0,
        'discount_amount' => 0,
        'original_order' => ['id' => 'orig-1'],
        'products' => [
            [
                'id' => 'p1',
                'total_price' => 100.0,
                'unit_price' => 100.0,
                'quantity' => 1,
                'discount_amount' => 0,
                'taxes' => [],
                'options' => [],
            ],
        ],
        'combos' => [],
        'charges' => [],
        'payments' => [
            ['amount' => 50.0, 'tips' => 0],
        ],
    ];

    $daftraPayload = [
        'CreditNote' => [
            'discount_amount' => 0,
        ],
        'InvoiceItem' => [
            [
                'unit_price' => 50.0,
                'quantity' => 1,
                'discount' => 0,
            ],
        ],
    ];

    $result = $this->service->compare($order, $daftraPayload);

    $returnPaymentsDiff = collect($result->differences)->first(fn ($d) => $d->component === 'return_payments');
    expect($returnPaymentsDiff)->not->toBeNull()
        ->and($returnPaymentsDiff->severity)->toBe('known_gap');

    $totalDiff = collect($result->differences)->first(fn ($d) => $d->component === 'total');
    expect($totalDiff)->not->toBeNull()
        ->and($totalDiff->severity)->toBe('mismatch')
        ->and($totalDiff->delta)->toBe(50.0);
});

it('extracts payment totals from Daftra-shaped InvoicePayment rows', function () {
    $order = [
        'status' => 1,
        'subtotal_price' => 100.0,
        'total_price' => 100.0,
        'discount_amount' => 0,
        'products' => [
            [
                'id' => 'p1',
                'total_price' => 100.0,
                'unit_price' => 100.0,
                'quantity' => 1,
                'discount_amount' => 0,
                'taxes' => [],
                'options' => [],
            ],
        ],
        'combos' => [],
        'charges' => [],
        'payments' => [
            ['amount' => 100.0, 'tips' => 0],
        ],
    ];

    $daftraPayload = [
        'Invoice' => [
            'discount_amount' => 0,
        ],
        'InvoiceItem' => [
            [
                'unit_price' => 100.0,
                'quantity' => 1,
                'discount' => 0,
            ],
        ],
        'InvoicePayment' => [
            ['InvoicePayment' => ['amount' => 70.0, 'payment_method' => 1]],
            ['InvoicePayment' => ['amount' => 30.0, 'payment_method' => 2]],
        ],
    ];

    $result = $this->service->compare($order, $daftraPayload);

    expect($result->status)->toBe(SalesReconciliationStatus::Ok);

    $paymentDiff = collect($result->differences)->first(fn ($d) => $d->component === 'payments');
    expect($paymentDiff)->toBeNull();
});

it('detects payment mismatch when existing Daftra payments differ from Foodics payments', function () {
    $order = [
        'status' => 1,
        'subtotal_price' => 100.0,
        'total_price' => 100.0,
        'discount_amount' => 0,
        'products' => [
            [
                'id' => 'p1',
                'total_price' => 100.0,
                'unit_price' => 100.0,
                'quantity' => 1,
                'discount_amount' => 0,
                'taxes' => [],
                'options' => [],
            ],
        ],
        'combos' => [],
        'charges' => [],
        'payments' => [
            ['amount' => 100.0, 'tips' => 0],
        ],
    ];

    $daftraPayload = [
        'Invoice' => [
            'discount_amount' => 0,
        ],
        'InvoiceItem' => [
            [
                'unit_price' => 100.0,
                'quantity' => 1,
                'discount' => 0,
            ],
        ],
        'InvoicePayment' => [
            ['InvoicePayment' => ['amount' => 75.0, 'payment_method' => 1]],
        ],
    ];

    $result = $this->service->compare($order, $daftraPayload);

    $paymentDiff = collect($result->differences)->first(fn ($d) => $d->component === 'payments');
    expect($paymentDiff)->not->toBeNull()
        ->and($paymentDiff->severity)->toBe('mismatch')
        ->and($paymentDiff->foodicsAmount)->toBe(100.0)
        ->and($paymentDiff->daftraAmount)->toBe(75.0);
});

it('includes return_payments as known gap but keeps it separate from total drift calculation', function () {
    $order = [
        'status' => 5,
        'subtotal_price' => 100.0,
        'total_price' => 100.0,
        'discount_amount' => 0,
        'original_order' => ['id' => 'orig-1'],
        'products' => [
            [
                'id' => 'p1',
                'total_price' => 100.0,
                'unit_price' => 100.0,
                'quantity' => 1,
                'discount_amount' => 0,
                'taxes' => [],
                'options' => [],
            ],
        ],
        'combos' => [],
        'charges' => [],
        'payments' => [
            ['amount' => 100.0, 'tips' => 0],
        ],
    ];

    $summary = $this->service->summarizeFoodicsOrder($order);

    $returnPaymentsDiff = collect($summary->differences)->first(fn ($d) => $d->component === 'return_payments');
    expect($returnPaymentsDiff)->not->toBeNull()
        ->and($returnPaymentsDiff->severity)->toBe('known_gap')
        ->and($returnPaymentsDiff->delta)->toBe(100.0);
});
