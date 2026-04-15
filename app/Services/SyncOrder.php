<?php

namespace App\Services;

use App\Exceptions\InvoiceAlreadyExistsException;
use App\Models\Invoice;
use App\Services\Daftra\ClientService;
use App\Services\Daftra\InvoiceService;
use App\Services\Daftra\PaymentMethodService;
use App\Services\Daftra\ProductService;
use App\Services\Daftra\TaxService;

class SyncOrder
{
    public function __construct(
        protected InvoiceService $invoiceService,
        protected ProductService $productService,
        protected ClientService $clientService,
        protected TaxService $taxService,
        protected PaymentMethodService $paymentMethodService,
    ) {}

    /** @var array<string, int> */
    protected array $taxMap = [];

    /** @var array<string, int> */
    protected array $paymentMethodMap = [];

    /**
     * A sample of the array structure of foodics order
     * and Daftra Invoice are found in see section.
     *
     * @see json-stubs/foodics/get-order.json
     * @see json-stubs/daftra/create-invoice.json
     */
    public function handle(array $order): void
    {

        try {
            $this->skipIfAlreadySynced($order['id']);
        } catch (InvoiceAlreadyExistsException $e) {
            return;
        }

        // 1. Resolve all unique taxes from the order
        $this->taxMap = [];
        $this->resolveUniqueTaxes($order);

        // 2. Resolve all unique payment methods from the order
        $this->paymentMethodMap = [];
//        $this->resolveUniquePaymentMethods($order);

        // 2. Build invoice line items by resolving Daftra product IDs
        $invoiceItems = $this->getInvoiceItems($order['products']);

        // 3. Add charges as invoice items
        $invoiceItems = $this->addChargeInvoiceItems($invoiceItems, $order['charges'] ?? []);

        // 4. Resolve Daftra client ID from the order customer
        $clientId = null;
        if (! empty($order['customer'])) {
            $clientId = $this->clientService->getClientUsingFoodicsData($order['customer']);
        }

        // 5. Build the Daftra invoice payload
        //    po_number stores the Foodics order ID for later filtering
        $invoiceData = [
            'Invoice' => [
                'po_number' => $order['id'],
                'client_id' => $clientId,
                'date' => $order['business_date'],
                'discount_amount' => $order['discount_amount'] ?? 0,
                'notes' => $order['kitchen_notes'] ?? null,
            ],
            'InvoiceItem' => $invoiceItems,
        ];

        // 6. Create the invoice on Daftra
        $daftraInvoiceId = $this->invoiceService->createInvoice($invoiceData);

        // 7. Save the mapping between Foodics order ID and Daftra invoice ID
        $this->invoiceService->saveMapping($order['id'], $daftraInvoiceId, $order['reference']);
//        $this->syncPayment($order['payments'], $daftraInvoiceId);
    }

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
                'discount' => $orderProduct['discount_amount'] ?? 0,
                'discount_type' => $orderProduct['discount_type'] ?? 2,
                'tax1' => $daftraTaxIds->get(0),
                'tax2' => $daftraTaxIds->get(1),
            ];
        }

        return $invoiceItems;
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
                'discount_type' => 2,
                'tax1' => $daftraTaxIds->get(0),
                'tax2' => $daftraTaxIds->get(1),
            ];
        }

        return $invoiceItems;
    }

    protected function resolveUniqueTaxes(array $order): void
    {
        $allTaxes = collect();

        // Collect product-level taxes
        foreach ($order['products'] ?? [] as $product) {
            $allTaxes = $allTaxes->merge($product['taxes'] ?? []);
            // Collect modifier option taxes
            foreach ($product['options'] ?? [] as $option) {
                $allTaxes = $allTaxes->merge($option['taxes'] ?? []);
            }
        }

        // Collect charge taxes
        foreach ($order['charges'] ?? [] as $charge) {
            $allTaxes = $allTaxes->merge($charge['taxes'] ?? []);
        }

        // Deduplicate by Foodics tax ID and resolve each
        $uniqueTaxes = $allTaxes->unique('id');
        foreach ($uniqueTaxes as $tax) {
            $foodicsId = (string) $tax['id'];
            if (! isset($this->taxMap[$foodicsId])) {
                $this->taxMap[$foodicsId] = $this->taxService->resolveTaxId($tax);
            }
        }
    }

    protected function resolveUniquePaymentMethods(array $order): void
    {
        foreach ($order['payments'] ?? [] as $payment) {
            $foodicsPaymentMethod = $payment['payment_method'] ?? [];
            $foodicsId = (string) ($foodicsPaymentMethod['id'] ?? '');
            if ($foodicsId !== '' && ! isset($this->paymentMethodMap[$foodicsId])) {
                $this->paymentMethodMap[$foodicsId] = $this->paymentMethodService->resolvePaymentMethod($foodicsPaymentMethod);
            }
        }
    }

    public function syncPayment($payments, mixed $daftraInvoiceId): void
    {
        // 6. Sync payments against the newly created Daftra invoice
        foreach ($payments as $payment) {
            $foodicsPaymentMethodId = (string) ($payment['payment_method']['id'] ?? '');
            $daftraPaymentMethodId = $this->paymentMethodMap[$foodicsPaymentMethodId] ?? null;

            $this->invoiceService->createPayment($daftraInvoiceId, [
                'payment_method' => $daftraPaymentMethodId,
                'amount' => $payment['amount'],
                'date' => $payment['added_at'],
            ]);
        }
    }

    /**
     * @throws \Throwable
     */
    protected function skipIfAlreadySynced($id): void
    {
        $orderAlreadyExists = Invoice::query()->where('foodics_id', $id)->exists();
        throw_if($orderAlreadyExists, new InvoiceAlreadyExistsException('Order already synced locally'));

        // Skip if already exists on Daftra (e.g. synced by another process)
        $orderExistsOnDaftra = $this->invoiceService->doesFoodicsInvoiceExistInDaftra($id);
        throw_if($orderExistsOnDaftra, new InvoiceAlreadyExistsException('Order already synced on Daftra'));
    }

    /**
     * @param  array<string, mixed>  $orderProduct
     */
    protected function resolveFoodicsProductId(array $orderProduct): string
    {
        $productId = data_get($orderProduct, 'product.id') ?? ($orderProduct['id'] ?? null);
        if (! is_string($productId) || trim($productId) === '') {
            throw new \RuntimeException('Order product line is missing a Foodics product id.');
        }

        return $productId;
    }
}
