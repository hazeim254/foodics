<?php

namespace App\Services\Concerns;

use App\Enums\DaftraDiscountType;
use App\Enums\SettingKey;
use App\Exceptions\InvalidOrderLineException;
use App\Models\User;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;

trait BuildsInvoiceItems
{
    /** @var array<string, int> */
    protected array $taxMap = [];

    protected ?string $currentOrderId = null;

    public function getInvoiceItems($products): array
    {
        $invoiceItems = [];
        foreach ($products as $orderProduct) {
            $foodicsProductId = $this->resolveFoodicsProductId($orderProduct);
            $embeddedProduct = is_array($orderProduct['product'] ?? null) ? $orderProduct['product'] : [];
            $sku = trim((string) ($embeddedProduct['sku'] ?? ($orderProduct['sku'] ?? '')));
            $enrichedProduct = array_merge($orderProduct, [
                'id' => $embeddedProduct['id'] ?? $foodicsProductId,
                'name' => $embeddedProduct['name'] ?? ($orderProduct['name'] ?? 'Foodics Product'),
                'sku' => $sku !== '' ? $sku : $foodicsProductId,
                'description' => $embeddedProduct['description'] ?? ($orderProduct['description'] ?? ''),
                'barcode' => $embeddedProduct['barcode'] ?? ($orderProduct['barcode'] ?? null),
                'price' => $embeddedProduct['price'] ?? ($orderProduct['price'] ?? null),
                'cost' => $embeddedProduct['cost'] ?? ($orderProduct['cost'] ?? null),
                'is_active' => $embeddedProduct['is_active'] ?? ($orderProduct['is_active'] ?? true),
            ]);

            $daftraProductId = $this->productService->getProductByFoodicsData($enrichedProduct);

            $taxes = $orderProduct['taxes'] ?? [];
            $daftraTaxIds = collect($taxes)
                ->pluck('id')
                ->map(fn ($foodicsId) => $this->taxMap[$foodicsId] ?? null)
                ->filter()
                ->values()
                ->take(2);

            $invoiceItems[] = [
                'product_id' => $daftraProductId,
                'item' => $enrichedProduct['name'] ?? 'Foodics Product',
                'quantity' => $orderProduct['quantity'],
                'unit_price' => $orderProduct['unit_price'],
                'discount' => $this->perUnitDiscount($orderProduct['discount_amount'] ?? 0, $orderProduct['quantity'] ?? 1),
                'discount_type' => DaftraDiscountType::Fixed->value,
                'tax1' => $daftraTaxIds->get(0),
                'tax2' => $daftraTaxIds->get(1),
            ];

            foreach ($orderProduct['options'] ?? [] as $option) {
                $invoiceItems[] = $this->buildOptionInvoiceItem($option);
            }
        }

        return $invoiceItems;
    }

    protected function buildOptionInvoiceItem(array $option): array
    {
        $modifierOption = $option['modifier_option'] ?? [];
        $foodicsId = $modifierOption['id'] ?? ($option['id'] ?? null);

        if ($foodicsId === null || (is_string($foodicsId) && trim($foodicsId) === '')) {
            throw (new InvalidOrderLineException('Order option line is missing a Foodics id.'))
                ->setOrderId($this->currentOrderId)
                ->setLineIdentifier($modifierOption['sku'] ?? null);
        }

        $enriched = [
            'id' => $foodicsId,
            'name' => $modifierOption['name'] ?? 'Modifier Option',
            'sku' => isset($modifierOption['sku']) && trim((string) $modifierOption['sku']) !== ''
                ? trim((string) $modifierOption['sku'])
                : (string) $foodicsId,
            'description' => $modifierOption['description'] ?? '',
            'barcode' => $modifierOption['barcode'] ?? null,
            'price' => $modifierOption['price'] ?? 0,
            'cost' => $modifierOption['cost'] ?? null,
            'is_active' => $modifierOption['is_active'] ?? true,
        ];

        $daftraProductId = $this->productService->getProductByFoodicsData($enriched);

        $resolved = collect($option['taxes'] ?? [])
            ->map(fn (array $tax) => [
                'foodics_id' => $tax['id'] ?? null,
                'daftra_id' => $this->taxMap[$tax['id'] ?? null] ?? null,
            ])
            ->filter(fn (array $pair) => $pair['daftra_id'] !== null)
            ->values();

        $daftraTaxIds = $resolved->pluck('daftra_id')->take(2);

        if ($resolved->count() > 2) {
            $droppedFoodicsIds = $resolved->slice(2)->pluck('foodics_id')->values()->all();

            Log::warning('Option line has more than 2 taxes; dropping excess.', [
                'order_id' => $this->currentOrderId,
                'option_id' => $foodicsId,
                'dropped_foodics_tax_ids' => $droppedFoodicsIds,
            ]);
        }

        $discount = $option['discount_amount']
            ?? $option['tax_exclusive_discount_amount']
            ?? 0;

        $quantity = $option['quantity'] ?? 1;

        return [
            'product_id' => $daftraProductId,
            'item' => $enriched['name'],
            'quantity' => $quantity,
            'unit_price' => $option['unit_price'] ?? 0,
            'discount' => $this->perUnitDiscount($discount, $quantity),
            'discount_type' => DaftraDiscountType::Fixed->value,
            'tax1' => $daftraTaxIds->get(0),
            'tax2' => $daftraTaxIds->get(1),
        ];
    }

