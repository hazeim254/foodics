<?php

namespace App\Services;

use App\Models\Invoice;
use App\Services\Daftra\ClientService;
use App\Services\Daftra\InvoiceService;
use App\Services\Daftra\ProductService;

class SyncOrder
{
    public function __construct(
        protected InvoiceService $invoiceService,
        protected ProductService $productService,
        protected ClientService $clientService,
    ) {}

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
            $this->skipIfAlreadySyncedLocally($order['id']);
        } catch (\Throwable $e) {
            return;
        }

        // 1. Build invoice line items by resolving Daftra product IDs
        $invoiceItems = $this->getInvoiceItems($order['products']);

        // 2. Resolve Daftra client ID from the order customer
        $clientId = null;
        if (! empty($order['customer'])) {
            $clientId = $this->clientService->getClientUsingFoodicsData($order['customer']);
        }

        // 3. Build the Daftra invoice payload
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

        // 4. Create the invoice on Daftra
        $daftraInvoiceId = $this->invoiceService->createInvoice($invoiceData);

        // 5. Save the mapping between Foodics order ID and Daftra invoice ID
        $this->invoiceService->saveMapping($order['id'], $daftraInvoiceId);
        $this->syncPayment($order['payments'], $daftraInvoiceId);
    }

    public function getInvoiceItems($products): array
    {
        $invoiceItems = [];
        foreach ($products as $orderProduct) {
            $daftraProductId = $this->productService->getProductByFoodicsData($orderProduct['product']);

            $invoiceItems[] = [
                'product_id' => $daftraProductId,
                'item' => $orderProduct['product']['name'],
                'quantity' => $orderProduct['quantity'],
                'unit_price' => $orderProduct['unit_price'],
                'discount' => $orderProduct['discount_amount'] ?? 0,
                'discount_type' => $orderProduct['discount_type'] ?? 2,
            ];
        }

        return $invoiceItems;
    }

    public function syncPayment($payments, mixed $daftraInvoiceId): void
    {
        // 6. Sync payments against the newly created Daftra invoice
        foreach ($payments as $payment) {
            $this->invoiceService->createPayment($daftraInvoiceId, [
                'payment_method' => $payment['payment_method']['name'],
                'amount' => $payment['amount'],
                'date' => $payment['added_at'],
            ]);
        }
    }

    /**
     * @throws \Throwable
     */
    protected function skipIfAlreadySyncedLocally($id): void
    {
        $orderAlreadyExists = Invoice::query()->where('foodics_id', $id)->exists();
        throw_if($orderAlreadyExists, new \RuntimeException('Order already synced'));

        // Skip if already exists on Daftra (e.g. synced by another process)
        $orderExistsOnDaftra = $this->invoiceService->doesFoodicsInvoiceExistInDaftra($id);
        throw_if($orderExistsOnDaftra, new \RuntimeException('Order already synced'));
    }
}
