<?php

namespace App\Services;

use App\Enums\InvoiceSyncStatus;
use App\Enums\InvoiceType;
use App\Enums\SalesReconciliationStatus;
use App\Exceptions\InvalidOrderLineException;
use App\Exceptions\OriginalInvoiceNotSyncedException;
use App\Models\Invoice;
use App\Services\Concerns\BuildsInvoiceItems;
use App\Services\Daftra\ClientService;
use App\Services\Daftra\InvoiceService;
use App\Services\Daftra\ProductService;
use App\Services\Daftra\TaxService;
use App\Services\Reconciliation\SalesReconciliationService;
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
        protected SalesReconciliationService $reconciliationService,
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

            $daftraPayload = null;

            $daftraCreditNoteId = $this->resolveDaftraCreditNoteId($order, $creditNoteRow, $original, $daftraPayload);

            if ($creditNoteRow->daftra_id !== $daftraCreditNoteId) {
                $creditNoteRow->update(['daftra_id' => $daftraCreditNoteId]);
            }

            if (! empty($order['payments'])) {
                Log::warning('Return order carries payments; credit-note payments are not yet synced.', [
                    'order_id' => $order['id'],
                    'payments_count' => count($order['payments']),
                ]);
            }

            $daftraDocument = null;
            $daftraCreditNote = $this->invoiceService->getCreditNoteById($daftraCreditNoteId);
            if ($daftraCreditNote !== null) {
                $daftraDocument = $daftraCreditNote;
            }

            $this->persistReconciliation($order, $creditNoteRow, $daftraPayload, $daftraDocument);

            $creditNoteRow->update(['status' => InvoiceSyncStatus::Synced]);
        } catch (Throwable $e) {
            $creditNoteRow->update(['status' => InvoiceSyncStatus::Failed]);

            throw $e;
        } finally {
            $this->currentOrderId = null;
        }
    }

    protected function resolveDaftraCreditNoteId(array $order, Invoice $row, Invoice $original, ?array &$daftraPayload = null): int
    {
        if ($row->daftra_id !== null) {
            return (int) $row->daftra_id;
        }

        $existing = $this->invoiceService->getCreditNote($order['id']);
        if (! empty($existing['id'])) {
            return (int) $existing['id'];
        }

        $daftraPayload = $this->buildDaftraCreditNotePayload($order, $original);

        return $this->invoiceService->createCreditNote($daftraPayload);
    }

    /**
     * Build the Daftra credit-note payload from a Foodics return order.
     *
     * @return array{CreditNote: array<string, mixed>, InvoiceItem: array<int, array<string, mixed>>}
     */
    public function buildDaftraCreditNotePayload(array $order, Invoice $original): array
    {
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

        return [
            'CreditNote' => [
                'po_number' => $order['id'],
                'client_id' => $clientId,
                'subscription_id' => (int) $original->daftra_id,
                'date' => $order['business_date'],
                'discount_amount' => $order['discount_amount'] ?? 0,
                'notes' => $order['kitchen_notes'] ?? null,
            ],
            'InvoiceItem' => $invoiceItems,
        ];
    }

    protected function buildMinimalCreditNotePayload(array $order): array
    {
        return [
            'CreditNote' => [
                'discount_amount' => $order['discount_amount'] ?? 0,
            ],
            'InvoiceItem' => [],
        ];
    }

    protected function persistReconciliation(array $order, Invoice $invoice, ?array $daftraPayload = null, ?array $daftraDocument = null): void
    {
        try {
            if ($daftraPayload === null) {
                $daftraPayload = $this->buildMinimalCreditNotePayload($order);
            }

            $result = $this->reconciliationService->compare($order, $daftraPayload, $daftraDocument);

            $invoice->update([
                'foodics_metadata' => array_merge(
                    $invoice->foodics_metadata ?? [],
                    ['sales_reconciliation' => $result->toArray()],
                ),
            ]);

            if ($result->status === SalesReconciliationStatus::Mismatch) {
                Log::warning('Sales reconciliation mismatch', [
                    'order_id' => $order['id'],
                    'invoice_id' => $invoice->id,
                    'invoice_type' => 'credit_note',
                    'status' => $result->status->value,
                    'differences' => array_map(fn ($d) => $d->toArray(), $result->differences),
                ]);
            }
        } catch (Throwable $e) {
            Log::error('Reconciliation failed after sync', [
                'order_id' => $order['id'],
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);
        }
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
                'total_price' => (float) ($order['total_price'] ?? 0),
                'foodics_metadata' => [],
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
            'total_price' => (float) ($order['total_price'] ?? 0),
            'foodics_metadata' => [],
        ]);
    }
}
