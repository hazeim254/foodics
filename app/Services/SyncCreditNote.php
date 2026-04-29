<?php

namespace App\Services;

use App\Enums\InvoiceSyncStatus;
use App\Enums\InvoiceType;
use App\Exceptions\InvalidOrderLineException;
use App\Exceptions\OriginalInvoiceNotSyncedException;
use App\Models\Invoice;
use App\Services\Concerns\BuildsInvoiceItems;
use App\Services\Daftra\ClientService;
use App\Services\Daftra\InvoiceService;
use App\Services\Daftra\ProductService;
use App\Services\Daftra\TaxService;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncCreditNote
{
    use BuildsInvoiceItems;

    public function __construct(
        protected InvoiceService $invoiceService,
        protected ProductService $productService,
        protected ClientService $clientService,
        protected TaxService $taxService,
    ) {}

    public function handle(array $order): void
    {
        $this->currentOrderId = $order['id'];

        $originalFoodicsId = data_get($order, 'original_order.id');
        if (empty($originalFoodicsId)) {
            throw (new InvalidOrderLineException('Return order is missing original_order reference.'))
                ->setOrderId($this->currentOrderId);
        }

        $original = Invoice::query()
            ->where('user_id', Context::get('user')?->id)
            ->where('foodics_id', $originalFoodicsId)
            ->where('type', InvoiceType::Invoice)
            ->first();

        if (
            $original === null
            || $original->status !== InvoiceSyncStatus::Synced
            || $original->daftra_id === null
        ) {
            throw (new OriginalInvoiceNotSyncedException)
                ->setOriginalFoodicsId($originalFoodicsId)
                ->setReturnFoodicsId($order['id']);
        }

        $creditNoteRow = $this->createPendingCreditNote($order, $original);

        try {
            $this->taxMap = [];
            $this->resolveUniqueTaxes($order);

            $daftraCreditNoteId = $this->resolveDaftraCreditNoteId($order, $creditNoteRow, $original);

            if ($creditNoteRow->daftra_id !== $daftraCreditNoteId) {
                $creditNoteRow->update(['daftra_id' => $daftraCreditNoteId]);
            }

            if (! empty($order['payments'])) {
                Log::warning('Return order carries payments; credit-note payments are not yet synced.', [
                    'order_id' => $order['id'],
                    'payments_count' => count($order['payments']),
                ]);
            }

            $creditNoteRow->update(['status' => InvoiceSyncStatus::Synced]);
        } catch (Throwable $e) {
            $creditNoteRow->update(['status' => InvoiceSyncStatus::Failed]);

            throw $e;
        } finally {
            $this->currentOrderId = null;
        }
    }

    protected function resolveDaftraCreditNoteId(array $order, Invoice $row, Invoice $original): int
    {
        if ($row->daftra_id !== null) {
            return (int) $row->daftra_id;
        }

        $existing = $this->invoiceService->getCreditNote($order['id']);
        if (! empty($existing['id'])) {
            return (int) $existing['id'];
        }

        $invoiceItems = $this->getInvoiceItems($this->getOrderProductLines($order));
        $invoiceItems = $this->addChargeInvoiceItems($invoiceItems, $order['charges'] ?? []);

        $clientId = null;
        if (! empty($order['customer'])) {
            $clientId = $this->clientService->getClientUsingFoodicsData($order['customer']);
        }

        if (! $clientId) {
            $clientId = $original->daftra_metadata['client_id'] ?? null;
        }

        if (! $clientId) {
            $clientId = $this->resolveDefaultClientId();
        }

        $payload = [
            'Invoice' => [
                'po_number' => $order['id'],
                'client_id' => $clientId,
                'subscription_id' => (int) $original->daftra_id,
                'date' => $order['business_date'],
                'discount_amount' => $order['discount_amount'] ?? 0,
                'notes' => $order['kitchen_notes'] ?? null,
            ],
            'InvoiceItem' => $invoiceItems,
        ];

        return $this->invoiceService->createCreditNote($payload);
    }

    protected function createPendingCreditNote(array $order, Invoice $original): Invoice
    {
        $userId = Context::get('user')?->id;

        $creditNote = Invoice::query()
            ->where('user_id', $userId)
            ->where('foodics_id', $order['id'])
            ->first();

        if ($creditNote !== null) {
            $creditNote->fill([
                'foodics_reference' => $order['reference'],
                'status' => InvoiceSyncStatus::Pending,
                'foodics_metadata' => [
                    'total_price' => (float) ($order['total_price'] ?? 0),
                ],
            ])->save();

            return $creditNote;
        }

        return Invoice::query()->create([
            'user_id' => $userId,
            'foodics_id' => $order['id'],
            'foodics_reference' => $order['reference'],
            'daftra_id' => null,
            'type' => InvoiceType::CreditNote,
            'original_invoice_id' => $original->id,
            'status' => InvoiceSyncStatus::Pending,
            'foodics_metadata' => [
                'total_price' => (float) ($order['total_price'] ?? 0),
            ],
        ]);
    }
}
