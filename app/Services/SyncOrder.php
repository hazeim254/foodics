<?php

namespace App\Services;

use App\Enums\DaftraDiscountType;
use App\Enums\InvoiceSyncStatus;
use App\Enums\SalesReconciliationStatus;
use App\Exceptions\InvoiceAlreadyExistsException;
use App\Models\Invoice;
use App\Services\Concerns\BuildsInvoiceItems;
use App\Services\Daftra\ClientService;
use App\Services\Daftra\InvoiceService;
use App\Services\Daftra\PaymentMethodService;
use App\Services\Daftra\ProductService;
use App\Services\Daftra\TaxService;
use App\Services\Reconciliation\SalesReconciliationService;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncOrder
{
    use BuildsInvoiceItems;

    public function __construct(
        protected InvoiceService $invoiceService,
        protected ProductService $productService,
        protected ClientService $clientService,
        protected TaxService $taxService,
        protected PaymentMethodService $paymentMethodService,
        protected SyncCreditNote $syncCreditNote,
        protected SalesReconciliationService $reconciliationService,
    ) {}

    /** @var array<string, string> */
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
        $this->currentOrderId = $order['id'];

        try {
            $this->skipIfAlreadySynced($order['id'], $order['reference']);
        } catch (InvoiceAlreadyExistsException $e) {
            return;
        }

        if ((int) ($order['status'] ?? 0) === 5) {
            $this->syncCreditNote->handle($order);

            return;
        }

        $invoice = $this->createPendingInvoice($order);

        try {
            $this->runSync($order, $invoice);
        } catch (Throwable $e) {
            $invoice->update(['status' => InvoiceSyncStatus::Failed]);

            throw $e;
        } finally {
            $this->currentOrderId = null;
        }
    }

    protected function runSync(array $order, Invoice $invoice): void
    {
        $this->taxMap = [];
        $this->resolveUniqueTaxes($order);

        $this->paymentMethodMap = [];
        $this->resolveUniquePaymentMethods($order);

        $daftraPayload = null;

        $daftraInvoiceId = $this->resolveDaftraInvoiceId($order, $invoice, $daftraPayload);

        if ($invoice->daftra_id !== $daftraInvoiceId) {
            $invoice->update(['daftra_id' => $daftraInvoiceId]);
        }

        $paymentData = $this->syncPaymentsIfMissing($order['payments'] ?? [], $daftraInvoiceId);

        $daftraDocument = null;
        $daftraInvoice = $this->invoiceService->getInvoiceById($daftraInvoiceId);
        if ($daftraInvoice !== null) {
            $daftraDocument = $daftraInvoice;
            $invoice->update([
                'daftra_no' => $daftraInvoice['no'] ?? null,
                'daftra_metadata' => array_merge(
                    $invoice->daftra_metadata ?? [],
                    ['client_id' => $daftraInvoice['client_id'] ?? null],
                ),
            ]);
        }

        $this->persistReconciliation($order, $invoice, $daftraPayload, $daftraDocument, $paymentData);

        $invoice->update(['status' => InvoiceSyncStatus::Synced]);
    }

    /**
     * Resolve the Daftra invoice id to use for this order, preferring an
     * existing id on the local row, then an invoice already present on
     * Daftra, and finally creating a new one.
     *
     * When a new invoice is created, the payload is captured into
     * `$daftraPayload` for reconciliation without side effects.
     */
    protected function resolveDaftraInvoiceId(array $order, Invoice $invoice, ?array &$daftraPayload = null): int
    {
        if ($invoice->daftra_id !== null) {
            return (int) $invoice->daftra_id;
        }

        $existing = $this->invoiceService->getInvoice($order['id']);
        if (! empty($existing['id'])) {
            return (int) $existing['id'];
        }

        $invoiceData = $this->buildDaftraInvoicePayload($order);
        $daftraPayload = $invoiceData;

        return $this->invoiceService->createInvoice($invoiceData);
    }

    /**
     * Build the Daftra invoice payload from a Foodics order.
     *
     * This method extracts payload construction so that the same data can be
     * used for reconciliation without calling external services.
     *
     * @return array{Invoice: array<string, mixed>, InvoiceItem: array<int, array<string, mixed>>}
     */
    public function buildDaftraInvoicePayload(array $order): array
    {
        $invoiceItems = $this->getInvoiceItems($this->getOrderProductLines($order));
        $invoiceItems = $this->addChargeInvoiceItems($invoiceItems, $order['charges'] ?? []);

        $clientId = null;
        if (! empty($order['customer'])) {
            $clientId = $this->clientService->getClientUsingFoodicsData($order['customer']);
        }

        if (! $clientId) {
            $clientId = $this->resolveDefaultClientId();
        }

        return [
            'Invoice' => [
                'po_number' => $order['id'],
                'client_id' => $clientId,
                'date' => $order['business_date'],
                'discount_amount' => $order['discount_amount'] ?? 0,
                'discount_type' => DaftraDiscountType::Fixed->value,
                'notes' => $order['kitchen_notes'] ?? null,
            ],
            'InvoiceItem' => $invoiceItems,
        ];
    }

    protected function buildMinimalInvoicePayload(array $order): array
    {
        return [
            'Invoice' => [
                'discount_amount' => $order['discount_amount'] ?? 0,
            ],
            'InvoiceItem' => [],
        ];
    }

    protected function persistReconciliation(array $order, Invoice $invoice, ?array $daftraPayload = null, ?array $daftraDocument = null, array $paymentData = []): void
    {
        try {
            if ($daftraPayload === null) {
                $daftraPayload = $this->buildMinimalInvoicePayload($order);
            }

            if ($paymentData !== []) {
                $daftraPayload['InvoicePayment'] = $paymentData;
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
                    'invoice_type' => 'invoice',
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

    protected function resolveUniquePaymentMethods(array $order): void
    {
        $this->paymentMethodService->beginPaymentMethodBatch();

        try {
            foreach ($order['payments'] ?? [] as $payment) {
                $foodicsPaymentMethod = $payment['payment_method'] ?? [];
                $foodicsId = $foodicsPaymentMethod['id'] ?? '';
                if ($foodicsId !== '' && ! isset($this->paymentMethodMap[$foodicsId])) {
                    $this->paymentMethodMap[$foodicsId] = $this->paymentMethodService->resolvePaymentMethod($foodicsPaymentMethod);
                }
            }
        } finally {
            $this->paymentMethodService->endPaymentMethodBatch();
        }
    }

    /**
     * Post Foodics payments to Daftra only when the Daftra invoice has no
     * payments recorded yet. If any payments already exist on Daftra, the
     * sync is considered payment-complete (per spec 017: no Foodics-side
     * correlation, presence of any payments is treated as done).
     *
     * Returns normalised payment data for reconciliation: existing Daftra
     * payment rows when already present, or the created InvoicePayment
     * payloads when payments were just created.
     *
     * @param  array<int, array<string, mixed>>  $payments
     * @return array<int, array<string, mixed>>
     */
    public function syncPaymentsIfMissing(array $payments, int $daftraInvoiceId): array
    {
        $existing = $this->invoiceService->listInvoicePayments($daftraInvoiceId);

        if ($existing !== []) {
            return $existing;
        }

        $createdPayments = [];

        foreach ($payments as $payment) {
            $foodicsPaymentMethodId = (string) ($payment['payment_method']['id'] ?? '');
            $daftraPaymentMethodId = $this->paymentMethodMap[$foodicsPaymentMethodId] ?? null;

            $paymentPayload = [
                'InvoicePayment' => [
                    'invoice_id' => $daftraInvoiceId,
                    'payment_method' => $daftraPaymentMethodId,
                    'amount' => $payment['amount'],
                    'date' => $payment['added_at'],
                ],
            ];

            $this->invoiceService->createPayment($paymentPayload);

            $createdPayments[] = $paymentPayload;
        }

        return $createdPayments;
    }

    /**
     * @throws InvoiceAlreadyExistsException
     */
    protected function skipIfAlreadySynced(string $foodicsId, string $foodicsReference): void
    {
        $userId = Context::get('user')?->id;

        $blocking = Invoice::query()
            ->where('user_id', $userId)
            ->whereIn('status', [InvoiceSyncStatus::Pending, InvoiceSyncStatus::Synced])
            ->where(function ($query) use ($foodicsId, $foodicsReference) {
                $query->where('foodics_id', $foodicsId)
                    ->orWhere('foodics_reference', $foodicsReference);
            })
            ->exists();

        throw_if($blocking, new InvoiceAlreadyExistsException('Order already synced or in progress locally'));
    }

    /**
     * Insert or revive the single local row that tracks this Foodics order.
     *
     * The duplicate guard has already rejected `pending`/`synced` rows, so any
     * row found here is `failed` — we flip it back to `pending` (keeping its
     * `daftra_id` if already known) rather than creating a second row, per
     * the "exactly one local row per Foodics order" rule.
     */
    protected function createPendingInvoice(array $order): Invoice
    {
        $userId = Context::get('user')?->id;

        $invoice = Invoice::query()
            ->where('user_id', $userId)
            ->where('foodics_id', $order['id'])
            ->first();

        if ($invoice !== null) {
            $invoice->fill([
                'foodics_reference' => $order['reference'],
                'status' => InvoiceSyncStatus::Pending,
                'total_price' => (float) ($order['total_price'] ?? 0),
                'foodics_metadata' => [],
            ])->save();

            return $invoice;
        }

        return Invoice::query()->create([
            'user_id' => $userId,
            'foodics_id' => $order['id'],
            'foodics_reference' => $order['reference'],
            'daftra_id' => null,
            'status' => InvoiceSyncStatus::Pending,
            'total_price' => (float) ($order['total_price'] ?? 0),
            'foodics_metadata' => [],
        ]);
    }
}