    public function addChargeInvoiceItems(array $invoiceItems, array $charges): array
    {
        foreach ($charges as $charge) {
            $taxes = $charge['taxes'] ?? [];
            $daftraTaxIds = collect($taxes)
                ->pluck('id')
                ->map(fn ($foodicsId) => $this->taxMap[$foodicsId] ?? null)
                ->filter()
                ->values()
                ->take(2);

            $invoiceItems[] = [
                'item' => $charge['charge']['name'],
                'quantity' => 1,
                'unit_price' => $charge['amount'],
                'discount' => 0,
                'discount_type' => DaftraDiscountType::Fixed->value,
                'tax1' => $daftraTaxIds->get(0),
                'tax2' => $daftraTaxIds->get(1),
            ];
        }

        return $invoiceItems;
    }

    protected function getOrderProductLines(array $order): array
    {
        $products = $order['products'] ?? [];

        foreach ($order['combos'] ?? [] as $combo) {
            foreach ($combo['products'] ?? [] as $comboProduct) {
                unset($comboProduct['options']);

                $products[] = $comboProduct;
            }
        }

        return $products;
    }

    protected function resolveUniqueTaxes(array $order): void
    {
        $allTaxes = collect();

        foreach ($order['products'] ?? [] as $product) {
            $allTaxes = $allTaxes->merge($product['taxes'] ?? []);
            foreach ($product['options'] ?? [] as $option) {
                $allTaxes = $allTaxes->merge($option['taxes'] ?? []);
            }
        }

        foreach ($order['combos'] ?? [] as $combo) {
            foreach ($combo['products'] ?? [] as $comboProduct) {
                $allTaxes = $allTaxes->merge($comboProduct['taxes'] ?? []);
            }
        }

        foreach ($order['charges'] ?? [] as $charge) {
            $allTaxes = $allTaxes->merge($charge['taxes'] ?? []);
        }

        $uniqueTaxes = $allTaxes->unique('id');
        foreach ($uniqueTaxes as $tax) {
            $foodicsId = (string) $tax['id'];
            if (! isset($this->taxMap[$foodicsId])) {
                $this->taxMap[$foodicsId] = $this->taxService->resolveTaxId($tax);
            }
        }
    }

    /**
     * Foodics emits discount as a total fixed amount on the line, but Daftra
     * multiplies the line discount by quantity. Convert to per-unit so the
     * total Daftra applies matches what Foodics intended.
     */
    protected function perUnitDiscount(float|int $discount, float|int $quantity): float|int
    {
        return $quantity > 0 ? $discount / $quantity : $discount;
    }

    protected function resolveDefaultClientId(): ?int
    {
        $user = Context::get('user');
        if (! $user instanceof User) {
            return null;
        }

        $default = $user->setting(SettingKey::DaftraDefaultClientId);

        return $default !== null && $default !== '' ? (int) $default : null;
    }

    /**
     * @param  array<string, mixed>  $orderProduct
     */
    protected function resolveFoodicsProductId(array $orderProduct): string
    {
        $productId = data_get($orderProduct, 'product.id') ?? ($orderProduct['id'] ?? null);
        if (! is_string($productId) || trim($productId) === '') {
            throw (new InvalidOrderLineException('Order product line is missing a Foodics product id.'))
                ->setOrderId($this->currentOrderId)
                ->setLineIdentifier($orderProduct['sku'] ?? null);
        }

        return $productId;
    }
}
