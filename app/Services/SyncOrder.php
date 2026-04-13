<?php

namespace App\Services;

use App\Models\Invoice;
use App\Services\Daftra\InvoiceService;

class SyncOrder
{
    public function __construct(protected InvoiceService $invoiceService)
    {
    }

    /**
     * A sample of the array structure of foodics order
     * and Daftra Invoice are found in see section.
     *
     * @param array $order
     * @return void
     * @see json-stubs/foodics/get-order.json
     * @see json-stubs/daftra/create-invoice.json
     */
    public function handle(array $order)
    {
        $orderAlreadyExists = Invoice::query()->where('foodics_id', $order['id'])->exists();
        if ($orderAlreadyExists) {
            return;
        }

        $orderExistsOnDaftra = $this->invoiceService->getInvoiceByFoodicsId($order['id']);
        if ($orderExistsOnDaftra) {
            return;
        }
    }
}
